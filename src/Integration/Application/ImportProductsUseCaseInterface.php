<?php

declare(strict_types=1);

namespace App\Integration\Application;

use App\Integration\Domain\ImportResult;

interface ImportProductsUseCaseInterface
{
    public function execute(
        string $source,
        string $delimiter = ',',
        ?int $limit = null,
        bool $dryRun = false,
    ): ImportResult;
}