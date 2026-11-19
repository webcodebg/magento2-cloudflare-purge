<?php
/**
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\CloudflarePurge\Test\Unit\Model;

use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webcode\CloudflarePurge\Model\CloudflareClient;
use Webcode\CloudflarePurge\Model\Config;
use Webcode\CloudflarePurge\Model\Purger;
use Webcode\CloudflarePurge\Model\PurgeResult;
use Webcode\CloudflarePurge\Model\Queue;

class PurgerTest extends TestCase
{
    private const ZONE = ['zone_id' => 'z1', 'token' => 't1', 'hosts' => ['example.com']];

    private Queue&MockObject $queue;
    private Config&Stub $config;
    private CloudflareClient&MockObject $client;
    private Purger $purger;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->queue = $this->createMock(Queue::class);
        $this->config = $this->createStub(Config::class);
        $this->client = $this->createMock(CloudflareClient::class);
        $lock = $this->createStub(LockManagerInterface::class);
        $lock->method('lock')->willReturn(true);
        $this->purger = $this->purger($lock);
    }

    /**
     * @param LockManagerInterface $lock
     * @return Purger
     */
    private function purger(LockManagerInterface $lock): Purger
    {
        return new Purger(
            $this->queue,
            $this->config,
            $this->client,
            $lock,
            $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * @return void
     */
    public function testSkipsWhenAnotherRunHoldsTheLock(): void
    {
        $lock = $this->createMock(LockManagerInterface::class);
        $lock->method('lock')->willReturn(false);
        $lock->expects($this->never())->method('unlock');
        $this->queue->expects($this->never())->method('stats');
        $this->client->expects($this->never())->method('purgeTags');

        $this->purger($lock)->execute();
    }

    /**
     * @return void
     */
    public function testDrainsTheQueueWhenNoZoneIsEnabled(): void
    {
        $lock = $this->createMock(LockManagerInterface::class);
        $lock->method('lock')->willReturn(true);
        $lock->expects($this->once())->method('unlock');
        $this->queue->method('stats')->willReturn(['count' => 3, 'max_id' => 42, 'flush' => false]);
        $this->config->method('getZones')->willReturn([]);
        $this->client->expects($this->never())->method('purgeTags');
        $this->queue->expects($this->once())->method('deleteUpTo')->with(42);

        $this->purger($lock)->execute();
    }

    /**
     * @return void
     */
    public function testFlushPurgesHostsAndClearsUpToTheSnapshot(): void
    {
        $this->queue->method('stats')->willReturn(['count' => 2, 'max_id' => 7, 'flush' => true]);
        $this->config->method('getZones')->willReturn(['z1' => self::ZONE]);
        $this->client->expects($this->once())->method('purgeHosts')
            ->with('z1', 't1', ['example.com'])
            ->willReturn(new PurgeResult(true, 200));
        $this->client->expects($this->never())->method('purgeTags');
        $this->queue->expects($this->once())->method('deleteUpTo')->with(7);

        $this->purger->execute();
    }

    /**
     * @return void
     */
    public function testBacklogFallsBackToHosts(): void
    {
        $this->queue->method('stats')
            ->willReturn(['count' => Purger::BACKLOG_LIMIT + 1, 'max_id' => 9000, 'flush' => false]);
        $this->config->method('getZones')->willReturn(['z1' => self::ZONE]);
        $this->client->expects($this->once())->method('purgeHosts')->willReturn(new PurgeResult(true, 200));
        $this->queue->expects($this->once())->method('deleteUpTo')->with(9000);

        $this->purger->execute();
    }

    /**
     * @return void
     */
    public function testTransientHostFailureKeepsTheQueue(): void
    {
        $this->queue->method('stats')->willReturn(['count' => 1, 'max_id' => 7, 'flush' => true]);
        $this->config->method('getZones')->willReturn(['z1' => self::ZONE]);
        $this->client->expects($this->once())->method('purgeHosts')->willReturn(new PurgeResult(false, 503, 'down'));
        $this->queue->expects($this->never())->method('deleteUpTo');

        $this->purger->execute();
    }

    /**
     * @return void
     */
    public function testTagsGoInChunksOfOneHundredWithinTheBudget(): void
    {
        $rows = [];
        for ($id = 1; $id <= 400; $id++) {
            $rows[$id] = 'cat_p_' . $id;
        }
        $this->queue->method('stats')->willReturn(['count' => 400, 'max_id' => 400, 'flush' => false]);
        $this->config->method('getZones')->willReturn(['z1' => self::ZONE]);
        $this->queue->expects($this->once())->method('fetch')
            ->with(Purger::REQUESTS_PER_RUN * CloudflareClient::MAX_ITEMS_PER_REQUEST)
            ->willReturn($rows);
        $this->client->expects($this->exactly(4))->method('purgeTags')
            ->willReturnCallback(function (string $zone, string $token, array $tags): PurgeResult {
                $this->assertCount(100, $tags);
                return new PurgeResult(true, 200);
            });
        $deleted = [];
        $this->queue->method('delete')->willReturnCallback(function (array $ids) use (&$deleted): void {
            $deleted = array_merge($deleted, $ids);
        });

        $this->purger->execute();

        $this->assertSame(range(1, 400), $deleted);
    }

    /**
     * @return void
     */
    public function testRateLimitStopsTheRunAndKeepsTheChunk(): void
    {
        $this->queue->method('stats')->willReturn(['count' => 150, 'max_id' => 150, 'flush' => false]);
        $this->config->method('getZones')->willReturn(['z1' => self::ZONE]);
        $rows = [];
        for ($id = 1; $id <= 150; $id++) {
            $rows[$id] = 'cat_p_' . $id;
        }
        $this->queue->method('fetch')->willReturn($rows);
        $this->client->expects($this->exactly(2))->method('purgeTags')
            ->willReturnOnConsecutiveCalls(new PurgeResult(true, 200), new PurgeResult(false, 429, 'slow down'));
        $this->queue->expects($this->once())->method('delete')->with(range(1, 100));

        $this->purger->execute();
    }

    /**
     * @return void
     */
    public function testPermanentRefusalDropsTheChunk(): void
    {
        $this->queue->method('stats')->willReturn(['count' => 1, 'max_id' => 5, 'flush' => false]);
        $this->config->method('getZones')->willReturn(['z1' => self::ZONE]);
        $this->queue->method('fetch')->willReturn([5 => 'cat_p_1']);
        $this->client->expects($this->once())->method('purgeTags')
            ->willReturn(new PurgeResult(false, 403, '10000 Authentication error'));
        $this->queue->expects($this->once())->method('delete')->with([5]);

        $this->purger->execute();
    }

    /**
     * @return void
     */
    public function testBudgetIsSharedAcrossZones(): void
    {
        $this->queue->method('stats')->willReturn(['count' => 10, 'max_id' => 10, 'flush' => false]);
        $this->config->method('getZones')->willReturn([
            'z1' => self::ZONE,
            'z2' => ['zone_id' => 'z2', 'token' => 't2', 'hosts' => ['herbtm.com']],
        ]);
        $this->queue->expects($this->once())->method('fetch')
            ->with(2 * CloudflareClient::MAX_ITEMS_PER_REQUEST)
            ->willReturn([1 => 'cat_p_1']);
        $this->client->expects($this->exactly(2))->method('purgeTags')->willReturn(new PurgeResult(true, 200));

        $this->purger->execute();
    }
}
