<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document Corpus
    |--------------------------------------------------------------------------
    |
    | The folder that contains the internal client-service PDFs. Documents are
    | read from this folder by the `rag:ingest` command.
    |
    */

    'documents_path' => env('RAG_DOCUMENTS_PATH', base_path('../documents')),

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    */

    'chunk_size' => (int) env('RAG_CHUNK_SIZE', 4000),
    'chunk_overlap' => (int) env('RAG_CHUNK_OVERLAP', 600),

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    */

    'top_k_lexical' => (int) env('RAG_TOP_K_LEXICAL', 20),
    'top_k_vector' => (int) env('RAG_TOP_K_VECTOR', 20),
    'top_k_final' => (int) env('RAG_TOP_K_FINAL', 5),
    'rrf_k' => (int) env('RAG_RRF_K', 60),

    /*
    |--------------------------------------------------------------------------
    | Pinecone
    |--------------------------------------------------------------------------
    |
    | The index is a document index with a single text field that supports
    | integrated embedding and full-text search. `host` is optional. When it
    | is omitted, the host is resolved from the control plane.
    |
    */

    'pinecone' => [
        'api_key' => env('PINECONE_API_KEY'),
        'index' => env('PINECONE_INDEX'),
        'host' => env('PINECONE_HOST'),
        'namespace' => env('PINECONE_NAMESPACE', '__default__'),
        'text_field' => env('PINECONE_TEXT_FIELD', 'chunk_text'),
        'embedding_model' => env('PINECONE_EMBEDDING_MODEL', 'llama-text-embed-v2'),
        'embedding_dimensions' => (int) env('PINECONE_EMBEDDING_DIMENSIONS', 1024),
        'cloud' => env('PINECONE_CLOUD', 'aws'),
        'region' => env('PINECONE_REGION', 'us-east-1'),
        'delete_timeout' => (int) env('PINECONE_DELETE_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Groq
    |--------------------------------------------------------------------------
    |
    | Groq exposes an OpenAI-compatible chat completions API under /openai/v1.
    |
    */

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
        'max_tokens' => (int) env('GROQ_MAX_TOKENS', 1200),
        'reasoning_effort' => env('GROQ_REASONING_EFFORT', 'low'),
    ],

];
