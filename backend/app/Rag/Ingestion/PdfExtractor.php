<?php

namespace App\Rag\Ingestion;

use RuntimeException;
use Smalot\PdfParser\Parser;

class PdfExtractor
{
    /**
     * @return array<int, array{page: int, text: string}>
     */
    public function extract(string $path): array
    {
        $document = (new Parser)->parseFile($path);
        $pages = [];

        foreach ($document->getPages() as $index => $page) {
            $text = trim($page->getText());

            if ($text === '') {
                continue;
            }

            $pages[] = [
                'page' => $index + 1,
                'text' => $text,
            ];
        }

        if ($pages === []) {
            throw new RuntimeException(
                'No extractable text was found. The PDF may be scanned or image-based.',
            );
        }

        return $pages;
    }
}
