<?php

namespace App\Console\Commands;

use App\Rag\Clients\ElasticsearchClient;
use App\Rag\Clients\PineconeClient;
use Illuminate\Console\Command;
use Throwable;

class SetupRagCommand extends Command
{
    protected $signature = 'rag:setup';

    protected $description = 'Create the Elasticsearch indexes and verify the Pinecone index';

    public function handle(ElasticsearchClient $elasticsearch, PineconeClient $pinecone): int
    {
        try {
            $elasticsearch->createIndexes();
            $this->info('Elasticsearch indexes are ready.');

            if (! $pinecone->isConfigured()) {
                $this->warn(
                    'Pinecone is not configured. Set PINECONE_API_KEY and PINECONE_INDEX in backend/.env.',
                );

                return self::FAILURE;
            }

            $dimensions = (int) config('rag.huggingface.embedding_dimensions');

            if ($dimensions <= 0) {
                $this->error('HUGGINGFACE_EMBEDDING_DIMENSIONS must be greater than zero.');

                return self::FAILURE;
            }

            $pinecone->ensureIndex($dimensions);
            $this->info("Pinecone index [{$pinecone->indexName()}] is ready ({$dimensions} dimensions).");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
