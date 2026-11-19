<?php
/**
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\CloudflarePurge\Plugin\Magento\CacheInvalidate\Model;

use Magento\CacheInvalidate\Model\PurgeCache as Subject;
use Psr\Log\LoggerInterface;
use Webcode\CloudflarePurge\Model\Config;
use Webcode\CloudflarePurge\Model\Queue;
use Webcode\CloudflarePurge\Model\TagExtractor;

/**
 * Queue every tag Magento purges in Varnish for the Cloudflare purge cron.
 *
 * sendPurgeRequest() is the one call every Varnish invalidation goes through -- tag purges
 * from saves, sync and reindex, and the `.*` flush -- so hooking it follows Varnish exactly.
 * Queued, not sent: a save must not wait on Cloudflare, and Cloudflare's per-minute limit
 * needs the deduplication a queue gives.
 */
class PurgeCache
{
    /**
     * @param Config $config
     * @param TagExtractor $tagExtractor
     * @param Queue $queue
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly TagExtractor $tagExtractor,
        private readonly Queue $queue,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Queue the purged tags; never let a failure here break the purge or the save behind it.
     *
     * @param Subject $subject
     * @param bool $result
     * @param array<mixed>|string $tags
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSendPurgeRequest(Subject $subject, $result, $tags)
    {
        try {
            if ($this->config->isEnabled()) {
                $this->queue->add($this->tagExtractor->extract($tags));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Could not queue tags for Cloudflare: ' . $e->getMessage());
        }

        return $result;
    }
}
