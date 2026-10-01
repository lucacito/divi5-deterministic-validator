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
 * The rule this test enforces: nothing licence-gated, remote-fetching, remote-administering
 * or custom-code-saving may enter wp-plugin/. Paid features live in a separate add-on
 * (staged in pro-addon/). Every scan is exercised by a planted violation in
 * testEachScanCatchesAPlantedViolation(), so a scan cannot silently stop working.
 * Do NOT weaken a scan to make it pass — fix the plugin, or add an explicit, justified exception.
 */
class WpOrgComplianceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../wp-plugin';

    /** Every text-like file type a plugin can ship. */
    private const EXTENSIONS = ['php', 'js', 'json', 'css', 'svg', 'txt', 'html', 'htm', 'md', 'pot', 'po', 'xml', 'yml', 'yaml'];

    private const FORBIDDEN_CODE = [
        'Licensing', 'LicenseClient', 'isPremium', 'MenuBuilder', 'CustomCss', 'PhpProposals',
        'set_custom_css', 'propose_php_snippet', 'set_front_page', 'set_primary_menu',
        'setFrontPage', 'setPrimaryMenu',
        'pre_set_site_transient', 'update_plugins', 'upgrade_url', 'PREMIUM',
    ];

    /** Any HTTP client or socket API, and any read of a remote URL. */
    private const REMOTE_FETCH = '/\b(?:wp_safe_remote_(?:get|post|request|head)|wp_remote_(?:get|post|request|head)|download_url|curl_init|curl_exec|curl_multi_exec|fsockopen|pfsockopen|stream_socket_client|get_headers|_wp_http_get_object)\s*\(|\bnew\s+\\\\?WP_Http\b|\bWP_Http::|\b(?:file_get_contents|fopen|readfile|file|copy)\(\s*[\'"](?:https?|ftp):/i';

    /** Local file reads (file_get_contents/fopen/readfile/file/copy): each call's argument text must be listed here. */
    private const LOCAL_READ = '/\b(file_get_contents|fopen|readfile|file|copy)\(\s*([^)]*?)\s*\)/';

    private const LOCAL_READS = [
        'src/SectionRecipes.php' => ['self::DATA' => "DATA = __DIR__ . '/../data/section-recipes.json' (bundled)"],
        'src/ImagePack.php'      => ['$path ?? self::DEFAULT_PATH' => "DEFAULT_PATH = __DIR__ . '/../assets/images/manifest.json'; \$path is only passed by tests"],
    ];

    private const IMAGE_HOSTS = ['picsum.photos', 'pravatar', 'randomuser', 'placehold.co', 'placehold.it', 'loremflickr', 'unsplash', 'pexels', 'pixabay', 'lorempixel', 'dummyimage'];

    /**
     * EXACT hosts that may appear as plain text/links in plugin files (never fetched by the plugin).
     * No subdomain wildcard. Each entry is justified; add nothing without a reason.
     */
    private const ALLOWED_HOSTS = [
        'www.w3.org'   => 'xmlns namespace identifier inside the bundled SVG images (an XML name, never fetched)',
        'www.gnu.org'  => 'GPL licence URI in the plugin header',
        'divi5lab.com' => 'Author URI, the connection guides and the one Pro add-on card link (links, never fetched)',
        'example.com'  => 'reserved documentation domain (RFC 2606) for examples',
    ];

    /** Hosts allowed even when no file uses them (reserved for documentation). */
    private const OPTIONAL_HOSTS = ['example.com'];

    /** Absolute and protocol-relative URLs (src/href/@import/url()). */
    private const URL_HOSTS = '#https?://([a-z0-9.-]+)|(?:src|href)\s*=\s*\\\\?["\']?//([a-z0-9.-]+)|@import\s+(?:url\(\s*)?["\']?//([a-z0-9.-]+)|url\(\s*["\']?//([a-z0-9.-]+)#i';

    /**
     * Commercial-language scan (in addition to FORBIDDEN_CODE). Any match in wp-plugin/ text files
     * (readme.txt has its own checks; the bundled validator/ is excluded) fails, unless it is
     * one of the explicit exceptions below or inside the one Pro card.
     */
    private const COMMERCIAL_WORDS = '/\bupgrade\b|\bpremium\b|\bPro\b|licen[sc]e key/i';

    /** Legitimate hits: [relative path, substring the matching line must contain, reason]. */
    private const COMMERCIAL_EXCEPTIONS = [
        ['src/UsageTracker.php', "wp-admin/includes/upgrade.php", 'WordPress core include that provides dbDelta() for the usage-log table'],
    ];

    /** APIs that change site settings, users, themes, plugins, menus or run code. */
    private const REMOTE_ADMIN = '/\b(?:set_theme_mod|remove_theme_mod|wp_update_nav_menu\w*|wp_create_nav_menu|wp_delete_nav_menu|switch_theme|activate_plugins?|deactivate_plugins|install_plugin|delete_plugins|wp_insert_user|wp_update_user|wp_create_user|wp_delete_user|wp_update_custom_css_post|create_function|show_on_front|page_on_front|page_for_posts|eval|assert)\s*\(|\b(?:show_on_front|page_on_front|page_for_posts)\b/i';

    /** [relative path, substring the matching line must contain, reason]. */
    private const REMOTE_ADMIN_EXCEPTIONS = [
        ['jhmg-ai-editor-for-divi-5.php', 'deactivate_plugins(plugin_basename(__FILE__));', 'the plugin deactivates ITSELF when activation requirements (PHP/WP version) are not met'],
    ];

    /** update_option/add_option may only write the plugin's own options, from these files. */
    private const OPTION_WRITERS = [
        'src/ApiKey.php'       => 'stores the plugin API key and its user (ai_editor_divi5_api_key / _api_user_id)',
        'src/UsageTracker.php' => 'stores the usage-log table schema version (ai_editor_divi5_db_version)',
    ];

    /** @return array<string,string> relative path => contents */
    private function files(): array
    {
        $root = realpath(self::ROOT);
        $out  = [];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && in_array(strtolower($f->getExtension()), self::EXTENSIONS, true)) {
                $out[substr($f->getPathname(), strlen($root) + 1)] = (string) file_get_contents($f->getPathname());
            }
        }
        ksort($out);
        return $out;
    }

    // ---------------------------------------------------------------
    // Scanners (pure: they take files and return hits, so planted violations can exercise them)
    // ---------------------------------------------------------------

    /** @param array<string,string> $files @return list<string> */
    private function remoteFetchHits(array $files): array
    {
        $hits = [];
        foreach ($files as $rel => $src) {
            if (str_starts_with($rel, 'validator/') || str_ends_with($rel, 'readme.txt')) {
                continue;
            }
            if (preg_match_all(self::REMOTE_FETCH, $src, $m)) {
                foreach ($m[0] as $hit) {
                    $hits[] = "$rel: {$hit}";
                }
            }
            if (in_array(pathinfo($rel, PATHINFO_EXTENSION), ['php', 'js'], true) && preg_match_all(self::LOCAL_READ, $src, $m, PREG_SET_ORDER)) {
                foreach ($m as [$call, , $arg]) {
                    if (!isset(self::LOCAL_READS[$rel][$arg])) {
                        $hits[] = "$rel: unlisted file read {$call}";
                    }
                }
            }
        }
        return $hits;
    }

    /** @param array<string,string> $files @return list<string> */
    private function hostHits(array $files, ?array &$used = null): array
    {
        $unknown = [];
        $used    = [];
        foreach ($files as $rel => $src) {
            if (!preg_match_all(self::URL_HOSTS, $src, $m)) {
                continue;
            }
            $hosts = array_filter(array_merge($m[1], $m[2], $m[3], $m[4]));
            foreach (array_unique(array_map('strtolower', $hosts)) as $host) {
                $host = rtrim($host, '.');
                if (isset(self::ALLOWED_HOSTS[$host])) {
                    $used[$host] = true;
                } else {
                    $unknown[] = "$rel → $host";
                }
            }
        }
        return $unknown;
    }

    /** @param array<string,string> $files @return array{0:list<string>,1:list<string>} [hits, card lines] */
    private function commercialHits(array $files): array
    {
        $hits     = [];
        $cardHits = [];
        foreach ($files as $rel => $src) {
            if (str_ends_with($rel, 'readme.txt') || str_starts_with($rel, 'validator/')) {
                continue;
            }
            [$cardStart, $cardEnd] = [-1, -1];
            if ($rel === 'src/AdminPage.php' && ($fn = strpos($src, 'private function proCard()')) !== false) {
                // The one allowed group: the Pro card (its docblock + private function proCard()).
                $cardStart = (int) strrpos(substr($src, 0, $fn), '/**');
                $next      = strpos($src, 'private function ', $fn + 10);
                $cardEnd   = $next === false ? strlen($src) : $next;
            }
            $offset = 0;
            foreach (explode("\n", $src) as $i => $line) {
                $lineStart = $offset;
                $offset   += strlen($line) + 1;
                if (!preg_match(self::COMMERCIAL_WORDS, $line, $m)) {
                    continue;
                }
                if ($rel === 'jhmg-ai-editor-for-divi-5.php' && preg_match('/^\s*\*\s*License( URI)?:/', $line)) {
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
        return [$hits, $cardHits];
    }

    /** @param array<string,string> $files @return list<string> */
    private function remoteAdminHits(array $files): array
    {
        $hits = [];
        foreach ($files as $rel => $src) {
            if (!in_array(pathinfo($rel, PATHINFO_EXTENSION), ['php', 'js'], true)) {
                continue;
            }
            foreach (explode("\n", $src) as $i => $line) {
                if (preg_match(self::REMOTE_ADMIN, $line, $m)) {
                    foreach (self::REMOTE_ADMIN_EXCEPTIONS as [$path, $mustContain]) {
                        if ($rel === $path && str_contains($line, $mustContain)) {
                            continue 2;
                        }
                    }
                    $hits[] = "$rel:" . ($i + 1) . " ({$m[0]})";
                }
                if (preg_match('/\b(?:update_option|add_option|update_site_option|add_site_option)\s*\(/', $line, $m)
                    && !(isset(self::OPTION_WRITERS[$rel]) && preg_match('/\(\s*self::(?:OPTION_KEY|OPTION_USER|DB_VERSION_OPTION)\s*,/', $line))) {
                    $hits[] = "$rel:" . ($i + 1) . " ({$m[0]}) writes an option";
                }
            }
        }
        return $hits;
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

    // ---------------------------------------------------------------
    // The scans, against the real plugin
    // ---------------------------------------------------------------

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
        $files = $this->files();
        $this->assertArrayHasKey('assets/images/README.txt', $files, '.txt files are scanned too');
        [$hits, $cardHits] = $this->commercialHits($files);
        $this->assertSame([], $hits, 'upgrade/premium/Pro/licence-key wording is only allowed in the one Dashboard card');
        $this->assertNotSame([], $cardHits, 'the Pro card is expected to exist');
        foreach ($cardHits as $line) {
            $this->assertStringContainsString('Pro add-on', $line, 'the card may only refer to "the Pro add-on"');
            $this->assertDoesNotMatchRegularExpression('/\bupgrade\b|\bpremium\b|licen[sc]e key/i', $line);
        }
    }

    public function testThePluginNeverFetchesRemoteFiles(): void
    {
        $this->assertSame([], $this->remoteFetchHits($this->files()));
    }

    public function testThePluginHasNoRemoteAdministrationOrCodeExecution(): void
    {
        $this->assertSame([], $this->remoteAdminHits($this->files()));
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

    public function testEveryUrlHostIsAllowlistedExactlyAndEveryAllowlistEntryIsUsed(): void
    {
        $unknown = $this->hostHits($this->files(), $used);
        $this->assertSame([], $unknown, 'every host in the plugin must be allowlisted (exact host) with a reason');
        foreach (array_keys(self::ALLOWED_HOSTS) as $host) {
            if (!in_array($host, self::OPTIONAL_HOSTS, true)) {
                $this->assertArrayHasKey($host, $used, "allowlisted host {$host} is no longer used — remove it");
            }
        }
    }

    public function testEachScanCatchesAPlantedViolation(): void
    {
        $planted = [
            'src/a.php' => "<?php wp_safe_remote_get(\$u);",
            'src/b.php' => "<?php download_url(\$u);",
            'src/c.php' => "<?php \$h = new WP_Http();",
            'src/d.php' => "<?php fsockopen('x', 80);",
            'src/e.php' => "<?php stream_socket_client('tcp://x:80');",
            'src/f.php' => "<?php file_get_contents(\$url);",
            'src/g.php' => "<?php file_get_contents('https://x.test/a');",
            'src/h.php' => "<?php wp_remote_post(\$u);",
        ];
        $hits = $this->remoteFetchHits($planted);
        foreach (array_keys($planted) as $rel) {
            $this->assertNotEmpty(array_filter($hits, fn($h) => str_starts_with($h, $rel)), "remote-fetch scan missed {$rel}");
        }

        $this->assertSame(
            ['a.html → cdn.evil.test', 'b.css → fonts.evil.test', 'c.css → img.evil.test', 'd.js → sub.divi5lab.com', 'e.md → evil.test'],
            $this->hostHits([
                'a.html' => '<script src="//cdn.evil.test/x.js"></script>',
                'b.css'  => '@import "//fonts.evil.test/f.css";',
                'c.css'  => '.x{background:url(//img.evil.test/a.png)}',
                'd.js'   => "fetch('https://sub.divi5lab.com/x')",
                'e.md'   => '[x](http://evil.test)',
            ])
        );

        [$c] = $this->commercialHits(['assets/images/README.txt' => "Get Pro\nupgrade now\nPremium\nenter your licence key"]);
        $this->assertCount(4, $c, 'commercial-word scan must cover .txt files');

        $admin = $this->remoteAdminHits([
            'src/x.php' => "<?php\nset_theme_mod('a', 1);\nwp_update_nav_menu_item(1, 0, []);\nswitch_theme('x');\nactivate_plugin('x');\nwp_insert_user([]);\neval(\$c);\nupdate_option('page_on_front', 2);\nupdate_option('blogname', 'x');",
            'src/ApiKey.php' => "<?php\nupdate_option('siteurl', 'x');",
        ]);
        $this->assertCount(10, $admin, 'remote-admin scan: ' . implode(' | ', $admin));
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
        foreach (['set_front_page', 'set_primary_menu', 'set_custom_css', 'propose_php_snippet', 'upgrade', 'premium', 'Custom CSS'] as $bad) {
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

    public function testPluginHeaderMakesNoOverclaimAndNoSalesLink(): void
    {
        $main = (string) file_get_contents(self::ROOT . '/jhmg-ai-editor-for-divi-5.php');
        $this->assertSame(1, preg_match('#/\*\*(.*?)\*/#s', $main, $h));
        $header = $h[1];
        $this->assertStringContainsString('Plugin Name:       JHMG AI Editor for Divi 5', $header);
        $this->assertDoesNotMatchRegularExpression('/^\s*\*\s*Plugin URI:/m', $header, 'Plugin URI pointed at the Pro sales page; it is optional and omitted');
        $this->assertDoesNotMatchRegularExpression('/impossible|\bPro\b|premium/i', $header);
        $this->assertStringContainsString('a broken page is never saved', $header);
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
            $this->assertLessThan($actual, $n, "{$rel} says “more than {$n}” but the validator knows {$actual}");
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
        foreach (['#1', 'best plugin', 'world\'s first', 'guaranteed', '5 stars', '★', 'impossible'] as $bad) {
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
        $this->assertStringContainsString('Divi is a trademark of Elegant Themes. This plugin is not affiliated with, endorsed by, or sponsored by Elegant Themes.', $r);
        $this->assertSame(1, substr_count(strtolower($r), 'not affiliated'), 'one non-affiliation sentence only');
        foreach (['unlock', 'locked', 'premium', 'upgrade to'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $r, "readme must not imply locked features ({$bad})");
        }
    }

    public function testReadmeDoesNotAdvertiseSiteLevelToolsAnywhere(): void
    {
        $r = (string) file_get_contents(self::ROOT . '/readme.txt');
        $this->assertSame(1, preg_match_all('/\bPro add-on\b/', $r), 'the add-on is mentioned in exactly one sentence');
        $this->assertStringContainsString('A separate Pro add-on (sold separately at https://divi5lab.com/plugins/divi-5-ai-editor) adds live stock-photo sourcing.', $r);
        // The only place menus / the front page may be named is the FAQ answer stating the plugin CANNOT change them.
        $faq = 'They cannot install plugins, change users, settings, menus or the front page, or run PHP or other server-side code.';
        $this->assertStringContainsString($faq, $r);
        $rest = str_replace($faq, '', $r);
        foreach (['site-level tools', 'site tools', 'front page', 'menu'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $rest, "readme must not advertise or list site-level tools ({$bad})");
        }
    }

    public function testReadmeFaqAnswersTheReviewersQuestions(): void
    {
        $r = (string) file_get_contents(self::ROOT . '/readme.txt');
        foreach (['= Does the AI get administrator access to my site? =', '= Where do images come from? =', '= Is my data sent anywhere? ='] as $q) {
            $this->assertStringContainsString($q, $r);
        }
        $this->assertStringContainsString("Divi's own module definitions", $r, 'rule sources are described accurately');
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
