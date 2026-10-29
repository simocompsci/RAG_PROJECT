I want to build a **RAG Document Q&A application** where users upload PDFs and ask questions about their contents. The AI retrieves relevant text chunks and generates answers with source citations.

**Tech stack:**
- Frontend: Next.js + TypeScript + Tailwind CSS
- Backend: Laravel 13 (REST API)
- Lexical search: Elasticsearch (BM25)
- Vector search: Pinecone
- AI models: Hugging Face (embeddings + LLM)
- Infrastructure: Docker

The application will extract text from uploaded PDFs, split it into chunks, index them in Elasticsearch, store their embeddings in Pinecone, and combine both search results using **hybrid search with Reciprocal Rank Fusion (RRF)** before generating grounded answers with citations.

No PostgreSQL, authentication, or user accounts.

First, help me set up the project structure and implement it step by step. Keep the architecture clean and avoid unnecessary complexity. Start by proposing the initial setup before writing code.