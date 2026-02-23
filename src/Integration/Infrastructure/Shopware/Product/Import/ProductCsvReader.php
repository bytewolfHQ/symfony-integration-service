<?php

declare(strict_types = 1);

namespace App\Integration\Infrastructure\Shopware\Product\Import;

use App\Integration\Infrastructure\Shopware\Product\ProductDraft;

final class ProductCsvReader
{
    /**
     * @param string $file
     * @param string $delimiter
     * @param int|null $limit
     * @return list<ProductDraft>
     */
    public function read(string $file, string $delimiter = ',', ?int $limit = null): array
    {
        if (!is_file($file)) {
            throw new \InvalidArgumentException(sprintf('CSF file not found: %s', $file));
        }

        $fh = new \SplFileObject($file, 'r');
        $fh->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $fh->setCsvControl($delimiter);

        $header = null;
        $drafts = [];

        foreach ($fh as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Normalize: SplFileObject may return [null] at EOF
            if (count($row) === 1 && ($row[0] === null || $row[0] === '')) {
                continue;
            }

            if ($header === null) {
                $header = array_map(static fn($v) => is_string($v) ? trim($v) : '', $row);
                continue;
            }
            
            $assoc = $this->combine($header, $row);

            $productNumber = trim($assoc['productNumber'] ?? '');
            $name = trim((string)$assoc['name'] ?? '');

            if ($productNumber === '' || $name === '') {
                continue;
            }

            $drafts[] = new ProductDraft($productNumber, $name);

            if ($limit !== null && count($drafts) >= $limit) {
                break;
            }
        }

        return $drafts;
    }

    /**
     * @param list<string> $header
     * @param array<int, mixed> $row
     * @return array<string, mixed>
     */
    private function combine(array $header, array $row): array
    {
        $assoc = [];
        foreach ($header as $i => $key) {
            if ($key === '') {
                continue;
            }
            $assoc[$key] = $row[$i] ?? null;
        }
        return $assoc;
    }
}