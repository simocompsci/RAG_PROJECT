I want to build a **RAG Q&A application for client-service employees**. Internal client-service documents (procedures, policies, and playbooks) are placed in the root `documents/` folder and ingested through a CLI command. Employees then ask the questions customers bring them, and the AI retrieves relevant text chunks and generates answers with source citations.

**Tech stack:**
- Frontend: Next.js + TypeScript + Tailwind CSS (chat-only interface)
- Backend: Laravel 13 (REST API and ingestion commands)
- Retrieval and storage: Pinecone document index
- Embeddings: Pinecone hosted embeddings (`llama-text-embed-v2`, 1024 dimensions)
- Lexical search: Pinecone full-text search (BM25)
- AI answer generation: Hugging Face LLM

The ingestion command extracts text from the PDFs in `documents/`, splits it into page-aware chunks, and writes them to Pinecone. Pinecone embeds each chunk automatically and indexes the same text field for BM25. A query runs a dense search and a lexical search, combines both ranked lists with **Reciprocal Rank Fusion (RRF)**, and generates a grounded answer with citations.

There is no PDF upload from the UI and no document management screen. No PostgreSQL, authentication, or user accounts. Automated tests are deferred for the MVP.

Keep the architecture clean and avoid unnecessary complexity.
