<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';

/**
 * Style kits are decision tables (never markup): the schema is strict, the file stays small,
 * and the loader is as lenient as DesignLanguages (corrupt text means no kits).
 */
final class StyleKitsTest extends TestCase
{
    private const FILE = __DIR__ . '/../wp-plugin/data/style-kits.json';

    /** @return array<string,mixed> */
    private static function good(): array
    {
        return [
            'id'      => 'test-kit',
            'title'   => 'Test kit',
            'summary' => 'A kit used by the tests.',
            'avoids'  => [],
            'fill'    => 'solid',
            'color'   => 'accent',
            'border'  => [ 'width' => '2px', 'style' => 'solid', 'color' => 'text' ],
            'radius'  => [ 'control' => '8px', 'card' => '12px', 'media' => '999px' ],
            'shadow'  => [ 'rest' => 'none', 'hover' => 'none' ],
            'hover'   => 'fill-swap',
            'text'    => [ 'capitalization' => 'uppercase', 'letterSpacing' => '1px', 'weight' => '600' ],
            'space'   => [ 'control' => [ 'vertical' => '12px', 'horizontal' => '24px' ], 'card' => '20px' ],
        ];
    }

    protected function setUp(): void
    {
        StyleKits::clearCache();
    }

    public function testTheGoodFixtureIsValid(): void
    {
        $this->assertSame([], StyleKits::problems(self::good()));
    }

