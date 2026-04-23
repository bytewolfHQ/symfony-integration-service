<?php

declare(strict_types = 1);

namespace App\Integration\Infrastructure\Shopware\Product\Import;

use App\Integration\Application\Port\ProductReaderInterface;
use App\Integration\Domain\ProductDraft;

final class ProductCsvReader implements ProductCsvReaderInterface, ProductReaderInterface
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
            throw new \InvalidArgumentException(sprintf('CSV file not found: %s', $file));
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
                $header = array_map(static fn ($v) => is_string($v) ? trim($v) : '', $row);
                continue;
            }

            $assoc = $this->combine($header, $row);

            $productNumber = trim((string) ($assoc['productNumber'] ?? ''));
            $name = trim((string) ($assoc['name'] ?? ''));
            $manufacturer = trim((string) ($assoc['manufacturer'] ?? ''));
            $description = trim((string) ($assoc['description'] ?? ''));

            if ($productNumber === '' || $name === '') {
                continue;
            }

            $stock = $this->parseInt($assoc['stock'] ?? null);
            $gross = $this->parseFloat($assoc['gross'] ?? null);
            $net = $this->parseFloat($assoc['net'] ?? null);
            $taxRate = $this->parseFloat($assoc['taxRate'] ?? null);
            $currency = $this->parseString($assoc['currency'] ?? null);
            $active = $this->parseBool($assoc['active'] ?? null);

            $drafts[] = new ProductDraft(
                $productNumber,
                $name,
                $manufacturer,
                $description,
                $stock,
                $gross,
                $net,
                $taxRate,
                $currency,
                $active,
            );

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

    private function parseString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function parseInt(mixed $value): ?int
    {
        $value = $this->parseString($value);

        if ($value === null) {
            return null;
        }

        if (!preg_match('/^-?\d+$/', $value)) {
            return null;
        }

        return (int) $value;
    }

    private function parseFloat(mixed $value): ?float
    {
        $value = $this->parseString($value);

        if ($value === null) {
            return null;
        }

        $normalized = str_replace(',', '.', $value);

        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function parseBool(mixed $value): ?bool
    {
        $value = $this->parseString($value);

        if ($value === null) {
            return null;
        }

        $normalized = strtolower($value);

        return match ($normalized) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
    }
}