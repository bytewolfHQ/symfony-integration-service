<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * Loads and validates the "integration:" configuration.
 * Exposes it as container parameters for now (simple + generic).
 *
 * Later we can map this into dedicated Settings classes.
 */
final class IntegrationExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Expose as parameters (simple to inject)
        $container->setParameter('integration.defaults', $config['defaults']);
        $container->setParameter('integration.adapters', $config['adapters']);
        $container->setParameter('integration.adapters.shopware', $config['adapters']['shopware'] ?? []);
    }
}
