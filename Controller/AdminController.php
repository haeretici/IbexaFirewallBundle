<?php

namespace Ne0Heretic\FirewallBundle\Controller;

use Ibexa\Contracts\AdminUi\Controller\Controller;
use Ibexa\Core\MVC\Symfony\Security\Authorization\Attribute;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Ne0Heretic\FirewallBundle\Lib\ConfigService;
use Ne0Heretic\FirewallBundle\Form\FirewallSettingsType;

class AdminController extends Controller
{
    /** @var RedisTagAwareAdapter */
    protected $cache;
    /** @var string */
    protected $cacheDir;
    /** @var ManagerRegistry */
    protected $doctrine;
    /** @var ConfigService */
    protected $configService;

    public function __construct(
        RedisTagAwareAdapter $cache,
        string $cacheDir,
        ManagerRegistry $doctrine,
        ConfigService $configService
    )
    {
        $this->cache = $cache;
        $this->cacheDir = $cacheDir;
        $this->doctrine = $doctrine;
        $this->configService = $configService;
    }

    public function performAccessCheck(): void
    {
        parent::performAccessCheck();
        $this->denyAccessUnlessGranted(new Attribute('ne0heretic_firewall', 'admin'));
    }

    public function dashboardAction()
    {
        $this->performAccessCheck();
        $pathItems = [
            ['value' => 'Ibexa Firewall'],
        ];
        $params = [
            'title' => 'Ibexa Firewall: Dashboard',
            'path_items' => $pathItems,
        ];
        $request = Request::createFromGlobals();
        $entityManager = $this->doctrine->getManager();
        $connection = $entityManager->getConnection();

        // Fetch latest server metrics from cache or DB
        $metricsCacheItem = $this->cache->getItem('ne0heretic_server_metrics');
        if ($metricsCacheItem->isHit()) {
            $latestMetrics = json_decode($metricsCacheItem->get(), true);
        } else {
            $latestMetrics = $connection->fetchAssociative('SELECT * FROM server_metrics ORDER BY timestamp DESC LIMIT 1');
            if ($latestMetrics) {
                $metricsCacheItem->set(json_encode($latestMetrics));
                $this->cache->save($metricsCacheItem);
            }
        }
        $params['latestMetrics'] = $latestMetrics ?? [];

        // Fetch HTTP request log aggregates for past 24 hours
        $start24h = (new \DateTime())->sub(new \DateInterval('P1D'))->format('Y-m-d H:i:s');
        $requestStats = [
            'total' => (int) $connection->fetchOne("SELECT COUNT(*) FROM http_request_logs WHERE timestamp >= ?", [$start24h]),
            'bots' => (int) $connection->fetchOne("SELECT COUNT(*) FROM http_request_logs WHERE isBotAgent = 1 AND timestamp >= ?", [$start24h]),
            'bannedBots' => (int) $connection->fetchOne("SELECT COUNT(*) FROM http_request_logs WHERE isBannedBot = 1 AND timestamp >= ?", [$start24h]),
            'challenges' => (int) $connection->fetchOne("SELECT COUNT(*) FROM http_request_logs WHERE isChallenge = 1 AND timestamp >= ?", [$start24h]),
            'rateLimited' => (int) $connection->fetchOne("SELECT COUNT(*) FROM http_request_logs WHERE isRateLimited = 1 AND timestamp >= ?", [$start24h]),
        ];
        $params['requestStats'] = $requestStats;

        // Fetch recent requests (last 10)
        $recentRequests = $connection->fetchAllAssociative(
            "SELECT ip, path, query, agent, firewallTime, responseTime, isBotAgent, isBannedBot, isChallenge, isRateLimited, timestamp
             FROM http_request_logs
             ORDER BY timestamp DESC
             LIMIT 10"
        );
        $params['recentRequests'] = $recentRequests;

        // Fetch top 5 paths by request count past 24 hours
        $topPaths = $connection->fetchAllAssociative(
            "SELECT path, COUNT(*) as count
             FROM http_request_logs
             WHERE timestamp >= ?
             GROUP BY path
             ORDER BY count DESC
             LIMIT 5",
            [$start24h]
        );
        $params['topPaths'] = $topPaths;

        return $this->render('@ibexadesign/ne0heretic/pages/dashboard.html.twig', $params);
    }

