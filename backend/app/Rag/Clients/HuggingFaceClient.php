<?php

namespace App\Rag\Clients;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HuggingFaceClient
{
    private ?string $apiKey;

    private string $baseUrl;

    private string $llmModel;

    private int $llmMaxTokens;

    public function __construct()
    {
        $this->apiKey = config('rag.huggingface.api_key');
        $this->baseUrl = rtrim((string) config('rag.huggingface.base_url'), '/');
        $this->llmModel = (string) config('rag.huggingface.llm_model');
        $this->llmMaxTokens = (int) config('rag.huggingface.llm_max_tokens');
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
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
