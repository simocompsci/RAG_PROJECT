<?php

namespace App\Rag\Clients;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PineconeClient
{
    private const CONTROL_PLANE_URL = 'https://api.pinecone.io';

    private ?string $apiKey;

    private ?string $indexName;

    private ?string $host;

    private string $cloud;

    private string $region;

    public function __construct()
    {
        $this->apiKey = config('rag.pinecone.api_key');
        $this->indexName = config('rag.pinecone.index');
        $this->host = config('rag.pinecone.host');
        $this->cloud = (string) config('rag.pinecone.cloud');
        $this->region = (string) config('rag.pinecone.region');
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey) && filled($this->indexName);
    }

    public function indexName(): string
    {
        return (string) $this->indexName;
    }

    public function ensureIndex(int $dimension): array
    {
        $this->ensureConfigured();

        $existing = $this->describeIndex();

        if ($existing !== null) {
            $actualDimension = (int) ($existing['dimension'] ?? 0);

            if ($actualDimension !== $dimension) {
                throw new RuntimeException(
                    "Pinecone index [{$this->indexName}] has dimension {$actualDimension}, "
                    ."but HUGGINGFACE_EMBEDDING_DIMENSIONS is {$dimension}.",
                );
            }

            $this->waitUntilReady();

            return $this->describeIndex() ?? $existing;
        }

        $this->request('POST', self::CONTROL_PLANE_URL.'/indexes', [
            'name' => $this->indexName,
            'dimension' => $dimension,
            'metric' => 'cosine',
            'spec' => [
                'serverless' => [
                    'cloud' => $this->cloud,
                    'region' => $this->region,
                ],
            ],
        ]);

        $this->waitUntilReady();

        return $this->describeIndex() ?? [];
    }

    /**
     * @param  array<int, array{id: string, values: array<int, float>, metadata: array}>  $vectors
     */
    public function upsert(array $vectors): void
    {
        $this->ensureConfigured();

        foreach (array_chunk($vectors, 100) as $batch) {
            $this->request('POST', $this->dataUrl('/vectors/upsert'), [
                'vectors' => $batch,
            ]);
        }
    }

    public function deleteForDocument(string $documentId): void
    {
        $this->ensureConfigured();

        $this->request('POST', $this->dataUrl('/vectors/delete'), [
            'filter' => [
                'document_id' => ['$eq' => $documentId],
            ],
        ]);
    }

    public function deleteAll(): void
    {
        $this->ensureConfigured();

        $this->request('POST', $this->dataUrl('/vectors/delete'), [
            'deleteAll' => true,
        ]);
    }

    /**
     * @param  array<int, float>  $vector
     * @return array<int, array{id: string, score: float, metadata?: array}>
     */
    public function query(array $vector, int $topK): array
    {
        $this->ensureConfigured();

        $response = $this->request('POST', $this->dataUrl('/vectors/query'), [
            'vector' => $vector,
            'topK' => $topK,
            'includeMetadata' => true,
        ]);

        return $response['matches'] ?? [];
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
        if (filled($this->host)) {
            $host = (string) $this->host;

            return str_starts_with($host, 'http') ? rtrim($host, '/') : 'https://'.rtrim($host, '/');
        }

        $index = $this->describeIndex();
        $host = $index['host'] ?? null;

        if (! is_string($host) || $host === '') {
            throw new RuntimeException(
                "Unable to resolve the Pinecone host for index [{$this->indexName}].",
            );
        }

        return str_starts_with($host, 'http') ? rtrim($host, '/') : 'https://'.rtrim($host, '/');
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

        $details = mb_substr($response->body(), 0, 500);

        throw new RuntimeException(
            "Pinecone request {$operation} failed with status {$response->status()}: {$details}",
        );
    }
}
