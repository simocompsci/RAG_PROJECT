<?php

namespace App\Rag\Clients;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ElasticsearchClient
{
    private string $baseUrl;

    private string $documentsIndex;

    private string $chunksIndex;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('rag.elasticsearch.url'), '/');
        $this->documentsIndex = (string) config('rag.elasticsearch.documents_index');
        $this->chunksIndex = (string) config('rag.elasticsearch.chunks_index');
    }

    public function ping(): bool
    {
        return Http::timeout(5)->get($this->baseUrl)->successful();
    }

    public function createIndexes(): void
    {
        if (! $this->indexExists($this->documentsIndex)) {
            $this->request('PUT', "/{$this->documentsIndex}", [
                'mappings' => $this->documentsMapping(),
            ]);
        }

        if (! $this->indexExists($this->chunksIndex)) {
            $this->request('PUT', "/{$this->chunksIndex}", [
                'mappings' => $this->chunksMapping(),
            ]);
        }
    }

    public function deleteIndexes(): void
    {
        $this->request('DELETE', "/{$this->documentsIndex}", allowNotFound: true);
        $this->request('DELETE', "/{$this->chunksIndex}", allowNotFound: true);
    }

    public function getDocument(string $documentId): ?array
    {
        $response = $this->request(
            'GET',
            "/{$this->documentsIndex}/_doc/".rawurlencode($documentId),
            allowNotFound: true,
        );

        return $response['_source'] ?? null;
    }

    public function indexDocument(string $documentId, array $document): void
    {
        $this->request(
            'PUT',
            "/{$this->documentsIndex}/_doc/".rawurlencode($documentId).'?refresh=wait_for',
            $document,
        );
    }

    /**
     * @param  array<int, array{_id: string, source: array}>  $documents
     */
    public function bulkIndexChunks(array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $body = '';

        foreach ($documents as $document) {
            $body .= json_encode([
                'index' => [
                    '_index' => $this->chunksIndex,
                    '_id' => $document['_id'],
                ],
            ], JSON_THROW_ON_ERROR)."\n";
            $body .= json_encode($document['source'], JSON_THROW_ON_ERROR)."\n";
        }

        $response = Http::timeout(60)
            ->withBody($body, 'application/x-ndjson')
            ->post("{$this->baseUrl}/_bulk?refresh=wait_for");

        $this->ensureSuccessful($response, 'bulk indexing chunks');

        if ($response->json('errors')) {
            $reason = $response->json('items.0.index.error.reason')
                ?? 'unknown bulk indexing error';

            throw new RuntimeException("Elasticsearch bulk indexing failed: {$reason}");
        }
    }

    public function deleteChunksForDocument(string $documentId): void
    {
        $this->request(
            'POST',
            "/{$this->chunksIndex}/_delete_by_query?refresh=true&conflicts=proceed",
            [
                'query' => [
                    'term' => ['document_id' => $documentId],
                ],
            ],
        );
    }

    /**
     * @return array<int, array{_id: string, _source: array}>
     */
    public function searchChunks(string $query, int $size): array
    {
        $response = $this->request('POST', "/{$this->chunksIndex}/_search", [
            'size' => $size,
            'query' => [
                'match' => [
                    'content' => $query,
                ],
            ],
            '_source' => [
                'chunk_id',
                'document_id',
                'document_name',
                'page',
                'chunk_index',
                'content',
            ],
        ]);

        return $response['hits']['hits'] ?? [];
    }

    /**
     * @param  array<int, string>  $chunkIds
     * @return array<string, array>
     */
    public function getChunks(array $chunkIds): array
    {
        if ($chunkIds === []) {
            return [];
        }

        $response = $this->request('POST', "/{$this->chunksIndex}/_mget", [
            'ids' => array_values($chunkIds),
        ]);

        $chunks = [];

        foreach ($response['docs'] ?? [] as $document) {
            if (($document['found'] ?? false) === true) {
                $chunks[$document['_id']] = $document['_source'];
            }
        }

        return $chunks;
    }

    private function indexExists(string $index): bool
    {
        return $this->request('GET', "/{$index}", allowNotFound: true) !== null;
    }

    private function request(
        string $method,
        string $path,
        ?array $body = null,
        bool $allowNotFound = false,
    ): ?array {
        $request = Http::timeout(30)->acceptJson();

        if ($body !== null) {
            $request = $request->withBody(
                json_encode($body, JSON_THROW_ON_ERROR),
                'application/json',
            );
        }

        $response = $request->send($method, $this->baseUrl.$path);

        if ($allowNotFound && $response->status() === 404) {
            return null;
        }

        $this->ensureSuccessful($response, "{$method} {$path}");

        return $response->json() ?? [];
    }

    private function ensureSuccessful(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        $details = mb_substr($response->body(), 0, 500);

        throw new RuntimeException(
            "Elasticsearch {$operation} failed with status {$response->status()}: {$details}",
        );
    }

    private function documentsMapping(): array
    {
        return [
            'properties' => [
                'document_id' => ['type' => 'keyword'],
                'document_name' => [
                    'type' => 'text',
                    'fields' => ['keyword' => ['type' => 'keyword']],
                ],
                'source_path' => ['type' => 'keyword'],
                'content_hash' => ['type' => 'keyword'],
                'page_count' => ['type' => 'integer'],
                'chunk_count' => ['type' => 'integer'],
                'created_at' => ['type' => 'date'],
                'updated_at' => ['type' => 'date'],
            ],
        ];
    }

    private function chunksMapping(): array
    {
        return [
            'properties' => [
                'chunk_id' => ['type' => 'keyword'],
                'document_id' => ['type' => 'keyword'],
                'document_name' => [
                    'type' => 'text',
                    'fields' => ['keyword' => ['type' => 'keyword']],
                ],
                'source_path' => ['type' => 'keyword'],
                'page' => ['type' => 'integer'],
                'chunk_index' => ['type' => 'integer'],
                'content' => ['type' => 'text'],
                'created_at' => ['type' => 'date'],
            ],
        ];
    }
}
