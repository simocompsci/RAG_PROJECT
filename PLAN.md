# RAG Client-Service Q&A — Implementation Plan

Status: implemented. Pinecone is the only retrieval/storage service. Elasticsearch was removed.

## 1. Goal

Build a Retrieval-Augmented Generation (RAG) Q&A system for **client-service employees**. The knowledge base is a curated set of internal client-service documents (procedures, policies, playbooks) stored in the root `documents/` folder. Employees ask the questions customers bring them, and the system answers from the internal documents with source citations.

Documents are prepared and maintained by an operator. There is no document upload in the application.

Core pipeline:

```text
documents/*.pdf
  -> CLI ingestion command
  -> page-aware text extraction
  -> overlapping chunks
  -> Pinecone document upsert
  -> Pinecone embeds chunk_text automatically
  -> Pinecone indexes chunk_text for BM25
  -> employee asks a customer question
  -> Pinecone dense search + Pinecone lexical search
  -> Reciprocal Rank Fusion
  -> Hugging Face LLM
  -> answer + source citations
```

## 2. Architecture

- **`documents/`** is the canonical, operator-managed document corpus. It is read by the ingestion command and is not modified by the application.
- **Pinecone** is the only retrieval/storage service. One document index stores:
  - `chunk_text`, which supports integrated embedding and full-text search;
  - chunk/document metadata used for sources, filtering, and incremental ingestion.
- **Hugging Face** is used only for answer generation.
- **Laravel 13** provides the ingestion command and the query API, and orchestrates chunking, indexing, hybrid retrieval, RRF, LLM generation, and citations.
- **Next.js + TypeScript + Tailwind CSS** provides a chat-only interface for client-service employees.
- Ingestion runs from the CLI, not from the UI. It is synchronous for the MVP.
- No PostgreSQL, SQLite metadata tables, Eloquent metadata models, authentication, or user accounts.
- No upload endpoint, upload storage, document list, or document status UI.
- No Elasticsearch, Docker, or external embedding model.

## 3. Pinecone Index

Index name: `rag-embeddings`

Namespace: `__default__`

Schema:

```json
{
  "fields": {
    "chunk_text": {
      "type": "string",
      "embed": {
        "model": "llama-text-embed-v2",
        "dimension": 1024
      },
      "full_text_search": {
        "language": "en"
      },
      "store_text": true
    },
    "title": {
      "type": "string",
      "full_text_search": {
        "language": "en"
      },
      "store_text": true
    }
  }
}
```

The app uses `chunk_text` for both dense and lexical retrieval. `title` is available in the index schema but is not required by the app.

Pinecone documents use this shape:

```text
_id            {document_id}:{chunk_index}
chunk_text     chunk text (embedded + BM25 indexed by Pinecone)
document_id    deterministic document identifier
document_name  original PDF filename
source_path    relative path inside documents/
page           PDF page number
chunk_index    zero-based chunk index
content_hash   SHA-256 of the whole PDF
page_count     number of extracted pages
chunk_count    number of chunks for the document
created_at     ISO-8601 timestamp
updated_at     ISO-8601 timestamp
```

All requests use API version `2026-07`.

## 4. API Surface

```text
POST /api/query    Ask a customer question
```

There are no document upload, list, status, or delete endpoints in the MVP.

### Query request

```json
{
  "question": "What is our refund policy for a damaged item?"
}
```

### Query response

```json
{
  "answer": "Answer text with citations [1], [2].",
  "sources": [
    {
      "index": 1,
      "document_id": "...",
      "document_name": "refund-policy.pdf",
      "page": 4,
      "chunk_id": "...",
      "snippet": "Relevant text...",
      "rrf_score": 0.032
    }
  ]
}
```

## 5. Ingestion Flow

```sh
php artisan rag:ingest
```

Command behavior:

1. Scan `documents/` recursively for PDFs.
2. For each PDF, compute a SHA-256 content hash and deterministic `document_id`.
3. Fetch one existing chunk for the document and skip when `content_hash` is unchanged.
4. For new or changed documents, extract text page-by-page.
5. Split text into overlapping chunks while preserving page numbers.
6. Delete the document's existing Pinecone chunks and wait until they are gone.
7. Upsert the new chunks; Pinecone batches at most 96 documents per request and embeds `chunk_text` automatically.
8. Print a per-file summary and exit non-zero if any document fails.

