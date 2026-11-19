<?php
/**
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\CloudflarePurge\Test\Unit\Plugin\Magento\CacheInvalidate\Model;

use Magento\CacheInvalidate\Model\PurgeCache as Subject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webcode\CloudflarePurge\Model\Config;
use Webcode\CloudflarePurge\Model\Queue;
use Webcode\CloudflarePurge\Model\TagExtractor;
use Webcode\CloudflarePurge\Plugin\Magento\CacheInvalidate\Model\PurgeCache;

class PurgeCacheTest extends TestCase
{
    /**
     * @return void
     */
    public function testQueuesTagsAndKeepsTheResult(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->once())->method('add')->with(['cat_p_1']);

        $plugin = new PurgeCache(
            $this->config(true),
            new TagExtractor(),
            $queue,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertFalse(
            $plugin->afterSendPurgeRequest($this->createStub(Subject::class), false, ['((^|,)cat_p_1(,|$))'])
        );
    }

    /**
     * @return void
     */
    public function testQueuesNothingWhenDisabled(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->never())->method('add');

        $plugin = new PurgeCache(
            $this->config(false),
            new TagExtractor(),
            $queue,
            $this->createStub(LoggerInterface::class)
        );
        $plugin->afterSendPurgeRequest($this->createStub(Subject::class), true, ['.*']);
    }

    /**
     * @return void
     */
    public function testAQueueFailureNeverBreaksThePurge(): void
    {
        $queue = $this->createStub(Queue::class);
        $queue->method('add')->willThrowException(new \RuntimeException('table missing'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $plugin = new PurgeCache($this->config(true), new TagExtractor(), $queue, $logger);

        $this->assertTrue($plugin->afterSendPurgeRequest($this->createStub(Subject::class), true, ['.*']));
    }

    /**
     * @param bool $enabled
     * @return Config
     */
    private function config(bool $enabled): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        return $config;
    }
}
