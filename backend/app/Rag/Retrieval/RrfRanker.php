<?php

namespace App\Rag\Retrieval;

class RrfRanker
{
    /**
     * Fuse ranked chunk ID lists using Reciprocal Rank Fusion.
     *
     * @param  array<int, array<int, string>>  $rankings
     * @return array<int, array{chunk_id: string, rrf_score: float}>
     */
    public function fuse(array $rankings, int $k = 60): array
    {
        $k = max(1, $k);
        $scores = [];

        foreach ($rankings as $ranking) {
            $rank = 1;

            foreach (array_unique($ranking) as $chunkId) {
                $scores[$chunkId] = ($scores[$chunkId] ?? 0.0) + (1 / ($k + $rank));
                $rank++;
            }
        }

        arsort($scores);

        $fused = [];

        foreach ($scores as $chunkId => $score) {
            $fused[] = [
                'chunk_id' => (string) $chunkId,
                'rrf_score' => $score,
            ];
        }

        return $fused;
    }
}
