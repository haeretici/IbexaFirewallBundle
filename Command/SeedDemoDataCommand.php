<?php

namespace Haeretici\FirewallBundle\Command;

use DateInterval;
use DateTimeImmutable;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SeedDemoDataCommand extends Command
{
    private const SAMPLE_PATHS = [
        '/' => 28,
        '/media/images/hero.jpg' => 18,
        '/media/cache/thumb/article.png' => 12,
        '/search' => 10,
        '/login' => 8,
        '/api/ezp/v2/content/objects' => 7,
        '/admin' => 6,
        '/contact' => 5,
        '/sitemap.xml' => 4,
        '/wp-login.php' => 2,
    ];

    private const SAMPLE_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Twitterbot/1.0',
        'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
        'LinkedInBot/1.0 (compatible; Mozilla/5.0; +http://www.linkedin.com)',
        'curl/8.4.0',
        'python-requests/2.31.0',
    ];

    private ManagerRegistry $doctrine;
    private RedisTagAwareAdapter $cache;

    public function __construct(RedisTagAwareAdapter $cache, ManagerRegistry $doctrine)
    {
        parent::__construct();
        $this->cache = $cache;
        $this->doctrine = $doctrine;
    }

    protected function configure(): void
    {
        $this
            ->setName('ibexa:firewall:seed-demo')
            ->setDescription('Populate firewall tables with dummy data for dashboard demos and UI testing')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Delete existing rows from http_request_logs and server_metrics before seeding')
            ->addOption('metrics-days', null, InputOption::VALUE_REQUIRED, 'Days of server_metrics history to generate', '7')
            ->addOption('metrics-interval', null, InputOption::VALUE_REQUIRED, 'Minutes between each server_metrics sample', '5')
            ->addOption('requests', null, InputOption::VALUE_REQUIRED, 'Number of http_request_logs rows to generate', '800')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Random seed for reproducible demo data', null)
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Skip updating the haeretici_server_metrics Redis cache key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('seed') !== null) {
            mt_srand((int) $input->getOption('seed'));
        }

        $metricsDays = max(1, (int) $input->getOption('metrics-days'));
        $metricsInterval = max(1, (int) $input->getOption('metrics-interval'));
        $requestCount = max(1, (int) $input->getOption('requests'));

        $connection = $this->doctrine->getConnection();

        if ($input->getOption('clear')) {
            $connection->executeStatement('DELETE FROM http_request_logs');
            $connection->executeStatement('DELETE FROM server_metrics');
            $io->note('Cleared http_request_logs and server_metrics.');
        }

        $metricsInserted = $this->seedServerMetrics($connection, $metricsDays, $metricsInterval);
        $requestsInserted = $this->seedRequestLogs($connection, $requestCount);

        if (!$input->getOption('no-cache')) {
            $this->updateMetricsCache($connection);
        }

        $io->success(sprintf(
            'Demo data ready: %d server_metrics rows, %d http_request_logs rows.',
            $metricsInserted,
            $requestsInserted
        ));
        $io->listing([
            'Open the dashboard: Content → Firewall → Dashboard',
            'Chart ranges: try 3h, 12h, 1d, and 1w in the metrics widget',
            'Re-run with --clear to replace data, or --seed=42 for reproducible output',
        ]);

        return Command::SUCCESS;
    }

    private function seedServerMetrics($connection, int $days, int $intervalMinutes): int
    {
        $end = new DateTimeImmutable();
        $start = $end->sub(new DateInterval(sprintf('P%dD', $days)));
        $interval = new DateInterval(sprintf('PT%dM', $intervalMinutes));

        $insertSql = <<<'SQL'
            INSERT INTO server_metrics
            (cpu, memory, redis_mem, apache2_mem, varnish_mem, mysql_mem, os_disk, data_disk, timestamp)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL;

        $inserted = 0;
        $pointIndex = 0;

        for ($at = $start; $at <= $end; $at = $at->add($interval), ++$pointIndex) {
            $phase = $pointIndex / 12;
            $cpu = $this->clamp($this->wave($phase, 22, 18) + $this->randomFloat(-4, 4), 3, 96);
            $memory = $this->clamp($this->wave($phase + 1.2, 48, 12) + $this->randomFloat(-3, 3), 20, 92);

            // Simulate occasional load spikes (deploy, cache warm, crawl burst)
            if (mt_rand(1, 100) <= 4) {
                $cpu += mt_rand(15, 35);
                $memory += mt_rand(8, 18);
            }

            $redisMem = $this->clamp(1.2 + $this->wave($phase, 2.5, 0.8) + $this->randomFloat(-0.3, 0.3), 0.5, 8);
            $apacheMem = $this->clamp(3.5 + $this->wave($phase + 0.4, 4, 1.5) + $this->randomFloat(-0.5, 0.5), 1, 18);
            $varnishMem = $this->clamp(1.8 + $this->wave($phase + 0.8, 2, 0.6), 0.8, 10);
            $mysqlMem = $this->clamp(6 + $this->wave($phase + 1.6, 5, 2) + $this->randomFloat(-1, 1), 3, 28);
            $osDisk = $this->clamp(58 + ($pointIndex * 0.002) + $this->randomFloat(-0.2, 0.2), 50, 88);
            $dataDisk = $this->clamp(41 + ($pointIndex * 0.0015) + $this->randomFloat(-0.15, 0.15), 35, 75);

            $connection->executeStatement($insertSql, [
                round($cpu, 2),
                round($memory, 2),
                round($redisMem, 2),
                round($apacheMem, 2),
                round($varnishMem, 2),
                round($mysqlMem, 2),
                round($osDisk, 2),
                round($dataDisk, 2),
                $at->format('Y-m-d H:i:s'),
            ]);
            ++$inserted;
        }

        return $inserted;
    }

    private function seedRequestLogs($connection, int $count): int
    {
        $insertSql = <<<'SQL'
            INSERT INTO http_request_logs
            (ip, path, query, agent, firewallTime, responseTime, isBotAgent, isBannedBot, isChallenge, isRateLimited, timestamp)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL;

        $now = new DateTimeImmutable();
        $inserted = 0;

        for ($i = 0; $i < $count; ++$i) {
            $flags = $this->randomRequestFlags();
            $path = $this->weightedPath();
            $query = $this->randomQuery($path);
            $agent = self::SAMPLE_AGENTS[array_rand(self::SAMPLE_AGENTS)];

            if ($flags['isBotAgent']) {
                $agent = self::SAMPLE_AGENTS[mt_rand(2, 6)];
            }

            // Bias timestamps toward the last 24 hours so dashboard stats look lively
            $minutesAgo = $this->skewedMinutesAgo();
            $timestamp = $now->sub(new DateInterval(sprintf('PT%dM', $minutesAgo)));

            $firewallTime = $this->randomFloat(0.0004, 0.012);
            $responseTime = $this->randomFloat(0.02, 1.8);
            if ($flags['isChallenge']) {
                $responseTime = $this->randomFloat(0.005, 0.05);
            }
            if ($flags['isRateLimited']) {
                $responseTime = $this->randomFloat(0.001, 0.02);
            }

            $connection->executeStatement($insertSql, [
                $this->randomIp(),
                $path,
                $query,
                $agent,
                round($firewallTime, 6),
                round($responseTime, 6),
                $flags['isBotAgent'] ? 1 : 0,
                $flags['isBannedBot'] ? 1 : 0,
                $flags['isChallenge'] ? 1 : 0,
                $flags['isRateLimited'] ? 1 : 0,
                $timestamp->format('Y-m-d H:i:s'),
            ]);
            ++$inserted;
        }

        return $inserted;
    }

    private function updateMetricsCache($connection): void
    {
        $latest = $connection->fetchAssociative(
            'SELECT cpu, memory, redis_mem, apache2_mem, varnish_mem, mysql_mem, os_disk, data_disk, timestamp
             FROM server_metrics ORDER BY timestamp DESC LIMIT 1'
        );

        if (!$latest) {
            return;
        }

        $metricsItem = $this->cache->getItem('haeretici_server_metrics');
        $metricsItem->set(json_encode($latest));
        $this->cache->save($metricsItem);
    }

    private function randomRequestFlags(): array
    {
        $roll = mt_rand(1, 100);

        if ($roll <= 5) {
            return ['isBotAgent' => true, 'isBannedBot' => true, 'isChallenge' => false, 'isRateLimited' => false];
        }
        if ($roll <= 12) {
            return ['isBotAgent' => true, 'isBannedBot' => false, 'isChallenge' => false, 'isRateLimited' => false];
        }
        if ($roll <= 17) {
            return ['isBotAgent' => false, 'isBannedBot' => false, 'isChallenge' => true, 'isRateLimited' => false];
        }
        if ($roll <= 22) {
            return ['isBotAgent' => false, 'isBannedBot' => false, 'isChallenge' => false, 'isRateLimited' => true];
        }

        return ['isBotAgent' => false, 'isBannedBot' => false, 'isChallenge' => false, 'isRateLimited' => false];
    }

    private function weightedPath(): string
    {
        $pool = [];
        foreach (self::SAMPLE_PATHS as $path => $weight) {
            for ($i = 0; $i < $weight; ++$i) {
                $pool[] = $path;
            }
        }

        return $pool[array_rand($pool)];
    }

    private function randomQuery(string $path): ?string
    {
        if ($path !== '/search' && mt_rand(1, 100) > 25) {
            return null;
        }

        $queries = [
            'q=ibexa+cms',
            'q=firewall&page=2',
            'utm_source=newsletter&utm_medium=email',
            'lang=eng-GB',
            'page=3&sort=date',
        ];

        return $queries[array_rand($queries)];
    }

    private function randomIp(): string
    {
        if (mt_rand(1, 100) <= 70) {
            return sprintf('%d.%d.%d.%d', mt_rand(1, 223), mt_rand(0, 255), mt_rand(0, 255), mt_rand(1, 254));
        }

        return sprintf(
            '%04x:%04x:%04x:%04x:%04x:%04x:%04x:%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Skew timestamps: ~75% within last 24h, rest spread over 7 days.
     */
    private function skewedMinutesAgo(): int
    {
        if (mt_rand(1, 100) <= 75) {
            return mt_rand(1, 24 * 60);
        }

        return mt_rand(24 * 60 + 1, 7 * 24 * 60);
    }

    private function wave(float $phase, float $center, float $amplitude): float
    {
        return $center + sin($phase) * $amplitude + sin($phase * 0.37) * ($amplitude * 0.35);
    }

    private function randomFloat(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}