    public function getMetricsAction(Request $request): JsonResponse
    {
        $this->performAccessCheck();
        $entityManager = $this->doctrine->getManager();
        $connection = $entityManager->getConnection();

        $range = $request->query->get('range', '3h');
        $start = $request->query->get('start');
        $end = $request->query->get('end', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));

        // Determine time range
        if ($start && $end) {
            // Custom range
            $whereClause = "timestamp >= ? AND timestamp <= ?";
            $params = [$start, $end];
            $groupBy = $this->getGroupByForCustomRange($start, $end);  // Auto-downsample long customs
        } else {
            // Preset ranges
            $endTime = new \DateTimeImmutable();
            $startTime = match($range) {
                '1h' => $endTime->sub(new \DateInterval('PT1H')),
                '3h' => $endTime->sub(new \DateInterval('PT3H')),
                '12h' => $endTime->sub(new \DateInterval('PT12H')),
                '1d' => $endTime->sub(new \DateInterval('P1D')),
                '3d' => $endTime->sub(new \DateInterval('P3D')),
                '1w' => $endTime->sub(new \DateInterval('P1W')),
                default => $endTime->sub(new \DateInterval('PT3H'))
            };
            $start = $startTime->format('Y-m-d H:i:s');
            $end = $endTime->format('Y-m-d H:i:s');
            $whereClause = "timestamp >= ? AND timestamp <= ?";
            $params = [$start, $end];
            $groupBy = $this->getGroupByForRange($range);
        }

        // Conditional SELECT: Raw for full res, AVG for aggregated
        $selectClause = $groupBy
            ? "SELECT timestamp, AVG(cpu) AS cpu, AVG(memory) AS memory, AVG(redis_mem) AS redis_mem, AVG(apache2_mem) AS apache2_mem, AVG(varnish_mem) AS varnish_mem, AVG(mysql_mem) AS mysql_mem, AVG(os_disk) AS os_disk, AVG(data_disk) AS data_disk"
            : "SELECT timestamp, cpu, memory, redis_mem, apache2_mem, varnish_mem, mysql_mem, os_disk, data_disk";

        $sql = "{$selectClause} FROM server_metrics WHERE {$whereClause}";

        if ($groupBy) {
            $sql .= " GROUP BY {$groupBy} ORDER BY timestamp ASC";
        } else {
            $sql .= " ORDER BY timestamp ASC";
        }

        $rows = $connection->fetchAllAssociative($sql, $params);

        // Format timestamps as strings
        $metrics = array_map(function ($row) {
            $row['timestamp'] = (new \DateTime($row['timestamp']))->format('Y-m-d H:i:s');
            return $row;
        }, $rows);

