# RAG Client-Service Q&A

A RAG Q&A system for client-service employees. Internal procedures, policies, and playbooks are placed in `documents/`, ingested through a Laravel CLI command, and queried from a chat-only Next.js interface. Answers are grounded in the indexed documents and include source citations.

See [PLAN.md](PLAN.md) for the full architecture and implementation plan.

## Stack

- Next.js + TypeScript + Tailwind CSS (chat UI)
- Laravel 13 (ingestion commands + query API)
- Elasticsearch (BM25)
- Pinecone (vector search)
- Hugging Face (embeddings + LLM)
- Docker (Elasticsearch)

## Setup

### 1. Install dependencies

```sh
cd backend && composer install
cd ../frontend && npm install
```

### 2. Configure environment

Backend:

```sh
cd backend
cp .env.example .env
php artisan key:generate
```

Set these values in `backend/.env`:

```env
PINECONE_API_KEY=...
PINECONE_INDEX=...
HUGGINGFACE_API_KEY=...
```

The model settings have working defaults and can be replaced:

```env
HUGGINGFACE_EMBEDDING_MODEL=sentence-transformers/all-MiniLM-L6-v2
HUGGINGFACE_EMBEDDING_DIMENSIONS=384
HUGGINGFACE_LLM_MODEL=meta-llama/Llama-3.1-8B-Instruct
```

If you change the embedding model, update `HUGGINGFACE_EMBEDDING_DIMENSIONS` and make the Pinecone index dimension match. `rag:setup` creates the Pinecone index if it does not exist.

Frontend (optional, defaults to `http://localhost:8000`):

```sh
cd frontend
cp .env.example .env.local
```

### 3. Start Elasticsearch

```sh
docker compose up -d elasticsearch
```

### 4. Add documents and ingest

Put the internal client-service PDFs in `documents/`, then run:

```sh
cd backend
php artisan rag:setup
php artisan rag:ingest
```

Useful ingestion options:

```sh
php artisan rag:ingest --fresh                  # clear both indexes and re-ingest
php artisan rag:ingest --force                  # re-ingest unchanged documents
php artisan rag:ingest --only=refund-policy.pdf
```

### 5. Run the app

```sh
cd backend && php artisan serve
cd frontend && npm run dev
```

Open `http://localhost:3000` and ask a customer question.

## Notes

- There is no PDF upload or document management UI. The `documents/` folder is the source of truth.
- Automated tests are intentionally deferred for this MVP.
- Quality checks:

```sh
cd backend && vendor/bin/pint --test
cd frontend && npm run lint && npx tsc --noEmit && npm run build
```
