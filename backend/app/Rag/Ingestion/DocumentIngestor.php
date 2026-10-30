<?php

namespace App\Rag\Ingestion;

use App\Rag\Clients\ElasticsearchClient;
use App\Rag\Clients\HuggingFaceClient;
use App\Rag\Clients\PineconeClient;
use RuntimeException;

class DocumentIngestor
{
    public function __construct(
        private readonly ElasticsearchClient $elasticsearch,
        private readonly PineconeClient $pinecone,
        private readonly HuggingFaceClient $huggingFace,
        private readonly PdfExtractor $pdfExtractor,
        private readonly TextChunker $chunker,
    ) {}

    /**
     * @return array{status: string, document_id: string, page_count: int, chunk_count: int}
     */
    public function ingest(string $absolutePath, string $relativePath, bool $force = false): array
    {
        $contentHash = hash_file('sha256', $absolutePath);

        if ($contentHash === false) {
            throw new RuntimeException('Unable to read the document contents.');
        }

        $documentId = substr(hash('sha256', $relativePath), 0, 32);
        $existing = $this->elasticsearch->getDocument($documentId);

        if (! $force && $existing !== null && ($existing['content_hash'] ?? null) === $contentHash) {
            return [
                'status' => 'skipped',
                'document_id' => $documentId,
                'page_count' => (int) ($existing['page_count'] ?? 0),
                'chunk_count' => (int) ($existing['chunk_count'] ?? 0),
            ];
        }

        $pages = $this->pdfExtractor->extract($absolutePath);
        $chunks = $this->chunker->chunkPages(
            $pages,
            (int) config('rag.chunk_size'),
            (int) config('rag.chunk_overlap'),
        );

        if ($chunks === []) {
            throw new RuntimeException('The document did not produce any text chunks.');
        }

        $embeddings = $this->huggingFace->embed(array_column($chunks, 'content'));

        if (count($embeddings) !== count($chunks)) {
            throw new RuntimeException('Embedding count does not match the chunk count.');
        }

        $documentName = basename($relativePath);
        $now = now()->toIso8601String();
        $createdAt = $existing['created_at'] ?? $now;

        $chunkDocuments = [];
        $vectors = [];

        foreach ($chunks as $index => $chunk) {
            $chunkId = "{$documentId}:{$index}";

            $chunkSource = [
                'chunk_id' => $chunkId,
                'document_id' => $documentId,
                'document_name' => $documentName,
                'source_path' => $relativePath,
                'page' => $chunk['page'],
                'chunk_index' => $index,
                'content' => $chunk['content'],
                'created_at' => $now,
            ];

            $chunkDocuments[] = ['_id' => $chunkId, 'source' => $chunkSource];

            $vectors[] = [
                'id' => $chunkId,
                'values' => $embeddings[$index],
                'metadata' => [
                    'document_id' => $documentId,
                    'document_name' => $documentName,
                    'page' => $chunk['page'],
                    'chunk_index' => $index,
                ],
            ];
        }

        $this->elasticsearch->deleteChunksForDocument($documentId);
        $this->pinecone->deleteForDocument($documentId);

        $this->elasticsearch->bulkIndexChunks($chunkDocuments);
        $this->pinecone->upsert($vectors);

        $this->elasticsearch->indexDocument($documentId, [
            'document_id' => $documentId,
            'document_name' => $documentName,
            'source_path' => $relativePath,
            'content_hash' => $contentHash,
            'page_count' => count($pages),
            'chunk_count' => count($chunks),
            'created_at' => $createdAt,
            'updated_at' => $now,
        ]);

        return [
            'status' => 'indexed',
            'document_id' => $documentId,
            'page_count' => count($pages),
            'chunk_count' => count($chunks),
        ];
    }
}
