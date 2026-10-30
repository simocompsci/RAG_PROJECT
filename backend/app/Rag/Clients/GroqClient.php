<?php

namespace App\Rag\Clients;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GroqClient
{
    private ?string $apiKey;

    private string $baseUrl;

    private string $model;

    private int $maxTokens;

    private ?string $reasoningEffort;

    public function __construct()
    {
        $this->apiKey = config('rag.groq.api_key');
        $this->baseUrl = rtrim((string) config('rag.groq.base_url'), '/');
        $this->model = (string) config('rag.groq.model');
        $this->maxTokens = (int) config('rag.groq.max_tokens');
        $this->reasoningEffort = config('rag.groq.reasoning_effort');
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    public function chat(string $systemPrompt, string $userPrompt): string
    {
        $this->ensureConfigured();

        $body = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => $this->maxTokens,
        ];

        if (filled($this->reasoningEffort)) {
            $body['reasoning_effort'] = $this->reasoningEffort;
        }

        $response = $this->request('POST', $this->endpoint('/chat/completions'), $body);

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Groq returned an empty chat completion.');
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
                'Groq is not configured. Set GROQ_API_KEY in backend/.env.',
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
            "Groq request {$url} failed with status {$response->status()}: {$details}",
        );
    }
}
