<?php

namespace App\Console\Commands;

use App\Rag\Clients\ElasticsearchClient;
use App\Rag\Clients\PineconeClient;
use App\Rag\Ingestion\DocumentIngestor;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

class IngestDocumentsCommand extends Command
{
    protected $signature = 'rag:ingest
        {--fresh : Clear all indexed data before ingesting}
        {--force : Re-ingest documents even when their contents are unchanged}
        {--only= : Comma-separated list of filenames to ingest}';

    protected $description = 'Chunk, embed, and index the PDFs in the documents folder';

    public function handle(
        ElasticsearchClient $elasticsearch,
        PineconeClient $pinecone,
        DocumentIngestor $ingestor,
    ): int {
        set_time_limit(0);

        $documentsPath = $this->resolveDocumentsPath();

        if (! is_dir($documentsPath)) {
            $this->error("Documents folder not found: {$documentsPath}");

            return self::FAILURE;
        }

        $only = $this->onlyOption();
        $files = $this->findPdfFiles($documentsPath, $only);

        if ($files === []) {
            $this->warn('No PDF files were found to ingest.');

            return self::SUCCESS;
        }

        try {
            $this->prepareIndexes($elasticsearch, $pinecone);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $indexed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($files as $file) {
            try {
                $result = $ingestor->ingest(
                    $file['absolute'],
                    $file['relative'],
                    (bool) $this->option('force'),
                );

                $indexed += $result['status'] === 'indexed' ? 1 : 0;
                $skipped += $result['status'] === 'skipped' ? 1 : 0;

                $this->line(sprintf(
                    '  %-8s %s (%d pages, %d chunks)',
                    strtoupper($result['status']),
                    $file['relative'],
                    $result['page_count'],
                    $result['chunk_count'],
                ));
            } catch (Throwable $exception) {
                $failed++;
                $this->error("  FAILED   {$file['relative']}: {$exception->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Done. {$indexed} indexed, {$skipped} skipped, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function prepareIndexes(
        ElasticsearchClient $elasticsearch,
        PineconeClient $pinecone,
    ): void {
        $dimensions = (int) config('rag.huggingface.embedding_dimensions');

        if ($dimensions <= 0) {
            throw new RuntimeException('HUGGINGFACE_EMBEDDING_DIMENSIONS must be greater than zero.');
        }

        $elasticsearch->createIndexes();
        $pinecone->ensureIndex($dimensions);

        if (! $this->option('fresh')) {
            return;
        }

        $this->line('Clearing existing indexes...');
        $elasticsearch->deleteIndexes();
        $elasticsearch->createIndexes();
        $pinecone->deleteAll();
    }

    private function resolveDocumentsPath(): string
    {
        $path = (string) config('rag.documents_path');

        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }

        return realpath($path) ?: $path;
    }

    /**
     * @param  array<int, string>  $only
     * @return array<int, array{absolute: string, relative: string}>
     */
    private function findPdfFiles(string $documentsPath, array $only): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($documentsPath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'pdf') {
                continue;
            }

            $relativePath = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($file->getPathname(), strlen(rtrim($documentsPath, DIRECTORY_SEPARATOR)) + 1),
            );

            if ($only !== [] && ! in_array($file->getFilename(), $only, true) && ! in_array($relativePath, $only, true)) {
                continue;
            }

            $files[] = [
                'absolute' => $file->getPathname(),
                'relative' => $relativePath,
            ];
        }

        usort($files, fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));

        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function onlyOption(): array
    {
        $only = (string) $this->option('only');

        if (trim($only) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $only))));
    }
}
