<?php

namespace Haeretici\FirewallBundle\Lib;

use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;

class BotValidator
{
    /** @var RedisTagAwareAdapter */
    protected $cache;
    /** @var array */
    private array $config;
    /**
     * Authoritative core network blocks for X (Twitter) and Meta (Facebook)
     */
    private const TWITTER_CIDR_BLOCKS = [
        '104.244.40.0/21',
        '192.133.76.0/22',
        '199.16.156.0/22',
        '199.59.148.0/22',
        '199.96.56.0/21',
        '64.63.0.0/18',
        '69.195.160.0/19',
        '209.237.192.0/19'
    ];

    private const META_CIDR_BLOCKS = [
        '31.13.64.0/18',
        '57.144.0.0/14',
        '57.142.0.0/15',
        '57.148.0.0/15',
        '66.220.144.0/20',
        '69.63.176.0/20',
        '129.134.0.0/16',
        '157.240.0.0/16',
        '173.252.64.0/18',
        '185.60.216.0/22',
        '204.15.20.0/22'
    ];

    public function __construct(RedisTagAwareAdapter $cache, ConfigService $configService)
    {
        $this->cache = $cache;
        $this->config = $configService->getConfig();
    }

    /**
     * Check if IP is globally banned for bot spoofing
     *
     * @param string $ip
     * @return bool
     */
    public function isBanned(string $ip): bool
    {
        $banKey = 'bot_ban_' . md5($ip);
        $banned = $this->cache->getItem($banKey);
        return $banned->isHit() && $banned->get();
    }

    /**
     * Check rate limit for the IP
     * Increments request count and returns false if limit exceeded
     *
     * @param string $ip
     * @return bool True if under limit, false if rate limited
     */
    public function checkRateLimit(string $ip): bool
    {
        $windowSize = $this->config['rate_limiting']['window']; // e.g., 60 seconds
        $maxRequests = $this->config['rate_limiting']['max_requests'];

        $now = time();
        $currentWindowFloor = $now - ($now % $windowSize);
        $previousWindowFloor = $currentWindowFloor - $windowSize;

        $baseKey = 'rate_limiter_' . md5($ip);
        $currentKey = $baseKey . '_' . $currentWindowFloor;
        $previousKey = $baseKey . '_' . $previousWindowFloor;

        // BULK FETCH: Fetch both keys in exactly ONE network round-trip (Redis MGET)
        // Wrap in iterator_to_array to convert the Generator into a usable associative array
        $cacheItems = iterator_to_array($this->cache->getItems([$currentKey, $previousKey]));

        $currentCount = (isset($cacheItems[$currentKey]) && $cacheItems[$currentKey]->isHit())
            ? (int) $cacheItems[$currentKey]->get()
            : 0;

        $previousCount = (isset($cacheItems[$previousKey]) && $cacheItems[$previousKey]->isHit())
            ? (int) $cacheItems[$previousKey]->get()
            : 0;

        // MATHEMATICAL SLIDING SLICE
        // Calculate how deep we are into the current window
        $timeIntoCurrentWindow = $now % $windowSize;
        $weight = ($windowSize - $timeIntoCurrentWindow) / $windowSize;

        // Extrapolate request density smoothly
        $predictedRequests = ($previousCount * $weight) + $currentCount;

        if ($predictedRequests >= $maxRequests) {
            $this->banIpGlobally($ip);
            return false;
        }

        // 3. SINGLE WRITE: Save only the current window bucket increment
        $currentItem = $cacheItems[$currentKey];
        $currentItem->set($currentCount + 1);

        // TTL must survive into the next window phase to be read as $previousCount
        $currentItem->expiresAfter($windowSize * 2);
        $this->cache->save($currentItem);

        return true;
    }

