<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';

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
            'shadow neon'                => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'neon', 'hover' => 'neon' ]; return $k; } ],
            'edge rest, soft hover'      => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'edge', 'hover' => 'soft' ]; return $k; } ],
            'glow rest, hover none'      => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'glow', 'hover' => 'none' ]; return $k; } ],
            'glow on hover only'         => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'none', 'hover' => 'glow' ]; return $k; } ],
            'edge on hover only'         => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'none', 'hover' => 'edge' ]; return $k; } ],
            'edge rest, glow hover'      => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'edge', 'hover' => 'glow' ]; return $k; } ],
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
            'edge rest and hover'          => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'edge', 'hover' => 'edge' ]; return $k; } ],
            'lift with an edge'            => [ static function (array $k): array { $k['hover'] = 'lift'; $k['shadow'] = [ 'rest' => 'edge', 'hover' => 'edge' ]; return $k; } ],
            'glow rest and hover'          => [ static function (array $k): array { $k['shadow'] = [ 'rest' => 'glow', 'hover' => 'glow' ]; $k['fill'] = 'outline'; return $k; } ],
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
        $this->assertSame([ 'none', 'soft', 'hard', 'edge', 'glow' ], StyleKits::SHADOWS);
        $this->assertSame([ 'none/none', 'soft/soft', 'hard/hard', 'none/soft', 'edge/edge', 'glow/glow' ], StyleKits::SHADOW_PAIRS);
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

    public function testNoShippedKitNamesAColour(): void
    {
        // A kit carries no colour (the design language supplies it), so its words must not name one either.
        $words = [ 'red', 'blue', 'green', 'neon', 'gold', 'black', 'white', 'pink', 'orange', 'purple', 'yellow', 'grey', 'gray' ];
        $pattern = '/\\b(' . implode('|', $words) . ')\\b/i';
        foreach (StyleKits::ids() as $id) {
            $kit = StyleKits::get($id);
            foreach ([ 'title', 'summary' ] as $key) {
                $this->assertDoesNotMatchRegularExpression($pattern, (string) $kit[$key], "$id.$key");
            }
        }
        $this->assertMatchesRegularExpression($pattern, 'Neon-filled buttons', 'the check catches a colour word');
    }

    /**
     * The kits built from the owner's button sheet (Task 9, docs/button-sheet-classification.md "What was built"). The
     * ids are pinned so a later edit cannot silently drop one.
     */
    private const SHEET_KITS = [
        'hairline-frame-caps', 'offset-frame', 'soft-hairline-caps', 'thick-frame-caps', 'thick-rounded-caps', 'rimmed-solid',
        'flat-square', 'block-caps', 'pill-caps', 'outline-pill', 'outline-soft', 'thick-frame', 'raised-square',
        'offset-block-caps', 'text-link', 'text-link-caps', 'underlined-label', 'pressable', 'hairline-halo',
    ];

    /** The owner's cap on the number of kits (the sheet kits included). */
    private const MAX_KITS = 50;

    public function testTheSheetKitsShipAndTheCatalogStaysWithinTheCap(): void
    {
        $ids = StyleKits::ids();
        foreach (self::SHEET_KITS as $id) {
            $this->assertContains($id, $ids, $id);
        }
        $this->assertLessThanOrEqual(self::MAX_KITS, count($ids));
    }

    /**
     * What a kit looks like, colour left out (the design language supplies colour): fill, border width, style and sides,
     * control radius, shadow rest/hover, hover, capitalization, weight, letter spacing and control padding.
     *
     * @param array<string,mixed> $kit
     */
    private static function decisionSet(array $kit): string
    {
        return implode('|', [
            $kit['fill'], $kit['border']['width'], $kit['border']['style'], $kit['border']['sides'] ?? 'all', $kit['radius']['control'],
            $kit['shadow']['rest'] . '/' . $kit['shadow']['hover'], $kit['hover'], $kit['text']['capitalization'], $kit['text']['weight'],
            $kit['text']['letterSpacing'], $kit['space']['control']['vertical'] . '/' . $kit['space']['control']['horizontal'],
        ]);
    }

    public function testNoTwoShippedKitsShareADecisionSet(): void
    {
        $seen = [];
        foreach (StyleKits::ids() as $id) {
            $set = self::decisionSet((array) StyleKits::get($id));
            $this->assertArrayNotHasKey($set, $seen, "$id repeats " . ($seen[$set] ?? '') . ' with only the colours changed');
            $seen[$set] = $id;
        }
    }

    /** Radius family of a control radius: square 0-4px, soft 6-16px, round 20-48px, pill 999px (5px and 17-19px count with their nearer family). */
    private static function radiusFamily(string $radius): string
    {
        $px = (int) $radius;
        if (999 === $px) {
            return 'pill';
        }
        return $px <= 5 ? 'square' : ($px <= 18 ? 'soft' : 'round');
    }

    /**
     * Two kits differ VISIBLY when at least one of these differs: fill; border style; border sides; border width by 2px or
     * more; radius family; shadow rest/hover; hover kind; capitalization. A transparent button (outline or ghost) has no
     * fill colour to swap, so its `invert` fills it exactly as `fill-swap` does (KitAdapters) and counts as `fill-swap`.
     * Weight, letter spacing, padding and a 1px border step are NOT enough on their own: that is a near-duplicate.
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private static function differVisibly(array $a, array $b): bool
    {
        $hover = static fn (array $k): string => ('invert' === $k['hover'] && 'solid' !== $k['fill']) ? 'fill-swap' : (string) $k['hover'];
        return $a['fill'] !== $b['fill']
            || $a['border']['style'] !== $b['border']['style']
            || ($a['border']['sides'] ?? 'all') !== ($b['border']['sides'] ?? 'all')
            || abs((int) $a['border']['width'] - (int) $b['border']['width']) >= 2
            || self::radiusFamily((string) $a['radius']['control']) !== self::radiusFamily((string) $b['radius']['control'])
            || $a['shadow']['rest'] . '/' . $a['shadow']['hover'] !== $b['shadow']['rest'] . '/' . $b['shadow']['hover']
            || $hover($a) !== $hover($b)
            || $a['text']['capitalization'] !== $b['text']['capitalization'];
    }

    /**
     * Pairs of kits shipped BEFORE Task 9 that are near-duplicates by differVisibly() (kits are never changed to pass this
     * test: language default kits and the golden file pin them). Shrink-only: a pair that stops being a near-duplicate must
     * be removed, and no new pair may be added.
     */
    private const NEAR_DUPLICATE_EXEMPTIONS = [
        'quiet-solid|hairline-mono'  => 'Task 1 and Task 4 kits (editorial and tech-mono defaults before 9a): solid square-cornered buttons, 0px vs 1px same-colour border, 4px vs 2px corners.',
        'steady-solid|soft-rounded'  => 'Task 4 kits (corporate-trust and soft-saas defaults): solid soft-cornered buttons, 0px vs 1px same-colour border, 6px vs 12px corners.',
        'soft-rounded|soft-solid'    => 'soft-solid (Task 1 fixture kit) and soft-rounded (soft-saas default): 1px same-colour border vs none, 12px vs 8px corners.',
        'steady-solid|soft-solid'    => 'steady-solid (corporate-trust default) and soft-solid (Task 1 fixture kit): 6px vs 8px corners, otherwise the same.',
    ];

    public function testAnyTwoShippedKitsDifferVisibly(): void
    {
        $kits  = [];
        foreach (StyleKits::ids() as $id) {
            $kits[$id] = (array) StyleKits::get($id);
        }
        $ids   = array_keys($kits);
        $found = [];
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                if (! self::differVisibly($kits[$ids[$i]], $kits[$ids[$j]])) {
                    $found[] = $ids[$i] . '|' . $ids[$j];
                }
            }
        }
        $this->assertSame([], array_values(array_diff($found, array_keys(self::NEAR_DUPLICATE_EXEMPTIONS))), 'near-duplicate kits (change the new kit, never the bound)');
        $this->assertLessThanOrEqual(4, count(self::NEAR_DUPLICATE_EXEMPTIONS), 'shrink-only by test: the exemption list can never grow past today\'s 4');
        $this->assertSame([], array_values(array_diff(array_keys(self::NEAR_DUPLICATE_EXEMPTIONS), $found)), 'shrink-only: an exemption that no longer applies must be removed');
        foreach (self::SHEET_KITS as $id) {
            foreach (array_keys(self::NEAR_DUPLICATE_EXEMPTIONS) as $pair) {
                $this->assertNotContains($id, explode('|', $pair), 'no exemption for a sheet kit');
            }
        }
    }

    public function testTheVisibleDifferenceRuleCatchesWhatItShould(): void
    {
        $a = self::good();
        $b = $a;
        $b['border']['width'] = '3px';      // 1px step from 2px
        $b['radius']['control'] = '12px';   // same soft family as 8px
        $b['text']['weight'] = '800';
        $b['space']['control']['vertical'] = '20px';
        $this->assertFalse(self::differVisibly($a, $b), 'a 1px border step, a radius in the same family, weight and padding are near-duplicates');
        $b['border']['width'] = '4px';
        $this->assertTrue(self::differVisibly($a, $b), 'a 2px border step is visible');
        $c = $a;
        $c['radius']['control'] = '999px';
        $this->assertTrue(self::differVisibly($a, $c));
        $o = $a;
        $o['fill'] = 'outline';
        $o['hover'] = 'invert';
        $p = $o;
        $p['hover'] = 'fill-swap';
        $this->assertFalse(self::differVisibly($o, $p), 'invert on a transparent button is a fill-swap');
        $this->assertSame('square', self::radiusFamily('4px'));
        $this->assertSame('soft', self::radiusFamily('6px'));
        $this->assertSame('soft', self::radiusFamily('16px'));
        $this->assertSame('round', self::radiusFamily('20px'));
        $this->assertSame('pill', self::radiusFamily('999px'));
    }

    private const CARD_NEAR_DUPLICATES = __DIR__ . '/../fixtures/shapes/card-near-duplicates.txt';

    /**
     * What a kit's card looks like, from the card adapter's `card` patch on a light band (the patch is the same in every
     * language: colours are role tokens, so one fixed language is the patch itself): radius and padding in px (from the
     * kit's card values the whole-value tokens resolve to), the frame (sides, width, style, colour role), the shadow and
     * the hover (frame colour and transform).
     *
     * @param array<string,mixed> $kit
     * @return array{radius:int,padding:int,frame:string,shadow:string,hover:string}
     */
    private static function cardLook(array $kit): array
    {
        $patch = KitAdapters::patch('divi/group', 'card', 'light', $kit);
        self::assertIsArray($patch);
        $d      = $patch['module']['decoration'];
        $styles = $d['border']['desktop']['value']['styles'];
        $side   = $styles['bottom'] ?? $styles['all'];
        return [
            'radius'  => (int) $kit['radius']['card'],
            'padding' => (int) $kit['space']['card'],
            'frame'   => (string) json_encode([ isset($styles['bottom']) ? 'bottom' : 'all', $side['width'], $side['style'], $side['color'] ]),
            'shadow'  => (string) json_encode($d['boxShadow'] ?? null),
            'hover'   => (string) json_encode([ $d['border']['desktop']['hover'] ?? null, $d['transform'] ?? null ]),
        ];
    }

    /** Two kits make the same card: radius < 4px apart, padding < 8px apart, frame, shadow and hover equal. */
    private static function sameCard(array $a, array $b): bool
    {
        $x = self::cardLook($a);
        $y = self::cardLook($b);
        return abs($x['radius'] - $y['radius']) < 4 && abs($x['padding'] - $y['padding']) < 8
            && $x['frame'] === $y['frame'] && $x['shadow'] === $y['shadow'] && $x['hover'] === $y['hover'];
    }

    public function testEveryKitHasAVisiblyDifferentCard(): void
    {
        $kits = [];
        foreach (StyleKits::ids() as $id) {
            $kits[$id] = (array) StyleKits::get($id);
        }
        $ids   = array_keys($kits);
        $found = [];
        $pairs = 0;
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                ++$pairs;
                if (self::sameCard($kits[$ids[$i]], $kits[$ids[$j]])) {
                    $found[] = $ids[$i] . '|' . $ids[$j];
                }
            }
        }
        $this->assertSame(780, $pairs);
        $listed = [];
        foreach ((array) file(self::CARD_NEAR_DUPLICATES, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim((string) $line);
            if ('' !== $line && '#' !== $line[0]) {
                $listed[] = $line;
            }
        }
        $this->assertSame($listed, $found, 'fixtures/shapes/card-near-duplicates.txt is exactly the set of kit pairs with the same card (remove a pair that no longer is one; a new kit must not add one)');
        $this->assertLessThanOrEqual(9, count($listed), 'shrink-only by test: the card near-duplicate list can never grow past the 9 pairs measured in Task 4');
    }

    public function testTheSameCardRuleCatchesWhatItShould(): void
    {
        $a = (array) StyleKits::get('soft-solid');
        $a['border']['width'] = '1px';      // so an underline variant is valid
        $this->assertSame([], StyleKits::problems($a));
        $b = $a;
        $b['radius']['card'] = ((int) $a['radius']['card'] + 3) . 'px';
        $b['space']['card']  = ((int) $a['space']['card'] + 7) . 'px';
        $this->assertTrue(self::sameCard($a, $b), '3px of radius and 7px of padding are the same card');
        $c = $b;
        $c['radius']['card'] = ((int) $a['radius']['card'] + 4) . 'px';
        $this->assertFalse(self::sameCard($a, $c), '4px of radius is visible');
        $d = $b;
        $d['space']['card'] = ((int) $a['space']['card'] + 8) . 'px';
        $this->assertFalse(self::sameCard($a, $d), '8px of padding is visible');
        foreach ([ [ 'border', 'style', 'dashed' ], [ 'border', 'width', '2px' ], [ 'border', 'color', 'text' ], [ 'border', 'sides', 'bottom' ] ] as [ $g, $k, $v ]) {
            $e = $a;
            $e[$g][$k] = $v;
            $this->assertSame([], StyleKits::problems($e), "$g.$k");
            $this->assertFalse(self::sameCard($a, $e), "a different frame $k is visible");
        }
        $f = $a;
        $f['hover'] = 'lift';
        $this->assertFalse(self::sameCard($a, $f), 'a lift is visible');
        $g = $a;
        $g['shadow'] = [ 'rest' => 'soft', 'hover' => 'soft' ];
        $this->assertFalse(self::sameCard($a, $g), 'a shadow is visible');
        // fill and text decisions do not reach a card: a kit differing only there is the same card.
        $h = $a;
        $h['fill'] = 'outline';
        $h['text']['capitalization'] = 'uppercase';
        $this->assertSame([], StyleKits::problems($h));
        $this->assertTrue(self::sameCard($a, $h));
    }

    /** The item near-duplicate pairs measured in accordion-adapter Task 3 (12 of 780). It may only be LOWERED, never raised. */
    private const ITEM_NEAR_DUPLICATES_CAP = 12;

    private const ITEM_NEAR_DUPLICATES = __DIR__ . '/../fixtures/shapes/item-near-duplicates.txt';

    /**
     * What a kit's item looks like, from the item adapter's `item` patch on a light band, on an accordion (radius, frame and
     * shadow) and a toggle (padding: an accordion writes none). One fixed language is the patch itself (colours are role
     * tokens). Radius and padding in px from the kit's card values the whole-value tokens resolve to; the frame is sides,
     * width, style and colour role; the shadow is the rest shadow (items have no hover).
     *
     * @param array<string,mixed> $kit
     * @return array{radius:int,padding:int,frame:string,shadow:string}
     */
    private static function itemLook(array $kit): array
    {
        $patch  = KitAdapters::patch('divi/accordion', 'item', 'light', $kit);
        $toggle = KitAdapters::patch('divi/toggle', 'item', 'light', $kit);
        self::assertIsArray($patch);
        self::assertIsArray($toggle);
        self::assertSame('{{aied:kit:radius-card}}', $patch['module']['decoration']['border']['desktop']['value']['radius']);
        self::assertSame('{{aied:kit:space-card}}', $toggle['module']['decoration']['spacing']['desktop']['value']['padding']);
        $d      = $patch['module']['decoration'];
        $styles = $d['border']['desktop']['value']['styles'];
        $side   = $styles['bottom'] ?? $styles['all'];
        return [
            'radius'  => (int) $kit['radius']['card'],
            'padding' => (int) $kit['space']['card'],
            'frame'   => (string) json_encode([ isset($styles['bottom']) ? 'bottom' : 'all', $side['width'], $side['style'], $side['color'] ]),
            'shadow'  => (string) json_encode($d['boxShadow'] ?? null),
        ];
    }

    /** Two kits make the same item: radius < 4px apart, toggle padding < 8px apart, frame and shadow equal. */
    private static function sameItem(array $a, array $b): bool
    {
        $x = self::itemLook($a);
        $y = self::itemLook($b);
        return abs($x['radius'] - $y['radius']) < 4 && abs($x['padding'] - $y['padding']) < 8
            && $x['frame'] === $y['frame'] && $x['shadow'] === $y['shadow'];
    }

    public function testEveryKitHasAVisiblyDifferentItem(): void
    {
        $kits = [];
        foreach (StyleKits::ids() as $id) {
            $kits[$id] = (array) StyleKits::get($id);
        }
        $ids   = array_keys($kits);
        $found = [];
        $pairs = 0;
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                ++$pairs;
                if (self::sameItem($kits[$ids[$i]], $kits[$ids[$j]])) {
                    $found[] = $ids[$i] . '|' . $ids[$j];
                }
            }
        }
        $this->assertSame(780, $pairs);
        $listed = [];
        foreach ((array) file(self::ITEM_NEAR_DUPLICATES, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim((string) $line);
            if ('' !== $line && '#' !== $line[0]) {
                $listed[] = $line;
            }
        }
        $this->assertSame($listed, $found, 'fixtures/shapes/item-near-duplicates.txt is exactly the set of kit pairs with the same item (remove a pair that no longer is one; a new kit must not add one)');
        $this->assertLessThanOrEqual(self::ITEM_NEAR_DUPLICATES_CAP, count($listed), 'shrink-only by test: the item near-duplicate list can never grow past the pairs measured in accordion-adapter Task 3');
    }

    public function testTheSameItemRuleCatchesWhatItShould(): void
    {
        $a = (array) StyleKits::get('soft-solid');
        $a['border']['width'] = '1px';      // so an underline variant is valid
        $this->assertSame([], StyleKits::problems($a));
        $b = $a;
        $b['radius']['card'] = ((int) $a['radius']['card'] + 3) . 'px';
        $b['space']['card']  = ((int) $a['space']['card'] + 7) . 'px';
        $this->assertTrue(self::sameItem($a, $b), '3px of radius and 7px of padding are the same item');
        $c = $b;
        $c['radius']['card'] = ((int) $a['radius']['card'] + 4) . 'px';
        $this->assertFalse(self::sameItem($a, $c), '4px of radius is visible');
        $d = $b;
        $d['space']['card'] = ((int) $a['space']['card'] + 8) . 'px';
        $this->assertFalse(self::sameItem($a, $d), '8px of toggle padding is visible');
        foreach ([ [ 'border', 'style', 'dashed' ], [ 'border', 'width', '2px' ], [ 'border', 'color', 'text' ], [ 'border', 'sides', 'bottom' ] ] as [ $g, $k, $v ]) {
            $e = $a;
            $e[$g][$k] = $v;
            $this->assertSame([], StyleKits::problems($e), "$g.$k");
            $this->assertFalse(self::sameItem($a, $e), "a different frame $k is visible");
        }
        $g = $a;
        $g['shadow'] = [ 'rest' => 'soft', 'hover' => 'soft' ];
        $this->assertFalse(self::sameItem($a, $g), 'a rest shadow is visible');
        // A hover decision never reaches an item (no hover on items): a kit differing only in hover, hover shadow, fill or
        // text is the same item.
        $h = $a;
        $h['fill']                   = 'outline';
        $h['hover']                  = 'lift';
        $h['text']['capitalization'] = 'uppercase';
        $this->assertSame([], StyleKits::problems($h));
        $this->assertTrue(self::sameItem($a, $h));
    }

    /** The form near-duplicate pairs measured in forms-adapter Task 3. It may only be LOWERED, never raised. */
    private const FORM_NEAR_DUPLICATES_CAP = 2;

    private const FORM_NEAR_DUPLICATES = __DIR__ . '/../fixtures/shapes/form-near-duplicates.txt';

    /**
     * What a kit's contact form looks like, from the form adapter's patch on a light band: the field (control radius and
     * padding in px from the kit's values the whole-value tokens resolve to; the frame is sides, width, style and colour role)
     * and the submit button, whose whole decoration (fill, border, label and its hover, spacing, shadow, lift) is compared
     * except the two whole-value tokens already counted as numbers. One fixed language is the patch itself (colours are role tokens).
     *
     * @param array<string,mixed> $kit
     * @return array{radius:int,vertical:int,horizontal:int,frame:string,submit:string}
     */
    private static function formLook(array $kit): array
    {
        $patch = KitAdapters::patch('divi/contact-form', 'form', 'light', $kit);
        self::assertIsArray($patch);
        self::assertSame('{{aied:kit:radius-control}}', $patch['field']['decoration']['border']['desktop']['value']['radius']);
        self::assertSame('{{aied:kit:space-control}}', $patch['field']['decoration']['spacing']['desktop']['value']['padding']);
        $submit = $patch['button']['decoration'];
        unset($submit['border']['desktop']['value']['radius'], $submit['spacing']['desktop']['value']['padding']);
        return [
            'radius'     => (int) $kit['radius']['control'],
            'vertical'   => (int) $kit['space']['control']['vertical'],
            'horizontal' => (int) $kit['space']['control']['horizontal'],
            'frame'      => (string) json_encode($patch['field']['decoration']['border']['desktop']['value']['styles']),
            'submit'     => (string) json_encode($submit),
        ];
    }

    /** Two kits make the same form: control radius < 4px apart, control padding < 4px apart on both axes, field frame and whole submit equal. */
    private static function sameForm(array $a, array $b): bool
    {
        $x = self::formLook($a);
        $y = self::formLook($b);
        return abs($x['radius'] - $y['radius']) < 4 && abs($x['vertical'] - $y['vertical']) < 4 && abs($x['horizontal'] - $y['horizontal']) < 4
            && $x['frame'] === $y['frame'] && $x['submit'] === $y['submit'];
    }

    public function testEveryKitHasAVisiblyDifferentForm(): void
    {
        $kits = [];
        foreach (StyleKits::ids() as $id) {
            $kits[$id] = (array) StyleKits::get($id);
        }
        $ids   = array_keys($kits);
        $found = [];
        $pairs = 0;
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                ++$pairs;
                if (self::sameForm($kits[$ids[$i]], $kits[$ids[$j]])) {
                    $found[] = $ids[$i] . '|' . $ids[$j];
                }
            }
        }
        $this->assertSame(780, $pairs);
        $listed = [];
        foreach ((array) file(self::FORM_NEAR_DUPLICATES, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim((string) $line);
            if ('' !== $line && '#' !== $line[0]) {
                $listed[] = $line;
            }
        }
        $this->assertSame($listed, $found, 'fixtures/shapes/form-near-duplicates.txt is exactly the set of kit pairs with the same form (remove a pair that no longer is one; a new kit must not add one)');
        $this->assertLessThanOrEqual(self::FORM_NEAR_DUPLICATES_CAP, count($listed), 'shrink-only by test: the form near-duplicate list can never grow past the pairs measured in forms-adapter Task 3');
    }

    public function testTheSameFormRuleCatchesWhatItShould(): void
    {
        $a = (array) StyleKits::get('soft-solid');
        $a['border']['width'] = '1px';      // so an underline variant is valid
        $this->assertSame([], StyleKits::problems($a));
        $b = $a;
        $b['radius']['control'] = ((int) $a['radius']['control'] + 3) . 'px';
        $b['space']['control']['vertical']   = ((int) $a['space']['control']['vertical'] + 3) . 'px';
        $b['space']['control']['horizontal'] = ((int) $a['space']['control']['horizontal'] + 3) . 'px';
        $this->assertSame([], StyleKits::problems($b));
        $this->assertTrue(self::sameForm($a, $b), '3px of radius and 3px of padding are the same form');
        $c = $b;
        $c['radius']['control'] = ((int) $a['radius']['control'] + 4) . 'px';
        $this->assertFalse(self::sameForm($a, $c), '4px of radius is visible');
        foreach ([ 'vertical', 'horizontal' ] as $axis) {
            $d = $b;
            $d['space']['control'][$axis] = ((int) $a['space']['control'][$axis] + 4) . 'px';
            $this->assertFalse(self::sameForm($a, $d), "4px of $axis padding is visible");
        }
        foreach ([ [ 'border', 'style', 'dashed' ], [ 'border', 'width', '2px' ], [ 'border', 'color', 'text' ], [ 'border', 'sides', 'bottom' ] ] as [ $g, $k, $v ]) {
            $e = $a;
            $e[$g][$k] = $v;
            $this->assertSame([], StyleKits::problems($e), "$g.$k");
            $this->assertFalse(self::sameForm($a, $e), "a different frame $k is visible");
        }
        // The submit is part of the form: its fill, hover, shadow and text are visible too.
        foreach ([ [ 'fill', 'outline' ], [ 'hover', 'lift' ], [ 'hover', 'invert' ], [ 'color', 'text' ] ] as [ $k, $v ]) {
            $f = $a;
            $f[$k] = $v;
            $this->assertSame([], StyleKits::problems($f), $k);
            $this->assertFalse(self::sameForm($a, $f), "a different submit $k is visible");
        }
        $g = $a;
        $g['shadow'] = [ 'rest' => 'soft', 'hover' => 'soft' ];
        $this->assertFalse(self::sameForm($a, $g), 'a rest shadow is visible');
        $h = $a;
        $h['text']['capitalization'] = 'uppercase';
        $this->assertSame([], StyleKits::problems($h));
        $this->assertFalse(self::sameForm($a, $h), 'a capitalised submit label is visible');
        // The card radius, card padding and media radius never reach a form.
        $i = $a;
        $i['radius']['card'] = '40px';
        $i['radius']['media'] = '40px';
        $i['space']['card'] = '60px';
        $this->assertSame([], StyleKits::problems($i));
        $this->assertTrue(self::sameForm($a, $i));
    }

    /**
     * What a kit's rules look like: the style and the two drawn widths the rule adapter gives it. A rule has only a style and a
     * width (its colours are role tokens, equal for every kit), so the 40 kits give a handful of distinct looks by construction
     * (Controller Ruling 9: a pair list of about 280 lines would only restate that). The look is read from the real divider patch
     * of both roles on a light band, so it includes the double floor and any style fallback.
     *
     * @param array<string,mixed> $kit
     * @return array{0:string,1:string,2:string} style, quiet width, strong width
     */
    private static function ruleLook(array $kit): array
    {
        $quiet  = KitAdapters::patch('divi/divider', 'rule', 'light', $kit);
        $strong = KitAdapters::patch('divi/divider', 'rule-strong', 'light', $kit);
        self::assertIsArray($quiet);
        self::assertIsArray($strong);
        $q = $quiet['divider']['advanced']['line']['desktop']['value'];
        $s = $strong['divider']['advanced']['line']['desktop']['value'];
        self::assertSame($q['style'], $s['style']);
        return [ (string) $q['style'], (string) $q['weight'], (string) $s['weight'] ];
    }

    /**
     * The distinct rule looks over the 40 shipped kits, pinned literally with the kits that give each: a kit that changes a
     * look (or a new kit) must change this table on purpose. Triple (style, quiet width, strong width) => kit ids.
     */
    private const RULE_LOOKS = [
        'dashed|1px|2px' => [ 'hairline-dashed' ],
        'dotted|2px|3px' => [ 'dotted-pill' ],
        'double|3px|4px' => [ 'double-frame' ],
        'solid|1px|2px'  => [
            'quiet-solid', 'glass-edge', 'warm-pill', 'hairline-mono', 'steady-solid', 'soft-rounded', 'gilt-hairline', 'soft-solid',
            'warm-pill-soft', 'soft-lift', 'ghost-swap', 'hairline-frame-caps', 'soft-hairline-caps', 'flat-square', 'block-caps',
            'pill-caps', 'raised-square', 'offset-block-caps', 'text-link', 'text-link-caps', 'pressable', 'hairline-halo',
        ],
        'solid|2px|3px'  => [ 'chunky-pill', 'outline-2', 'chunky-pill-offset', 'underline-link', 'offset-frame', 'rimmed-solid', 'outline-pill', 'outline-soft' ],
        'solid|2px|4px'  => [ 'heavy-block', 'raw-frame', 'neo-brutal', 'thick-frame-caps', 'thick-rounded-caps', 'thick-frame', 'underlined-label' ],
    ];

    /** @param array<string,array<string,mixed>> $kits id => kit @return array<string,list<string>> look key => kit ids, in the kits' order */
    private static function ruleLooks(array $kits): array
    {
        $out = [];
        foreach ($kits as $id => $kit) {
            $out[implode('|', self::ruleLook($kit))][] = (string) $id;
        }
        ksort($out);
        return $out;
    }

    public function testTheKitsGiveTheseDistinctRuleLooks(): void
    {
        $kits = [];
        foreach (StyleKits::ids() as $id) {
            $kits[$id] = (array) StyleKits::get($id);
        }
        $this->assertCount(40, $kits);
        $looks = self::ruleLooks($kits);
        $this->assertCount(6, $looks, 'six distinct rule looks over the 40 kits');
        $expected = self::RULE_LOOKS;
        ksort($expected);
        $this->assertSame(array_keys($expected), array_keys($looks), 'the set of distinct (style, quiet width, strong width) triples is pinned');
        foreach ($expected as $key => $ids) {
            $this->assertEqualsCanonicalizing($ids, $looks[$key], "the kits that give the look $key are pinned (change the pin on purpose when a kit changes its look)");
        }
        $this->assertSame(40, array_sum(array_map('count', $expected)));
    }

    public function testTheRuleLookPinCatchesWhatItShould(): void
    {
        $a = (array) StyleKits::get('soft-solid');
        $this->assertSame([ 'solid', '1px', '2px' ], self::ruleLook($a));

        // Two kits with the same look collapse to one triple: everything but the border style and width is invisible to a rule.
        $b = $a;
        $b['id']                    = 'planted-twin';
        $b['fill']                  = 'outline';
        $b['color']                 = 'text';
        $b['radius']                = [ 'control' => '30px', 'card' => '30px', 'media' => '30px' ];
        $b['shadow']                = [ 'rest' => 'soft', 'hover' => 'soft' ];
        $b['hover']                 = 'lift';
        $b['text']['capitalization'] = 'uppercase';
        $b['space']['card']         = '60px';
        $b['border']['color']       = 'text';
        $this->assertSame([], StyleKits::problems($b));
        $this->assertSame(self::ruleLook($a), self::ruleLook($b));
        $looks = self::ruleLooks([ 'soft-solid' => $a, 'planted-twin' => $b ]);
        $this->assertSame([ 'solid|1px|2px' => [ 'soft-solid', 'planted-twin' ] ], $looks, 'two kits with the same look are one triple');

        // 0px and 1px give the same rule (the 1px floor), 3px and 4px too (the caps); 2px differs from both.
        $widths = [];
        foreach ([ '0px', '1px', '2px', '3px', '4px' ] as $w) {
            $c                  = $a;
            $c['border']['width'] = $w;
            $this->assertSame([], StyleKits::problems($c), $w);
            $widths[$w] = implode('|', self::ruleLook($c));
        }
        $this->assertSame($widths['0px'], $widths['1px']);
        $this->assertSame('solid|2px|3px', $widths['2px']);
        $this->assertSame('solid|2px|4px', $widths['3px']);
        $this->assertSame('solid|2px|4px', $widths['4px']);
        $this->assertCount(3, array_unique($widths), 'five widths, three looks');

        // A different style or a double is a different look; a pin that misses a look is caught by the key comparison.
        foreach ([ 'dashed', 'dotted', 'double' ] as $style) {
            $d                    = $a;
            $d['border']['style'] = $style;
            $this->assertSame([], StyleKits::problems($d), $style);
            $this->assertNotSame(self::ruleLook($a), self::ruleLook($d), "a $style rule is another look");
        }
        // A planted kit with a look nobody ships (a 3px dotted rule) is a look outside the pinned set, so the pin would fail.
        $e                    = $a;
        $e['border']['style'] = 'dotted';
        $e['border']['width'] = '3px';
        $this->assertSame([], StyleKits::problems($e));
        $this->assertSame('dotted|2px|4px', implode('|', self::ruleLook($e)));
        $this->assertArrayNotHasKey('dotted|2px|4px', self::RULE_LOOKS);
    }

    /** The chip near-duplicate pairs measured in Task 4 of the rules and chips plan (46 of 780). It may only be LOWERED, never raised. */
    private const CHIP_NEAR_DUPLICATES_CAP = 46;

    private const CHIP_NEAR_DUPLICATES = __DIR__ . '/../fixtures/shapes/chip-near-duplicates.txt';

    /**
     * What a kit's chip looks like, from the chip adapter's patches on a light band: the control radius in px (from the kit's
     * value the whole-value token resolves to), the tag's frame (width, style, colour role of `styles.all`) and the icon chip's
     * whole patch (fill, glyph and the radius token; the same for every kit today). One fixed language is the patch itself
     * (colours are role tokens).
     *
     * @param array<string,mixed> $kit
     * @return array{radius:int,frame:string,icon:string}
     */
    private static function chipLook(array $kit): array
    {
        $tag  = KitAdapters::patch('divi/group', 'chip', 'light', $kit);
        $icon = KitAdapters::patch('divi/icon', 'chip', 'light', $kit);
        self::assertIsArray($tag);
        self::assertIsArray($icon);
        self::assertSame('{{aied:kit:radius-control}}', $tag['module']['decoration']['border']['desktop']['value']['radius']);
        return [
            'radius' => (int) $kit['radius']['control'],
            'frame'  => (string) json_encode($tag['module']['decoration']['border']['desktop']['value']['styles']),
            'icon'   => (string) json_encode($icon),
        ];
    }

    /** Two kits make the same chip: control radius < 4px apart, tag frame and icon chip equal. */
    private static function sameChip(array $a, array $b): bool
    {
        $x = self::chipLook($a);
        $y = self::chipLook($b);
        return abs($x['radius'] - $y['radius']) < 4 && $x['frame'] === $y['frame'] && $x['icon'] === $y['icon'];
    }

    public function testEveryKitHasAVisiblyDifferentChip(): void
    {
        $kits = [];
        foreach (StyleKits::ids() as $id) {
            $kits[$id] = (array) StyleKits::get($id);
        }
        $ids   = array_keys($kits);
        $found = [];
        $pairs = 0;
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                ++$pairs;
                if (self::sameChip($kits[$ids[$i]], $kits[$ids[$j]])) {
                    $found[] = $ids[$i] . '|' . $ids[$j];
                }
            }
        }
        $this->assertSame(780, $pairs);
        $listed = [];
        foreach ((array) file(self::CHIP_NEAR_DUPLICATES, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim((string) $line);
            if ('' !== $line && '#' !== $line[0]) {
                $listed[] = $line;
            }
        }
        $this->assertSame($listed, $found, 'fixtures/shapes/chip-near-duplicates.txt is exactly the set of kit pairs with the same chip (remove a pair that no longer is one; a new kit must not add one)');
        $this->assertLessThanOrEqual(self::CHIP_NEAR_DUPLICATES_CAP, count($listed), 'shrink-only by test: the chip near-duplicate list can never grow past the pairs measured in Task 4 of the rules and chips plan');
        $this->assertLessThanOrEqual(60, count($listed), 'more than 60 pairs would mean the look function is wrong');
    }

    public function testTheSameChipRuleCatchesWhatItShould(): void
    {
        $a = (array) StyleKits::get('soft-solid');
        $a['border']['width'] = '1px';
        $this->assertSame([], StyleKits::problems($a));
        $b = $a;
        $b['radius']['control'] = ((int) $a['radius']['control'] + 3) . 'px';
        $this->assertSame([], StyleKits::problems($b));
        $this->assertTrue(self::sameChip($a, $b), '3px of radius is the same chip');
        $c = $b;
        $c['radius']['control'] = ((int) $a['radius']['control'] + 4) . 'px';
        $this->assertFalse(self::sameChip($a, $c), '4px of radius is visible');
        foreach ([ [ 'border', 'style', 'dashed' ], [ 'border', 'width', '2px' ], [ 'border', 'color', 'text' ] ] as [ $g, $k, $v ]) {
            $e = $a;
            $e[$g][$k] = $v;
            $this->assertSame([], StyleKits::problems($e), "$g.$k");
            $this->assertFalse(self::sameChip($a, $e), "a different tag frame $k is visible");
        }
        // The tag frame has a 1px floor: a 0px kit border draws the same tag as a 1px one.
        $z = $a;
        $z['border']['width'] = '0px';
        $this->assertSame([], StyleKits::problems($z));
        $this->assertTrue(self::sameChip($a, $z), '0px and 1px give the same tag');
        // The underline form never reaches a tag (a tag is an outline pill).
        $u = $a;
        $u['border']['sides'] = 'bottom';
        $u['hover']           = 'none';
        $this->assertSame([], StyleKits::problems($u));
        $this->assertTrue(self::sameChip($a, $u), 'the underline form does not change a tag');
        // Fill, hover, shadow, text, card radius, media radius and spacing never reach a chip.
        $h = $a;
        $h['fill']                    = 'outline';
        $h['hover']                   = 'lift';
        $h['shadow']                  = [ 'rest' => 'soft', 'hover' => 'soft' ];
        $h['text']['capitalization']  = 'uppercase';
        $h['radius']['card']          = '40px';
        $h['radius']['media']         = '40px';
        $h['space']['card']           = '60px';
        $this->assertSame([], StyleKits::problems($h));
        $this->assertTrue(self::sameChip($a, $h));
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
