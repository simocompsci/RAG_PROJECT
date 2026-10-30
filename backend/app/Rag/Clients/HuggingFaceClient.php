<?php

namespace App\Rag\Clients;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HuggingFaceClient
{
    private ?string $apiKey;

    private string $baseUrl;

    private string $embeddingModel;

    private int $embeddingDimensions;

    private int $embeddingBatchSize;

    private string $llmModel;

    private int $llmMaxTokens;

    public function __construct()
    {
        $this->apiKey = config('rag.huggingface.api_key');
        $this->baseUrl = rtrim((string) config('rag.huggingface.base_url'), '/');
        $this->embeddingModel = (string) config('rag.huggingface.embedding_model');
        $this->embeddingDimensions = (int) config('rag.huggingface.embedding_dimensions');
        $this->embeddingBatchSize = max(1, (int) config('rag.huggingface.embedding_batch_size'));
        $this->llmModel = (string) config('rag.huggingface.llm_model');
        $this->llmMaxTokens = (int) config('rag.huggingface.llm_max_tokens');
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array
    {
        $this->ensureConfigured();

        $embeddings = [];

        foreach (array_chunk($texts, $this->embeddingBatchSize) as $batch) {
            $response = $this->request('POST', $this->endpoint('/embeddings'), [
                'model' => $this->embeddingModel,
                'input' => array_values($batch),
            ]);

            $batchEmbeddings = $this->extractEmbeddings($response->json());

            if (count($batchEmbeddings) !== count($batch)) {
                throw new RuntimeException(
                    'Hugging Face returned an unexpected number of embeddings.',
                );
            }

            array_push($embeddings, ...$batchEmbeddings);
        }

        return $embeddings;
    }

    public function chat(string $systemPrompt, string $userPrompt): string
    {
        $this->ensureConfigured();

        $response = $this->request('POST', $this->endpoint('/chat/completions'), [
            'model' => $this->llmModel,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => $this->llmMaxTokens,
        ]);

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Hugging Face returned an empty chat completion.');
        }

        return trim($content);
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function extractEmbeddings(?array $payload): array
    {
        if ($payload === null) {
            throw new RuntimeException('Hugging Face returned an empty embedding response.');
        }

        $data = $payload['data'] ?? $payload['embeddings'] ?? $payload;

        if (! is_array($data)) {
            throw new RuntimeException('Hugging Face returned an invalid embedding response.');
        }

        $embeddings = [];

        foreach ($data as $item) {
            $embedding = is_array($item) && isset($item['embedding'])
                ? $item['embedding']
                : $item;

            if (! is_array($embedding) || $embedding === []) {
                throw new RuntimeException('Hugging Face returned an invalid embedding vector.');
            }

            if ($this->embeddingDimensions > 0 && count($embedding) !== $this->embeddingDimensions) {
                throw new RuntimeException(
                    'Embedding dimension mismatch: received '.count($embedding)
                    .", expected {$this->embeddingDimensions}. "
                    .'Update HUGGINGFACE_EMBEDDING_DIMENSIONS and the Pinecone index to match.',
                );
            }

            $embeddings[] = array_map('floatval', $embedding);
        }

        return $embeddings;
    }

    private function endpoint(string $path): string
    {
        $baseUrl = str_ends_with($this->baseUrl, '/v1')
            ? $this->baseUrl
            : $this->baseUrl.'/v1';

        return $baseUrl.$path;
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Hugging Face is not configured. Set HUGGINGFACE_API_KEY in backend/.env.',
            );
        }
    }

    private function request(string $method, string $url, array $body): Response
    {
        $response = Http::timeout(120)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->withBody(json_encode($body, JSON_THROW_ON_ERROR), 'application/json')
            ->send($method, $url);

        if ($response->successful()) {
            return $response;
        }

        $details = mb_substr($response->body(), 0, 500);

        throw new RuntimeException(
            "Hugging Face request {$url} failed with status {$response->status()}: {$details}",
        );
    }
}
