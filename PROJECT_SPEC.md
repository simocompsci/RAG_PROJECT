I want to build a **RAG Q&A application for client-service employees**. Internal client-service documents (procedures, policies, and playbooks) are placed in the root `documents/` folder and ingested through a CLI command. Employees then ask the questions customers bring them, and the AI retrieves relevant text chunks and generates answers with source citations.

**Tech stack:**
- Frontend: Next.js + TypeScript + Tailwind CSS (chat-only interface)
- Backend: Laravel 13 (REST API and ingestion commands)
- Lexical search: Elasticsearch (BM25)
- Vector search: Pinecone
- AI models: Hugging Face (embeddings + LLM)
- Infrastructure: Docker

The ingestion command extracts text from the PDFs in `documents/`, splits it into chunks, indexes them in Elasticsearch, stores their embeddings in Pinecone, and combines both search results using **hybrid search with Reciprocal Rank Fusion (RRF)** before generating grounded answers with citations.

There is no PDF upload from the UI and no document management screen. No PostgreSQL, authentication, or user accounts. Automated tests are deferred for the MVP.

First, help me set up the project structure and implement it step by step. Keep the architecture clean and avoid unnecessary complexity.
