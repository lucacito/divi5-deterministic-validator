<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\SchemaRules;
use PHPUnit\Framework\TestCase;

foreach (['ExtensionGuard', 'StyleGuide', 'SiteGuide', 'LandingGuide', 'ImagePack', 'ImageTokens', 'ImageGuide', 'SectionRecipes', 'OpenApiSpec'] as $aiedClass) {
    require_once __DIR__ . '/../wp-plugin/src/' . $aiedClass . '.php';
}

/**
 * Locks in the WordPress.org review findings of 2026-10-01 so they cannot regress.
 *
 * The rule this test enforces: nothing licence-gated, remote-fetching or custom-code-saving
 * may enter wp-plugin/. Paid features live in a separate add-on (staged in pro-addon/).
 * Do NOT weaken a scan to make it pass — fix the plugin, or add an explicit, justified exception.
 */
class WpOrgComplianceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../wp-plugin';

    private const FORBIDDEN_CODE = [
        'Licensing', 'LicenseClient', 'isPremium', 'MenuBuilder', 'CustomCss', 'PhpProposals',
        'set_custom_css', 'propose_php_snippet', 'set_front_page', 'set_primary_menu',
        'setFrontPage', 'setPrimaryMenu',
        'pre_set_site_transient', 'update_plugins', 'upgrade_url', 'PREMIUM',
    ];

    private const REMOTE_FETCH = '/\b(wp_remote_(?:get|post|request|head)|curl_init|curl_exec)\s*\(|file_get_contents\(\s*[\'"]https?:|fopen\(\s*[\'"]https?:/i';

    private const IMAGE_HOSTS = ['picsum.photos', 'pravatar', 'randomuser', 'placehold.co', 'placehold.it', 'loremflickr', 'unsplash', 'pexels', 'pixabay', 'lorempixel', 'dummyimage'];

    /**
     * Hosts that may appear as plain text/links in plugin files (never fetched by the plugin).
     * Each entry is justified; add nothing without a reason.
     */
    private const ALLOWED_HOSTS = [
        'example.com'             => 'documentation/example URLs',
        'www.gnu.org'             => 'GPL licence URI in the plugin header',
        'divi5lab.com'            => 'link to the separate Pro add-on page / author URI (a link, not a service call)',
        'wordpress.org'           => 'documentation links',
        'developer.wordpress.org' => 'documentation links',
        'json-schema.org'         => 'schema reference strings',
        'localhost'               => 'example MCP/REST URLs in connection instructions',
        'modelcontextprotocol.io' => 'documentation link in connection instructions',
        'www.w3.org'              => 'xmlns namespace identifier inside the bundled SVG images (an XML name, never fetched)',
    ];

    /**
     * Commercial-language scan (in addition to FORBIDDEN_CODE). Any match in wp-plugin/ source
     * (php/js/css/json/svg; readme.txt and the bundled validator/ excluded) fails, unless it is
     * one of the explicit exceptions below.
     */
    private const COMMERCIAL_WORDS = '/\bupgrade\b|\bpremium\b|\bPro\b|\bPRO\b|licen[sc]e key/i';

    /**
     * Legitimate hits: [relative path, substring the matching line must contain, reason].
     * The Pro card in AdminPage.php is handled separately (one allowed group, see below).
     */
    private const COMMERCIAL_EXCEPTIONS = [
        ['src/UsageTracker.php', "wp-admin/includes/upgrade.php", 'WordPress core include that provides dbDelta() for the usage-log table'],
    ];

    /** @return \Generator<string,string> relative path => contents */
    private function files(): \Generator
    {
        $root = realpath(self::ROOT);
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && in_array($f->getExtension(), ['php', 'js', 'json', 'txt', 'css', 'svg'], true)) {
                yield substr($f->getPathname(), strlen($root) + 1) => (string) file_get_contents($f->getPathname());
            }
        }
    }

    /** Number of distinct Divi 5 block types the validator knows (structural + leaf + extra). */
    private function knownBlockTypeCount(): int
    {
        $extra = (new \ReflectionClass(SchemaRules::class))->getReflectionConstant('EXTRA_TYPES');
        $this->assertNotFalse($extra, 'SchemaRules::EXTRA_TYPES must exist');
        $types = array_unique(array_merge(SchemaRules::STRUCTURAL_BLOCKS, SchemaRules::LEAF_MODULES, (array) $extra->getValue()));
        $rules = new SchemaRules();
        foreach ($types as $t) {
            $this->assertTrue($rules->isKnownType($t), "{$t} must be a known type");
        }
        return count($types);
    }

    public function testNoLicenceProOrCustomCodeFeatureRemains(): void
    {
        $hits = [];
        foreach ($this->files() as $rel => $src) {
            if (str_ends_with($rel, 'readme.txt')) {
                continue;
            }
            foreach (self::FORBIDDEN_CODE as $needle) {
                if (stripos($src, $needle) !== false) {
                    $hits[] = "$rel mentions $needle";
                }
            }
        }
        $this->assertSame([], $hits);
    }

    public function testNoUpgradePremiumProOrLicenceKeyWordingOutsideTheOneCard(): void
    {
        $hits     = [];
        $cardHits = [];
        foreach ($this->files() as $rel => $src) {
            if (str_ends_with($rel, 'readme.txt') || str_starts_with($rel, 'validator/') || !in_array(pathinfo($rel, PATHINFO_EXTENSION), ['php', 'js', 'css', 'json', 'svg'], true)) {
                continue;
            }
            [$cardStart, $cardEnd] = [-1, -1];
            if ($rel === 'src/AdminPage.php') {
                // The one allowed group: the Pro card (its docblock + private function proCard()).
                $fn = strpos($src, 'private function proCard()');
                $this->assertNotFalse($fn, 'AdminPage::proCard() must exist');
                $cardStart = (int) strrpos(substr($src, 0, (int) $fn), '/**');
                $next      = strpos($src, 'private function ', (int) $fn + 10);
                $cardEnd   = $next === false ? strlen($src) : $next;
            }
            $offset = 0;
            foreach (explode("\n", $src) as $i => $line) {
                $lineStart = $offset;
                $offset   += strlen($line) + 1;
                if (!preg_match(self::COMMERCIAL_WORDS, $line, $m)) {
                    continue;
                }
                if (preg_match('/^\s*\*\s*License( URI)?:/', $line)) {
                    continue; // GPL header lines of the main plugin file.
                }
                if ($lineStart >= $cardStart && $lineStart < $cardEnd) {
                    $cardHits[] = $line;
                    continue;
                }
                foreach (self::COMMERCIAL_EXCEPTIONS as [$path, $mustContain]) {
                    if ($rel === $path && str_contains($line, $mustContain)) {
                        continue 2;
                    }
                }
                $hits[] = "$rel:" . ($i + 1) . " ({$m[0]})";
            }
        }
        $this->assertSame([], $hits, 'upgrade/premium/Pro/licence-key wording is only allowed in the one Dashboard card');
        $this->assertNotSame([], $cardHits, 'the Pro card is expected to exist');
        foreach ($cardHits as $line) {
            $this->assertStringContainsString('Pro add-on', $line, 'the card may only refer to "the Pro add-on"');
            $this->assertDoesNotMatchRegularExpression('/\bupgrade\b|\bpremium\b|licen[sc]e key/i', $line);
        }
    }

    public function testThePluginNeverFetchesRemoteFiles(): void
    {
        $hits = [];
        foreach ($this->files() as $rel => $src) {
            if (str_starts_with($rel, 'validator/') || str_ends_with($rel, 'readme.txt')) {
                continue;
            }
            if (preg_match(self::REMOTE_FETCH, $src, $m)) {
                $hits[] = "$rel: {$m[0]}";
            }
        }
        $this->assertSame([], $hits);
    }

    public function testNoThirdPartyImageHostAnywhere(): void
    {
        $hits = [];
        foreach ($this->files() as $rel => $src) {
            foreach (self::IMAGE_HOSTS as $h) {
                if (stripos($src, $h) !== false) {
                    $hits[] = "$rel mentions $h";
                }
            }
        }
        $this->assertSame([], $hits);
    }

    public function testEveryUrlHostIsAllowlistedAndJustified(): void
    {
        $unknown = [];
        foreach ($this->files() as $rel => $src) {
            if (preg_match_all('#https?://([a-z0-9.-]+)#i', $src, $m)) {
                foreach (array_unique($m[1]) as $host) {
                    $host = strtolower($host);
                    $ok   = false;
                    foreach (array_keys(self::ALLOWED_HOSTS) as $allowed) {
                        if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                            $ok = true;
                        }
                    }
                    if (!$ok) {
                        $unknown[] = "$rel → $host";
                    }
                }
            }
        }
        $this->assertSame([], $unknown, 'every host in the plugin must be allowlisted with a reason');
    }

    public function testGeneratedGuidesRecipesAndSpecAreCleanToo(): void
    {
        $texts = [
            \AiEditorDivi5\WP\OpenApiSpec::spec('https://s.example/wp-json/ai-editor-divi5/v1', '4.0.0'),
        ];
        $blob = json_encode($texts) . \AiEditorDivi5\WP\StyleGuide::markdown() . \AiEditorDivi5\WP\SiteGuide::markdown()
              . \AiEditorDivi5\WP\LandingGuide::markdown() . \AiEditorDivi5\WP\ImageGuide::markdown();
        foreach (\AiEditorDivi5\WP\SectionRecipes::names() as $n) {
            $blob .= \AiEditorDivi5\WP\SectionRecipes::recipe($n);
        }
        $this->assertStringNotContainsString('{{aied:', $blob, 'no unresolved image token may reach the AI');
        foreach (['set_front_page', 'set_primary_menu', 'set_custom_css', 'propose_php_snippet', 'upgrade', 'premium'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $blob, "generated output mentions {$bad}");
        }
        foreach (self::IMAGE_HOSTS as $h) {
            $this->assertStringNotContainsString($h, $blob);
        }
    }

    public function testCreatePageIsFreeAndTheMcpToolListIsExactlyTheFreeTools(): void
    {
        $mcp  = (string) file_get_contents(self::ROOT . '/src/McpHandler.php');
        $rest = (string) file_get_contents(self::ROOT . '/src/RestController.php');
        $this->assertStringNotContainsString('upgrade_url', $mcp);
        $this->assertStringNotContainsString('upgrade_url', $rest);
        $this->assertStringNotContainsString('PREMIUM', (string) file_get_contents(self::ROOT . '/src/OpenApiSpec.php'));

        preg_match_all("/^\s+'name'\s+=> '([a-z_]+)',$/m", $mcp, $m);
        $tools = $m[1];
        sort($tools);
        $this->assertSame([
            'create_page', 'edit_page_content', 'get_image_guide', 'get_landing_guide', 'get_page_history_entry',
            'get_page_layout', 'get_section_recipes', 'get_site_guide', 'get_style_guide', 'list_divi_pages',
            'list_media_images', 'list_page_history', 'restore_page_version', 'update_page_layout', 'validate_layout',
        ], $tools);
        $this->assertCount(15, $tools);
    }

    public function testVisibleProductNameIsTheFullJhmgName(): void
    {
        $hits = [];
        foreach ($this->files() as $rel => $src) {
            if (str_starts_with($rel, 'validator/')) {
                continue;
            }
            if (preg_match_all('/(?<!JHMG )AI Editor for Divi 5/', $src, $m)) {
                $hits[] = "$rel (" . count($m[0]) . ')';
            }
        }
        $this->assertSame([], $hits, 'the product is called "JHMG AI Editor for Divi 5" everywhere it is named');
        $spec = \AiEditorDivi5\WP\OpenApiSpec::spec('https://s.example/wp-json/ai-editor-divi5/v1', '4.0.0');
        $this->assertSame('JHMG AI Editor for Divi 5', $spec['info']['title']);
    }

    public function testModuleCountClaimsAreRealComputedNumbers(): void
    {
        $actual = $this->knownBlockTypeCount();
        $sources = [
            'readme.txt'        => (string) file_get_contents(self::ROOT . '/readme.txt'),
            'src/AdminPage.php' => (string) file_get_contents(self::ROOT . '/src/AdminPage.php'),
        ];
        foreach ($sources as $rel => $src) {
            $this->assertSame(1, preg_match_all('/more than (\d+) Divi 5 block types/i', $src, $m), "{$rel} states the count once as “more than N Divi 5 block types”");
            $n = (int) $m[1][0];
            $this->assertLessThanOrEqual($actual, $n, "{$rel} claims more types than the validator knows ({$actual})");
            $this->assertGreaterThanOrEqual($actual - 15, $n, "{$rel} undersells the validator ({$actual}); update the number");
            $this->assertDoesNotMatchRegularExpression('/\b\d+\+ (Divi 5 )?(module|block)/i', $src, "{$rel}: no “NN+” style counts");
        }
    }

    public function testReadmeExplainsHowItWorksInPlainWords(): void
    {
        $r = (string) file_get_contents(self::ROOT . '/readme.txt');
        $this->assertStringContainsString('= How it works (in plain words) =', $r);
        $this->assertStringContainsString('= What makes it smart =', $r);
        $this->assertStringContainsString('= Examples to try =', $r);
        $this->assertMatchesRegularExpression('/^1\.\s.+\n2\.\s.+\n3\.\s.+\n4\.\s/m', $r, 'four numbered steps');
        $this->assertMatchesRegularExpression('/same page in, same (verdict|result) out|same input,? same (verdict|result)/i', $r, 'states the deterministic-checker idea');
        $this->assertMatchesRegularExpression('/more than \d+ (Divi 5 )?(module|block)/i', $r, 'quotes a real, computed number of known module types');
        foreach (['#1', 'best plugin', 'world\'s first', 'guaranteed', '5 stars', '★'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $r, "readme must not use hype/unverifiable claim: {$bad}");
        }
    }

    public function testReadmeStatesNoExternalServicesAndClaimsNoLockedFeatures(): void
    {
        $r = (string) file_get_contents(self::ROOT . '/readme.txt');
        $this->assertStringContainsString('does not connect to any external service', $r);
        foreach (['license key', 'licence key', 'activate your license', '$30', 'per year', 'Pro features already activated'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $r, "readme must not describe licensing ({$bad})");
        }
        $this->assertStringContainsString('not affiliated with Elegant Themes', $r);
        foreach (['unlock', 'locked', 'premium', 'upgrade to'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $r, "readme must not imply locked features ({$bad})");
        }
    }

    public function testReadmeFaqAnswersTheReviewersQuestions(): void
    {
        $r = (string) file_get_contents(self::ROOT . '/readme.txt');
        foreach (['= Does the AI get administrator access to my site? =', '= Where do images come from? =', '= Is my data sent anywhere? ='] as $q) {
            $this->assertStringContainsString($q, $r);
        }
    }

    public function testReadmeHeaderStaysWithinWordPressOrgLimits(): void
    {
        $r = (string) file_get_contents(self::ROOT . '/readme.txt');
        // The short description is the paragraph right after the header block; WordPress.org cuts it at 150 chars.
        $parts = preg_split('/\R\R/', $r);
        $this->assertIsArray($parts);
        $short = trim((string) $parts[1]);
        $this->assertStringStartsNotWith('==', $short);
        $this->assertLessThanOrEqual(150, strlen($short), "short description is too long: {$short}");
        $this->assertMatchesRegularExpression('/^Tags:\s*([^,\n]+,){0,4}[^,\n]+$/m', $r, 'at most 5 tags');
    }
}
