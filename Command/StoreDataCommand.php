<?php

namespace Haeretici\FirewallBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Haeretici\FirewallBundle\Lib\CacheInspector;

class StoreDataCommand extends Command
{
    /**
     * @var ManagerRegistry
     */
    private $doctrine;
    /** @var RedisTagAwareAdapter */
    protected $cache;

    public function __construct(RedisTagAwareAdapter $cache, ManagerRegistry $doctrine)
    {
        parent::__construct(null);
        $this->doctrine = $doctrine;
        $this->cache = $cache;
    }

    protected function configure()
    {
        $this
                ->setName('ibexa:firewall:store')
                ->setDescription('Stores Ibexa Firewall cached data into the database');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        // Get the directory of the current script
        $scriptDir = __DIR__;

        // Get stats for root and script dir
        $rootStat = stat('/');
        $scriptStat = stat($scriptDir);
        $serverData = [
            'os_disk' => 0,
            'data_disk' => 0
        ];
        if ($rootStat !== false && $scriptStat !== false) {
            // Check if on different disks
            $isDifferentDisk = ($rootStat['dev'] !== $scriptStat['dev']);
            $diskLabel = $isDifferentDisk ? 'Script Disk' : 'Root Disk (same as script)';

            // Function to calculate usage %
            function getDiskUsagePercent($path) {
                $total = disk_total_space($path);
                $free = disk_free_space($path);
                if ($total <= 0) {
                    return 0.0;  // Avoid division by zero
                }
                return ((($total - $free) / $total) * 100);
            }

            // Calculate for root
            $rootTotal = disk_total_space('/');
            $rootFree = disk_free_space('/');

            // Calculate for script dir
            $scriptTotal = disk_total_space($scriptDir);
            $scriptFree = disk_free_space($scriptDir);

            // Calculate percentages
            $serverData['os_disk'] = getDiskUsagePercent('/');
            $serverData['data_disk'] = getDiskUsagePercent($scriptDir);
        }

        $nproc = floatval(trim(shell_exec('nproc')));
        $cpuLoad = floatval(shell_exec("cat /proc/loadavg | awk '{print $1}'"));
        $serverData['cpu'] = 100*($cpuLoad/$nproc);
        $serverData['memory'] = 100*floatval(shell_exec('free -m | awk \'NR==2{printf "%.4f", $3/$2}\''));
        // Function to get memory % for a service (sum of %MEM for matching processes)
        function getServiceMemoryPercent($servicePattern) {
            $cmd = "ps -eo %mem,cmd --no-headers 2>/dev/null | grep -i " . escapeshellarg("[$servicePattern[0]]" . substr($servicePattern, 1)) . " | awk '{sum += \$1} END {printf \"%.4f\", sum}'";
            return floatval(trim(shell_exec($cmd)));
        }

        // Get memory % for each service
        $serverData['redis_mem'] = getServiceMemoryPercent('redis-server');
        $serverData['apache2_mem'] = getServiceMemoryPercent('apache2');
        $serverData['varnish_mem'] = getServiceMemoryPercent('varnishd');
        $serverData['mysql_mem'] = getServiceMemoryPercent('mysqld');
        // Store server data
        $entityManager = $this->doctrine->getManager();
        $connection = $entityManager->getConnection();
        $insertSql = "
            INSERT INTO server_metrics
            (cpu, memory, redis_mem, apache2_mem, varnish_mem, mysql_mem, os_disk, data_disk) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $params = [
            $serverData['cpu'],
            $serverData['memory'],
            $serverData['redis_mem'],
            $serverData['apache2_mem'],
            $serverData['varnish_mem'],
            $serverData['mysql_mem'],
            $serverData['os_disk'],
            $serverData['data_disk'],
        ];
        $connection->executeStatement($insertSql, $params);
        // also store in redis
        $metricsItem = $this->cache->getItem('haeretici_server_metrics');
        $serverData['timestamp'] = time();
        $metricsItem->set(json_encode($serverData));
        $this->cache->save($metricsItem);
        // Now process requests data
        $cacheInspector = new CacheInspector($this->cache);
        foreach ($cacheInspector->getKeysByPrefix('request_time_') as $key) {
            $item = $this->cache->getItem($key);
            if ($item->isHit()) {
                $dataJson = $item->get();
                $data = json_decode($dataJson, true);
                $insertItemSql = "INSERT INTO http_request_logs (ip, path, query, agent, firewallTime, responseTime, isBotAgent, isBannedBot, isChallenge, isRateLimited) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $itemParams = [
                    $data['ip'],
                    $data['path'],
                    $data['query'],
                    $data['agent'],
                    $data['firewallTime'],
                    $data['responseTime'],
                    $data['isBotAgent'] ? 1 : 0,
                    $data['isBannedBot'] ? 1 : 0,
                    $data['isChallenge'] ? 1 : 0,
                    $data['isRateLimited'] ? 1 : 0
                ];
                $output->writeln(implode(' ', $data));
                $connection->executeStatement($insertItemSql, $itemParams);
                // Delete cache item
                $this->cache->deleteItem($key);
            }

        }
        // TODO: use a separated script for this
        // Delete http_request_logs entries older than 7 days
        $interval7 = (new \DateTime())->sub(new \DateInterval('P7D'))->format('Y-m-d H:i:s');
        $deleteSql = "DELETE FROM http_request_logs WHERE timestamp < ?";
        $connection->executeStatement($deleteSql, [$interval7]);
        // Delete server_metrics entries older than 90 days
        $interval90 = (new \DateTime())->sub(new \DateInterval('P90D'))->format('Y-m-d H:i:s');
        $deleteSql2 = "DELETE FROM server_metrics WHERE timestamp < ?";
        $connection->executeStatement($deleteSql2, [$interval90]);

        return Command::SUCCESS;
    }
}
