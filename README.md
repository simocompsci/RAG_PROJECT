# RAG Client-Service Q&A

A RAG Q&A system for client-service employees. Internal procedures, policies, and playbooks are placed in `documents/`, ingested through a Laravel CLI command, and queried from a chat-only Next.js interface. Answers are grounded in the indexed documents and include source citations.

See [PLAN.md](PLAN.md) for the full architecture and implementation plan.

## Stack

- Next.js + TypeScript + Tailwind CSS (chat UI)
- Laravel 13 (ingestion commands + query API)
- Pinecone document index (hosted embeddings + full-text search)
- Hugging Face (answer-generation LLM)

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
PINECONE_INDEX=rag-embeddings
HUGGINGFACE_API_KEY=...
```

The Pinecone index must be a document index with a string field named `chunk_text` that has both integrated embedding and full-text search enabled. The expected model is `llama-text-embed-v2` at 1024 dimensions:

```json
{
  "schema": {
    "fields": {
      "chunk_text": {
        "type": "string",
        "embed": {
          "model": "llama-text-embed-v2",
          "dimension": 1024
        },
        "full_text_search": {
          "language": "en"
        }
      }
    }
  }
}
```

Other Pinecone settings:

```env
PINECONE_HOST=
PINECONE_NAMESPACE=__default__
PINECONE_TEXT_FIELD=chunk_text
PINECONE_EMBEDDING_MODEL=llama-text-embed-v2
PINECONE_EMBEDDING_DIMENSIONS=1024
```

`rag:setup` verifies the existing index or creates it when it does not exist.

The answer model has a working default and can be replaced:

```env
HUGGINGFACE_LLM_MODEL=meta-llama/Llama-3.1-8B-Instruct
```

Frontend (optional, defaults to `http://localhost:8000`):

```sh
cd frontend
cp .env.example .env.local
```

### 3. Add documents and ingest

Put the internal client-service PDFs in `documents/`, then run:

```sh
cd backend
php artisan rag:setup
php artisan rag:ingest
```

Useful ingestion options:

```sh
php artisan rag:ingest --fresh                  # clear the Pinecone namespace and re-ingest
php artisan rag:ingest --force                  # re-ingest unchanged documents
php artisan rag:ingest --only=refund-policy.pdf
```

### 4. Run the app

```sh
cd backend && php artisan serve
cd frontend && npm run dev
```

Open `http://localhost:3000` and ask a customer question.

## How retrieval works

- Ingestion chunks each PDF and writes documents to Pinecone with `chunk_text`, document/page metadata, and a content hash.
- Pinecone embeds `chunk_text` automatically using the index's hosted model.
- Queries run two Pinecone searches: dense (`embed`) and lexical (`text`), then fuse the ranked lists with Reciprocal Rank Fusion.
- The top chunks are passed to Hugging Face for answer generation with numbered citations.

## Notes

- There is no PDF upload or document management UI. The `documents/` folder is the source of truth.
- Pinecone deletes and newly upserted documents can take a short time to become fully consistent.
- Automated tests are intentionally deferred for this MVP.
- Quality checks:

```sh
cd backend && vendor/bin/pint --test
cd frontend && npm run lint && npx tsc --noEmit && npm run build
```
