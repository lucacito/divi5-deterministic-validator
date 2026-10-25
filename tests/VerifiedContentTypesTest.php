<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\SchemaRules;
use Divi5Validator\Tools\RenderFatalPromoter;
use Divi5Validator\Validator;
use Divi5Validator\VerifiedContentTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * src/VerifiedContentTypes.php is generated from the render-fatal sweep (fixtures/shapes/render-fatals.json,
 * docs/render-fatal-sweep.md) by scripts/promote-render-fatals.php. Every live-proven fatal of the three directions
 * must be refused with the right code, and the same block with the right type must not be.
 */
final class VerifiedContentTypesTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** @return array<string,mixed> */
    private static function evidence(): array
    {
        return json_decode((string) file_get_contents(self::ROOT . '/' . RenderFatalPromoter::EVIDENCE), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheCommittedFileIsWhatTheGeneratorWritesFromTheEvidence(): void
    {
        $this->assertSame(
            RenderFatalPromoter::render(RenderFatalPromoter::promote(self::evidence())),
            file_get_contents(self::ROOT . '/src/VerifiedContentTypes.php'),
            'src/VerifiedContentTypes.php is stale or hand-edited: run php scripts/promote-render-fatals.php'
        );
        $this->assertSame(file_get_contents(self::ROOT . '/src/VerifiedContentTypes.php'), file_get_contents(self::ROOT . '/wp-plugin/validator/VerifiedContentTypes.php'));
    }

    public function testTheTablesHoldExactlyTheLiveFatalsOfTheEvidence(): void
    {
        $object = 0;
        $scalar = [];
        foreach (self::evidence()['entries'] as $e) {
            if ($e['result'] !== 'fatal') {
                continue;
            }
            if ($e['direction'] === 'scalar-where-object') {
                ++$object;
                $this->assertContains($e['key'], VerifiedContentTypes::OBJECT_KEYS[$e['module']] ?? [], "{$e['module']} {$e['key']}");
            } else {
                $scalar[$e['module'] . ' ' . $e['key']] = true;
                $this->assertContains($e['key'], VerifiedContentTypes::SCALAR_KEYS[$e['module']] ?? [], "{$e['module']} {$e['key']}");
            }
        }
        $count = fn (array $m): int => array_sum(array_map('count', $m));
        $this->assertSame($object, $count(VerifiedContentTypes::OBJECT_KEYS), 'nothing but the live fatals');
        $this->assertSame(count($scalar), $count(VerifiedContentTypes::SCALAR_KEYS), 'nothing but the live fatals');
        // Pinned counts of the Divi 5.15.1 run (docs/render-fatal-sweep.md): 21 string fatals, 59 object fatals (the 12
        // empty-array fatals are all on keys that also fail as an object).
        $this->assertSame([21, 15, 59, 32], [$count(VerifiedContentTypes::OBJECT_KEYS), count(VerifiedContentTypes::OBJECT_KEYS), $count(VerifiedContentTypes::SCALAR_KEYS), count(VerifiedContentTypes::SCALAR_KEYS)]);
        $this->assertSame('5.15.1', VerifiedContentTypes::DIVI_VERSION);
        $this->assertSame('Divi 5.15.1, 2026-10-25', VerifiedContentTypes::CALIBRATED_ON);
    }

    public function testAKeyThatRenderedWithoutAThrowIsNotGuarded(): void
    {
        // The ruling: nothing is guarded on Divi's definitions alone. Each of these was swept and rendered fine.
        $this->assertNotContains('number', VerifiedContentTypes::SCALAR_KEYS['divi/circle-counter'] ?? []);
        $this->assertNotContains('email', VerifiedContentTypes::SCALAR_KEYS['divi/contact-form'] ?? []);
        $this->assertArrayNotHasKey('divi/code', VerifiedContentTypes::SCALAR_KEYS, 'code content as an object is a warning, the page still answers 200');
        $this->assertArrayNotHasKey('divi/button', VerifiedContentTypes::OBJECT_KEYS, 'a string button renders no button but does not throw (the old table still guards it)');
    }

    public function testTheGeneratorRefusesUntrustworthyEvidence(): void
    {
        $good = self::evidence();
        $cases = [
            'uncalibrated'   => array_replace($good, ['calibration' => ['clean' => 'ok', 'company_string' => 'ok', 'clean_again' => 'ok']]),
            'no version'     => array_replace($good, ['divi_version' => '']),
            'no entries'     => array_replace($good, ['entries' => []]),
            'dirty baseline' => array_replace($good, ['entries' => [['module' => 'divi/text', 'key' => 'content', 'shape' => 'scalar', 'direction' => 'object-where-scalar', 'result' => 'fatal', 'baseline_after' => 'dirty']]]),
            'bad name'       => array_replace($good, ['entries' => [['module' => 'divi/x\'', 'key' => 'content', 'shape' => 'scalar', 'direction' => 'object-where-scalar', 'result' => 'fatal', 'baseline_after' => 'clean']]]),
            'both ways'      => array_replace($good, ['entries' => [
                ['module' => 'divi/text', 'key' => 'content', 'shape' => 'scalar', 'direction' => 'object-where-scalar', 'result' => 'fatal', 'baseline_after' => 'clean'],
                ['module' => 'divi/text', 'key' => 'content', 'shape' => 'object', 'direction' => 'scalar-where-object', 'result' => 'fatal', 'baseline_after' => 'clean'],
            ]]),
            'array on object' => array_replace($good, ['entries' => [['module' => 'divi/image', 'key' => 'image', 'shape' => 'object', 'direction' => 'array-value', 'result' => 'fatal', 'baseline_after' => 'clean']]]),
        ];
        foreach ($cases as $name => $evidence) {
            try {
                RenderFatalPromoter::promote($evidence);
                $this->fail("$name was promoted");
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{string,string,string,mixed,mixed,string}> module, key, direction, wrong value, right value, code */
    public static function fatalProvider(): iterable
    {
        foreach (self::evidence()['entries'] as $e) {
            if ($e['result'] !== 'fatal') {
                continue;
            }
            $label = "{$e['module']} {$e['key']} {$e['direction']}";
            yield $label => match ($e['direction']) {
                'scalar-where-object' => [$e['module'], $e['key'], $e['direction'], 'sweep-x', ['text' => 'sweep-x'], Validator::E_SCALAR_WHERE_OBJECT],
                'object-where-scalar' => [$e['module'], $e['key'], $e['direction'], ['text' => 'sweep-x'], 'sweep-x', Validator::E_OBJECT_WHERE_SCALAR],
                'array-value'         => [$e['module'], $e['key'], $e['direction'], [], 'sweep-x', Validator::E_OBJECT_WHERE_SCALAR],
            };
        }
    }

    #[DataProvider('fatalProvider')]
    public function testEveryLiveFatalIsRefusedAndTheRightTypeIsNot(string $module, string $key, string $direction, mixed $wrong, mixed $right, string $code): void
    {
        $rules = new SchemaRules();
        if (!$rules->isKnownType($module)) {
            $this->markTestSkipped("$module is not a block type the validator knows: the entry is kept but harmless");
        }
        $v      = new Validator();
        $suffix = ".$key.innerContent.desktop.value";

        $bad  = $v->validateContent(self::layout($rules, $module, $key, $wrong))->violations();
        $hits = array_values(array_filter($bad, fn ($x) => $x->code() === $code && str_ends_with($x->path(), $module . $suffix)));
        $this->assertCount(1, $hits, "$module $key ($direction) is refused once with $code; got " . implode('; ', array_map(fn ($x) => $x->code() . ' ' . $x->path(), $bad)));
        $this->assertStringContainsString('fatal on render', $hits[0]->message());

        $good = $v->validateContent(self::layout($rules, $module, $key, $right))->violations();
        $guards = [Validator::E_SCALAR_WHERE_OBJECT, Validator::E_OBJECT_WHERE_SCALAR, Validator::E_UNEXPECTED_CHILD_TYPE, Validator::E_WRONG_NESTING, Validator::E_UNKNOWN_MODULE_TYPE, Validator::E_MISSING_REQUIRED_FIELD];
        $left   = array_values(array_filter($good, fn ($x) => in_array($x->code(), $guards, true)));
        $this->assertSame([], array_map(fn ($x) => $x->code() . ' ' . $x->path(), $left), "$module $key with the right type");
    }

    /** placeholder > section > row > column > [parent >] module, with only the one key set (the sweep's mutation). */
    private static function layout(SchemaRules $rules, string $module, string $key, mixed $value): string
    {
        $ver   = VerifiedContentTypes::DIVI_VERSION;
        $plain = json_encode(['builderVersion' => $ver]);
        $attrs = json_encode([$key => ['innerContent' => ['desktop' => ['value' => $value]]], 'builderVersion' => $ver], JSON_UNESCAPED_SLASHES);
        $block = "<!-- wp:$module $attrs /-->";
        if (!in_array($module, $rules->allowedChildrenOf('divi/column'), true)) {
            $parent = self::parentOf($rules, $module);
            $block  = "<!-- wp:$parent $plain -->$block<!-- /wp:$parent -->";
        }
        $column = json_encode(['module' => ['advanced' => ['type' => ['desktop' => ['value' => '4_4']]]], 'builderVersion' => $ver]);

        return "<!-- wp:divi/placeholder --><!-- wp:divi/section $plain --><!-- wp:divi/row $plain --><!-- wp:divi/column $column -->"
            . $block . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    /** The first (sorted) parent that may sit in a column and holds the module: a child item is only valid inside it. */
    private static function parentOf(SchemaRules $rules, string $module): string
    {
        $parents = array_keys(SchemaRules::ALLOWED_CHILDREN);
        sort($parents);
        foreach ($parents as $p) {
            if (in_array($p, $rules->allowedChildrenOf('divi/column'), true) && in_array($module, $rules->allowedChildrenOf($p), true)) {
                return $p;
            }
        }
        throw new \LogicException("$module has no parent that may sit in a column");
    }

    public function testTheNewCodeIsUniqueAndDocumented(): void
    {
        $codes = array_values((new \ReflectionClass(Validator::class))->getConstants());
        $this->assertSame(count($codes), count(array_unique($codes)), 'violation codes are unique');
        $this->assertSame('OBJECT_WHERE_SCALAR', Validator::E_OBJECT_WHERE_SCALAR);
        foreach (['/docs/SCHEMA.md', '/README.md'] as $doc) {
            $this->assertStringContainsString('OBJECT_WHERE_SCALAR', (string) file_get_contents(self::ROOT . $doc), "$doc names the code");
        }
        $this->assertStringContainsString('scripts/promote-render-fatals.php', (string) file_get_contents(self::ROOT . '/docs/SCHEMA.md'));
    }

    public function testThePluginLoadsEveryBundledValidatorFile(): void
    {
        // The plugin loads the validator only through this list, so a missing file passes the unit suite and fatals on a site.
        $autoload = (string) file_get_contents(self::ROOT . '/wp-plugin/src/autoload.php');
        $this->assertSame(1, preg_match("/foreach \\( \\[([^\\]]+)\\] as \\\$cls \\)/", $autoload, $m));
        $listed = array_map(fn ($s) => trim($s, " '"), explode(',', $m[1]));
        foreach (glob(self::ROOT . '/wp-plugin/validator/*.php') ?: [] as $file) {
            if (basename($file) !== 'index.php') {
                $this->assertContains(basename($file, '.php'), $listed, basename($file) . ' is loaded by wp-plugin/src/autoload.php');
            }
        }
        $this->assertLessThan(array_search('SchemaRules', $listed, true), array_search('VerifiedContentTypes', $listed, true), 'loaded before the classes that read it');
    }
}