Chunk IDs are deterministic:

```text
{document_id}:{chunk_index}
```

Optional flags:

```sh
php artisan rag:ingest --fresh     # clear the namespace and re-ingest everything
php artisan rag:ingest --force     # re-ingest unchanged documents
php artisan rag:ingest --only=refund-policy.pdf
```

`--fresh` deletes all documents in the configured namespace and waits for the namespace to become empty before ingesting.

Pinecone updates and deletes are asynchronous. The client polls fetch/list endpoints so a changed document never leaves stale trailing chunks.

## 6. Retrieval and Generation Flow

1. Run a Pinecone dense search:

```json
{
  "top_k": 20,
  "score_by": [{ "type": "embed", "fields": ["chunk_text"], "query": "..." }],
  "include_fields": ["chunk_text", "document_id", "document_name", "page", "..."]
}
```

2. Run a Pinecone lexical search:

```json
{
  "top_k": 20,
  "score_by": [{ "type": "text", "fields": ["chunk_text"], "query": "..." }],
  "include_fields": ["chunk_text", "document_id", "document_name", "page", "..."]
}
```

3. Fuse both ranked lists with Reciprocal Rank Fusion:

```text
score = sum(1 / (k + rank))
```

4. Keep the top 8 chunks, build source citations, and pass the context to Hugging Face.
5. Return the generated answer and source metadata.

Pinecone cannot combine `embed` and `text` in one search request, so hybrid retrieval uses two requests plus local RRF.

## 7. Configuration

Backend environment:

```env
PINECONE_API_KEY=
PINECONE_INDEX=rag-embeddings
PINECONE_HOST=
PINECONE_NAMESPACE=__default__
PINECONE_TEXT_FIELD=chunk_text
PINECONE_EMBEDDING_MODEL=llama-text-embed-v2
PINECONE_EMBEDDING_DIMENSIONS=1024
PINECONE_CLOUD=aws
PINECONE_REGION=us-east-1

HUGGINGFACE_API_KEY=
HUGGINGFACE_BASE_URL=https://router.huggingface.co
HUGGINGFACE_LLM_MODEL=meta-llama/Llama-3.1-8B-Instruct
HUGGINGFACE_LLM_MAX_TOKENS=800

RAG_DOCUMENTS_PATH=../documents
RAG_CHUNK_SIZE=4000
RAG_CHUNK_OVERLAP=600
RAG_TOP_K_LEXICAL=20
RAG_TOP_K_VECTOR=20
RAG_TOP_K_FINAL=8
RAG_RRF_K=60
```

Chunk size `4000` characters stays within `llama-text-embed-v2`'s 2048-token limit for these documents.

## 8. Repository Layout

```text
documents/                                      source PDFs
backend/
  app/Console/Commands/SetupRagCommand.php      verify/create Pinecone index
  app/Console/Commands/IngestDocumentsCommand.php
  app/Rag/Clients/PineconeClient.php            Documents API client
  app/Rag/Clients/HuggingFaceClient.php         chat completions only
  app/Rag/Ingestion/DocumentIngestor.php
  app/Rag/Ingestion/PdfExtractor.php
  app/Rag/Ingestion/TextChunker.php
  app/Rag/Retrieval/RagQueryService.php
  app/Rag/Retrieval/RrfRanker.php
  app/Http/Controllers/QueryController.php
  app/Http/Requests/QueryRequest.php
  config/rag.php
  routes/api.php
frontend/
  app/                                          chat page and light theme
  components/chat.tsx                           chat-only UI
  components/ui/                                shadcn chat primitives
  lib/rag.ts                                    API client and types
```

## 9. Verification

```sh
cd backend
php artisan rag:setup
php artisan rag:ingest --fresh
php artisan rag:ingest
vendor/bin/pint --test

cd ../frontend
npm run lint
npx tsc --noEmit
npm run build
```

Manual end-to-end check:

```sh
curl -X POST http://localhost:8000/api/query \
  -H "Content-Type: application/json" \
  -d '{"question":"What is the refund policy for a damaged item?"}'
```

Expect a grounded answer and citations. After ingestion, Pinecone may take up to about a minute to make new documents searchable.

## 10. Constraints

- No automated tests for the MVP.
- No authentication, user accounts, or database tables for RAG data.
- No document management UI.
- Keep the implementation minimal, readable, and maintainable.
