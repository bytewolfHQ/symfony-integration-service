<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Shopware\Product\Import;

use App\Integration\Infrastructure\Shopware\Product\ShopwareProductImporterInterface;

final class ProductCsvImportRunner
{
    public function __construct(
        private readonly ShopwareProductImporterInterface $importService,
        private readonly ProductDraftValidatorInterface $validator,
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

        $rowNumber = 2; // row 1 is the header, already consumed by the reader
        foreach ($drafts as $draft) {
            $errors = $this->validator->validate($draft, $rowNumber);

            if (count($errors) === 0) {
                try {
                    $action = $this->importService->upsert($draft, $dryRun);

                    match ($action) {
                        'create' => $created++,
                        'update' => $updated++,
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
            'failed' => $failed,
            'results' => $results,
        ];
    }
}
