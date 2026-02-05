<?php

declare(strict_types=1);

namespace App\Integration\Infrastructure\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Defines the schema for config/packages/integration.yaml (root key: "integration").
 *
 * Pattern: Configuration Schema (Symfony Config Component)
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('integration');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()

                ->arrayNode('defaults')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('currency')->defaultValue('EUR')->end()
                        ->scalarNode('language')->defaultValue('de-DE')->end()
                    ->end()
                ->end()

                ->arrayNode('adapters')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('shopware')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('base_url')->isRequired()->cannotBeEmpty()->end()
                                ->scalarNode('client_id')->isRequired()->cannotBeEmpty()->end()
                                ->scalarNode('client_secret')->isRequired()->cannotBeEmpty()->end()
                            ->end()
                        ->end()

                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
