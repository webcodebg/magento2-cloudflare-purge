<?php
/**
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\CloudflarePurge\Model;

/**
 * Outcome of one Cloudflare purge request.
 */
class PurgeResult
{
    /**
     * @param bool $success
     * @param int $status HTTP status; 0 when the request never got an answer.
     * @param string $error
     */
    public function __construct(
        public readonly bool $success,
        public readonly int $status,
        public readonly string $error = ''
    ) {
    }

    /**
     * Cloudflare said "slow down": the rest of this run's budget is gone.
     *
     * @return bool
     */
    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }
}
