<?php

namespace App\Rag\Clients;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PineconeClient
{
    private const CONTROL_PLANE_URL = 'https://api.pinecone.io';

    private const API_VERSION = '2026-07';

    private const MAX_UPSERT_BATCH = 96;

    private ?string $apiKey;

    private ?string $indexName;

    private ?string $host;

    private string $namespace;

    private string $textField;

    private string $embeddingModel;

    private int $embeddingDimensions;

    private string $cloud;

    private string $region;

    private int $deleteTimeout;

    private ?string $resolvedHost = null;

    public function __construct()
    {
        $this->apiKey = config('rag.pinecone.api_key');
        $this->indexName = config('rag.pinecone.index');
        $this->host = config('rag.pinecone.host');
        $this->namespace = (string) config('rag.pinecone.namespace');
        $this->textField = (string) config('rag.pinecone.text_field');
        $this->embeddingModel = (string) config('rag.pinecone.embedding_model');
        $this->embeddingDimensions = (int) config('rag.pinecone.embedding_dimensions');
        $this->cloud = (string) config('rag.pinecone.cloud');
        $this->region = (string) config('rag.pinecone.region');
        $this->deleteTimeout = max(1, (int) config('rag.pinecone.delete_timeout'));
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey) && filled($this->indexName);
    }

    public function indexName(): string
    {
        return (string) $this->indexName;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function textField(): string
    {
        return $this->textField;
    }

    public function embeddingModel(): string
    {
        return $this->embeddingModel;
    }

    public function embeddingDimensions(): int
    {
        return $this->embeddingDimensions;
    }

    public function ensureIndex(): array
    {
        $this->ensureConfigured();

        $existing = $this->describeIndex();

        if ($existing !== null) {
            $this->assertCompatibleIndex($existing);
            $this->waitUntilReady();

            return $this->describeIndex() ?? $existing;
        }

        $this->request('POST', self::CONTROL_PLANE_URL.'/indexes', [
            'name' => $this->indexName,
            'deployment' => [
                'deployment_type' => 'managed',
                'cloud' => $this->cloud,
                'region' => $this->region,
            ],
            'schema' => [
                'fields' => [
                    $this->textField => [
                        'type' => 'string',
                        'embed' => [
                            'model' => $this->embeddingModel,
                            'dimension' => $this->embeddingDimensions,
                        ],
                        'full_text_search' => [
                            'language' => 'en',
                        ],
                    ],
                ],
            ],
        ]);

        $this->waitUntilReady();

        return $this->describeIndex() ?? [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $documents
     */
    public function upsertDocuments(array $documents): void
    {
        $this->ensureConfigured();

        foreach (array_chunk($documents, self::MAX_UPSERT_BATCH) as $batch) {
            $this->request(
                'POST',
                $this->dataUrl($this->namespacePath('/documents/upsert')),
                ['documents' => array_values($batch)],
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchDense(string $query, int $topK): array
    {
        return $this->search($query, 'embed', $topK);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchLexical(string $query, int $topK): array
    {
        return $this->search($query, 'text', $topK);
    }

    public function firstChunkForDocument(string $documentId): ?array
    {
        $this->ensureConfigured();

        $response = $this->request(
            'POST',
            $this->dataUrl($this->namespacePath('/documents/fetch')),
            [
                'filter' => [
                    'document_id' => ['$eq' => $documentId],
                ],
                'limit' => 1,
                'include_fields' => [
                    'content_hash',
                    'page_count',
                    'chunk_count',
                    'created_at',
                ],
            ],
            allowNotFound: true,
        );

        if ($response === null) {
            return null;
        }

        $documents = $response['documents'] ?? [];

        return is_array($documents) ? (reset($documents) ?: null) : null;
    }

    public function deleteForDocument(string $documentId): void
    {
        $this->ensureConfigured();

        $response = $this->request(
            'POST',
            $this->dataUrl($this->namespacePath('/documents/delete')),
            [
                'filter' => [
                    'document_id' => ['$eq' => $documentId],
                ],
            ],
            allowNotFound: true,
        );

        if ($response === null) {
            return;
        }

        $this->waitForDocumentRemoval($documentId);
    }

    public function deleteAllDocuments(): void
    {
        $this->ensureConfigured();

        $response = $this->request(
            'POST',
            $this->dataUrl($this->namespacePath('/documents/delete')),
            ['delete_all' => true],
            allowNotFound: true,
        );

        if ($response === null) {
            return;
        }

        $this->waitForNamespaceEmpty();
    }

    /**
     * @return array<int, array{_id: string}>
     */
    public function listDocuments(int $limit = 100, ?string $paginationToken = null): array
    {
        $this->ensureConfigured();

        $body = ['limit' => max(1, min($limit, 100))];

        if ($paginationToken !== null) {
            $body['pagination_token'] = $paginationToken;
        }

        $response = $this->request(
            'POST',
            $this->dataUrl($this->namespacePath('/documents/list')),
            $body,
            allowNotFound: true,
        );

        return $response['documents'] ?? [];
    }

    private function search(string $query, string $scoringType, int $topK): array
    {
        $this->ensureConfigured();

        $response = $this->request(
            'POST',
            $this->dataUrl($this->namespacePath('/documents/search')),
            [
                'top_k' => max(1, $topK),
                'score_by' => [[
                    'type' => $scoringType,
                    'fields' => [$this->textField],
                    'query' => $query,
                ]],
                'include_fields' => [
                    $this->textField,
                    'document_id',
                    'document_name',
                    'source_path',
                    'page',
                    'chunk_index',
                    'content_hash',
                    'page_count',
                    'chunk_count',
                    'created_at',
                    'updated_at',
                ],
            ],
            allowNotFound: true,
        );

        return $response['matches'] ?? [];
    }

    private function waitForDocumentRemoval(string $documentId): void
    {
        $deadline = microtime(true) + $this->deleteTimeout;

        while (microtime(true) < $deadline) {
            if ($this->firstChunkForDocument($documentId) === null) {
                return;
            }

            sleep(1);
        }

        throw new RuntimeException(
            "Pinecone did not finish deleting document [{$documentId}] within {$this->deleteTimeout} seconds.",
        );
    }

    private function waitForNamespaceEmpty(): void
    {
        $deadline = microtime(true) + $this->deleteTimeout;

        while (microtime(true) < $deadline) {
            if ($this->listDocuments(1) === []) {
                return;
            }

            sleep(1);
        }

        throw new RuntimeException(
            "Pinecone did not finish clearing namespace [{$this->namespace}] within {$this->deleteTimeout} seconds.",
        );
    }

    private function assertCompatibleIndex(array $index): void
    {
        $fields = $index['schema']['fields'] ?? null;

        if (! is_array($fields)) {
            throw new RuntimeException(
                "Pinecone index [{$this->indexName}] is not a document index. "
                .'Create an index with a document schema that supports integrated embedding and full-text search.',
            );
        }

        $field = $fields[$this->textField] ?? null;

        if (! is_array($field)) {
            throw new RuntimeException(
                "Pinecone index [{$this->indexName}] has no [{$this->textField}] field.",
            );
        }

        if (($field['type'] ?? null) !== 'string' || ! is_array($field['embed'] ?? null)) {
            throw new RuntimeException(
                "Pinecone field [{$this->textField}] must be a string field with integrated embedding.",
            );
        }

        if (! array_key_exists('full_text_search', $field)) {
            throw new RuntimeException(
                "Pinecone field [{$this->textField}] must have full-text search enabled.",
            );
        }

        if (($field['store_text'] ?? true) !== true) {
            throw new RuntimeException(
                "Pinecone field [{$this->textField}] must store text so search results can be returned.",
            );
        }

        $actualModel = (string) ($field['embed']['model'] ?? '');
        $actualDimensions = (int) ($field['embed']['dimension'] ?? 0);

        if ($actualModel !== $this->embeddingModel || $actualDimensions !== $this->embeddingDimensions) {
            throw new RuntimeException(
                "Pinecone field [{$this->textField}] uses {$actualModel} ({$actualDimensions} dimensions), "
                ."but the app expects {$this->embeddingModel} ({$this->embeddingDimensions} dimensions).",
            );
        }
    }

    private function describeIndex(): ?array
    {
        return $this->request(
            'GET',
            self::CONTROL_PLANE_URL.'/indexes/'.rawurlencode((string) $this->indexName),
            allowNotFound: true,
        );
    }

    private function waitUntilReady(int $timeoutSeconds = 120): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $index = $this->describeIndex();

            if (($index['status']['ready'] ?? false) === true) {
                return;
            }

            sleep(3);
        }

        throw new RuntimeException(
            "Pinecone index [{$this->indexName}] did not become ready within {$timeoutSeconds} seconds.",
        );
    }

    private function dataUrl(string $path): string
    {
        return $this->resolveHost().$path;
    }

    private function resolveHost(): string
    {
        if ($this->resolvedHost !== null) {
            return $this->resolvedHost;
        }

        if (filled($this->host)) {
            $host = (string) $this->host;
            $this->resolvedHost = str_starts_with($host, 'http')
                ? rtrim($host, '/')
                : 'https://'.rtrim($host, '/');

            return $this->resolvedHost;
        }

        $index = $this->describeIndex();
        $host = $index['host'] ?? null;

        if (! is_string($host) || $host === '') {
            throw new RuntimeException(
                "Unable to resolve the Pinecone host for index [{$this->indexName}].",
            );
        }

        $this->resolvedHost = str_starts_with($host, 'http')
            ? rtrim($host, '/')
            : 'https://'.rtrim($host, '/');

        return $this->resolvedHost;
    }

    private function namespacePath(string $suffix): string
    {
        return '/namespaces/'.rawurlencode($this->namespace).$suffix;
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Pinecone is not configured. Set PINECONE_API_KEY and PINECONE_INDEX in backend/.env.',
            );
        }
    }

    private function request(
        string $method,
        string $url,
        ?array $body = null,
        bool $allowNotFound = false,
    ): ?array {
        $request = Http::timeout(120)
            ->withHeaders([
                'Api-Key' => (string) $this->apiKey,
                'Accept' => 'application/json',
                'X-Pinecone-Api-Version' => self::API_VERSION,
            ]);

        if ($body !== null) {
            $request = $request->withBody(
                json_encode($body, JSON_THROW_ON_ERROR),
                'application/json',
            );
        }

        $response = $request->send($method, $url);

        if ($allowNotFound && $response->status() === 404) {
            return null;
        }

        $this->ensureSuccessful($response, "{$method} {$url}");

        return $response->json() ?? [];
    }

    private function ensureSuccessful(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message')
            ?? $response->json('message')
            ?? mb_substr($response->body(), 0, 500);

        throw new RuntimeException(
            "Pinecone request {$operation} failed with status {$response->status()}: {$message}",
        );
    }
}
