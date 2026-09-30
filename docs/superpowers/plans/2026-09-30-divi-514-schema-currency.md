# Divi 5.14 Schema Currency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the validator accept every Divi 5.14 module that is verified to render, without ever guessing, and ship it as AI Editor 3.4.0.

**Architecture:** A pure-PHP tooling layer (`tools/`, namespace `Divi5Validator\Tools`) derives module proposals from Divi's shipped `module.json` files, builds minimal block markup, and classifies render evidence. A harness renders each proposal on the real Divi 5.14 (Docker WP) and records `pass | fail | needs-real-export`. A promoter turns only `pass` results into a generated `src/VerifiedModules.php` that `SchemaRules` spreads into its constants. The validator stays pure and deterministic; the tooling never ships in the plugin zip.

**Tech Stack:** PHP 8.1+, PHPUnit 11, bash, WP-CLI in the Docker env (`docker compose`), Divi 5.14.0 (`divi/Divi.zip`).

**Spec:** `docs/superpowers/specs/2026-09-30-divi-514-schema-currency-design.md`

## Global Constraints

- The validator is deterministic: no AI/LLM calls, no network, no randomness in `src/`.
- No Divi schema from memory: every block type added to the schema must have a `pass` record in `docs/module-verification-5.14.json`.
- `src/` is canonical; `wp-plugin/validator/` must be an exact mirror (enforced by a test in Task 4).
- `tools/`, `scripts/`, `build/` and `docs/` never ship in `ai-editor-for-divi-5.zip` (only `wp-plugin/` does).
- Plugin text domain and slug: `ai-editor-for-divi-5`; REST namespace, menu slug and MCP server name stay `ai-editor-divi5`.
- PHP `>=8.1`; PHPUnit runs with `failOnWarning` and `failOnRisky` (no deprecations, no risky tests).
- `divi/Divi.zip` is commercial and gitignored: never commit it or anything extracted from it (`build/` is gitignored).
- Commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- `make test` exit 0 is the gate after every task.

## Review Focus

1. A `module.json` with a missing or non-string `name` must abort derivation loudly, not be skipped (Task 1 test).
2. A module that renders with a PHP notice/warning must be `fail`, never `pass` (Task 2 test).
3. A child-only module (e.g. a slider item) placed alone in a column must still be rejected (Task 4 test).
4. A module whose optional plugin is absent renders nothing recognisable and must be `needs-real-export`, never `pass` (Task 2 test).
5. A Divi version string that is empty, garbage, or only a patch bump above the tested version must not trigger a warning notice (Task 6 test).

---

### Task 1: Derive proposals and report the schema gap

**Files:**
- Create: `tools/ModuleDeriver.php`
- Create: `tools/SchemaGap.php`
- Create: `scripts/derive-schema.php`
- Create: `scripts/schema-gap.sh`
- Create: `tests/Tools/ModuleDeriverTest.php`
- Modify: `composer.json` (autoload-dev)
- Modify: `Makefile` (new `schema-gap` target, `.PHONY`)
- Modify: `.gitignore` (add `build/`)

**Interfaces:**
- Produces: `ModuleDeriver::derive(array $defs): array` — input: map of relative path => decoded `module.json` array; output: list of proposals sorted by name, each `['name'=>string,'category'=>?string,'children'=>list<string>,'isChild'=>bool]`. Throws `\UnexpectedValueException` on an entry without a string `divi/*` name.
- Produces: `SchemaGap::missing(array $proposals, SchemaRules $rules): array` — list of proposal arrays whose `name` is not `isKnownType`.
- Produces: `SchemaGap::unmatched(array $proposals, SchemaRules $rules): array` — sorted list of type names the validator knows that no proposal declares.
- Produces CLI: `php scripts/derive-schema.php <packages-dir> [--json=<file>] [--candidates=<file>] [--report]`.

- [ ] **Step 1: Write the failing test** — create `tests/Tools/ModuleDeriverTest.php`

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Tools;

use Divi5Validator\SchemaRules;
use Divi5Validator\Tools\ModuleDeriver;
use Divi5Validator\Tools\SchemaGap;
use PHPUnit\Framework\TestCase;

class ModuleDeriverTest extends TestCase
{
    public function testDerivesNameCategoryAndChildren(): void
    {
        $proposals = ModuleDeriver::derive([
            'b/module.json' => ['name' => 'divi/video-slider', 'category' => 'module',
                'childModuleName' => 'divi/video-slider-item', 'childrenName' => ['divi/video-slider-item']],
            'a/module.json' => ['name' => 'divi/video-slider-item', 'category' => 'child-module'],
            'c/module.json' => ['name' => 'divi/portfolio', 'category' => 'module', 'childrenName' => []],
        ]);

        $this->assertSame(['divi/portfolio', 'divi/video-slider', 'divi/video-slider-item'], array_column($proposals, 'name'));
        $byName = array_column($proposals, null, 'name');
        $this->assertSame(['divi/video-slider-item'], $byName['divi/video-slider']['children']);
        $this->assertFalse($byName['divi/video-slider']['isChild']);
        $this->assertTrue($byName['divi/video-slider-item']['isChild']);
        $this->assertSame([], $byName['divi/portfolio']['children']);
    }

    public function testMissingCategoryIsNullNotAnError(): void
    {
        $proposals = ModuleDeriver::derive(['x/module.json' => ['name' => 'divi/thing']]);
        $this->assertNull($proposals[0]['category']);
    }

    public function testEntryWithoutValidNameAbortsLoudly(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('bad/module.json');
        ModuleDeriver::derive(['bad/module.json' => ['title' => 'no name here']]);
    }

    public function testNonDiviNameAbortsLoudly(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ModuleDeriver::derive(['bad/module.json' => ['name' => 'other/heading']]);
    }

    public function testGapSplitsKnownFromMissing(): void
    {
        $proposals = ModuleDeriver::derive([
            'a/module.json' => ['name' => 'divi/heading', 'category' => 'module'],
            'b/module.json' => ['name' => 'divi/zzz-brand-new', 'category' => 'module'],
        ]);
        $missing = SchemaGap::missing($proposals, new SchemaRules());
        $this->assertSame(['divi/zzz-brand-new'], array_column($missing, 'name'));
    }

    public function testUnmatchedListsKnownTypesWithNoDefinition(): void
    {
        $proposals = ModuleDeriver::derive(['a/module.json' => ['name' => 'divi/heading', 'category' => 'module']]);
        $unmatched = SchemaGap::unmatched($proposals, new SchemaRules());
        $this->assertContains('divi/placeholder', $unmatched);
        $this->assertNotContains('divi/heading', $unmatched);
    }
}
```

- [ ] **Step 2: Run it, expect FAIL** (classes missing)

Run: `vendor/bin/phpunit tests/Tools/ModuleDeriverTest.php`
Expected: error `Class "Divi5Validator\Tools\ModuleDeriver" not found`.

- [ ] **Step 3: Wire autoload and write the implementation**

In `composer.json`, change `autoload-dev` to:

```json
    "autoload-dev": {
        "psr-4": {
            "Divi5Validator\\Tests\\": "tests/",
            "Divi5Validator\\Tools\\": "tools/"
        }
    },
```

Create `tools/ModuleDeriver.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tools;

/**
 * Turns Divi's shipped module.json definitions into schema proposals.
 * Pure: takes already-decoded arrays, performs no I/O. Fails loudly on a shape
 * it does not understand rather than guessing.
 */
