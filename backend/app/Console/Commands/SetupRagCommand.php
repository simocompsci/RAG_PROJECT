<?php

namespace App\Console\Commands;

use App\Rag\Clients\PineconeClient;
use Illuminate\Console\Command;
use Throwable;

class SetupRagCommand extends Command
{
    protected $signature = 'rag:setup';

    protected $description = 'Verify or create the Pinecone document index';

    public function handle(PineconeClient $pinecone): int
    {
        try {
            if (! $pinecone->isConfigured()) {
                $this->error(
                    'Pinecone is not configured. Set PINECONE_API_KEY and PINECONE_INDEX in backend/.env.',
                );

                return self::FAILURE;
            }

            $pinecone->ensureIndex();
            $this->info(
                "Pinecone index [{$pinecone->indexName()}] is ready "
                ."({$pinecone->textField()}, {$pinecone->embeddingModel()}, "
                ."{$pinecone->embeddingDimensions()} dimensions, "
                ."namespace [{$pinecone->namespace()}]).",
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
