# RAG Client-Service Q&A — Implementation Plan

Status: architecture agreed, implementation not started.

Implementation is blocked until Pinecone and Hugging Face are configured. See [Implementation gate](#14-implementation-gate).

## 1. Goal

Build a Retrieval-Augmented Generation (RAG) Q&A system for **client-service employees**. The knowledge base is a curated set of internal client-service documents (procedures, policies, playbooks) stored in the root `documents/` folder. Employees ask the questions customers bring them, and the system answers from the internal documents with source citations.

Documents are prepared and maintained by an operator. There is no document upload in the application.

Core pipeline:

```text
documents/*.pdf
  -> CLI ingestion command
  -> text extraction
  -> chunking
  -> Hugging Face embeddings
  -> Pinecone vectors
  -> Elasticsearch BM25 index
  -> employee asks a customer question
  -> BM25 search + vector search
  -> Reciprocal Rank Fusion
  -> fetch chunk text from Elasticsearch
  -> Hugging Face LLM
  -> answer + source citations
```

## 2. Agreed Architecture

- **`documents/`** is the canonical, operator-managed document corpus.
  - It contains the internal client-service PDFs (procedures, policies, etc.).
  - It is read by the ingestion command and is not modified by the application.
- **Elasticsearch** handles BM25 search and stores:
  - original chunk text;
  - document metadata;
  - document content hashes for incremental re-ingestion.
- **Pinecone** stores:
  - chunk embeddings;
  - metadata needed to identify each chunk's source.
- **Hugging Face** provides:
  - the embedding model;
  - the answer-generation model.
- **Laravel 13** provides the ingestion command and the query API, and orchestrates chunking, indexing, hybrid retrieval, RRF, LLM generation, and citations.
- **Next.js + TypeScript + Tailwind CSS** provides a chat-only interface for client-service employees.
- Ingestion runs from the CLI, not from the UI. It is synchronous for the MVP.
- No PostgreSQL, SQLite metadata tables, Eloquent metadata models, authentication, or user accounts.
- No upload endpoint, upload storage, document list, or document status UI.
- Redis queues are an optional future improvement, not part of the initial stack.

## 3. Repository Inspection

Existing:

- Laravel 13 skeleton in `backend/`, PHP 8.5 available, Composer dependencies installed.
- Next.js 16.4.3 + React 19 + TypeScript + Tailwind 4 skeleton in `frontend/`.
- Elasticsearch 9.2.0 service in `docker-compose.yml`.
- Default Laravel SQLite file, migrations, `User` model, and auth config from the skeleton.
- Empty `documents/` and `infrastructure/elasticsearch/` directories.

What needs changing:

1. **API routing is missing.** Add `routes/api.php` and register it in `bootstrap/app.php`. Only the query endpoint is required.
2. **CORS configuration is missing.** Add Laravel CORS config for the Next.js origin. A Next.js proxy is an alternative, but Laravel CORS is the chosen default.
3. **Runtime drivers currently use the database.** Set session/cache/queue drivers to non-database equivalents:
   - `SESSION_DRIVER=cookie`
   - `CACHE_STORE=file`
   - `QUEUE_CONNECTION=sync`
4. **No PDF parser is installed.** Add `smalot/pdfparser` for the ingestion command.
5. **No RAG services/commands/controllers exist.** All ingestion, retrieval, and generation code is new.
6. **Frontend is still the starter page.** Replace it with the chat-only Q&A UI.
7. **Docker currently runs Elasticsearch only.** That is sufficient for the MVP.
8. **Default SQLite/Eloquent scaffolding can remain unused.** Do not add migrations or metadata models. No application code will query the database.

No Elasticsearch, Pinecone, or Hugging Face SDK is required. Laravel's built-in HTTP client is sufficient.

## 4. Storage Layout

### 4.1 Elasticsearch: `rag_documents`

Document metadata written during ingestion:

```text
document_id       keyword
document_name     text + keyword
source_path       keyword
content_hash      keyword
page_count        integer
chunk_count       integer
created_at        date
updated_at        date
```

`document_id` is deterministic, derived from the document path and content hash. The stored `content_hash` lets the ingestion command skip unchanged files and detect updates.

### 4.2 Elasticsearch: `rag_chunks`

Original chunk text and BM25 content:

```text
chunk_id          keyword
document_id       keyword
document_name     text + keyword
source_path       keyword
page              integer
chunk_index       integer
content           text
created_at        date
```

Chunk IDs should be deterministic:

```text
{document_id}:{chunk_index}
```

Chunk text lives in Elasticsearch because Pinecone does not store text. Sources are hydrated from `rag_chunks` after RRF.

### 4.3 Pinecone

```text
vector id   -> chunk_id
values      -> embedding vector
metadata    -> document_id
               document_name
               page
               chunk_index
```

### 4.4 Documents folder

```text
documents/
  {name}.pdf
```

This folder is the source of truth for the knowledge base. Add or update PDFs there, then run the ingestion command to update the indexes. PDFs are not copied into application storage.

## 5. API Surface

Essential endpoint:

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

## 6. Ingestion Flow

Ingestion is a CLI command, not part of the web request lifecycle:

```sh
php artisan rag:ingest
```

Command behavior:

1. Scan `documents/*.pdf`.
2. For each PDF, compute a content hash and deterministic `document_id`.
3. Skip documents whose `content_hash` is unchanged.
4. For new or changed documents, extract text page-by-page.
5. Split text into overlapping chunks while preserving page numbers.
6. Generate embeddings for all chunks through Hugging Face.
7. Upsert vectors into Pinecone.
8. Bulk-index chunks into `rag_chunks` with `refresh=wait_for`.
9. Upsert the document metadata into `rag_documents`.
10. For changed documents, remove stale chunks and vectors before or after the new write so no duplicate chunks remain.
11. Print a per-file summary and exit non-zero if any document fails.

Optional flags:

```sh
php artisan rag:ingest --fresh     # clear both indexes and re-ingest everything
php artisan rag:ingest --force     # re-ingest unchanged documents
php artisan rag:ingest --only=refund-policy.pdf
```

Keep a conservative page/chunk limit for the MVP. If ingestion becomes too slow, introduce an optional queue later.

## 7. Retrieval and Generation Flow

1. Embed the employee's question with the same Hugging Face embedding model used for document chunks.
2. Run Elasticsearch BM25 search over `rag_chunks`.
3. Run Pinecone vector search using the same embedding.
4. Fuse both ranked lists with Reciprocal Rank Fusion:

```text
score = sum(1 / (k + rank))
```

Default `k = 60`; ranks start at 1.

5. Deduplicate by `chunk_id`.
6. Select the final top-K chunks.
7. Fetch the selected chunk text from `rag_chunks` by ID.
8. Build numbered context blocks with document name and page.
9. Call the Hugging Face instruct model with a grounded prompt.
10. Return the answer and source metadata.

Prompt requirements:

- Answer only from the provided context.
- Cite sources as `[1]`, `[2]`, etc.
- Say when the answer is not present in the documents.
- Treat document text as untrusted and ignore instructions embedded inside it.

## 8. Implementation Plan

### Phase 0 — Backend foundation

- Install `smalot/pdfparser`.
- Add `routes/api.php` and register it in `bootstrap/app.php`.
- Add `config/rag.php` for:
  - documents path;
  - Elasticsearch indexes;
  - Pinecone index;
  - Hugging Face models;
  - chunking and retrieval settings.
- Add `config/cors.php` for the Next.js origin.
- Update `.env.example` with placeholders.
- Change runtime drivers to non-database values.
- Add `rag:setup` command to create/verify:
  - `rag_documents` Elasticsearch index and mapping;
  - `rag_chunks` Elasticsearch index and mapping;
  - Pinecone index dimension.
- Do not add migrations or metadata models.
- Skip Laravel Boost for the MVP unless explicitly requested.

### Phase 1 — CLI ingestion

- Add the `rag:ingest` command.
- Add PDF discovery and content hashing.
- Add PDF text extraction with page preservation.
- Add configurable chunking with overlap.
- Add Hugging Face embedding client with batching.
- Add Elasticsearch index/write/delete methods.
- Add Pinecone upsert/delete methods.
- Add stale-chunk cleanup for changed or removed documents.
- Print clear per-file results and a final summary.

### Phase 2 — Hybrid retrieval and answer generation

- Add Elasticsearch BM25 search.
- Add Pinecone vector query.
- Add RRF ranking.
- Add chunk hydration from Elasticsearch.
- Add context assembly and citation numbering.
- Add Hugging Face chat completion client.
- Add grounded prompt and answer generation.

### Phase 3 — API endpoint

- Add `POST /api/query`.
- Add request validation and consistent JSON errors.

### Phase 4 — Frontend

- Replace the starter page with a chat-only interface for client-service employees.
- Add API client and shared TypeScript types.
- Add chat input, message history for the current session, and loading/error states.
- Add answer display with citation chips and expandable source cards.
- No upload controls, document list, or document status UI.
- Read the bundled Next.js 16 documentation before frontend implementation, as required by `frontend/AGENTS.md`.

### Phase 5 — Verification and documentation

- Run Pint and the frontend lint, typecheck, and build.
- Manually ingest the real PDFs in `documents/` with Elasticsearch, Pinecone, and Hugging Face configured.
- Manually test client-service questions end to end.
- Update the root README with setup, ingestion, and run instructions.
- Tests are deferred for now; add them in a later pass if the project needs them.

## 9. Essential vs Optional

### Essential for the MVP

- Elasticsearch `rag_documents` and `rag_chunks` indexes.
- Pinecone vectors with source metadata.
- CLI ingestion of `documents/*.pdf`.
- PDF extraction and chunking.
- Hugging Face embeddings and answer generation.
- BM25 + vector retrieval.
- RRF fusion.
- Chunk text hydration from Elasticsearch.
- Answer citations.
- Chat-only Q&A UI with source cards.
- CORS configuration.
- Non-database session/cache/queue drivers.
- Elasticsearch via Docker.

### Optional or future

- `rag:ingest --fresh` / incremental re-ingestion polish.
- Document deletion and cross-store cleanup for removed files.
- `rag:health` command.
- Async ingestion using Redis, Laravel's deferred/background queue drivers, or another queue.
- Elasticsearch Docker volume and healthcheck.
- Streaming answers.
- Markdown rendering library.
- Retry/backoff logic for external APIs.
- Rate limiting.
- Document filtering in queries.
- Automated tests.
- Full application Dockerization.
- Laravel Boost dev tooling.

### Out of scope

- PDF upload through the UI or API.
- Document list and processing-status UI.
- Authentication, user accounts, and multi-tenancy.
- PostgreSQL, SQLite metadata tables, or Eloquent metadata models.
- OCR for scanned PDFs.
- Conversation/history persistence across sessions.
- Reranking models.
- Evaluation dashboard.

## 10. Configuration

### 10.1 Backend environment variables

```env
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:3000

SESSION_DRIVER=cookie
CACHE_STORE=file
QUEUE_CONNECTION=sync

ELASTICSEARCH_URL=http://localhost:9200
ELASTICSEARCH_DOCUMENTS_INDEX=rag_documents
ELASTICSEARCH_CHUNKS_INDEX=rag_chunks

PINECONE_API_KEY=
PINECONE_INDEX=
PINECONE_HOST=
PINECONE_CLOUD=aws
PINECONE_REGION=us-east-1

HUGGINGFACE_API_KEY=
HUGGINGFACE_BASE_URL=https://router.huggingface.co
HUGGINGFACE_EMBEDDING_MODEL=sentence-transformers/all-MiniLM-L6-v2
HUGGINGFACE_EMBEDDING_DIMENSIONS=384
HUGGINGFACE_EMBEDDING_BATCH_SIZE=32
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

The default Laravel database configuration can remain in place but must not be used by application code. Do not run or add migrations.

### 10.2 Frontend environment variables

```env
NEXT_PUBLIC_API_BASE_URL=http://localhost:8000
```

### 10.3 External services

Elasticsearch:

- Run locally with Docker Compose.
- No authentication for local development.
- Start with:

```sh
docker compose up -d elasticsearch
```

Hugging Face:

- Create an account and access token.
- Ensure the account can use Inference Providers.
- Accept model terms if required.
- Choose an embedding model and an instruct model that the account can access.
- The embedding dimension must be configured correctly; it must match the Pinecone index.

Pinecone:

- Create an account and API key.
- Create an index with:
  - the same dimension as the Hugging Face embedding model;
  - cosine metric;
  - the configured cloud/region.
- Put the index name and API key in `backend/.env`.

### 10.4 Local prerequisites

- PHP 8.3+ with `mbstring`, `curl`, `fileinfo`, `dom`, and `xml` extensions.
- Composer.
- Node.js 20+.
- Docker and Docker Compose.
- At least one client-service PDF in `documents/`.
- No queue worker is required for synchronous ingestion.

## 11. Verification and Quality Gates

Backend:

```sh
vendor/bin/pint --test
php artisan rag:setup
php artisan rag:ingest
```

Frontend:

```sh
npm run lint
npx tsc --noEmit
npm run build
```

Verification rules:

- Automated tests are intentionally deferred for this MVP.
- Do not add tests or `RefreshDatabase` in this pass.
- Use `Http::fake()` later if tests are added for Elasticsearch, Pinecone, and Hugging Face.
- Run one manual end-to-end test with the real documents and credentials.

## 12. Security and Robustness

- Treat PDF text as untrusted input.
- Delimit retrieved context and instruct the model to ignore embedded instructions.
- Do not expose API keys or full document contents in logs.
- Render answer text safely; do not allow raw HTML.
- Keep synchronous ingestion limits conservative to avoid long CLI runs that appear hung.
- Fail loudly during ingestion and report which documents could not be processed.
- Do not commit real client documents, credentials, or `.env` files if they contain sensitive information.

## 13. Recommended Defaults

- Use Laravel CORS configuration rather than a Next.js API proxy.
- Use `smalot/pdfparser` for PDF extraction. Switch to `pdftotext` only if extraction quality is insufficient.
- Use a single `rag:ingest` command with `--fresh` and `--only` flags.
- Use a single `rag:setup` command for Elasticsearch index creation and Pinecone index verification.
- Use synchronous CLI ingestion for the MVP.
- Keep Redis and async queues out of the initial implementation.
- Keep Elasticsearch in Docker; run Laravel and Next.js locally.
- Keep the UI chat-only.

## 14. Implementation Gate

Do not start implementation until the following are ready:

- Client-service PDFs placed in `documents/`.
- Hugging Face account and API token.
- Hugging Face embedding model selected.
- Embedding dimension confirmed.
- Hugging Face instruct model selected and accessible.
- Pinecone account and API key.
- Pinecone index created with the confirmed embedding dimension and cosine metric.
- Pinecone index name and region recorded.
- Elasticsearch running locally.
- Backend `.env` populated with local credentials.

Secrets belong only in local `.env` files. Do not commit them or paste them into chat.

## 15. MVP Definition of Done

- Start Elasticsearch with Docker Compose.
- Place the internal client-service PDFs in `documents/`.
- Run `php artisan rag:ingest`.
- Laravel extracts text, chunks it, embeds it, and indexes it in Elasticsearch and Pinecone.
- Ask a customer question through the chat UI.
- Laravel performs BM25 and vector search, fuses results with RRF, retrieves chunk text from Elasticsearch, and calls Hugging Face.
- The answer is displayed with citations.
- Cited sources show document name, page, and snippet.
- Pint, frontend lint, typecheck, and build pass.
