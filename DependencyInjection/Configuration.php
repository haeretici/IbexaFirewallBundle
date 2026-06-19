<?php

namespace Haeretici\FirewallBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/configuration.html}
 */
class Configuration implements ConfigurationInterface {

    public function getConfigTreeBuilder() {
        $treeBuilder = new TreeBuilder('haeretici_firewall');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('rate_limiting')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('window')->defaultValue(121)->end()
                        ->integerNode('max_requests')->defaultValue(30)->end()
                        ->integerNode('ban_duration')->defaultValue(3600)->end()
                        ->floatNode('min_response_time')->defaultValue(0.1)->end()
                    ->end()
                ->end()
                ->arrayNode('challenge')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('ttl')->defaultValue(300)->end()
                        ->integerNode('verified_ttl')->defaultValue(1800)->end()
                        ->integerNode('secret_length')->defaultValue(16)->end()
                        ->floatNode('dummy_ratio')->defaultValue(0.2)->end()
                        ->scalarNode('dummy_char')->defaultValue('!')->end()
                        ->booleanNode('enabled_for_non_bots')->defaultValue(false)->end()
                    ->end()
                ->end()
                ->arrayNode('bots')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('google_enabled')->defaultValue(true)->end()
                        ->booleanNode('twitter_enabled')->defaultValue(true)->end()
                        ->booleanNode('facebook_enabled')->defaultValue(true)->end()
                        ->booleanNode('bing_enabled')->defaultValue(true)->end()
                        ->booleanNode('linkedin_enabled')->defaultValue(true)->end()
                    ->end()
                ->end()
                ->arrayNode('exemptions')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('paths')
                            ->prototype('scalar')->end()
                            ->defaultValue(['/_fragment*']) // Most assets are directly accessed by apache (build, var, assets)
                        ->end()
                    ->end()
                ->end()
                // Honeypot / Trap Configuration
                ->arrayNode('honeypot')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultValue(true)->end()
                        ->integerNode('ban_duration')->defaultValue(86400)->end() // Default: 24h for clear intent
                        ->arrayNode('paths')
                            ->prototype('scalar')->end()
                            ->defaultValue([
                                '*/wp-admin*',      // Common WordPress scanner traps
                                '*/wp-login.php',
                                '*/.env',           // Secret leak scanners
                                '*/.git/*',
                                '*/config.json',
                                '*/phpinfo.php',
                                '*/xmlrpc.php'
                            ])
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('enable_rate_limiting')->defaultValue(true)->end()
            ->end()
        ;

        return $treeBuilder;
    }
}