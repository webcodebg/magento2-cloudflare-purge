<?php
/**
 * @author       Kostadin Bashev (bashev@webcode.bg)
 * @copyright Copyright © 2026 Webcode (https://webcode.bg/)
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Webcode\CloudflarePurge\Test\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webcode\CloudflarePurge\Model\TagExtractor;

class TagExtractorTest extends TestCase
{
    /**
     * @param array|string $patterns
     * @param string[] $expected
     * @return void
     */
    #[DataProvider('patterns')]
    public function testExtract(array|string $patterns, array $expected): void
    {
        $this->assertSame($expected, (new TagExtractor())->extract($patterns));
    }

    /**
     * @return array<string, array{0: array|string, 1: string[]}>
     */
    public static function patterns(): array
    {
        return [
            'core tag patterns, deduplicated' => [
                ['((^|,)cat_p_123(,|$))', '((^|,)cat_c_5(,|$))', '((^|,)cat_p_123(,|$))'],
                ['cat_p_123', 'cat_c_5'],
            ],
            'patterns already joined by PurgeCache::chunkTags()' => [
                '((^|,)cat_p_1(,|$))|((^|,)cms_b_footer-links-block(,|$))',
                ['cat_p_1', 'cms_b_footer-links-block'],
            ],
            'flush all' => [['.*'], [TagExtractor::FLUSH]],
            'flush among tags wins' => [['((^|,)cat_p_1(,|$))', '.*'], [TagExtractor::FLUSH]],
            'foreign regex is a flush' => [['cat_p_\d+'], [TagExtractor::FLUSH]],
            'tag pattern with foreign tail is a flush' => [
                ['((^|,)cat_p_1(,|$))|cat_c_.*'],
                [TagExtractor::FLUSH],
            ],
            'tag with a space is a flush' => [['((^|,)bad tag(,|$))'], [TagExtractor::FLUSH]],
            'over-long tag is a flush' => [
                ['((^|,)' . str_repeat('a', 256) . '(,|$))'],
                [TagExtractor::FLUSH],
            ],
            'nothing' => [[], []],
            'empty strings are skipped' => [['', '((^|,)store(,|$))'], ['store']],
        ];
    }
}