    public function testEveryShippedKitIsValid(): void
    {
        $ids = StyleKits::ids();
        $this->assertNotSame([], $ids);
        $this->assertSame($ids, array_values(array_unique($ids)), 'ids are unique');
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id);
            $kit = StyleKits::get($id);
            $this->assertIsArray($kit);
            $this->assertSame([], StyleKits::problems($kit), $id);
        }
        $this->assertContains('soft-solid', $ids);
        $this->assertContains('outline-2', $ids);
        $this->assertNull(StyleKits::get('no-such-kit'));
    }

    /** @return array<string,array{0:callable}> */
    public static function badKits(): array
    {
        return [
            'hex colour in a title'      => [ static function (array $k): array { $k['title'] = 'Kit #ff0000'; return $k; } ],
            'short hex colour'           => [ static function (array $k): array { $k['summary'] = 'Uses #fff for text.'; return $k; } ],
            'less-than'                  => [ static function (array $k): array { $k['summary'] = 'a < b'; return $k; } ],
            'greater-than'               => [ static function (array $k): array { $k['summary'] = '<b>bold</b>'; return $k; } ],
            'ampersand'                  => [ static function (array $k): array { $k['summary'] = 'one & two'; return $k; } ],
            'double hyphen'              => [ static function (array $k): array { $k['summary'] = 'one -- two'; return $k; } ],
            'double quote'               => [ static function (array $k): array { $k['summary'] = 'say "hi"'; return $k; } ],
            'single quote'               => [ static function (array $k): array { $k['title'] = "Kit's"; return $k; } ],
            'ten kilobyte string'        => [ static function (array $k): array { $k['summary'] = str_repeat('a', 10240); return $k; } ],
            'summary over 160'           => [ static function (array $k): array { $k['summary'] = str_repeat('a', 161); return $k; } ],
            'number for a string'        => [ static function (array $k): array { $k['title'] = 5; return $k; } ],
            'number for width'           => [ static function (array $k): array { $k['border']['width'] = 2; return $k; } ],
            'unknown fill'               => [ static function (array $k): array { $k['fill'] = 'gradient'; return $k; } ],
            'unknown colour role'        => [ static function (array $k): array { $k['color'] = 'purple'; return $k; } ],
            'radius minus one'           => [ static function (array $k): array { $k['radius']['control'] = '-1px'; return $k; } ],
            'radius 49'                  => [ static function (array $k): array { $k['radius']['card'] = '49px'; return $k; } ],
            'radius em'                  => [ static function (array $k): array { $k['radius']['media'] = '1em'; return $k; } ],
            'unknown extra key'          => [ static function (array $k): array { $k['css'] = 'x'; return $k; } ],
            'unknown nested key'         => [ static function (array $k): array { $k['border']['extra'] = 'x'; return $k; } ],
            'missing border'             => [ static function (array $k): array { unset($k['border']); return $k; } ],
            'missing id'                 => [ static function (array $k): array { unset($k['id']); return $k; } ],
            'id not kebab-case'          => [ static function (array $k): array { $k['id'] = 'Test_Kit'; return $k; } ],
            'border width 5px'           => [ static function (array $k): array { $k['border']['width'] = '5px'; return $k; } ],
            'border style groove'        => [ static function (array $k): array { $k['border']['style'] = 'groove'; return $k; } ],
            'border style none'          => [ static function (array $k): array { $k['border']['style'] = 'none'; return $k; } ],
            'border colour unknown'      => [ static function (array $k): array { $k['border']['color'] = 'red'; return $k; } ],
            'shadow not a value'         => [ static function (array $k): array { $k['shadow']['rest'] = '0 4px 8px'; return $k; } ],
            'shadow glow'                => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'glow', 'hover' => 'glow' ]; return $k; } ],
            'hover shadow hard over none' => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'none', 'hover' => 'hard' ]; return $k; } ],
            'rest soft, hover none'      => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'soft', 'hover' => 'none' ]; return $k; } ],
            'rest hard, hover soft'      => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'hard', 'hover' => 'soft' ]; return $k; } ],
            'rest soft, hover hard'      => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'soft', 'hover' => 'hard' ]; return $k; } ],
            'shadow rest missing'        => [ static function (array $k): array { unset($k['shadow']['rest']); return $k; } ],
            'hover darken'               => [ static function (array $k): array { $k['hover'] = 'darken'; return $k; } ],
            'hover scale'                => [ static function (array $k): array { $k['hover'] = 'scale'; return $k; } ],
            'border sides top'           => [ static function (array $k): array { $k['border']['sides'] = 'top'; return $k; } ],
            'border sides left'          => [ static function (array $k): array { $k['border']['sides'] = 'left'; return $k; } ],
            'border sides not a string'  => [ static function (array $k): array { $k['border']['sides'] = [ 'bottom' ]; return $k; } ],
            'border sides upper case'    => [ static function (array $k): array { $k['border']['sides'] = 'Bottom'; return $k; } ],
            'underline with fill-swap'   => [ static function (array $k): array { $k['border']['sides'] = 'bottom'; $k['hover'] = 'fill-swap'; return $k; } ],
            'underline with invert'      => [ static function (array $k): array { $k['border']['sides'] = 'bottom'; $k['hover'] = 'invert'; return $k; } ],
            'underline of 0px'           => [ static function (array $k): array { $k['border']['sides'] = 'bottom'; $k['hover'] = 'none'; $k['border']['width'] = '0px'; return $k; } ],
            'underline on a ghost'       => [ static function (array $k): array { $k['border']['sides'] = 'bottom'; $k['hover'] = 'none'; $k['fill'] = 'ghost'; return $k; } ],
            'gradient fill (no kit can pass contrast)' => [ static function (array $k): array { $k['fill'] = 'gradient'; $k['color2'] = 'text'; return $k; } ],
            'color2 key'                 => [ static function (array $k): array { $k['color2'] = 'text'; return $k; } ],
            'direction key'              => [ static function (array $k): array { $k['direction'] = '90deg'; return $k; } ],
            'capitalization unknown'     => [ static function (array $k): array { $k['text']['capitalization'] = 'lowercase'; return $k; } ],
            'letter spacing 5px'         => [ static function (array $k): array { $k['text']['letterSpacing'] = '5px'; return $k; } ],
            'weight 900'                 => [ static function (array $k): array { $k['text']['weight'] = '900'; return $k; } ],
            'weight 399'                 => [ static function (array $k): array { $k['text']['weight'] = '399'; return $k; } ],
            'space not px'               => [ static function (array $k): array { $k['space']['card'] = '2rem'; return $k; } ],
            'space missing horizontal'   => [ static function (array $k): array { unset($k['space']['control']['horizontal']); return $k; } ],
            'avoids not a list'          => [ static function (array $k): array { $k['avoids'] = 'split'; return $k; } ],
            'avoids unknown layout'      => [ static function (array $k): array { $k['avoids'] = [ 'not-a-layout' ]; return $k; } ],
            'greater-than alone'         => [ static function (array $k): array { $k['summary'] = 'a > b'; return $k; } ],
            'backtick'                   => [ static function (array $k): array { $k['summary'] = 'use `code` here'; return $k; } ],
            'newline in a summary'       => [ static function (array $k): array { $k['summary'] = "one\n# Injected"; return $k; } ],
            'carriage return in a title' => [ static function (array $k): array { $k['title'] = "Kit\rtwo"; return $k; } ],
            'tab in a title'             => [ static function (array $k): array { $k['title'] = "Kit\ttwo"; return $k; } ],
            'nul byte in a summary'      => [ static function (array $k): array { $k['summary'] = "one\0two"; return $k; } ],
            'delete character'           => [ static function (array $k): array { $k['summary'] = "one\x7ftwo"; return $k; } ],
            'opening brace'              => [ static function (array $k): array { $k['summary'] = 'a {{aied:color:accent}} token'; return $k; } ],
            'closing brace alone'        => [ static function (array $k): array { $k['title'] = 'Kit }'; return $k; } ],
            'brace in an avoids-free id' => [ static function (array $k): array { $k['id'] = 'kit{x}'; return $k; } ],
            'shadow hover not none'      => [ static function (array $k): array { $k['shadow']['hover'] = '0 8px 16px'; return $k; } ],
            'space above 120px'          => [ static function (array $k): array { $k['space']['card'] = '121px'; return $k; } ],
            'space control vertical bad' => [ static function (array $k): array { $k['space']['control']['vertical'] = '12'; return $k; } ],
            'space control vertical big' => [ static function (array $k): array { $k['space']['control']['vertical'] = '200px'; return $k; } ],
            'radius 100px'               => [ static function (array $k): array { $k['radius']['control'] = '100px'; return $k; } ],
            'radius 1000px'              => [ static function (array $k): array { $k['radius']['control'] = '1000px'; return $k; } ],
            'non-string weight'          => [ static function (array $k): array { $k['text']['weight'] = 600; return $k; } ],
        ];
    }

    #[DataProvider('badKits')]
    public function testProblemsRejectsWhatItShould(callable $mutate): void
    {
        $this->assertNotSame([], StyleKits::problems($mutate(self::good())));
    }

    public function testEveryProvenBorderStyleIsAccepted(): void
    {
        foreach ([ 'solid', 'dashed', 'dotted', 'double' ] as $style) {
            $kit = self::good();
            $kit['border']['style'] = $style;
            $this->assertSame([], StyleKits::problems($kit), $style);
        }
        // Every border style a shipped kit uses is one of them.
        foreach (StyleKits::ids() as $id) {
            $this->assertContains(StyleKits::get($id)['border']['style'], StyleKits::BORDER_STYLES, $id);
        }
    }

    /** @return array<string,array{0:callable}> */
    public static function goodVariants(): array
    {
        return [
            'soft rest and hover'          => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'soft', 'hover' => 'soft' ]; return $k; } ],
            'hard rest and hover'          => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'hard', 'hover' => 'hard' ]; return $k; } ],
            'soft on hover only'           => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'none', 'hover' => 'soft' ]; return $k; } ],
            'lift'                         => [ static function (array $k): array { $k['hover'] = 'lift'; return $k; } ],
            'lift with a hover shadow'     => [ static function (array $k): array { $k['hover'] = 'lift'; $k['shadow'] = [ 'rest' => 'none', 'hover' => 'soft' ]; return $k; } ],
            'lift with a hard shadow'      => [ static function (array $k): array { $k['hover'] = 'lift'; $k['shadow'] = [ 'rest' => 'hard', 'hover' => 'hard' ]; return $k; } ],
            'sides all'                    => [ static function (array $k): array { $k['border']['sides'] = 'all'; return $k; } ],
            'underline, hover none'        => [ static function (array $k): array { $k['border']['sides'] = 'bottom'; $k['hover'] = 'none'; $k['fill'] = 'outline'; return $k; } ],
            'underline, lift'              => [ static function (array $k): array { $k['border']['sides'] = 'bottom'; $k['hover'] = 'lift'; return $k; } ],
            'dashed'                       => [ static function (array $k): array { $k['border']['style'] = 'dashed'; return $k; } ],
        ];
    }

    #[DataProvider('goodVariants')]
    public function testTheNewOptionalDecisionsAreAccepted(callable $mutate): void
    {
        $this->assertSame([], StyleKits::problems($mutate(self::good())));
    }

    public function testControlCharactersAndBracesAreRejectedEverywhereWithTheirPath(): void
    {
        $kit = self::good();
        $kit['summary'] = "line\nbreak";
        $this->assertContains('summary must be plain text of at most 160 characters (no markup, colour, quote, &, --, brace, backtick or control character)', StyleKits::problems($kit));
        $kit = self::good();
        $kit['border']['color'] = "text\n";
        $problems = StyleKits::problems($kit);
        $this->assertContains('border.color must be plain text of at most 160 characters (no markup, colour, quote, &, --, brace, backtick or control character)', $problems);
        // Ordinary punctuation a summary needs stays allowed.
        $kit = self::good();
        $kit['summary'] = 'Filled, rounded (8px) buttons: calm; quiet.';
        $this->assertSame([], StyleKits::problems($kit));
    }

    public function testAvoidsIsOptional(): void
    {
        $kit = self::good();
        unset($kit['avoids']);
        $this->assertSame([], StyleKits::problems($kit));
        $kit['avoids'] = [ \AiEditorDivi5\WP\SectionRecipes::LAYOUTS[0] ];
        $this->assertSame([], StyleKits::problems($kit));
    }

    public function testTheVocabularyConstantsAreTheOnlyAllowedValues(): void
    {
        $this->assertSame([ 'solid', 'dashed', 'dotted', 'double' ], StyleKits::BORDER_STYLES);
        $this->assertSame([ 'fill-swap', 'invert', 'none', 'lift' ], StyleKits::HOVERS);
        $this->assertSame([ 'none', 'soft', 'hard' ], StyleKits::SHADOWS);
        $this->assertSame([ 'all', 'bottom' ], StyleKits::BORDER_SIDES);
        $this->assertSame([ 'solid', 'outline', 'ghost' ], StyleKits::FILLS, 'no gradient fill: no role pair reads in every language');
    }

    public function testTheFileStaysSmall(): void
    {
        $this->assertLessThanOrEqual(120 * 1024, filesize(self::FILE));
        foreach (StyleKits::parse((string) file_get_contents(self::FILE)) as $kit) {
            $this->assertLessThanOrEqual(1536, strlen((string) json_encode($kit)), (string) $kit['id']);
            array_walk_recursive($kit, function ($v, $k): void {
                if (is_string($v)) {
                    $this->assertDoesNotMatchRegularExpression('/[<>&"\'`{}\x00-\x1F\x7F]|--|#[0-9a-fA-F]{3,8}\b/', $v, (string) $k);
                }
            });
        }
    }

    public function testParseOfCorruptTextIsEmpty(): void
    {
        $this->assertSame([], StyleKits::parse(''));
        $this->assertSame([], StyleKits::parse('not json {'));
        $this->assertSame([], StyleKits::parse('"a string"'));
        $this->assertSame([], StyleKits::parse('[1, "x", {"title": "no id"}, {"id": 5}]'));
        $this->assertCount(1, StyleKits::parse('[1, {"id": "ok"}]'));
    }

    public function testValueTokenForeignNameIsNull(): void
    {
        $kit = self::good();
        foreach ([ 'radius-other', 'space', 'color', '', 'Radius-Control', 'radius-control ' ] as $name) {
            $this->assertNull(StyleKits::valueToken($kit, $name), $name);
        }
    }

    public function testValueTokenRadiusAndSpaceShapes(): void
    {
        $kit = self::good();
        $this->assertSame(
            [ 'topLeft' => '8px', 'topRight' => '8px', 'bottomRight' => '8px', 'bottomLeft' => '8px' ],
            StyleKits::valueToken($kit, 'radius-control')
        );
        $this->assertSame(
            [ 'topLeft' => '12px', 'topRight' => '12px', 'bottomRight' => '12px', 'bottomLeft' => '12px' ],
            StyleKits::valueToken($kit, 'radius-card')
        );
        $this->assertSame(
            [ 'topLeft' => '999px', 'topRight' => '999px', 'bottomRight' => '999px', 'bottomLeft' => '999px' ],
            StyleKits::valueToken($kit, 'radius-media')
        );
        $this->assertSame(
            [ 'top' => '12px', 'right' => '24px', 'bottom' => '12px', 'left' => '24px' ],
            StyleKits::valueToken($kit, 'space-control')
        );
        $this->assertSame(
            [ 'top' => '20px', 'right' => '20px', 'bottom' => '20px', 'left' => '20px' ],
            StyleKits::valueToken($kit, 'space-card')
        );
    }

    public function testMarkdownListsEveryKitOnce(): void
    {
        $md = StyleKits::markdown();
        foreach (StyleKits::ids() as $id) {
            $this->assertSame(1, substr_count($md, '`' . $id . '`'), $id);
        }
        $kit = self::good();
        $kit['id'] = 'x';
        $kit['border']['sides'] = 'bottom';
        $kit['hover'] = 'lift';
        $kit['shadow'] = [ 'rest' => 'hard', 'hover' => 'hard' ];
        $this->assertSame([], StyleKits::problems($kit));
        $one = StyleKits::markdown('outline-2');
        $this->assertStringContainsString('outline-2', $one);
        $this->assertStringContainsString('2px', $one);
        $this->assertStringNotContainsString('soft-solid', $one);
        $unknown = StyleKits::markdown('nope');
        $this->assertStringContainsString('Unknown style kit', $unknown);
        $this->assertStringContainsString('soft-solid', $unknown);
    }
}
