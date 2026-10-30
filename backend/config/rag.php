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
    'top_k_final' => (int) env('RAG_TOP_K_FINAL', 8),
    'rrf_k' => (int) env('RAG_RRF_K', 60),

    /*
    |--------------------------------------------------------------------------
    | Elasticsearch
    |--------------------------------------------------------------------------
    */

    'elasticsearch' => [
        'url' => env('ELASTICSEARCH_URL', 'http://localhost:9200'),
        'documents_index' => env('ELASTICSEARCH_DOCUMENTS_INDEX', 'rag_documents'),
        'chunks_index' => env('ELASTICSEARCH_CHUNKS_INDEX', 'rag_chunks'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pinecone
    |--------------------------------------------------------------------------
    |
    | `host` is optional. When omitted, the host is resolved from the Pinecone
    | control plane using the index name.
    |
    */

    'pinecone' => [
        'api_key' => env('PINECONE_API_KEY'),
        'index' => env('PINECONE_INDEX'),
        'host' => env('PINECONE_HOST'),
        'cloud' => env('PINECONE_CLOUD', 'aws'),
        'region' => env('PINECONE_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hugging Face
    |--------------------------------------------------------------------------
    |
    | The router exposes an OpenAI-compatible API under `/v1`.
    |
    */

    'huggingface' => [
        'api_key' => env('HUGGINGFACE_API_KEY'),
        'base_url' => env('HUGGINGFACE_BASE_URL', 'https://router.huggingface.co'),
        'embedding_model' => env('HUGGINGFACE_EMBEDDING_MODEL', 'sentence-transformers/all-MiniLM-L6-v2'),
        'embedding_dimensions' => (int) env('HUGGINGFACE_EMBEDDING_DIMENSIONS', 384),
        'embedding_batch_size' => (int) env('HUGGINGFACE_EMBEDDING_BATCH_SIZE', 32),
        'llm_model' => env('HUGGINGFACE_LLM_MODEL', 'meta-llama/Llama-3.1-8B-Instruct'),
        'llm_max_tokens' => (int) env('HUGGINGFACE_LLM_MAX_TOKENS', 800),
    ],

];
