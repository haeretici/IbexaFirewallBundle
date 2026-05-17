<?php

namespace Ne0Heretic\FirewallBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Yaml\Yaml;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * @link http://symfony.com/doc/current/cookbook/bundles/extension.html
 */
class Ne0HereticFirewallExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $environment = $container->getParameter('kernel.environment');
        $fileLocator = new FileLocator(__DIR__ . '/../Resources/config');
        $loader = new Loader\YamlFileLoader($container, $fileLocator);
        $standardConfigFileTypes = array(
            'app',
        );
        foreach ($standardConfigFileTypes as $type) {
            $found = true;
            // load env file
            try {
                $loader->load($type . '_' . $environment . '.yml');
            } catch (\InvalidArgumentException $e) {
                $found = false;
                // file missing on filesystem - it's expected
            }
            // fallback to non-env file
            if (!$found) {
                try {
                    $loader->load($type . '.yml');
                } catch (\InvalidArgumentException $e) {
                    // file missing on filesystem - it's expected
                }
            }
        }
        /*
         *  Load settings related to the current bundle
         */
        $bundleConfigFiles = array(
            'settings.yaml.TODO',
            "settings_{$environment}.yaml",
        );
        $configurations = [];
        foreach ($bundleConfigFiles as $file) {
            try {
                $path = $fileLocator->locate($file);
                if(file_exists($path))
                {
                    $configurations = @array_replace_recursive($configurations, Yaml::parse(file_get_contents($path)));
                }
            } catch (\InvalidArgumentException $e) {
                // file missing on filesystem - it's expected
            }
        }
        if (!empty($configurations['parameters'])) {
            foreach ($configurations['parameters'] as $namespace => $namespaces) {
                if (!empty($namespaces) && \is_array($namespaces)) {
                    foreach ($namespaces as $scope => $vars) {
                        if (!empty($vars) && \is_array($vars)) {
                            foreach ($vars as $var => $value) {
                                if (!$container->hasParameter("{$namespace}.{$scope}.{$var}")) {
                                    $container->setParameter("{$namespace}.{$scope}.{$var}", $value);
                                }
                                if (isset($value['links'])) {
                                    $finalLinkList = [];
                                    if (isset($container->getParameter("{$namespace}.{$scope}.{$var}")['links'])) {
                                        $finalLinkList = $container->getParameter("{$namespace}.{$scope}.{$var}")['links'];
                                    }
                                    foreach ($value['links'] as $valueLink) {
                                        $found = false;
                                        foreach ($finalLinkList as $finalLink) {
                                            if (json_encode($finalLink) == json_encode($valueLink)) {
                                                $found = true;
                                                break;
                                            }
                                        }
                                        if (!$found) {
                                            $finalLinkList[] = $valueLink;
                                        }
                                    }
                                    $finalSettings = $container->getParameter("{$namespace}.{$scope}.{$var}");
                                    $finalSettings['links'] = $finalLinkList;
                                    $container->setParameter("{$namespace}.{$scope}.{$var}", $finalSettings);
                                }
                            }
                        }
                    }
                }
            }
        }
         // Process bundle configuration
        $configuration = new Configuration();
        $processedConfig = $this->processConfiguration($configuration, $configs);
        $container->setParameter('ne0heretic_firewall.defaults', $processedConfig);
    }

    /**
     * Extending the 'ibexa' configuration section.
     */
    public function prepend(ContainerBuilder $container)
    {
        // more specific configuration before more generic config
        $standardConfigFileTypes = array(
            'ibexa',
        );
        foreach ($standardConfigFileTypes as $file) {
            $configFile = __DIR__ . '/../Resources/config/' . $file . '.yaml';
            if (file_exists($configFile)) {
                $config = Yaml::parse(file_get_contents($configFile));
                if (!empty($config) && isset($config['ibexa'])) {
                    $container->prependExtensionConfig('ibexa', $config['ibexa']);
                    $container->addResource(new FileResource($configFile));
                } else {
                    // report unexpected format
                }
            }
        }
    }
}