    /**
     * Validate if the IP belongs to a legitimate Googlebot
     * Uses DNS forward/reverse checks (Google's recommended method)
     * Caches result in Redis for 24 hours to avoid repeated lookups
     *
     * @param string $ip
     * @return bool
     */
    public function validateGooglebot(string $ip): bool
    {
        $cacheKey = 'googlebot_valid_' . md5($ip);
        $cached = $this->cache->getItem($cacheKey);
        if ($cached->isHit()) {
            return $cached->get();
        }

        $hostname = gethostbyaddr($ip);
        if ($hostname === $ip) {
            return $this->cacheAndReturn($cached, false);
        }

        // Check if hostname ends with .googlebot.com or .google.com
        if (!str_ends_with($hostname, '.googlebot.com') && !str_ends_with($hostname, '.google.com')) {
            return $this->cacheAndReturn($cached, false);
        }

        return $this->cacheAndReturn($cached, $this->forwardDnsMatches($hostname, $ip));
    }

    /**
     * Validate if the IP belongs to a legitimate Twitterbot (X)
     * Uses DNS forward/reverse checks
     * Caches result in Redis for 24 hours
     *
     * @param string $ip
     * @return bool
     */
    public function validateTwitterbot(string $ip): bool
    {
        $cacheKey = 'twitterbot_valid_' . md5($ip);
        $cached = $this->cache->getItem($cacheKey);
        if ($cached->isHit()) {
            return $cached->get();
        }

        // 1. FAST PATH: Check public CIDR block definitions instantly in-memory
        if ($this->ipInAnyCidr($ip, self::TWITTER_CIDR_BLOCKS)) {
            return $this->cacheAndReturn($cached, true);
        }

        // 2. FALLBACK PATH: Look up FCrDNS for new or unannounced outbound nodes
        $hostname = gethostbyaddr($ip);
        if ($hostname === $ip) {
            return $this->cacheAndReturn($cached, false);
        }

        if (!str_ends_with($hostname, '.twitter.com') && !str_ends_with($hostname, '.twttr.com')) {
            return $this->cacheAndReturn($cached, false);
        }

        return $this->cacheAndReturn($cached, $this->forwardDnsMatches($hostname, $ip));
    }

    /**
     * Validate if the IP belongs to a legitimate Facebookbot (Facebot)
     * Uses DNS forward/reverse checks (common practice; official prefers IP list via whois AS32934)
     * Caches result in Redis for 24 hours
     *
     * @param string $ip
     * @return bool
     */
    public function validateFacebookbot(string $ip): bool
    {
        $cacheKey = 'facebookbot_valid_' . md5($ip);
        $cached = $this->cache->getItem($cacheKey);
        if ($cached->isHit()) {
            return $cached->get();
        }

        // 1. FAST PATH: Match against Meta's primary autonomous network ranges
        if ($this->ipInAnyCidr($ip, self::META_CIDR_BLOCKS)) {
            return $this->cacheAndReturn($cached, true);
        }

        // 2. FALLBACK PATH: Check proxy host domains using the updated string parsing
        $hostname = gethostbyaddr($ip);
        if ($hostname === $ip) {
            return $this->cacheAndReturn($cached, false);
        }

        if (!str_ends_with($hostname, '.facebook.com') && !str_ends_with($hostname, '.fbsv.net')) {
            return $this->cacheAndReturn($cached, false);
        }

        return $this->cacheAndReturn($cached, $this->forwardDnsMatches($hostname, $ip));
    }

    /**
     * Validate if the IP belongs to a legitimate Bingbot
     * Uses DNS forward/reverse checks (Microsoft's recommended method)
     * Caches result in Redis for 24 hours
     *
     * @param string $ip
     * @return bool
     */
    public function validateBingbot(string $ip): bool
    {
        $cacheKey = 'bingbot_valid_' . md5($ip);
        $cached = $this->cache->getItem($cacheKey);
        if ($cached->isHit()) {
            return $cached->get();
        }

        $hostname = gethostbyaddr($ip);
        if ($hostname === $ip) {
            return $this->cacheAndReturn($cached, false);
        }

        // Modern Bingbots resolve to both legacy .search.msn.com and modern .bing.com hostnames
        if (!str_ends_with($hostname, '.search.msn.com') && !str_ends_with($hostname, '.bing.com')) {
            return $this->cacheAndReturn($cached, false);
        }

        return $this->cacheAndReturn($cached, $this->forwardDnsMatches($hostname, $ip));
    }

