<?php

namespace App\Rag\Ingestion;

class TextChunker
{
    /**
     * Split page text into overlapping chunks while keeping page references.
     *
     * @param  array<int, array{page: int, text: string}>  $pages
     * @return array<int, array{page: int, content: string}>
     */
    public function chunkPages(array $pages, int $chunkSize, int $overlap): array
    {
        $chunkSize = max(200, $chunkSize);
        $overlap = max(0, min($overlap, $chunkSize - 1));

        $chunks = [];

        foreach ($pages as $page) {
            $text = $this->normalizeText($page['text']);
            $length = mb_strlen($text);
            $offset = 0;

            while ($offset < $length) {
                $end = min($offset + $chunkSize, $length);

                if ($end < $length) {
                    $end = $this->findBetterBreak($text, $offset, $end, $chunkSize);
                }

                $content = trim(mb_substr($text, $offset, $end - $offset));

                if ($content !== '') {
                    $chunks[] = [
                        'page' => $page['page'],
                        'content' => $content,
                    ];
                }

                if ($end >= $length) {
                    break;
                }

                $nextOffset = $end - $overlap;
                $offset = $nextOffset > $offset ? $nextOffset : $end;
            }
        }

        return $chunks;
    }

    private function findBetterBreak(string $text, int $offset, int $end, int $chunkSize): int
    {
        $window = mb_substr($text, $offset, $end - $offset);
        $minimumBreak = (int) ($chunkSize * 0.6);
        $bestPosition = null;

        foreach (["\n", '. ', '! ', '? ', '; ', ', ', ' '] as $delimiter) {
            $position = mb_strrpos($window, $delimiter);

            if ($position === false || $position < $minimumBreak) {
                continue;
            }

            $position += mb_strlen($delimiter);

            if ($bestPosition === null || $position > $bestPosition) {
                $bestPosition = $position;
            }
        }

        return $bestPosition === null ? $end : $offset + $bestPosition;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
