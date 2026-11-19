<?php
/**
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\CloudflarePurge\Model;

use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * POST /zones/{zone}/purge_cache -- by tags or by hostnames.
 *
 * @see https://developers.cloudflare.com/api/resources/cache/methods/purge/
 */
class CloudflareClient
{
    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/zones/%s/purge_cache';

    /**
     * Cloudflare's cap on tags / hosts in one purge request, on every plan.
     */
    public const MAX_ITEMS_PER_REQUEST = 100;

    private const TIMEOUT = 10;

    /**
     * @param ClientFactory $clientFactory
     * @param Json $json
     */
    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly Json $json
    ) {
    }

    /**
     * @param string $zoneId
     * @param string $token
     * @param string[] $tags At most MAX_ITEMS_PER_REQUEST.
     * @return PurgeResult
     */
    public function purgeTags(string $zoneId, string $token, array $tags): PurgeResult
    {
        return $this->purge($zoneId, $token, ['tags' => array_values($tags)]);
    }

    /**
     * @param string $zoneId
     * @param string $token
     * @param string[] $hosts At most MAX_ITEMS_PER_REQUEST.
     * @return PurgeResult
     */
    public function purgeHosts(string $zoneId, string $token, array $hosts): PurgeResult
    {
        return $this->purge($zoneId, $token, ['hosts' => array_values($hosts)]);
    }

    /**
     * @param string $zoneId
     * @param string $token
     * @param array<string, string[]> $body
     * @return PurgeResult
     */
    private function purge(string $zoneId, string $token, array $body): PurgeResult
    {
        $curl = $this->clientFactory->create();
        $curl->setTimeout(self::TIMEOUT);
        $curl->setHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ]);

        try {
            $curl->post(sprintf(self::ENDPOINT, rawurlencode($zoneId)), (string)$this->json->serialize($body));
        } catch (\Exception $e) {
            return new PurgeResult(false, 0, $e->getMessage());
        }

        $status = $curl->getStatus();
        $response = $this->decode((string)$curl->getBody());
        if ($status === 200 && ($response['success'] ?? false) === true) {
            return new PurgeResult(true, $status);
        }

        $message = $this->errorMessage($response);

        return new PurgeResult(false, $status, $message !== '' ? $message : 'HTTP ' . $status);
    }

    /**
     * The response body as an array; anything that is not a JSON object is an empty one.
     *
     * @param string $body
     * @return array<mixed>
     */
    private function decode(string $body): array
    {
        try {
            $response = $this->json->unserialize($body !== '' ? $body : '{}');
        } catch (\InvalidArgumentException) {
            return [];
        }

        return is_array($response) ? $response : [];
    }

    /**
     * Cloudflare's `errors` list as "<code> <message>; ...".
     *
     * @param array<mixed> $response
     * @return string
     */
    private function errorMessage(array $response): string
    {
        $errors = $response['errors'] ?? [];

        return implode('; ', array_map(
            static fn ($error): string => is_array($error)
                ? ($error['code'] ?? '?') . ' ' . ($error['message'] ?? '')
                : (string)$error,
            is_array($errors) ? $errors : []
        ));
    }
}