    /**
     * Validate if the IP belongs to a legitimate LinkedInBot
     * Uses DNS forward/reverse checks
     * Caches result in Redis for 24 hours
     *
     * @param string $ip
     * @return bool
     */
    public function validateLinkedInBot(string $ip): bool
    {
        $cacheKey = 'linkedinbot_valid_' . md5($ip);
        $cached = $this->cache->getItem($cacheKey);
        if ($cached->isHit()) {
            return $cached->get();
        }

        $hostname = gethostbyaddr($ip);
        if ($hostname === $ip) {
            return $this->cacheAndReturn($cached, false);
        }

        // Matches underlying patterns like *.fwd.linkedin.com
        if (!str_ends_with($hostname, '.linkedin.com')) {
            return $this->cacheAndReturn($cached, false);
        }

        return $this->cacheAndReturn($cached, $this->forwardDnsMatches($hostname, $ip));
    }

    /**
     * Helper to clean up repetitive cache storage logic
     */
    private function cacheAndReturn(\Psr\Cache\CacheItemInterface $item, bool $value): bool
    {
        $item->set($value);
        $item->expiresAfter(86400);
        $this->cache->save($item);
        return $value;
    }

    /**
     * Helper: Perform forward DNS lookup and check if original IP matches resolved IPs
     * Supports IPv4 (A) and IPv6 (AAAA) records
     *
     * @param string $hostname
     * @param string $ip
     * @return bool
     */
    private function forwardDnsMatches(string $hostname, string $ip): bool
    {
        $resolvedIps = [];

        // IPv4 A records
        $aRecords = dns_get_record($hostname, DNS_A);
        if ($aRecords) {
            foreach ($aRecords as $record) {
                $resolvedIps[] = $record['ip'];
            }
        } else {
            $forwardIpv4 = gethostbyname($hostname);
            if ($forwardIpv4 !== false && $forwardIpv4 !== $hostname) {
                $resolvedIps[] = $forwardIpv4;
            }
        }

        // IPv6 AAAA records (if IP is IPv6)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $aaaaRecords = dns_get_record($hostname, DNS_AAAA);
            if ($aaaaRecords) {
                foreach ($aaaaRecords as $record) {
                    $resolvedIps[] = $record['ipv6'];
                }
            }
        }

        return in_array($ip, $resolvedIps, true);
    }

    /**
     * Check if an IP matches an array of CIDR blocks
     */
    private function ipInAnyCidr(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Internal helper to match a single IP against a CIDR block (IPv4 and IPv6 safe)
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        // Handling IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $mask = -1 << (32 - $bits);
            return ($ipLong & $mask) === ($subnetLong & $mask);
        }

        // Handling IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);

            $mask = '';
            while ($bits >= 8) {
                $mask .= chr(255);
                $bits -= 8;
            }
            if ($bits > 0) {
                $mask .= chr(256 - (1 << (8 - $bits)));
            }
            $mask = str_pad($mask, 16, chr(0));

            return ($ipBin & $mask) === ($subnetBin & $mask);
        }

        return false;
    }

    /**
     * Helper: Ban IP globally across all bot checks (TTL from config)
     */
    public function banIpGlobally(string $ip, $duration = false): void
    {
        $banKey = 'bot_ban_' . md5($ip);
        $banItem = $this->cache->getItem($banKey);
        $banItem->set(true);
        if(!$duration) {
            $duration = $this->config['rate_limiting']['ban_duration'];
        }
        $banItem->expiresAfter($duration);  // Use config
        $this->cache->save($banItem);
    }
}