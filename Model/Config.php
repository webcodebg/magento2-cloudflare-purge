<?php
/**
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\CloudflarePurge\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The Cloudflare zones to purge: one per website that has the module enabled and configured.
 */
class Config
{
    private const XML_PATH_ENABLED = 'system/cloudflare_purge/enabled';
    private const XML_PATH_ZONE_ID = 'system/cloudflare_purge/zone_id';
    private const XML_PATH_API_TOKEN = 'system/cloudflare_purge/api_token';

    /**
     * @var array<string, array{zone_id: string, token: string, hosts: string[]}>|null
     */
    private ?array $zones = null;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Zones keyed by zone id, so two websites on the same zone purge it once.
     *
     * @return array<string, array{zone_id: string, token: string, hosts: string[]}>
     */
    public function getZones(): array
    {
        if ($this->zones !== null) {
            return $this->zones;
        }

        $zones = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int)$website->getId();
            $credentials = $this->getCredentials($websiteId);
            if ($credentials === null) {
                continue;
            }
            [$zoneId, $token] = $credentials;
            $zone = $zones[$zoneId] ?? ['zone_id' => $zoneId, 'token' => $token, 'hosts' => []];
            $zone['hosts'] = array_values(array_unique(array_merge($zone['hosts'], $this->getHosts($websiteId))));
            $zones[$zoneId] = $zone;
        }

        return $this->zones = $zones;
    }

    /**
     * Zone id and token of a website that has the module enabled and fully configured.
     *
     * @param int $websiteId
     * @return array{0: string, 1: string}|null
     */
    private function getCredentials(int $websiteId): ?array
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_WEBSITE, $websiteId)) {
            return null;
        }
        $zoneId = trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_ZONE_ID,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        ));
        $token = trim($this->encryptor->decrypt((string)$this->scopeConfig->getValue(
            self::XML_PATH_API_TOKEN,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        )));

        return $zoneId === '' || $token === '' ? null : [$zoneId, $token];
    }

    /**
     * Hostnames of the website's store views, from their secure and unsecure base URLs.
     *
     * @param int $websiteId
     * @return string[]
     */
    private function getHosts(int $websiteId): array
    {
        $hosts = [];
        foreach ($this->storeManager->getStores() as $store) {
            if (!$store instanceof Store || (int)$store->getWebsiteId() !== $websiteId) {
                continue;
            }
            foreach ([false, true] as $secure) {
                $host = parse_url((string)$store->getBaseUrl(UrlInterface::URL_TYPE_WEB, $secure), PHP_URL_HOST);
                if (is_string($host) && $host !== '') {
                    $hosts[] = strtolower($host);
                }
            }
        }

        return $hosts;
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->getZones() !== [];
    }
}
