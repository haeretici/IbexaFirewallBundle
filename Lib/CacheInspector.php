<?php

namespace Ne0Heretic\FirewallBundle\Lib;

use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use ReflectionClass;
use ReflectionProperty;

class CacheInspector
{
    private static $redis = null;
    public function __construct(private RedisTagAwareAdapter $cacheAdapter)
    {
    }

    public function getRedisAndNamespace() {
        if(self::$redis) {
           return self::$redis;
        }
        $reflection = new ReflectionClass($this->cacheAdapter);
        $redisProperty = $reflection->getProperty('redis');
        $redisProperty->setAccessible(true);
        $redis = $redisProperty->getValue($this->cacheAdapter);

        // Full prefix: adapter namespace + your prefix
        $namespace = $reflection->getProperty('namespace')->getValue($this->cacheAdapter);  // Or hardcode if known
        if($namespace) {
            $namespace .= ':';
        }
        self::$redis = ['redis' => $redis, 'namespace' => $namespace];
        return self::$redis;
    }

    /**
     * @return string[] List of matching keys (without values)
     */
    public function getKeysByPrefix(string $prefix): array
    {
        $prefix = rtrim($prefix, '*') . '*';
        $redisArr = $this->getRedisAndNamespace();
        $redis = $redisArr['redis'];
        // Full prefix: adapter namespace + your prefix
        $namespace = $redisArr['namespace'];
        $fullPrefix = $namespace . $prefix;
        $keys = $redis->keys($fullPrefix);
        // Remove the namespace from the start of each key (if present)
        foreach ($keys as &$key) {
            if ($namespace && strpos($key, $namespace) === 0) {
                $key = substr($key, strlen($namespace));
            }
        }
        return $keys;
    }

    /**
     * To get keys + values, loop over keys and fetch with $this->cacheAdapter->getItem($key)
     */
    public function getItemsByPrefix(string $prefix): array
    {
        $keys = $this->getKeysByPrefix($prefix);
        $items = [];
        foreach ($keys as $key) {
            $item = $this->cacheAdapter->getItem($key);
            if ($item->isHit()) {
                $items[$key] = $item->get();
            }
        }
        return $items;
    }
}