final class ModuleDeriver
{
    /**
     * @param array<string, mixed> $defs relative path => decoded module.json
     * @return list<array{name:string, category:?string, children:list<string>, isChild:bool}>
     */
    public static function derive(array $defs): array
    {
        $proposals = [];
        foreach ($defs as $path => $def) {
            if (!is_array($def) || !isset($def['name']) || !is_string($def['name']) || !str_starts_with($def['name'], 'divi/')) {
                throw new \UnexpectedValueException("module.json at {$path} has no valid divi/* name");
            }

            $children = [];
            if (isset($def['childrenName'])) {
                if (!is_array($def['childrenName'])) {
                    throw new \UnexpectedValueException("module.json at {$path} has a non-array childrenName");
                }
                foreach ($def['childrenName'] as $child) {
                    if (is_string($child)) {
                        $children[] = $child;
                    }
                }
            }
            if (isset($def['childModuleName']) && is_string($def['childModuleName'])) {
                $children[] = $def['childModuleName'];
            }

            $category = isset($def['category']) && is_string($def['category']) ? $def['category'] : null;

            $proposals[$def['name']] = [
                'name'     => $def['name'],
                'category' => $category,
                'children' => array_values(array_unique($children)),
                'isChild'  => $category === 'child-module',
            ];
        }

        ksort($proposals);

        return array_values($proposals);
    }
}
```

Create `tools/SchemaGap.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tools;

use Divi5Validator\SchemaRules;

/** Compares Divi's declared modules with what the validator knows. */
final class SchemaGap
{
    /**
     * @param list<array{name:string}> $proposals
     * @return list<array{name:string}> proposals the validator does not recognise
     */
    public static function missing(array $proposals, SchemaRules $rules): array
    {
        return array_values(array_filter($proposals, fn (array $p): bool => !$rules->isKnownType($p['name'])));
    }

    /**
     * @param list<array{name:string}> $proposals
     * @return list<string> types the validator knows that no module.json declares
     */
    public static function unmatched(array $proposals, SchemaRules $rules): array
    {
        $declared = array_column($proposals, 'name');
        $known    = array_merge(SchemaRules::STRUCTURAL_BLOCKS, SchemaRules::LEAF_MODULES);
        $out      = array_values(array_diff($known, $declared));
        sort($out);

        return $out;
    }
}
```

Create `scripts/derive-schema.php`:

```php
#!/usr/bin/env php
<?php
// Usage: php scripts/derive-schema.php <packages-dir> [--json=all.json] [--candidates=todo.json] [--report]
// <packages-dir> is Divi/includes/builder-5/visual-builder/packages extracted from Divi.zip.

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Divi5Validator\SchemaRules;
use Divi5Validator\Tools\ModuleDeriver;
use Divi5Validator\Tools\SchemaGap;

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Usage: php scripts/derive-schema.php <packages-dir> [--json=f] [--candidates=f] [--report]\n");
    exit(2);
}
$opts = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

$defs = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getFilename() === 'module.json') {
        $decoded = json_decode((string) file_get_contents($file->getPathname()), true);
        $defs[substr($file->getPathname(), strlen($dir) + 1)] = $decoded;
    }
}
ksort($defs);

$proposals = ModuleDeriver::derive($defs);
$rules     = new SchemaRules();
$missing   = SchemaGap::missing($proposals, $rules);

// Candidates the harness should try: missing, placeable on their own (not child items),
// and of a category that lives in a column/section.
$candidates = array_values(array_filter(
    $missing,
    fn (array $p): bool => !$p['isChild'] && in_array($p['category'], ['module', 'fullwidth-module'], true)
));
// Children referenced by a candidate travel with it; keep their own proposals for the promoter.
$all = $proposals;

