<?php
/**
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\CloudflarePurge\Model;

use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Drain the tag queue into Cloudflare within the Free-plan purge budget.
 *
 * Cloudflare Free: 5 tag/host purge requests per minute (bucket 25), 100 tags each, and the
 * limit is shared by every Free zone in the account. One run per minute spends at most
 * REQUESTS_PER_RUN of them, leaving one for anything else on the account.
 *
 * A queued flush (`.*` from cache:flush, a theme change, a cache type refresh) or a backlog
 * that the budget would take more than a few minutes to drain (a sync touching thousands of
 * products) is purged by hostname instead: one request per zone, and the edge refills from
 * Varnish, which has already been purged.
 */
class Purger
{
    private const LOCK_NAME = 'webcode_cloudflare_purge';

    /**
     * Purge requests one run may send, across all zones.
     */
    public const REQUESTS_PER_RUN = 4;

    /**
     * Tags waiting beyond which a hostname purge beats five minutes of tag purges.
     */
    public const BACKLOG_LIMIT = 2000;

    /**
     * @param Queue $queue
     * @param Config $config
     * @param CloudflareClient $client
     * @param LockManagerInterface $lockManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Queue $queue,
        private readonly Config $config,
        private readonly CloudflareClient $client,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        // Stores that run cron:run twice a minute (two crontab lines on one release) make two
        // runs at once the normal case, not an edge case.
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return;
        }

        try {
            $this->process();
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * @return void
     */
    private function process(): void
    {
        $stats = $this->queue->stats();
        if ($stats['count'] === 0) {
            return;
        }

        $zones = $this->config->getZones();
        if ($zones === []) {
            // Disabled since the tags were queued: nobody will ever send them.
            $this->queue->deleteUpTo($stats['max_id']);
            return;
        }

        if ($stats['flush'] || $stats['count'] > self::BACKLOG_LIMIT) {
            $this->purgeHosts($zones, $stats);
            return;
        }

        $this->purgeTags($zones);
    }

    /**
     * @param array<string, array{zone_id: string, token: string, hosts: string[]}> $zones
     * @param array{count: int, max_id: int, flush: bool} $stats
     * @return void
     */
    private function purgeHosts(array $zones, array $stats): void
    {
        foreach ($zones as $zone) {
            foreach (array_chunk($zone['hosts'], CloudflareClient::MAX_ITEMS_PER_REQUEST) as $hosts) {
                $result = $this->client->purgeHosts($zone['zone_id'], $zone['token'], $hosts);
                if (!$result->success && $this->isTransient($result)) {
                    $this->logFailure('hosts', $zone['zone_id'], $result);
                    return;
                }
                if (!$result->success) {
                    $this->logFailure('hosts', $zone['zone_id'], $result);
                }
            }
        }

        $this->queue->deleteUpTo($stats['max_id']);
        $this->logger->info(sprintf(
            'Purged hosts %s (%s, %d tags queued).',
            implode(', ', array_merge(...array_column(array_values($zones), 'hosts'))),
            $stats['flush'] ? 'flush' : 'backlog',
            $stats['count']
        ));
    }

    /**
     * @param array<string, array{zone_id: string, token: string, hosts: string[]}> $zones
     * @return void
     */
    private function purgeTags(array $zones): void
    {
        $requestsPerZone = max(1, intdiv(self::REQUESTS_PER_RUN, count($zones)));
        $rows = $this->queue->fetch($requestsPerZone * CloudflareClient::MAX_ITEMS_PER_REQUEST);
        $sent = 0;

        foreach (array_chunk($rows, CloudflareClient::MAX_ITEMS_PER_REQUEST, true) as $chunk) {
            foreach ($zones as $zone) {
                $result = $this->client->purgeTags($zone['zone_id'], $zone['token'], array_values($chunk));
                if ($result->success) {
                    continue;
                }
                $this->logFailure('tags', $zone['zone_id'], $result);
                if ($this->isTransient($result)) {
                    // Keep the chunk; the next run retries it. A zone that already took it
                    // gets it twice -- a purge is idempotent.
                    $this->logSent($sent);
                    return;
                }
                // A permanent refusal (bad token, bad tag) would block the queue forever.
            }
            $this->queue->delete(array_keys($chunk));
            $sent += count($chunk);
        }

        $this->logSent($sent);
    }

    /**
     * Rate limit, Cloudflare down, or no answer at all: try again next minute.
     *
     * @param PurgeResult $result
     * @return bool
     */
    private function isTransient(PurgeResult $result): bool
    {
        return $result->isRateLimited() || $result->status === 0 || $result->status >= 500;
    }

    /**
     * @param string $kind
     * @param string $zoneId
     * @param PurgeResult $result
     * @return void
     */
    private function logFailure(string $kind, string $zoneId, PurgeResult $result): void
    {
        $this->logger->warning(sprintf(
            'Cloudflare purge by %s failed for zone %s (%s): %s',
            $kind,
            $zoneId,
            $this->isTransient($result) ? 'kept for the next run' : 'dropped',
            $result->error
        ));
    }

    /**
     * @param int $sent
     * @return void
     */
    private function logSent(int $sent): void
    {
        if ($sent > 0) {
            $this->logger->info(sprintf('Purged %d tags.', $sent));
        }
    }
}
