<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Symfony;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Minimal bundle to register a custom config root: "integration:"
 *
 * Pattern: Symfony Bundle + Extension (Configuration/DI).
 */
final class IntegrationBundle extends Bundle
{
}