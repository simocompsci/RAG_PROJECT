<?php

namespace App\Rag\Ingestion;

use App\Rag\Clients\PineconeClient;
use RuntimeException;

class DocumentIngestor
{
    private string $textField;

    public function __construct(
        private readonly PineconeClient $pinecone,
        private readonly PdfExtractor $pdfExtractor,
        private readonly TextChunker $chunker,
    ) {
        $this->textField = (string) config('rag.pinecone.text_field');
    }

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
        $existing = $this->pinecone->firstChunkForDocument($documentId);

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

        $documentName = basename($relativePath);
        $now = now()->toIso8601String();
        $createdAt = (string) ($existing['created_at'] ?? $now);
        $pageCount = count($pages);
        $chunkCount = count($chunks);

        $documents = [];

        foreach ($chunks as $index => $chunk) {
            $documents[] = [
                '_id' => "{$documentId}:{$index}",
                $this->textField => $chunk['content'],
                'document_id' => $documentId,
                'document_name' => $documentName,
                'source_path' => $relativePath,
                'page' => $chunk['page'],
                'chunk_index' => $index,
                'content_hash' => $contentHash,
                'page_count' => $pageCount,
                'chunk_count' => $chunkCount,
                'created_at' => $createdAt,
                'updated_at' => $now,
            ];
        }

        $this->pinecone->deleteForDocument($documentId);
        $this->pinecone->upsertDocuments($documents);

        return [
            'status' => 'indexed',
            'document_id' => $documentId,
            'page_count' => $pageCount,
            'chunk_count' => $chunkCount,
        ];
    }
}
