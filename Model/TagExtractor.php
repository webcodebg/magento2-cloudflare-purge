<?php
/**
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\CloudflarePurge\Model;

/**
 * Turn the Varnish ban patterns Magento hands to PurgeCache::sendPurgeRequest() back into tags.
 *
 * Core wraps every tag as `((^|,)<tag>(,|$))` (InvalidateVarnishObserver) and flushes with `.*`
 * (FlushAllCacheObserver). Anything else -- a third-party module banning by its own regex -- cannot
 * be mapped to Cloudflare tags, so it is reported as a flush: purging too much costs a cache
 * refill, purging too little serves a stale price.
 */
class TagExtractor
{
    /**
     * The queue row that stands for "purge everything".
     */
    public const FLUSH = '*';

    private const TAG_PATTERN = '/\(\(\^\|,\)(.+?)\(,\|\$\)\)/';

    /**
     * Cloudflare caps a purge tag at 1024 bytes; the queue column holds 255.
     */
    private const MAX_TAG_LENGTH = 255;

    /**
     * @param array<mixed>|string $patterns
     * @return string[] Unique tags, or [self::FLUSH] when the patterns cannot be mapped.
     */
    public function extract(array|string $patterns): array
    {
        $tags = [];
        foreach ((array)$patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }
            if (!preg_match_all(self::TAG_PATTERN, $pattern, $matches)) {
                return [self::FLUSH];
            }
            // Whatever is left besides the wrapped tags and the `|` joining them is a regex of
            // someone else's -- not ours to translate.
            if (trim((string)preg_replace(self::TAG_PATTERN, '', $pattern), '|') !== '') {
                return [self::FLUSH];
            }
            foreach ($matches[1] as $tag) {
                if (strlen($tag) > self::MAX_TAG_LENGTH || !preg_match('/^[\x21-\x7e]+$/', $tag)
                    || str_contains($tag, ',')) {
                    return [self::FLUSH];
                }
                $tags[$tag] = true;
            }
        }

        return array_keys($tags);
    }
}