if (isset($opts['json']) && is_string($opts['json'])) {
    file_put_contents($opts['json'], json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
if (isset($opts['candidates']) && is_string($opts['candidates'])) {
    file_put_contents($opts['candidates'], json_encode($candidates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

if (isset($opts['report'])) {
    printf("Divi declares %d modules; validator is missing %d (%d placeable candidates).\n", count($proposals), count($missing), count($candidates));
    foreach ($missing as $p) {
        printf("  MISSING  %-46s %s%s\n", $p['name'], $p['category'] ?? '?', $p['isChild'] ? ' (child item)' : '');
    }
    $unmatched = SchemaGap::unmatched($proposals, $rules);
    printf("Known to the validator but with no module.json (wrappers / references): %s\n", $unmatched === [] ? 'none' : implode(', ', $unmatched));
}
```

Create `scripts/schema-gap.sh`:

```bash
#!/usr/bin/env bash
# schema-gap.sh — compare the modules Divi ships with what the validator knows.
# Reads divi/Divi.zip (never committed). Writes build/proposals.json + build/candidates.json.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

ZIP="divi/Divi.zip"
[ -f "$ZIP" ] || { echo "[schema-gap] $ZIP not found (place Divi.zip there first)." >&2; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
PKG="Divi/includes/builder-5/visual-builder/packages"

unzip -q "$ZIP" "$PKG/*module.json" Divi/style.css -d "$TMP"
VERSION="$(grep -m1 -E '^[[:space:]]*Version:' "$TMP/Divi/style.css" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')"
echo "[schema-gap] Divi version in $ZIP: $VERSION"

mkdir -p build
echo "$VERSION" > build/divi-version.txt
php scripts/derive-schema.php "$TMP/$PKG" --json=build/proposals.json --candidates=build/candidates.json --report
```

Add to `Makefile` (and append `schema-gap` to the `.PHONY` line):

```make
# ---------------------------------------------------------------
# schema-gap — which Divi modules does the validator not know yet?
# ---------------------------------------------------------------
schema-gap:
	@bash scripts/schema-gap.sh
```

Add to `.gitignore`:

```
# Generated tooling output (proposals, extracted Divi files)
build/
```

- [ ] **Step 4: Run test, expect PASS; run the real gap report**

Run: `composer dump-autoload -q && vendor/bin/phpunit tests/Tools/ModuleDeriverTest.php`
Expected: `OK (6 tests, ...)`.

Run: `make schema-gap`
Expected: `Divi version in divi/Divi.zip: 5.14.0` and a report like `Divi declares 115 modules; validator is missing 58 ...`. The missing list must include `divi/portfolio` and `divi/lottie`; the unmatched line must include `divi/placeholder`.

- [ ] **Step 5: Full suite and commit**

Run: `make test` — Expected: exit 0.

```bash
git add tools scripts/derive-schema.php scripts/schema-gap.sh tests/Tools composer.json Makefile .gitignore
git commit -m "feat(tools): derive module proposals from Divi module.json + make schema-gap

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Markup builder and render-evidence classifier

**Files:**
- Create: `tools/MarkupBuilder.php`
- Create: `tools/RenderEvidence.php`
- Create: `tests/Tools/MarkupBuilderTest.php`
- Create: `tests/Tools/RenderEvidenceTest.php`

**Interfaces:**
- Produces: `MarkupBuilder::page(string $module, string $placement, ?string $child = null, string $version = '5.14.0'): string` — full `post_content` (placeholder → section → [row → column →] module, with one optional child). Placements: `MarkupBuilder::PLACEMENT_COLUMN = 'column'`, `MarkupBuilder::PLACEMENT_SECTION = 'section'`.
- Produces: `RenderEvidence::classify(string $html, string $noise, string $module): array{status:string, reasons:list<string>}` where status is `pass | fail | needs-real-export`.
- Produces: `RenderEvidence::markers(string $module): list<string>` — module-specific substrings (each containing the full module name), any one of which proves the module rendered.

- [ ] **Step 1: Write the failing tests**

`tests/Tools/MarkupBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Tools;

use Divi5Validator\Tools\MarkupBuilder;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

class MarkupBuilderTest extends TestCase
{
    public function testColumnPlacementProducesValidMarkupForAKnownLeaf(): void
    {
        $result = (new Validator())->validateContent(MarkupBuilder::page('divi/shop', MarkupBuilder::PLACEMENT_COLUMN));
        $this->assertTrue($result->isValid(), $this->dump($result));
    }

    public function testParentWithChildIsValidForKnownCompoundModules(): void
    {
        foreach ([['divi/accordion', 'divi/accordion-item'], ['divi/tabs', 'divi/tab']] as [$parent, $child]) {
            $result = (new Validator())->validateContent(MarkupBuilder::page($parent, MarkupBuilder::PLACEMENT_COLUMN, $child));
            $this->assertTrue($result->isValid(), "$parent: " . $this->dump($result));
        }
    }

    public function testSectionPlacementPutsTheModuleDirectlyInTheSection(): void
    {
        $markup = MarkupBuilder::page('divi/shop', MarkupBuilder::PLACEMENT_SECTION);
        $this->assertStringNotContainsString('wp:divi/column', $markup);
        $this->assertStringContainsString('wp:divi/shop', $markup);
        // The builder is placement-neutral: the validator (not the builder) rejects this today.
        $this->assertFalse((new Validator())->validateContent($markup)->isValid());
    }

    public function testVersionIsStampedOnEveryBlock(): void
    {
        $markup = MarkupBuilder::page('divi/shop', MarkupBuilder::PLACEMENT_COLUMN, null, '9.9.9');
        $this->assertSame(0, preg_match('/5\.14\.0/', $markup));
        $this->assertGreaterThanOrEqual(4, substr_count($markup, '"builderVersion":"9.9.9"'));
    }

    public function testUnknownPlacementThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MarkupBuilder::page('divi/shop', 'footer');
    }

    private function dump(\Divi5Validator\ValidationResult $r): string
    {
        return implode('; ', array_map(fn ($v) => $v->code() . ': ' . $v->message(), $r->violations()));
    }
}
```

`tests/Tools/RenderEvidenceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Tools;

use Divi5Validator\Tools\RenderEvidence;
use PHPUnit\Framework\TestCase;

class RenderEvidenceTest extends TestCase
{
    public function testRecognisedMarkupIsAPass(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_module et_pb_video_slider_0">x</div>', '', 'divi/video-slider');
        $this->assertSame('pass', $r['status']);
    }

    public function testPhpNoticeInOutputIsAFailNotAPass(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_video_slider_0">Notice: Undefined index</div>', '', 'divi/video-slider');
        $this->assertSame('fail', $r['status']);
    }

    public function testPhpErrorCapturedOutsideTheHtmlIsAFail(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_video_slider_0"></div>', 'PHP Fatal error: boom', 'divi/video-slider');
        $this->assertSame('fail', $r['status']);
    }

    public function testEmptyOutputNeedsARealExport(): void
    {
        $this->assertSame('needs-real-export', RenderEvidence::classify('', '', 'divi/gravity-forms')['status']);
    }

    public function testOutputWithoutTheModuleMarkerNeedsARealExport(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_section">nothing from our module</div>', '', 'divi/gravity-forms');
        $this->assertSame('needs-real-export', $r['status']);
    }

    public function testMarkersAreModuleSpecific(): void
    {
        $m = RenderEvidence::markers('divi/video-slider');
        $this->assertContains('et_pb_video_slider', $m);
        foreach ($m as $marker) {
            $this->assertStringContainsString('video_slider', str_replace('-', '_', $marker), 'every marker must contain the full module name');
        }
    }

    public function testGenericWordsInOutputDoNotProveAModule(): void
    {
        // "link" appears in almost any page output; it must not prove divi/link rendered.
        $r = RenderEvidence::classify('<a class="link" href="#">x</a><link rel="stylesheet">', '', 'divi/link');
        $this->assertSame('needs-real-export', $r['status']);
    }
}
```

- [ ] **Step 2: Run, expect FAIL** — `vendor/bin/phpunit tests/Tools` → class not found.

- [ ] **Step 3: Implement**

`tools/MarkupBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tools;

/**
 * Builds the minimal Divi 5 block markup used to probe a module: the same shape
 * as the real-export fixture fixtures/valid/woo-shop-section.json.
 */
final class MarkupBuilder
{
    public const PLACEMENT_COLUMN  = 'column';
    public const PLACEMENT_SECTION = 'section';

    public static function page(string $module, string $placement, ?string $child = null, string $version = '5.14.0'): string
    {
        if (!in_array($placement, [self::PLACEMENT_COLUMN, self::PLACEMENT_SECTION], true)) {
            throw new \InvalidArgumentException("Unknown placement: {$placement}");
        }

        $attrs = '{"builderVersion":"' . $version . '"}';

        $block = $child === null
            ? sprintf('<!-- wp:%s %s /-->', $module, $attrs)
            : sprintf('<!-- wp:%1$s %2$s --><!-- wp:%3$s %2$s /--><!-- /wp:%1$s -->', $module, $attrs, $child);

        if ($placement === self::PLACEMENT_COLUMN) {
            $column = '{"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"' . $version . '"}';
            $block  = sprintf(
                '<!-- wp:divi/row %1$s --><!-- wp:divi/column %2$s -->%3$s<!-- /wp:divi/column --><!-- /wp:divi/row -->',
                $attrs,
                $column,
                $block
            );
        }

        return sprintf(
            '<!-- wp:divi/placeholder --><!-- wp:divi/section %1$s -->%2$s<!-- /wp:divi/section --><!-- /wp:divi/placeholder -->',
            $attrs,
            $block
        );
    }
}
```

`tools/RenderEvidence.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tools;

/** Decides, from what Divi rendered, whether a module is proven. Pure. */
final class RenderEvidence
{
    private const ERROR_PATTERN = '/(Fatal error|Parse error|Uncaught|Warning:|Notice:|Deprecated:)/i';

    /** @return array{status:string, reasons:list<string>} */
    public static function classify(string $html, string $noise, string $module): array
    {
        $reasons = [];

        if (preg_match(self::ERROR_PATTERN, $html . "\n" . $noise, $m) === 1) {
            return ['status' => 'fail', 'reasons' => ['PHP diagnostic in output: ' . $m[1]]];
        }

        if (trim($html) === '') {
            return ['status' => 'needs-real-export', 'reasons' => ['render produced no output']];
        }

        foreach (self::markers($module) as $marker) {
            if (stripos($html, $marker) !== false) {
                return ['status' => 'pass', 'reasons' => ['found marker ' . $marker]];
            }
        }

        $reasons[] = 'output had none of the expected module markers';

        return ['status' => 'needs-real-export', 'reasons' => $reasons];
    }

    /** @return list<string> */
    public static function markers(string $module): array
    {
        $slug       = preg_replace('#^divi/#', '', $module) ?? $module;
        $underscore = str_replace('-', '_', $slug);

        // Module-specific only. A bare slug such as "link" or "map" occurs in unrelated markup
        // and would produce false passes. Calibration (Task 3) may ADD further markers, but each
        // must contain the full module name.
        return ['et_pb_' . $underscore];
    }
}
```

- [ ] **Step 4: Run, expect PASS** — `vendor/bin/phpunit tests/Tools` → `OK`.

- [ ] **Step 5: Full suite and commit**

Run: `make test` — Expected: exit 0.

```bash
git add tools tests/Tools
git commit -m "feat(tools): markup builder + render-evidence classifier for module verification

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Verification harness, calibrated with controls, and the real run

**Files:**
- Create: `scripts/verify-modules.php` (runs inside WordPress via `wp eval-file`)
- Create: `scripts/verify-modules.sh`
- Modify: `Makefile` (new `verify-modules` target, `.PHONY`)
- Create: `docs/module-verification-5.14.json` (generated output, committed as evidence)

**Interfaces:**
- Consumes: `build/candidates.json` (Task 1), `MarkupBuilder::page`, `RenderEvidence::classify` (Task 2).
- Produces: `docs/module-verification-<version>.json` — `{ "divi_version": "5.14.0", "results": { "<divi/name>": { "status": "pass|fail|needs-real-export", "placements_ok": ["column","section"], "placements": { "column": {"status":..., "reasons":[...], "html_bytes":int}, "section": {...} } } }, "controls": {...} }`.

- [ ] **Step 1: Write the harness** — create `scripts/verify-modules.php`

```php
<?php
/**
 * Runs INSIDE WordPress:  wp eval-file verify-modules.php <candidates.json> <out.json> <divi-version>
 * For each candidate module, renders minimal markup in each placement through the real
 * Divi, then classifies the evidence. Scratch pages are always deleted.
 */

use Divi5Validator\Tools\MarkupBuilder;
use Divi5Validator\Tools\RenderEvidence;

require_once '/tmp/divi5-tools/MarkupBuilder.php';
require_once '/tmp/divi5-tools/RenderEvidence.php';

[$candidatesFile, $outFile, $version] = $args;
$candidates = json_decode((string) file_get_contents($candidatesFile), true);
if (!is_array($candidates)) {
    WP_CLI::error('candidates.json is not valid JSON');
}

$probe = function (string $module, ?string $child, string $placement) use ($version): array {
    $markup = MarkupBuilder::page($module, $placement, $child, $version);
    $id = wp_insert_post([
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => 'aied-verify',
        'post_content' => wp_slash($markup), // wp_slash: core strips backslashes otherwise
    ]);
    update_post_meta($id, '_et_pb_use_divi_5', 'on');
    update_post_meta($id, '_et_pb_use_builder', 'on');

    $post = get_post($id);
    $GLOBALS['post'] = $post;
    setup_postdata($post);
    ob_start();
    try {
        $html = (string) apply_filters('the_content', $post->post_content);
    } catch (\Throwable $e) {
        $html = 'Fatal error: ' . $e->getMessage();
    }
    $noise = (string) ob_get_clean();
    wp_delete_post($id, true);
    wp_reset_postdata();

    return RenderEvidence::classify($html, $noise, $module) + ['html_bytes' => strlen($html), 'sample' => substr($html, 0, 400)];
};

$verify = function (array $p) use ($probe): array {
    $child = $p['children'][0] ?? null;
    $placements = [];
    foreach ([MarkupBuilder::PLACEMENT_COLUMN, MarkupBuilder::PLACEMENT_SECTION] as $pl) {
        $placements[$pl] = $probe($p['name'], $child, $pl);
    }
    $ok = array_keys(array_filter($placements, fn ($r) => $r['status'] === 'pass'));
    if ($ok !== []) {
        $status = 'pass';
    } elseif (array_filter($placements, fn ($r) => $r['status'] === 'fail')) {
        $status = 'fail';
    } else {
        $status = 'needs-real-export';
    }

    return ['status' => $status, 'placements_ok' => $ok, 'placements' => $placements];
};

// Controls prove the harness can tell real from unreal BEFORE we trust any result.
$controls = [];
foreach (['divi/heading', 'divi/blurb', 'divi/shop'] as $known) {
    $controls[$known] = $verify(['name' => $known, 'children' => []]);
}
$controls['divi/not-a-module'] = $verify(['name' => 'divi/not-a-module', 'children' => []]);

$bad = [];
foreach (['divi/heading', 'divi/blurb', 'divi/shop'] as $known) {
    if (!in_array(MarkupBuilder::PLACEMENT_COLUMN, $controls[$known]['placements_ok'], true)) {
        $bad[] = "positive control $known did not pass in a column";
    }
}
if ($controls['divi/not-a-module']['status'] === 'pass') {
    $bad[] = 'negative control divi/not-a-module passed';
}
if ($bad !== []) {
    file_put_contents($outFile, json_encode(['controls' => $controls, 'error' => $bad], JSON_PRETTY_PRINT));
    WP_CLI::error('Harness calibration failed: ' . implode('; ', $bad) . " (see $outFile)");
}

$results = [];
foreach ($candidates as $p) {
    $results[$p['name']] = $verify($p);
    WP_CLI::log(sprintf('%-46s %s [%s]', $p['name'], $results[$p['name']]['status'], implode(',', $results[$p['name']]['placements_ok'])));
}

file_put_contents($outFile, json_encode(
    ['divi_version' => $version, 'results' => $results, 'controls' => $controls],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n");
WP_CLI::success("Wrote $outFile");
```

Create `scripts/verify-modules.sh`:

```bash
#!/usr/bin/env bash
# verify-modules.sh — render each missing Divi module on the real Divi (Docker WP) and record evidence.
# Requires: make up (WP + Divi running), and divi/Divi.zip matching the installed Divi.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

bash scripts/schema-gap.sh
VERSION="$(cat build/divi-version.txt)"

INSTALLED="$(docker compose exec -T wpcli wp theme get Divi --field=version | tr -d '\r\n')"
if [ "$INSTALLED" != "$VERSION" ]; then
  echo "[verify] Installed Divi ($INSTALLED) != divi/Divi.zip ($VERSION). Upgrade the theme first:" >&2
  echo "         docker compose exec -T wpcli wp theme install /divi-install/Divi.zip --activate --force" >&2
  exit 1
fi

docker compose exec -T wpcli rm -rf /tmp/divi5-tools
docker compose cp tools wpcli:/tmp/divi5-tools
docker compose cp scripts/verify-modules.php wpcli:/tmp/verify-modules.php
docker compose cp build/candidates.json wpcli:/tmp/candidates.json

docker compose exec -T wpcli wp eval-file /tmp/verify-modules.php /tmp/candidates.json /tmp/results.json "$VERSION"

docker compose cp wpcli:/tmp/results.json "docs/module-verification-${VERSION%.*}.json"
echo "[verify] Evidence written to docs/module-verification-${VERSION%.*}.json"
```

Add to `Makefile` (and to `.PHONY`):

```make
# ---------------------------------------------------------------
# verify-modules — render candidate modules on the real Divi and record evidence
# ---------------------------------------------------------------
verify-modules:
	@bash scripts/verify-modules.sh
```

- [ ] **Step 2: Run the harness and calibrate on the controls**

Run: `make verify-modules`

Expected on success: `Harness calibration failed` does NOT appear; one line per candidate; `Wrote /tmp/results.json`.

If it fails with `Harness calibration failed: positive control divi/heading did not pass in a column`: open `/tmp/results.json` in the container (`docker compose exec -T wpcli cat /tmp/results.json | head -60`) and read `controls["divi/heading"].placements.column.sample` — the real rendered HTML of a module Divi certainly supports. Identify the class/attribute that Divi 5.14 actually emits for that module and update `RenderEvidence::markers()` so the observed spelling is included (keep `et_pb_<name>`; add e.g. a different class prefix — it must still contain the full module name, never a bare word), update `RenderEvidenceTest::testMarkersAreModuleSpecific` to assert the new marker, re-run `vendor/bin/phpunit tests/Tools`, then re-run `make verify-modules`. The negative control (`divi/not-a-module`) must never pass; if it does, the markers are too loose — tighten them and repeat.

Do not proceed until all three positive controls pass and the negative control does not.

- [ ] **Step 3: Inspect the evidence**

Run: `python3 -c "import json;d=json.load(open('docs/module-verification-5.14.json'));import collections;print(collections.Counter(v['status'] for v in d['results'].values()))"`
Expected: a count of `pass`, `fail`, `needs-real-export`. Sanity check by hand: for three `pass` modules open `placements.column.sample` and confirm the HTML visibly belongs to that module; for any `fail`, read the reason. Record the counts for the final report.

- [ ] **Step 4: Commit**

```bash
git add scripts/verify-modules.php scripts/verify-modules.sh Makefile docs/module-verification-5.14.json
git commit -m "feat(tools): render-verification harness + Divi 5.14 evidence

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: VerifiedModules, SchemaRules integration, promoter, sync guard

**Files:**
- Create: `src/VerifiedModules.php` (generated; committed empty first)
- Create: `tools/Promoter.php`
- Create: `scripts/promote-modules.php`
- Create: `tests/Tools/PromoterTest.php`
- Create: `tests/VerifiedModulesTest.php`
- Create: `tests/ValidatorSyncTest.php`
- Modify: `src/SchemaRules.php` (spread the generated sets into the constants)
- Modify: `wp-plugin/src/autoload.php` (load `VerifiedModules`)
- Create (mirror): `wp-plugin/validator/VerifiedModules.php`, and mirror `SchemaRules.php`

**Interfaces:**
- Consumes: `build/proposals.json` (all proposals) and `docs/module-verification-*.json` (Task 3).
- Produces: `Promoter::promote(array $proposals, array $results): array{leaf:list<string>, structural:list<string>, columnChildren:list<string>, sectionChildren:list<string>, children:array<string,list<string>>}` and `Promoter::render(array $sets, string $version): string` (PHP source of `Divi5Validator\VerifiedModules`).
- Produces: `Divi5Validator\VerifiedModules` constants `DIVI_VERSION`, `LEAF`, `STRUCTURAL`, `COLUMN_CHILDREN`, `SECTION_CHILDREN`, `CHILDREN`.

- [ ] **Step 1: Write the failing tests**

`tests/Tools/PromoterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Tools;

use Divi5Validator\Tools\Promoter;
use PHPUnit\Framework\TestCase;

class PromoterTest extends TestCase
{
    private function proposals(): array
    {
        return [
            ['name' => 'divi/portfolio', 'category' => 'module', 'children' => [], 'isChild' => false],
            ['name' => 'divi/video-slider', 'category' => 'module', 'children' => ['divi/video-slider-item'], 'isChild' => false],
            ['name' => 'divi/video-slider-item', 'category' => 'child-module', 'children' => [], 'isChild' => true],
            ['name' => 'divi/gravity-forms', 'category' => 'module', 'children' => [], 'isChild' => false],
            ['name' => 'divi/broken', 'category' => 'module', 'children' => [], 'isChild' => false],
            ['name' => 'divi/fullwidth-image', 'category' => 'fullwidth-module', 'children' => [], 'isChild' => false],
        ];
    }

    private function results(): array
    {
        return [
            'divi/portfolio'       => ['status' => 'pass', 'placements_ok' => ['column']],
            'divi/video-slider'    => ['status' => 'pass', 'placements_ok' => ['column']],
            'divi/gravity-forms'   => ['status' => 'needs-real-export', 'placements_ok' => []],
            'divi/broken'          => ['status' => 'fail', 'placements_ok' => []],
            'divi/fullwidth-image' => ['status' => 'pass', 'placements_ok' => ['column', 'section']],
        ];
    }

    public function testOnlyPassingModulesArePromoted(): void
    {
        $sets = Promoter::promote($this->proposals(), $this->results());
        $all  = array_merge($sets['leaf'], $sets['structural']);
        $this->assertNotContains('divi/gravity-forms', $all);
        $this->assertNotContains('divi/broken', $all);
        $this->assertContains('divi/portfolio', $sets['leaf']);
    }

    public function testParentChildRelationshipIsCarried(): void
    {
        $sets = Promoter::promote($this->proposals(), $this->results());
        $this->assertContains('divi/video-slider', $sets['structural']);
        $this->assertSame(['divi/video-slider-item'], $sets['children']['divi/video-slider']);
        $this->assertContains('divi/video-slider-item', $sets['leaf']);
    }

    public function testChildItemsAreNeverPlacedInColumnOrSection(): void
    {
        $sets = Promoter::promote($this->proposals(), $this->results());
        $this->assertNotContains('divi/video-slider-item', $sets['columnChildren']);
        $this->assertNotContains('divi/video-slider-item', $sets['sectionChildren']);
    }

    public function testPlacementsFollowTheEvidence(): void
    {
        $sets = Promoter::promote($this->proposals(), $this->results());
        // Rendering cannot discriminate placement (a module that renders in a column also
        // renders directly in a section), so only the column placement — the one real-export
        // precedent (shop, fullwidth-header) supports — is promoted. Section stays empty until a
        // real builder export proves it.
        $this->assertContains('divi/fullwidth-image', $sets['columnChildren']);
        $this->assertContains('divi/portfolio', $sets['columnChildren']);
        $this->assertSame([], $sets['sectionChildren']);
    }

    public function testOutputIsSortedAndStable(): void
    {
        $a = Promoter::render(Promoter::promote($this->proposals(), $this->results()), '5.14.0');
        $b = Promoter::render(Promoter::promote(array_reverse($this->proposals()), $this->results()), '5.14.0');
        $this->assertSame($a, $b);
    }

    public function testRenderedSourceIsValidPhp(): void
    {
        $src = Promoter::render(Promoter::promote($this->proposals(), $this->results()), '5.14.0');
        $tmp = tempnam(sys_get_temp_dir(), 'vm') . '.php';
        file_put_contents($tmp, $src);
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
        unlink($tmp);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertStringContainsString("const DIVI_VERSION = '5.14.0';", $src);
    }
}
```

`tests/VerifiedModulesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\Tools\MarkupBuilder;
use Divi5Validator\Validator;
use Divi5Validator\VerifiedModules;
use PHPUnit\Framework\TestCase;

/** Every generated entry must behave exactly as its evidence claims. */
class VerifiedModulesTest extends TestCase
{
    public function testPromotedModulesValidateInTheirVerifiedPlacements(): void
    {
        $v = new Validator();
        foreach ([MarkupBuilder::PLACEMENT_COLUMN => VerifiedModules::COLUMN_CHILDREN, MarkupBuilder::PLACEMENT_SECTION => VerifiedModules::SECTION_CHILDREN] as $placement => $modules) {
            foreach ($modules as $m) {
                $child  = VerifiedModules::CHILDREN[$m][0] ?? null;
                $result = $v->validateContent(MarkupBuilder::page($m, $placement, $child, VerifiedModules::DIVI_VERSION));
                $this->assertTrue($result->isValid(), "$m in $placement: " . implode('; ', array_map(fn ($x) => $x->code(), $result->violations())));
            }
        }
        $this->addToAssertionCount(1); // valid even when nothing is promoted yet
    }

    public function testChildItemsAreRejectedAlone(): void
    {
        $v = new Validator();
        foreach (VerifiedModules::CHILDREN as $kids) {
            foreach ($kids as $kid) {
                $result = $v->validateContent(MarkupBuilder::page($kid, MarkupBuilder::PLACEMENT_COLUMN, null, VerifiedModules::DIVI_VERSION));
                $this->assertFalse($result->isValid(), "$kid must not be valid outside its parent");
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testParentsRejectAForeignChild(): void
    {
        $v = new Validator();
        foreach (array_keys(VerifiedModules::CHILDREN) as $parent) {
            $result = $v->validateContent(MarkupBuilder::page($parent, MarkupBuilder::PLACEMENT_COLUMN, 'divi/heading', VerifiedModules::DIVI_VERSION));
            $this->assertFalse($result->isValid(), "$parent must reject a divi/heading child");
        }
        $this->addToAssertionCount(1);
    }

    public function testVerdictIsDeterministic(): void
    {
        $markup = MarkupBuilder::page('divi/shop', MarkupBuilder::PLACEMENT_COLUMN);
        $v = new Validator();
        $this->assertEquals($v->validateContent($markup)->violations(), $v->validateContent($markup)->violations());
    }
}
```

`tests/ValidatorSyncTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

/** CLAUDE.md: src/ is canonical and wp-plugin/validator/ must mirror it. */
class ValidatorSyncTest extends TestCase
{
    public function testBundledValidatorMirrorsSrc(): void
    {
        $root = dirname(__DIR__);
        $files = glob($root . '/src/*.php') ?: [];
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $copy = $root . '/wp-plugin/validator/' . basename($file);
            $this->assertFileExists($copy, basename($file) . ' is missing from wp-plugin/validator/');
            $this->assertSame(file_get_contents($file), file_get_contents($copy), basename($file) . ' differs from wp-plugin/validator/');
        }
    }
}
```

- [ ] **Step 2: Run, expect FAIL** — `vendor/bin/phpunit tests/Tools/PromoterTest.php tests/VerifiedModulesTest.php` → classes not found.

- [ ] **Step 3: Implement the promoter and the empty generated class**

`tools/Promoter.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tools;

/** Turns verification evidence into the sets the schema consumes. Only `pass` is promoted. */
final class Promoter
{
    /**
     * @param list<array{name:string, children:list<string>, isChild:bool}> $proposals
     * @param array<string, array{status:string, placements_ok:list<string>}> $results
     * @return array{leaf:list<string>, structural:list<string>, columnChildren:list<string>, sectionChildren:list<string>, children:array<string,list<string>>}
     */
    public static function promote(array $proposals, array $results): array
    {
        $sets = ['leaf' => [], 'structural' => [], 'columnChildren' => [], 'sectionChildren' => [], 'children' => []];

        foreach ($proposals as $p) {
            $r = $results[$p['name']] ?? null;
            if ($p['isChild'] || $r === null || $r['status'] !== 'pass') {
                continue;
            }

            if ($p['children'] === []) {
                $sets['leaf'][] = $p['name'];
            } else {
                $sets['structural'][] = $p['name'];
                $kids = $p['children'];
                sort($kids);
                $sets['children'][$p['name']] = $kids;
                foreach ($kids as $kid) {
                    $sets['leaf'][] = $kid;
                }
            }
            if (in_array('column', $r['placements_ok'], true)) {
                $sets['columnChildren'][] = $p['name'];
            }
            // 'section' evidence is deliberately NOT promoted: a render that succeeds in a section
            // does not prove Divi's builder allows the module there (see Task 3 review).
        }

        foreach (['leaf', 'structural', 'columnChildren', 'sectionChildren'] as $k) {
            $sets[$k] = array_values(array_unique($sets[$k]));
            sort($sets[$k]);
        }
        ksort($sets['children']);

        return $sets;
    }

    /** @param array<string, mixed> $sets */
    public static function render(array $sets, string $version): string
    {
        $list = function (array $items): string {
            return $items === [] ? '[]' : "[\n" . implode('', array_map(fn ($i) => "        '" . $i . "',\n", $items)) . '    ]';
        };
        $map = function (array $items): string {
            if ($items === []) {
                return '[]';
            }
            $out = "[\n";
            foreach ($items as $k => $v) {
                $out .= "        '" . $k . "' => ['" . implode("', '", $v) . "'],\n";
            }

            return $out . '    ]';
        };

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Divi5Validator;\n\n"
            . "/**\n * GENERATED by scripts/promote-modules.php from docs/module-verification-*.json.\n"
            . " * Do not edit by hand: every entry is backed by a render-verification record.\n */\n"
            . "final class VerifiedModules\n{\n"
            . "    public const DIVI_VERSION = '" . $version . "';\n\n"
            . '    public const LEAF = ' . $list($sets['leaf']) . ";\n\n"
            . '    public const STRUCTURAL = ' . $list($sets['structural']) . ";\n\n"
            . '    public const COLUMN_CHILDREN = ' . $list($sets['columnChildren']) . ";\n\n"
            . '    public const SECTION_CHILDREN = ' . $list($sets['sectionChildren']) . ";\n\n"
            . '    public const CHILDREN = ' . $map($sets['children']) . ";\n}\n";
    }
}
```

Create `src/VerifiedModules.php` (initial, empty — exactly what `Promoter::render` emits for empty sets):

```php
<?php

declare(strict_types=1);

namespace Divi5Validator;

/**
 * GENERATED by scripts/promote-modules.php from docs/module-verification-*.json.
 * Do not edit by hand: every entry is backed by a render-verification record.
 */
final class VerifiedModules
{
    public const DIVI_VERSION = '5.14.0';

    public const LEAF = [];

    public const STRUCTURAL = [];

    public const COLUMN_CHILDREN = [];

    public const SECTION_CHILDREN = [];

    public const CHILDREN = [];
}
```

Edit `src/SchemaRules.php` — add the spreads (leave every existing entry untouched):
- `STRUCTURAL_BLOCKS`: add as the last element `...VerifiedModules::STRUCTURAL,`
- `LEAF_MODULES`: add as the last element `...VerifiedModules::LEAF,`
- `ALLOWED_CHILDREN['divi/section']`: change to `['divi/row', 'divi/column', 'divi/global-layout', ...VerifiedModules::SECTION_CHILDREN],`
- `ALLOWED_CHILDREN['divi/column']`: add as the last element (after `'divi/row-inner',`) `...VerifiedModules::COLUMN_CHILDREN,`
- `ALLOWED_CHILDREN`: add as the last element of the outer array (after the `'divi/timeline' => [...]` entry) `...VerifiedModules::CHILDREN,`

Edit `wp-plugin/src/autoload.php`: change the class list to
`['Violation', 'ValidationResult', 'VerifiedModules', 'SchemaRules', 'Block', 'ParseResult', 'BlockParser', 'Validator']`.

Create `scripts/promote-modules.php`:

```php
#!/usr/bin/env php
<?php
// Usage: php scripts/promote-modules.php <proposals.json> <module-verification-X.Y.json>
// Writes src/VerifiedModules.php, mirrors it (and SchemaRules.php) into wp-plugin/validator/,
// and writes one fixture per promoted module/placement into fixtures/valid/.

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Divi5Validator\Tools\MarkupBuilder;
use Divi5Validator\Tools\Promoter;

[$proposalsFile, $evidenceFile] = [$argv[1] ?? '', $argv[2] ?? ''];
if (!is_file($proposalsFile) || !is_file($evidenceFile)) {
    fwrite(STDERR, "Usage: php scripts/promote-modules.php <proposals.json> <module-verification.json>\n");
    exit(2);
}

$proposals = json_decode((string) file_get_contents($proposalsFile), true, 512, JSON_THROW_ON_ERROR);
$evidence  = json_decode((string) file_get_contents($evidenceFile), true, 512, JSON_THROW_ON_ERROR);
$version   = (string) $evidence['divi_version'];

$sets = Promoter::promote($proposals, $evidence['results']);
$root = dirname(__DIR__);

file_put_contents($root . '/src/VerifiedModules.php', Promoter::render($sets, $version));
foreach (['VerifiedModules.php', 'SchemaRules.php'] as $f) {
    copy($root . '/src/' . $f, $root . '/wp-plugin/validator/' . $f);
}

// Clear previously generated fixtures so demoted modules do not linger.
foreach (glob($root . '/fixtures/valid/verified-*.json') ?: [] as $old) {
    unlink($old);
}
$written = 0;
foreach (['column' => $sets['columnChildren'], 'section' => $sets['sectionChildren']] as $placement => $modules) {
    foreach ($modules as $m) {
        $child   = $sets['children'][$m][0] ?? null;
        $slug    = str_replace('divi/', '', $m);
        $fixture = [
            'source'       => 'render-verified',
            'format'       => 'gutenberg-blocks',
            'divi_version' => $version,
            'post_title'   => "Verified {$slug} ({$placement})",
            'post_status'  => 'draft',
            'post_content' => MarkupBuilder::page($m, $placement, $child, $version),
        ];
        file_put_contents("{$root}/fixtures/valid/verified-{$slug}-{$placement}.json", json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $written++;
    }
}

printf("Promoted: %d leaf, %d structural; column=%d section=%d; %d fixtures written.\n",
    count($sets['leaf']), count($sets['structural']), count($sets['columnChildren']), count($sets['sectionChildren']), $written);
```

Mirror the source changes: `cp src/VerifiedModules.php src/SchemaRules.php wp-plugin/validator/`

- [ ] **Step 4: Run, expect PASS**

Run: `composer dump-autoload -q && vendor/bin/phpunit tests/Tools tests/VerifiedModulesTest.php tests/ValidatorSyncTest.php`
Expected: `OK`.

- [ ] **Step 5: Full suite and commit**

Run: `make test` — Expected: exit 0 (all previous 100+ tests still pass: nothing is promoted yet, so behaviour is unchanged).

```bash
git add src tools scripts/promote-modules.php tests wp-plugin/validator wp-plugin/src/autoload.php
git commit -m "feat(schema): VerifiedModules generated set + promoter; guard src/bundled sync

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Promote the verified Divi 5.14 modules

**Files:**
- Modify (generated): `src/VerifiedModules.php`, `wp-plugin/validator/VerifiedModules.php`, `wp-plugin/validator/SchemaRules.php`
- Create (generated): `fixtures/valid/verified-*.json`
- Create: `docs/module-verification-5.14.json` already exists (Task 3); regenerate only if Task 3 is re-run.

**Interfaces:**
- Consumes: `build/proposals.json`, `docs/module-verification-5.14.json`, `Promoter`, `scripts/promote-modules.php` (Task 4).

- [ ] **Step 1: Regenerate inputs and promote**

Run: `make schema-gap && php scripts/promote-modules.php build/proposals.json docs/module-verification-5.14.json`
Expected: `Promoted: N leaf, M structural; column=… section=…; K fixtures written.`

- [ ] **Step 2: Review the promoted set against the evidence**

Run: `git diff --stat && sed -n 1,80p src/VerifiedModules.php`
Check by eye: no `divi/woocommerce-*` or `gravity-forms`/`contact-form-7` entry unless the evidence JSON says `pass` for it; every child item appears only under `CHILDREN` and `LEAF`. Any module you believe is wrongly promoted: do not hand-edit the generated file; fix the evidence rule (Task 2/3) and regenerate.

- [ ] **Step 3: Run the whole suite**

Run: `make test`
Expected: exit 0. This runs `ValidatorSchemaTest::testValidFixturesAllPass` over the new fixtures, `VerifiedModulesTest`, the sync guard, and the 17 section-recipe validations — all must pass with no edits to old tests.

- [ ] **Step 4: Prove the original problem is fixed, end to end**

Run (uses the live Docker site; the plugin directory is mounted, so no rebuild is needed):

```bash
docker compose exec -T wpcli wp eval '
wp_set_current_user(1);
$q=function($b){$r=new WP_REST_Request("POST","/ai-editor-divi5/v1/validate");$r->set_header("content-type","application/json");$r->set_body(json_encode($b));return rest_do_request($r);};
$env=["source"=>"x","format"=>"gutenberg-blocks","post_title"=>"t","post_status"=>"draft","post_content"=>
"<!-- wp:divi/placeholder --><!-- wp:divi/section {\"builderVersion\":\"5.14.0\"} --><!-- wp:divi/row {\"builderVersion\":\"5.14.0\"} --><!-- wp:divi/column {\"module\":{\"advanced\":{\"type\":{\"desktop\":{\"value\":\"4_4\"}}}},\"builderVersion\":\"5.14.0\"} --><!-- wp:divi/portfolio {\"builderVersion\":\"5.14.0\"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->"];
$r=$q($env); echo $r->get_status()," ",json_encode($r->get_data()),"\n";'
```

Expected: `200 {"valid":true,...}` if `divi/portfolio` was promoted; if the evidence marked it `needs-real-export`, expected `422` with `UNKNOWN_MODULE_TYPE` (and the final report must say so).

- [ ] **Step 5: Commit**

```bash
git add src wp-plugin/validator fixtures/valid docs
git commit -m "feat(schema): promote render-verified Divi 5.14 modules

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Divi version compatibility notice

**Files:**
- Create: `wp-plugin/src/DiviCompat.php`
- Create: `tests/DiviCompatTest.php`
- Modify: `wp-plugin/src/autoload.php` (require it)
- Modify: `wp-plugin/src/AdminPage.php` (render the notice after `$this->notice( $notice );`, and add the method)

**Interfaces:**
- Produces: `AiEditorDivi5\WP\DiviCompat::TESTED = '5.14'`; `DiviCompat::status(?string $installed, string $tested = self::TESTED): array{level:string, message:string}` with level `ok | newer | unknown`; `DiviCompat::installedVersion(): ?string` (WordPress-dependent, not unit-tested).

- [ ] **Step 1: Write the failing test** — `tests/DiviCompatTest.php`

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DiviCompat;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';

class DiviCompatTest extends TestCase
{
    public function testNewerMinorVersionWarns(): void
    {
        $s = DiviCompat::status('5.15.0', '5.14');
        $this->assertSame('newer', $s['level']);
        $this->assertStringContainsString('5.15', $s['message']);
        $this->assertStringContainsString('5.14', $s['message']);
    }

    public function testPatchBumpWithinTestedMinorIsFine(): void
    {
        $this->assertSame('ok', DiviCompat::status('5.14.9', '5.14')['level']);
    }

    public function testOlderOrEqualIsFine(): void
    {
        $this->assertSame('ok', DiviCompat::status('5.8.0', '5.14')['level']);
        $this->assertSame('ok', DiviCompat::status('5.14.0', '5.14')['level']);
    }

    public function testNewerMajorWarns(): void
    {
        $this->assertSame('newer', DiviCompat::status('6.0.0', '5.14')['level']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unknownVersions')]
    public function testUnreadableVersionNeverWarns(?string $installed): void
    {
        $s = DiviCompat::status($installed, '5.14');
        $this->assertSame('unknown', $s['level']);
        $this->assertSame('', $s['message']);
    }

    public static function unknownVersions(): array
    {
        return [[null], [''], ['garbage'], ['v?']];
    }
}
```

- [ ] **Step 2: Run, expect FAIL** — `vendor/bin/phpunit tests/DiviCompatTest.php` → class not found.

- [ ] **Step 3: Implement**

`wp-plugin/src/DiviCompat.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Compares the installed Divi against the newest version this plugin was verified on. */
final class DiviCompat
{
    /** Newest Divi major.minor whose modules have been render-verified (see docs/module-verification-*.json). */
    public const TESTED = '5.14';

    /** @return array{level:string, message:string} */
    public static function status( ?string $installed, string $tested = self::TESTED ): array
    {
        $inst = self::majorMinor( $installed );
        $test = self::majorMinor( $tested );
        if ( null === $inst || null === $test ) {
            return [ 'level' => 'unknown', 'message' => '' ];
        }
        if ( version_compare( $inst, $test, '>' ) ) {
            return [
                'level'   => 'newer',
                'message' => sprintf(
                    /* translators: 1: installed Divi version, 2: newest tested Divi version */
                    __( 'Divi %1$s is newer than the newest version this plugin was verified on (%2$s). Editing still works, but pages that use brand-new Divi modules may be rejected until a plugin update adds them.', 'ai-editor-for-divi-5' ),
                    $inst,
                    $test
                ),
            ];
        }

        return [ 'level' => 'ok', 'message' => '' ];
    }

    public static function installedVersion(): ?string
    {
        if ( defined( 'ET_CORE_VERSION' ) ) {
            return (string) ET_CORE_VERSION;
        }
        $theme = wp_get_theme( 'Divi' );
        if ( $theme->exists() ) {
            $v = $theme->get( 'Version' );

            return is_string( $v ) && '' !== $v ? $v : null;
        }

        return null;
    }

    private static function majorMinor( ?string $version ): ?string
    {
        if ( null === $version || 1 !== preg_match( '/^(\d+)\.(\d+)/', $version, $m ) ) {
            return null;
        }

        return $m[1] . '.' . $m[2];
    }
}
```

The test runs outside WordPress, where `__()` is shimmed by `tests/bootstrap.php`; if the shim is missing (`Call to undefined function __()`), add to `tests/bootstrap.php` next to the other `function_exists` shims:

```php
if ( ! function_exists( '__' ) ) {
    function __( $s, $d = null ) { return $s; }
}
```

In `wp-plugin/src/autoload.php`, add after the `UsageTracker.php` line: `require_once __DIR__ . '/DiviCompat.php';`

In `wp-plugin/src/AdminPage.php`, after the line `<?php $this->notice( $notice ); ?>` add `<?php $this->diviCompatNotice(); ?>`, and add this method next to `notice()`:

```php
    private function diviCompatNotice(): void
    {
        $status = DiviCompat::status( DiviCompat::installedVersion() );
        if ( 'newer' !== $status['level'] ) {
            return;
        }
        echo '<div class="notice notice-info inline"><p>' . esc_html( $status['message'] ) . '</p></div>';
    }
```

- [ ] **Step 4: Run, expect PASS** — `vendor/bin/phpunit tests/DiviCompatTest.php` → `OK`. Then confirm no admin regressions: `php -l wp-plugin/src/AdminPage.php` and `vendor/bin/phpunit --filter "ConnectCardRenderTest|ConnectClientsTest"` → `OK`.

- [ ] **Step 5: Full suite and commit**

Run: `make test` — Expected: exit 0.

```bash
git add wp-plugin/src tests
git commit -m "feat(admin): notice when installed Divi is newer than the verified version

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Docs, rule amendment, release 3.4.0

**Files:**
- Modify: `CLAUDE.md` (amended grounding rule, new commands, state)
- Modify: `docs/SCHEMA.md` (Divi 5.14 modules + known gaps)
- Modify: `wp-plugin/ai-editor-divi5.php` (`Version` header + `AI_EDITOR_DIVI5_VERSION`)
- Modify: `wp-plugin/readme.txt` (Stable tag, Changelog, Upgrade Notice; `Tested up to` stays readme-only)
- Modify: `ai-editor-for-divi-5.zip` (rebuilt)

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Amend the rule and document the commands in `CLAUDE.md`**

Replace the bullet starting `- **No Divi schema from memory.**` with:

```markdown
- **No Divi schema from memory.** Divi 4 used shortcodes; Divi 5 uses Gutenberg
  block JSON. All schema knowledge in `SchemaRules.php` must come from real
  exports (`make export-layouts`, documented in `docs/SCHEMA.md`) **or from the
  shipped Divi theme's own `module.json` definitions, in either case verified by
  a live render on the real Divi version** (`make verify-modules`; evidence in
  `docs/module-verification-*.json`). `src/VerifiedModules.php` is generated
  from that evidence — never hand-edit it. Never invent block types or
  attribute shapes.
```

Add to the Entry points table:

```markdown
| `make schema-gap` | List Divi modules (from `divi/Divi.zip`) the validator doesn't know |
| `make verify-modules` | Render candidate modules on the real Divi (Docker) and write evidence |
```

Add a `Current state` bullet: `- 3.4.0: validator recognises the render-verified Divi 5.14 modules (see docs/module-verification-5.14.json); modules that could not be verified stay rejected and are listed in docs/SCHEMA.md.`

- [ ] **Step 2: Document the outcome in `docs/SCHEMA.md`**

Append a section `## Divi 5.14 modules (render-verified)` containing: the Divi version, the verification date, the list of promoted modules grouped by placement (copy from `src/VerifiedModules.php`), and a `### Known gaps` list of every `needs-real-export` / `fail` module from `docs/module-verification-5.14.json` with its reason. Generate the lists with:

```bash
python3 - <<'EOF'
import json
d = json.load(open('docs/module-verification-5.14.json'))
for status in ('pass', 'needs-real-export', 'fail'):
    names = sorted(n for n, r in d['results'].items() if r['status'] == status)
    print(f'\n{status} ({len(names)}):')
    for n in names:
        r = d['results'][n]
        why = '; '.join(r['placements'].get('column', {}).get('reasons', []))
        print(f'- `{n}`' + (f' — placements: {", ".join(r["placements_ok"])}' if status == 'pass' else f' — {why}'))
EOF
```

Paste that output under the headings.

- [ ] **Step 3: Bump the version and changelog**

In `wp-plugin/ai-editor-divi5.php`: `Version: 3.3.0` → `Version: 3.4.0` and `define('AI_EDITOR_DIVI5_VERSION', '3.3.0');` → `'3.4.0'`.

In `wp-plugin/readme.txt`: `Stable tag:        3.3.0` → `3.4.0`; add above `= 3.3.0 =` in the Changelog (fill `N` from the Task 5 output):

```
= 3.4.0 =
* New: your AI can now edit pages that use N more Divi 5 modules (for example Portfolio, Post Slider, Video Slider, Lottie, SVG, Link, Tooltip, Dropdown, Charts, Table of Contents and the Fullwidth modules) — every one verified to render on Divi 5.14.
* New: a notice on the plugin's admin screen when your Divi is newer than the newest version this plugin was verified on.
* Modules that could not be verified yet are still rejected rather than guessed.
```

and above `= 3.3.0 =` in Upgrade Notice: `= 3.4.0 =` / `Adds support for more Divi 5.14 modules. No reconfiguration needed.`

- [ ] **Step 4: Verify everything and rebuild**

Run, in order, and confirm each:

```bash
make test                                   # exit 0
bash scripts/build-plugin-zip.sh            # writes ai-editor-for-divi-5.zip
unzip -l ai-editor-for-divi-5.zip | grep -E "tools/|scripts/|build/|docs/" && echo "LEAK" || echo "zip clean"
unzip -l ai-editor-for-divi-5.zip | grep -c VerifiedModules    # 1
```

Plugin Check under the real slug (this swaps the zip in for the mounted dev copy, then restores it):

```bash
S=$(mktemp -d) && unzip -q ai-editor-for-divi-5.zip -d "$S"
docker compose exec -T wpcli wp plugin deactivate ai-editor-divi5
docker cp "$S/ai-editor-for-divi-5" divi5val_wp:/var/www/html/wp-content/plugins/ai-editor-for-divi-5
docker compose exec -T -u root wordpress chown -R www-data:www-data /var/www/html/wp-content/plugins/ai-editor-for-divi-5
docker compose exec -T wpcli wp plugin activate ai-editor-for-divi-5
docker compose exec -T wpcli wp plugin check ai-editor-for-divi-5 --format=table
docker compose exec -T wpcli wp plugin deactivate ai-editor-for-divi-5
docker compose exec -T -u root wordpress rm -rf /var/www/html/wp-content/plugins/ai-editor-for-divi-5
docker compose exec -T wpcli wp plugin activate ai-editor-divi5
```

Expected: `Success: Checks complete. No errors found.` and `zip clean`. If Plugin Check reports anything, fix it and re-run before committing.

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md docs/SCHEMA.md wp-plugin ai-editor-for-divi-5.zip
git commit -m "release(aied): v3.4.0 — Divi 5.14 module coverage (render-verified)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```
