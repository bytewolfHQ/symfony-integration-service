<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product\Import;

use App\Integration\Infrastructure\Shopware\Product\ShopwareProductImporterInterface;

final class ProductCsvImportRunner
{
    public function __construct(
        private readonly ShopwareProductImporterInterface $importService,
    ) {}

    /**
     * @param list<\App\Integration\Infrastructure\Shopware\Product\ProductDraft> $drafts
     * @param bool $dryRun
     * @return array{
     *   total:int,
     *   created:int,
     *   updated:int,
     *   failed:int,
     *   results:list<array{productNumber:string, name:string, action:string}>
     * }
     */
    public function importDrafts(array $drafts, bool $dryRun = false): array
    {
        $created = 0;
        $updated = 0;
        $failed = 0;
        $results = [];

        foreach ($drafts as $draft) {
            try {
                $action = $this->importService->upsert($draft, $dryRun);

                match ($action) {
                    'create' => $created++,
                    'update' => $updated++,
                    default  => null,
                };

                $results[] = [
                    'productNumber' => $draft->productNumber,
                    'name' => $draft->name,
                    'action' => $action,
                ];
            } catch (\Throwable $e) {
                $failed++;
                $results[] = [
                    'productNumber' => $draft->productNumber,
                    'name' => $draft->name,
                    'action' => 'error: ' . $e->getMessage(),
                ];
            }
        }

        return [
            'total' => count($drafts),
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'results' => $results,
        ];
    }
}