        return new JsonResponse([
            'success' => true,
            'data' => $metrics,
            'range' => $range ?? 'custom',
            'start' => $start,
            'end' => $end,
            'count' => count($metrics)
        ]);
    }

    private function getGroupByForRange(string $range): ?string
    {
        return match($range) {
            '1h', '3h' => null,  // Full res: ~60-180 points
            '12h' => 'UNIX_TIMESTAMP(timestamp) DIV 1800',  // 30-min buckets (~24 points)
            '1d' => 'DATE_FORMAT(timestamp, "%Y-%m-%d %H")',  // Hourly (~24 points)
            '3d' => 'DATE(timestamp)',  // Daily (~3 points)
            '1w' => 'DATE(timestamp)',  // Daily (~7 points)
            default => null
        };
    }

    private function getGroupByForCustomRange(string $start, string $end): ?string
    {
        $startDt = new \DateTime($start);
        $endDt = new \DateTime($end);
        $durationHours = $startDt->diff($endDt)->h + ($startDt->diff($endDt)->days * 24);

        // Auto-downsample if >3h to cap at ~200 points (assuming 1-min data)
        if ($durationHours <= 3) {
            return null;  // Full res
        } elseif ($durationHours <= 12) {
            return 'UNIX_TIMESTAMP(timestamp) DIV 1800';  // 30-min
        } elseif ($durationHours <= 24) {
            return 'DATE_FORMAT(timestamp, "%Y-%m-%d %H")';  // Hourly
        } else {
            return 'DATE(timestamp)';  // Daily
        }
    }

    public function settingsAction(Request $request)
    {
        $this->performAccessCheck();
        $config = $this->configService->getConfig();
        $formData = $this->flattenConfig($config);
        $form = $this->createForm(FirewallSettingsType::class, $formData);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $newFormData = $form->getData();
            $newConfig = $this->unflattenConfig($newFormData);
            $this->configService->updateConfig($newConfig);
            $this->addFlash('success', 'Settings updated successfully. Some changes may require a page reload or server restart to take effect.');
        }

        $pathItems = [
            ['value' => 'Firewall'],
            ['value' => 'Settings'],
        ];
        $params = [
            'title' => 'Firewall Settings',
            'path_items' => $pathItems,
            'form' => $form->createView(),
        ];

        return $this->render('@ibexadesign/ne0heretic/pages/settings.html.twig', $params);
    }

    private function flattenConfig(array $config): array
    {
        return [
            'rate_limiting_window' => $config['rate_limiting']['window'],
            'rate_limiting_max_requests' => $config['rate_limiting']['max_requests'],
            'rate_limiting_min_response_time' => $config['rate_limiting']['min_response_time'],
            'rate_limiting_ban_duration' => $config['rate_limiting']['ban_duration'],
            'challenge_ttl' => $config['challenge']['ttl'],
            'challenge_verified_ttl' => $config['challenge']['verified_ttl'],
            'challenge_secret_length' => $config['challenge']['secret_length'],
            'challenge_dummy_ratio' => $config['challenge']['dummy_ratio'],
            'challenge_dummy_char' => $config['challenge']['dummy_char'],
            'challenge_enabled_for_non_bots' => $config['challenge']['enabled_for_non_bots'],
            'bots_google_enabled' => $config['bots']['google_enabled'],
            'bots_twitter_enabled' => $config['bots']['twitter_enabled'],
            'bots_facebook_enabled' => $config['bots']['facebook_enabled'],
            'bots_bing_enabled' => $config['bots']['bing_enabled'],
            'bots_linkedin_enabled' => $config['bots']['linkedin_enabled'],
            'enable_rate_limiting' => $config['enable_rate_limiting'],
            'exemptions_paths' => implode(',', $config['exemptions']['paths'] ?? []),
            'honeypot_enabled' => $config['honeypot']['enabled'],
            'honeypot_ban_duration' => $config['honeypot']['ban_duration'],
            'honeypot_paths' => implode(',', $config['honeypot']['paths'] ?? []),
        ];
    }

    private function unflattenConfig(array $flat): array
    {
        $exemptionsPaths = array_filter(array_map('trim', explode(',', $flat['exemptions_paths'] ?? '')));
        $honeypotPaths = array_filter(array_map('trim', explode(',', $flat['honeypot_paths'] ?? '')));
        return [
            'rate_limiting' => [
                'window' => $flat['rate_limiting_window'],
                'max_requests' => $flat['rate_limiting_max_requests'],
                'min_response_time' => $flat['rate_limiting_min_response_time'],
                'ban_duration' => $flat['rate_limiting_ban_duration'],
            ],
            'challenge' => [
                'ttl' => $flat['challenge_ttl'],
                'verified_ttl' => $flat['challenge_verified_ttl'],
                'secret_length' => $flat['challenge_secret_length'],
                'dummy_ratio' => $flat['challenge_dummy_ratio'],
                'dummy_char' => $flat['challenge_dummy_char'],
                'enabled_for_non_bots' => $flat['challenge_enabled_for_non_bots'],
            ],
            'bots' => [
                'google_enabled' => $flat['bots_google_enabled'],
                'twitter_enabled' => $flat['bots_twitter_enabled'],
                'facebook_enabled' => $flat['bots_facebook_enabled'],
                'bing_enabled' => $flat['bots_bing_enabled'],
                'linkedin_enabled' => $flat['bots_linkedin_enabled'],
            ],
            'exemptions' => [
                'paths' => $exemptionsPaths,
            ],
            'enable_rate_limiting' => $flat['enable_rate_limiting'],
            'honeypot' => [
                'enabled' => $flat['honeypot_enabled'],
                'ban_duration' => $flat['honeypot_ban_duration'],
                'paths' => $honeypotPaths,
            ],
        ];
    }
}