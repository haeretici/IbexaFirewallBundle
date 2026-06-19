<?php

namespace Haeretici\FirewallBundle\Lib;

use Symfony\Bridge\Doctrine\ManagerRegistry;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;

class ConfigService
{
    private array $defaults;
    private array $dbConfig = [];
    private array $mergedConfig;
    private ManagerRegistry $doctrine;

    public function __construct(ManagerRegistry $doctrine, RedisTagAwareAdapter $cache, array $defaults)
    {
        $this->doctrine = $doctrine;
        $this->defaults = $defaults;
        $this->cache = $cache;
        $this->bucketKey = 'HaereticiFirewallConfig';
        $bucketItem = $this->cache->getItem($this->bucketKey);
        if ($bucketItem->isHit()) {
            $this->mergedConfig = json_decode($bucketItem->get(), true);
        }
        else
        {
            $connection = $this->doctrine->getConnection();
            $row = $connection->fetchAssociative('SELECT config FROM firewall_config WHERE id = 1');
            if ($row) {
                $this->dbConfig = json_decode($row['config'], true) ?: [];
            }
            $this->mergedConfig = array_replace_recursive($this->defaults, $this->dbConfig);
            $bucketItem->set(json_encode($this->mergedConfig));
            $this->cache->save($bucketItem);
        }
    }

    public function getConfig(): array
    {
        return $this->mergedConfig;
    }

    public function updateConfig(array $config): void
    {
        $connection = $this->doctrine->getConnection();
        $json = json_encode($config);
        $connection->executeStatement(
            'INSERT INTO firewall_config (id, config) VALUES (1, ?) ON DUPLICATE KEY UPDATE config = VALUES(config)',
            [$json]
        );
        $this->dbConfig = $config;
        $this->mergedConfig = array_replace_recursive($this->defaults, $config);
        $bucketItem = $this->cache->getItem($this->bucketKey);
        $bucketItem->set(json_encode($this->mergedConfig));
        $this->cache->save($bucketItem);
    }
}
