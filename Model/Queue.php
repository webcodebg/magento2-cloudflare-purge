<?php
/**
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\CloudflarePurge\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * The deduplicating tag queue between the PURGE plugin and the cron.
 */
class Queue
{
    private const TABLE = 'webcode_cloudflare_purge_queue';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Queue tags; one already waiting is not queued twice.
     *
     * @param string[] $tags
     * @return void
     */
    public function add(array $tags): void
    {
        if ($tags === []) {
            return;
        }
        // ON DUPLICATE KEY UPDATE tag = VALUES(tag): a no-op that keeps the waiting row's
        // queue_id, i.e. its place in the queue.
        $this->connection()->insertOnDuplicate(
            $this->table(),
            array_map(static fn (string $tag): array => ['tag' => $tag], array_values($tags)),
            ['tag']
        );
    }

    /**
     * Oldest first, at most $limit.
     *
     * @param int $limit
     * @return array<int, string> queue_id => tag
     */
    public function fetch(int $limit): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table(), ['queue_id', 'tag'])
            ->order('queue_id ASC')
            ->limit($limit);

        return array_map('strval', $connection->fetchPairs($select));
    }

    /**
     * Rows waiting, the highest queue_id among them, and whether a flush is queued.
     *
     * @return array{count: int, max_id: int, flush: bool}
     */
    public function stats(): array
    {
        $connection = $this->connection();
        $row = $connection->fetchRow(
            $connection->select()->from(
                $this->table(),
                ['count' => 'COUNT(*)', 'max_id' => 'MAX(queue_id)']
            )
        );
        $flush = $connection->fetchOne(
            $connection->select()->from($this->table(), ['queue_id'])
                ->where('tag = ?', TagExtractor::FLUSH)
                ->limit(1)
        );

        return [
            'count' => (int)($row['count'] ?? 0),
            'max_id' => (int)($row['max_id'] ?? 0),
            'flush' => $flush !== false,
        ];
    }

    /**
     * @param int[] $ids
     * @return void
     */
    public function delete(array $ids): void
    {
        if ($ids !== []) {
            $this->connection()->delete($this->table(), ['queue_id IN (?)' => $ids]);
        }
    }

    /**
     * Everything up to and including $maxId; rows queued after it stay.
     *
     * @param int $maxId
     * @return void
     */
    public function deleteUpTo(int $maxId): void
    {
        $this->connection()->delete($this->table(), ['queue_id <= ?' => $maxId]);
    }

    /**
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    /**
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
