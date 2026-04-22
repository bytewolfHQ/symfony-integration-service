<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product\Import;

use App\Integration\Application\Port\ProductImporterInterface;
use App\Integration\Domain\ImportResult;
use App\Integration\Domain\ProductDraft;
use App\Integration\Infrastructure\Shopware\Product\ShopwareProductImportInterface;

final class ProductCsvImportRunner implements ProductCsvImportRunnerInterface, ProductImporterInterface
{
    public function __construct(
        private readonly ShopwareProductImportInterface $importService,
        private readonly ProductDraftValidatorInterface $validator,
    ) {}

    /**
     * Implements ProductImporterInterface — returns typed ImportResult.
     *
     * @param list<ProductDraft> $drafts
     */
    public function import(array $drafts, bool $dryRun = false): ImportResult
    {
        $raw = $this->importDrafts($drafts, $dryRun);

        return new ImportResult(
            total:   $raw['total'],
            created: $raw['created'],
            updated: $raw['updated'],
            skipped: $raw['skipped'],
            failed:  $raw['failed'],
            results: $raw['results'],
            dryRun:  $dryRun,
        );
    }

    /**
     * @param list<ProductDraft> $drafts
     * @return array{
     *   total:int,
     *   created:int,
     *   updated:int,
     *   skipped:int,
     *   failed:int,
     *   results:list<array{productNumber:string, name:string, action:string}>
     * }
     */
    public function importDrafts(array $drafts, bool $dryRun = false): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $results = [];

        // Note: row numbers reported in ValidationError start at 2 (row 1 = header).
        // Rows skipped by ProductCsvReader (empty productNumber/name) are not counted here,
        // so reported row numbers may not match the actual CSV line numbers for such files.
        $rowNumber = 2;
        foreach ($drafts as $draft) {
            $errors = $this->validator->validate($draft, $rowNumber);

            if (count($errors) === 0) {
                try {
                    $action = $this->importService->upsert($draft, $dryRun);

                    match ($action) {
                        'create' => $created++,
                        'update' => $updated++,
                        'skipped' => $skipped++,
                        default => null,
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
            } else {
                $failed++;
                foreach ($errors as $error) {
                    $results[] = [
                        'productNumber' => $draft->productNumber,
                        'name'          => $draft->name,
                        'action'        => 'validation: ' . $error->field . ' – ' . $error->reason,
                    ];
                }
            }

            $rowNumber++;
        }

        return [
            'total' => count($drafts),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'results' => $results,
        ];
    }
}
