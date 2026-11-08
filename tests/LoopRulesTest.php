<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Loop Builder (module.advanced.loop) and dynamic-content tokens. Every refused shape below was rendered on the real
 * Divi 5.15.1 (docs/loop-builder-evidence-5.15.1.md): either the whole page answered HTTP 500, or the loop silently
 * switched off / read every post type. The builder's own shapes (fixtures/valid/loop-builder-5.3.json) must stay valid.
 */
final class LoopRulesTest extends TestCase
{
    private static function page(array $loop, string $headingText = 'Title'): string
    {
        $group = ['module' => ['advanced' => ['loop' => ['desktop' => ['value' => $loop]]]], 'builderVersion' => '5.8.0'];
        $head  = ['title' => ['innerContent' => ['desktop' => ['value' => $headingText]]], 'builderVersion' => '5.8.0'];
        $j     = static fn(array $a): string => (string) json_encode($a, JSON_UNESCAPED_SLASHES);

        return '<!-- wp:divi/section {"builderVersion":"5.8.0"} --><!-- wp:divi/row {"builderVersion":"5.8.0"} --><!-- wp:divi/column {"builderVersion":"5.8.0"} -->'
            . '<!-- wp:divi/group ' . $j($group) . ' --><!-- wp:divi/heading ' . $j($head) . ' /--><!-- /wp:divi/group -->'
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
    }

    /** @return string[] violation codes */
    private static function codes(string $content): array
    {
        return array_map(static fn($v) => $v->code(), (new Validator())->validateContent($content)->violations());
    }

    private static function good(): array
    {
        return [
            'enable' => 'on', 'loopId' => 'loop-spike0001', 'queryType' => 'post_types',
            'subTypes' => [['value' => 'post', 'label' => 'Posts']], 'orderBy' => 'date', 'order' => 'descending',
            'postPerPage' => '3', 'postOffset' => '0',
        ];
    }

    public function testTheBuildersOwnExportIsValid(): void
    {
        $fx = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/valid/loop-builder-5.3.json'), true);
        $this->assertSame([], self::codes((string) $fx['post_content']));
    }

    public function testAGoodLoopIsValid(): void
    {
        $this->assertSame([], self::codes(self::page(self::good())));
        // what the builder writes for the empty lists, and an off loop
        $builder = array_merge(self::good(), ['excludePostWithSpecificTerms' => '', 'includeSpecificPosts' => '', 'excludeSpecificPosts' => '']);
        $this->assertSame([], self::codes(self::page($builder)));
        $this->assertSame([], self::codes(self::page(['enable' => 'off', 'loopId' => '', 'queryType' => 'post_types'])));
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function badSettings(): iterable
    {
        $g = self::good();
        yield 'enable true'         => [array_merge($g, ['enable' => true])];
        yield 'enable yes'          => [array_merge($g, ['enable' => 'yes'])];
        yield 'enable array'        => [array_merge($g, ['enable' => ['on']])];
        yield 'loopId array (500)'  => [array_merge($g, ['loopId' => ['x']])];
        yield 'loopId int'          => [array_merge($g, ['loopId' => 5])];
        yield 'queryType unknown'   => [array_merge($g, ['queryType' => 'products'])];
        yield 'queryType array'     => [array_merge($g, ['queryType' => ['post_types']])];
        yield 'order array (500)'   => [array_merge($g, ['order' => ['descending']])];
        yield 'order desc'          => [array_merge($g, ['order' => 'desc'])];
        yield 'order true'          => [array_merge($g, ['order' => true])];
        yield 'orderBy array'       => [array_merge($g, ['orderBy' => ['date']])];
        yield 'perPage zero'        => [array_merge($g, ['postPerPage' => '0'])];
        yield 'perPage text'        => [array_merge($g, ['postPerPage' => 'abc'])];
        yield 'perPage negative'    => [array_merge($g, ['postPerPage' => '-1'])];
        yield 'perPage array'       => [array_merge($g, ['postPerPage' => [3]])];
        yield 'offset text'         => [array_merge($g, ['postOffset' => 'x'])];
        yield 'excludeCurrent true' => [array_merge($g, ['excludeCurrentPost' => true])];
        yield 'sticky array'        => [array_merge($g, ['ignoreStickysPost' => ['on']])];
        yield 'subTypes string'     => [array_merge($g, ['subTypes' => 'post'])];
        yield 'subTypes strings'    => [array_merge($g, ['subTypes' => ['post']])];
        yield 'subTypes object'     => [array_merge($g, ['subTypes' => ['value' => 'post', 'label' => 'Posts']])];
        yield 'subTypes value int'  => [array_merge($g, ['subTypes' => [['value' => 5, 'label' => 'x']]])];
        yield 'subTypes value list' => [array_merge($g, ['subTypes' => [['value' => ['post'], 'label' => 'x']]])];
        yield 'metaQuery string (500)' => [array_merge($g, ['metaQuery' => 'x'])];
        yield 'metaQuery empty string (500)' => [array_merge($g, ['metaQuery' => ''])];
        yield 'metaQuery object'    => [array_merge($g, ['metaQuery' => ['metaKey' => 'x']])];
    }

    /** @param array<string,mixed> $loop */
    #[\PHPUnit\Framework\Attributes\DataProvider('badSettings')]
    public function testAWrongSettingIsRefused(array $loop): void
    {
        $this->assertContains(Validator::E_INVALID_LOOP_SETTING, self::codes(self::page($loop)));
    }

    public function testAMetaQueryListIsValid(): void
    {
        $mq = [['id' => 'mq1', 'metaKey' => '_thumbnail_id', 'metaValue' => '', 'compare' => 'EXISTS', 'type' => 'CHAR']];
        $this->assertSame([], self::codes(self::page(array_merge(self::good(), ['metaQuery' => $mq]))));
        $this->assertSame([], self::codes(self::page(array_merge(self::good(), ['metaQuery' => []]))));
    }

    public function testALoopThatIsNotAnObjectIsRefused(): void
    {
        foreach (['on', ['enable' => 'on'], ['desktop' => ['enable' => 'on']], ['desktop' => ['value' => 'on']]] as $loop) {
            $group   = ['module' => ['advanced' => ['loop' => $loop]], 'builderVersion' => '5.8.0'];
            $content = '<!-- wp:divi/section {"builderVersion":"5.8.0"} --><!-- wp:divi/row {"builderVersion":"5.8.0"} --><!-- wp:divi/column {"builderVersion":"5.8.0"} -->'
                . '<!-- wp:divi/group ' . json_encode($group) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
            $this->assertContains(Validator::E_INVALID_LOOP_SETTING, self::codes($content), json_encode($loop));
        }
    }

    public function testATokenNeedsBothEnds(): void
    {
        $ok = '$variable({"type":"content","value":{"name":"loop_post_title","settings":{}}})$';
        $this->assertSame([], self::codes(self::page(self::good(), $ok)));
        $this->assertSame([], self::codes(self::page(self::good(), '<p>' . $ok . ' and ' . $ok . '</p>')));
        $bad = '$variable({"type":"content","value":{"name":"loop_post_title","settings":{}}})';
        $this->assertContains(Validator::E_MALFORMED_DYNAMIC_TOKEN, self::codes(self::page(self::good(), $bad)));
        $this->assertContains(Validator::E_MALFORMED_DYNAMIC_TOKEN, self::codes(self::page(self::good(), '<p>' . $ok . ' ' . $bad . '</p>')));
    }
}
