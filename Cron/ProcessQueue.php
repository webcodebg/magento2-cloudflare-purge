<?php
/**
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\CloudflarePurge\Cron;

use Webcode\CloudflarePurge\Model\Purger;

/**
 * Send the queued tags to Cloudflare.
 */
class ProcessQueue
{
    /**
     * @param Purger $purger
     */
    public function __construct(
        private readonly Purger $purger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $this->purger->execute();
    }
}
