# Webcode_CloudflarePurge

Purges Cloudflare's edge cache of the anonymous HTML shell whenever Magento purges Varnish.

## Why

When Cloudflare caches the anonymous HTML at the edge (a Cache Rule in front of Varnish), it
keeps that HTML for the rule's edge TTL. Magento purges only Varnish, so without this
module a price or stock change stays stale at the edge for up to that TTL.

## How

1. Varnish copies `X-Magento-Tags` into `Cache-Tag` on every edge-cacheable response (add it in
   your VCL; Cloudflare strips the header before the visitor sees it).
2. A plugin on `Magento\CacheInvalidate\Model\PurgeCache::sendPurgeRequest()` — the one call every
   Varnish invalidation goes through — queues the purged tags in `webcode_cloudflare_purge_queue`
   (deduplicated). Nothing is sent from the request that saved the product.
3. The `webcode_cloudflare_purge` cron (every minute) sends them to Cloudflare's purge-by-tag API:
   at most 4 requests × 100 tags per run. Cloudflare Free allows 5 tag/host purges per minute
   (bucket 25), **shared by every Free zone in the account**.
4. A queued flush (`cache:flush`, a cache type refresh, a theme change, a foreign ban regex) or a
   backlog over 2000 tags is purged by hostname instead: one request per zone.

A 429 or a Cloudflare outage keeps the tags for the next run; any other refusal (bad token, bad
tag) is logged and dropped, so a broken token cannot grow the queue forever. Log:
`var/log/cloudflare_purge.log`.

## Configuration

Stores → Configuration → Advanced → System → **Cloudflare Edge Cache Purge**, per website:

- Enable Cloudflare Purge
- Zone ID
- API Token — a token with *Zone → Cache Purge → Purge* for that zone only

With no website enabled the plugin queues nothing.

## Installation

Webcode's free modules are installed from our own Composer repository — they are not published on Packagist.

```bash
composer config repositories.webcode composer https://repo.webcode.bg/public
composer require webcode/module-cloudflare-purge
bin/magento module:enable Webcode_CloudflarePurge
bin/magento setup:upgrade
```

## Compatibility

Magento Open Source and Mage-OS 2.4.7–2.4.9, PHP 8.3–8.5.

## License

[OSL-3.0](LICENSE.txt) and [AFL-3.0](LICENSE_AFL.txt), like Magento itself.

## About

Made by [Webcode](https://webcode.bg/), a Magento development and support studio from Bulgaria. Module page: <https://webcode.bg/en/cloudflare-purge>.
