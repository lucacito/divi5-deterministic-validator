# Pro Add-on 0.4.1: Hardening of the 0.4.0 Site Tools — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the deferred review items of Plans 4 and 5 (id-based audit cursor, per-call memoisation, `read_post` on link status, user-bound single-use preview tokens for both site-wide write tools, a strict REST body rule, clean OpenAPI summaries with 401/500, and four small minors) without adding a tool, route or operation.

**Architecture:** Every change is a small, test-first edit of existing Pro classes. The two preview tokens share one signed format (`PreviewToken` v2); the audit gets a deterministic id order; REST and OpenAPI stay generated from the tool list. Nothing under `wp-plugin/` or `src/` changes.

**Tech Stack:** PHP 8.1+, PHPUnit (`make test-pro`, shims in `pro-addon/tests/` and `tests/`), WordPress core functions, Divi 5.14 on the Docker site for the live gates.

**Spec:** `docs/superpowers/specs/2026-10-03-pro-0-4-1-hardening-design.md` (the owner-approved outline plus the decisions made while the code was built).

**Baseline:** `main` after the commit of this plan (code identical to `6ce824d`). `make test-pro` = `OK (702 tests, 2761 assertions)`; `make test` = `OK (726 tests, 5241 assertions)`. End state: `make test-pro` = `OK (756 tests, 3240 assertions)`; `make test` = `OK (726 tests, 5241 assertions)`.

## How this plan was made (read once)

The code was built and tested first, test-first, in a scratch worktree; this plan is generated from those verified commits, and every patch below was re-applied in a clean worktree in this order with the suites run after each step (the "Expected" lines are the measured results). Each task is a **tests patch** (apply, run, watch it fail), then a **source patch** (apply, run, watch it pass), then a commit. Patches are applied with the extractor, never retyped:

```bash
php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md <patch-name>
```

It finds the `Apply `<patch-name>`:` block below, runs `git apply --check`, then `git apply`, and prints `applied: <name>`. If it fails, STOP and report: do not hand-edit a patch.

## Global Constraints

- **The validator stays deterministic and untouched**: nothing in this plan edits `src/`, `wp-plugin/` or `wp-plugin/validator/`. Every layout write still goes through `\AiEditorDivi5\WP\LayoutGate::check( $content, $storedContent )`; this plan adds no write path.
- **No new tool, REST route or OpenAPI operation**: 16 free + 13 Pro = 29 of the ChatGPT importer's 30 stay as they are. Tool descriptions at most 300 characters (`ToolsTest`); OpenAPI descriptions and summaries at most 300 characters and no object schema without properties (`ProParityTest`).
- **Extension contract**: the add-on uses only the `jhmg_aied_*` hooks and the free classes listed in `Compat::REQUIRED_CLASSES` and pinned by `FreeApiCompatTest`.
- **No remote request, no PHP execution, no licence gating** changes. Nothing is pushed, uploaded, released or merged anywhere but local `main` (and that only when the owner says).
- **Tokens and secrets**: a preview token proves "this user previewed exactly this"; it never replaces the capability checks, the changed-since check or `LayoutGate`.
- Namespace `AiEditorDivi5\Pro`; PHP 8.1 (no standalone `null` return types, no `readonly` classes).

## Review Focus

Failure modes a happy-path test would miss, most likely first. Each is pinned by a test in the task named in brackets.

1. **A spent preview token coming back to life**: after the real run and an `undo_batch` the content is exactly as previewed again, so a token that only checks content would apply the change a second time; a token must also stop working for another user, after 30 minutes, and for other arguments, while a refusal must not use the token up [T2: `testATokenWorksOnlyOnce...`, `testATokenOnlyWorksForTheUserWhoPreviewed`, `testATokenExpires...`, `PreviewTokenTest`].
2. **The audit window shifting**: a page created below the cursor and a page deleted before it between two calls must neither repeat nor skip a page, and the live header/footer (ids that can be below or above the pages) must sit in the same id order [T1: `testPagesCreatedOrDeletedBetweenCallsCannotShiftTheWindow`, `testPagesAndLayoutsShareOneIdOrder`].
3. **The audit telling more than the user may know**: the status of a linked draft or private post the user cannot read must not appear, while the link is still reported as not working for visitors [T1: `testTheStatusOfALinkedPostTheUserCannotReadIsNotRevealed`].
4. **A REST tool running on a body it never read**: a form or text/plain body must be a 400, while an empty body, and a normal JSON object, keep working [T3].
5. **A cut OpenAPI summary that confuses the importer or a person**: a summary ending mid-word or inside an open bracket or quote, or a spec losing its 401/500 [T4: `ProOpenApiTest`].
6. **A live script run against a real site**: the `verify-pro-*` scripts create and delete content and must stop on any host that is not local [T6: `DevGuardTest`].

## Execution notes (read before Task 0)

- **Run the whole plan in the MAIN checkout on a branch**, not a worktree: the Docker site mounts `./pro-addon` and `./scripts` from the checkout where `docker compose` runs (Task 8 needs it). The first step creates `feat/pro-0-4-1` from `main`.
- **Task 8 is live**: the Pro add-on is INACTIVE on the dev site. Activate it for the runs and deactivate it afterwards, always with `php -d memory_limit=1G /usr/local/bin/wp ...` inside the container (Divi 5 exhausts the default 128 MB and prints a misleading "critical error"). Never `wp plugin delete` anything.
- **Never type a JSON-style unicode escape (a backslash followed by `u` and four hex digits) into a command or a file written through a tool call**: the tool channel turns it into the character itself and silently changes the file. Patches are applied by the extractor, which copies bytes.
- **Commit trailer, exactly** `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; subagents must not substitute their own model name. Check `git log -1 --format=%B` after every implementer.
- zsh: do not `cd` into subfolders in the Bash tool (the directory persists); use absolute paths. `echo =====` is parsed as a command; do not use it.
- **Reviewers**: Task 2 (tokens guard every site-wide write) and the final whole-branch review run on the most capable model (opus); verify each reviewer claim with grep or a PHP run before acting on it; fix findings in ONE dispatch. Do not push, upload or release anything.

---

## File Structure

| Path | Change |
|---|---|
| `pro-addon/src/Site/PreviewToken.php` | v2: user, nonce, `spend()` |
| `pro-addon/src/Tools/AuditSite.php` | id cursor, per-call memoisation, `read_post` |
| `pro-addon/src/Tools/FindReplaceSite.php`, `ManageThemeTemplate.php` | use the v2 token; the template write no-op case |
| `pro-addon/src/Rest/ProRest.php`, `ProOpenApi.php` | strict body; clean summaries, 401/500 |
| `pro-addon/src/ThemeBuilder/LayoutWriter.php`, `src/Compat.php` | verdict on a no-change dry run; `GlobalStyles` required |
| `scripts/dev-guard.php` (new), `scripts/verify-pro-*.php` | refuse a non-local site; live proof of the new rules |
| `pro-addon/tests/*`, `tests/bootstrap.php`, `tests/WpRestShims.php` | the tests of every task and the shared shims |
| `pro-addon/readme.txt`, `README.md`, `main file`, `docs/EXTENDING.md`, `CLAUDE.md` | 0.4.1 housekeeping |

---

### Task 0: Branch and baseline

- [ ] **Step 1: Create the branch from main**

```bash
git switch main && git status --short && git switch -c feat/pro-0-4-1
```
Expected: no output from `git status --short` (the spec and this plan are already committed on `main`), then `Switched to a new branch 'feat/pro-0-4-1'`.

- [ ] **Step 2: Confirm the baseline**

Run: `make test-pro 2>&1 | tail -3` → `OK (702 tests, 2761 assertions)`; `make test 2>&1 | tail -3` → `OK (726 tests, 5241 assertions)`.

### Task 1: `audit_site`: id cursor, one lookup per distinct link, read_post

**What:** `audit_site` windowing and cost. The cursor becomes the id of the last audited item: pages and the live header/footer are sorted into ONE post-id order and a call audits the next 25 items with an id above the cursor, so pages created or deleted between two calls cannot shift the window. Link lookups (`url_to_postid` + `get_post_status` + `read_post`) are cached per distinct path for one call; the site's known preset/colour ids are read once per call (`GlobalStyles::knownIds()` through an injectable closure, then `StyleReferences::advise()` per item), so `GlobalStyles` joins `Compat::REQUIRED_CLASSES` and `FreeApiCompatTest`. The status of a linked post the user cannot `read_post` is shown as "not visible to you". Nothing is remembered between calls.

**Files:** Modify: `pro-addon/src/Tools/AuditSite.php`, `pro-addon/src/Compat.php`; Test: `pro-addon/tests/AuditSiteTest.php`, `pro-addon/tests/FreeApiCompatTest.php`, `pro-addon/tests/WpSiteShims.php` (the `url_to_postid` shim counts its calls)

**Interfaces:** Produces: `AuditSite::__construct( ?\Closure $styleAdvisor = null, int $maxFindings = 200, ?\Closure $knownIds = null )`; `audit_site` input `cursor` = id of the last audited item (0 = start), output `next_cursor` = that id or null.

- [ ] **Step 1: Apply the tests patch**

Apply `T1-tests`:

`````diff
diff --git a/pro-addon/tests/AuditSiteTest.php b/pro-addon/tests/AuditSiteTest.php
index 72670a9..4869502 100644
--- a/pro-addon/tests/AuditSiteTest.php
+++ b/pro-addon/tests/AuditSiteTest.php
@@ -29,6 +29,7 @@ class AuditSiteTest extends TestCase
         $this->setUpThemeBuilder();
         $this->tool = new AuditSite();
         $GLOBALS['__wp_caps']['edit_posts'] = true;
+        $GLOBALS['__wp_caps']['read_post']  = true;
         $GLOBALS['__wp_posts'][5]->post_content = '';
         // link targets (plain objects: the WP_Post shim has no post_name property)
         $GLOBALS['__wp_posts'][90] = (object) [ 'ID' => 90, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About', 'post_content' => '', 'post_name' => 'about' ];
@@ -130,21 +131,103 @@ class AuditSiteTest extends TestCase
         $this->assertStringContainsString( '/blog/missing/', $b['LINK_NO_POST'][0]['detail'] );
     }
 
-    public function testItIsPaginatedTwentyFivePerCall(): void
+    public function testItIsPaginatedTwentyFivePerCallWithTheLastAuditedIdAsTheCursor(): void
     {
         for ( $i = 100; $i < 160; $i++ ) {
             $this->divi( $i, self::page() );
         }
         $r = $this->tool->run( [ 'scope' => 'pages' ] );
-        $this->assertSame( [ 60, 25, 25 ], [ $r['total_items'], $r['scanned'], $r['next_cursor'] ] );
-        $r = $this->tool->run( [ 'scope' => 'pages', 'cursor' => 25 ] );
-        $this->assertSame( [ 25, 50 ], [ $r['scanned'], $r['next_cursor'] ] );
-        $r = $this->tool->run( [ 'scope' => 'pages', 'cursor' => 50 ] );
+        $this->assertSame( [ 60, 25, 124 ], [ $r['total_items'], $r['scanned'], $r['next_cursor'] ] );
+        $r = $this->tool->run( [ 'scope' => 'pages', 'cursor' => 124 ] );
+        $this->assertSame( [ 25, 149 ], [ $r['scanned'], $r['next_cursor'] ] );
+        $r = $this->tool->run( [ 'scope' => 'pages', 'cursor' => 149 ] );
         $this->assertSame( [ 10, null ], [ $r['scanned'], $r['next_cursor'] ] );
         $r = $this->tool->run( [ 'scope' => 'pages', 'cursor' => 500 ] );
         $this->assertSame( [ 0, null ], [ $r['scanned'], $r['next_cursor'] ] );
     }
 
+    public function testPagesCreatedOrDeletedBetweenCallsCannotShiftTheWindow(): void
+    {
+        for ( $i = 100; $i < 160; $i++ ) {
+            $this->divi( $i, self::page() );
+        }
+        $r = $this->tool->run( [ 'scope' => 'pages' ] );
+        $this->assertSame( 124, $r['next_cursor'] );
+        // Between the calls a page with a LOWER id appears and an already audited page is deleted: an index offset
+        // would now skip or repeat pages, the id cursor continues exactly after page 124.
+        $this->divi( 50, self::page( '/gone/' ) );
+        unset( $GLOBALS['__wp_posts'][100] );
+        $r = $this->tool->run( [ 'scope' => 'pages', 'cursor' => 124 ] );
+        $this->assertSame( [ 25, 149 ], [ $r['scanned'], $r['next_cursor'] ] );
+        $this->assertSame( [], $r['findings'] );
+        $r = $this->tool->run( [ 'scope' => 'pages', 'cursor' => 149 ] );
+        $this->assertSame( [ 10, null ], [ $r['scanned'], $r['next_cursor'] ] );
+    }
+
+    public function testPagesAndLayoutsShareOneIdOrder(): void
+    {
+        $GLOBALS['__wp_posts'][201]->post_content = str_replace( 'wp:divi/shop', 'wp:divi/not-a-module', self::header() );
+        $this->divi( 11, self::page() );
+        for ( $i = 301; $i <= 330; $i++ ) {
+            $this->divi( $i, self::page() );
+        }
+        $r = $this->tool->run( [] );
+        $this->assertSame( [ 33, 25, 322 ], [ $r['total_items'], $r['scanned'], $r['next_cursor'] ], 'ids 11, 201, 202 then 301..322' );
+        $this->assertNotEmpty( self::byItem( $r, 201 ), 'the layout with an id below the pages is in the first window' );
+        $r = $this->tool->run( [ 'cursor' => 322 ] );
+        $this->assertSame( [ 8, null ], [ $r['scanned'], $r['next_cursor'] ] );
+    }
+
+    public function testLinkLookupsAreMemoisedWithinOneCallButNotAcrossCalls(): void
+    {
+        $this->divi( 11, self::page( '/about/' ) . self::page( '/missing/' ) );
+        $this->divi( 12, self::page( '/about/' ) . self::page( '/missing/' ) );
+        $this->divi( 13, self::page( '/about/' ) );
+        $GLOBALS['__url_to_postid_calls'] = 0;
+        $r = $this->tool->run( [ 'scope' => 'pages' ] );
+        $this->assertSame( 2, $GLOBALS['__url_to_postid_calls'], 'one lookup per distinct link, not per item' );
+        $this->assertSame( 1, self::byItem( $r, 11 )['LINK_NO_POST'][0]['count'] );
+        $this->assertSame( 1, self::byItem( $r, 12 )['LINK_NO_POST'][0]['count'] );
+        $this->assertSame( [], self::byItem( $r, 13 ) );
+        $this->tool->run( [ 'scope' => 'pages' ] );
+        $this->assertSame( 4, $GLOBALS['__url_to_postid_calls'], 'a second call looks again: the site may have changed' );
+    }
+
+    public function testTheSitesKnownStyleIdsAreReadOncePerCall(): void
+    {
+        $reads = 0;
+        $known = static function () use ( &$reads ): array {
+            $reads++;
+
+            return [ 'presets' => [ 'preset-ok' => true ], 'colors' => [ 'gcid-ok' => true ] ];
+        };
+        $tool    = new AuditSite( null, AuditSite::MAX_FINDINGS, $known );
+        $content = str_replace( 'wp:divi/button {', 'wp:divi/button {"modulePreset":["preset-x"],', self::page( '/about/' ) );
+        foreach ( [ 11, 12, 13 ] as $id ) {
+            $this->divi( $id, $content );
+        }
+        $r = $tool->run( [ 'scope' => 'pages' ] );
+        $this->assertSame( 1, $reads, 'one read of the site\'s presets and colors for all three items' );
+        foreach ( [ 11, 12, 13 ] as $id ) {
+            $this->assertSame( 'preset-x', self::byItem( $r, $id )['UNKNOWN_PRESET_ID'][0]['detail'] );
+        }
+        $tool->run( [ 'scope' => 'pages' ] );
+        $this->assertSame( 2, $reads );
+    }
+
+    public function testTheStatusOfALinkedPostTheUserCannotReadIsNotRevealed(): void
+    {
+        $this->divi( 11, self::page( '/draft-page/' ) );
+        $r = $this->tool->run( [ 'scope' => 'pages' ] );
+        $this->assertStringContainsString( '/draft-page/ (draft)', self::byItem( $r, 11 )['LINK_NOT_PUBLISHED'][0]['detail'] );
+        $GLOBALS['__wp_caps']['read_post:91'] = false;
+        $r = $this->tool->run( [ 'scope' => 'pages' ] );
+        $a = self::byItem( $r, 11 );
+        $this->assertSame( 1, $a['LINK_NOT_PUBLISHED'][0]['count'], 'it is still a link that does not work for visitors' );
+        $this->assertStringContainsString( '/draft-page/ (not visible to you)', $a['LINK_NOT_PUBLISHED'][0]['detail'] );
+        $this->assertStringNotContainsString( '(draft)', $a['LINK_NOT_PUBLISHED'][0]['detail'] );
+    }
+
     public function testFindingsAreCappedButTheTotalsStayComplete(): void
     {
         $tool = new AuditSite( null, 3 );
@@ -274,5 +357,6 @@ class AuditSiteTest extends TestCase
         $d = $this->tool->definition();
         $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
         $this->assertSame( [], $d['inputSchema']['required'] );
+        $this->assertStringContainsString( 'id of the last item', $d['inputSchema']['properties']['cursor']['description'] );
     }
 }
diff --git a/pro-addon/tests/FreeApiCompatTest.php b/pro-addon/tests/FreeApiCompatTest.php
index 3c5bc89..f2ac5b7 100644
--- a/pro-addon/tests/FreeApiCompatTest.php
+++ b/pro-addon/tests/FreeApiCompatTest.php
@@ -32,6 +32,7 @@ class FreeApiCompatTest extends TestCase
             [ $w . 'DryRun', 'preview', [ 'before' => 'string', 'after' => 'string' ], 'array' ],
             [ $w . 'StyleReferences', 'attach', [ 'payload' => 'array', 'content' => 'string' ], 'array' ],
             [ $w . 'StyleReferences', 'advise', [ 'content' => 'string', 'known' => 'array' ], '?array' ],
+            [ $w . 'GlobalStyles', 'knownIds', [], 'array' ],
         ];
     }
 
diff --git a/pro-addon/tests/WpSiteShims.php b/pro-addon/tests/WpSiteShims.php
index 2cad10e..b808cc6 100644
--- a/pro-addon/tests/WpSiteShims.php
+++ b/pro-addon/tests/WpSiteShims.php
@@ -42,6 +42,7 @@ if ( ! function_exists( 'get_posts' ) ) {
 if ( ! function_exists( 'url_to_postid' ) ) {
     /** Resolves `?p=ID`, `?page_id=ID` and `/slug/` against the stored posts' `post_name`; 0 when nothing matches. */
     function url_to_postid( $url ) {
+        $GLOBALS['__url_to_postid_calls'] = ( $GLOBALS['__url_to_postid_calls'] ?? 0 ) + 1;
         $parts = parse_url( (string) $url );
         parse_str( (string) ( $parts['query'] ?? '' ), $q );
         foreach ( [ 'p', 'page_id' ] as $k ) {
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md T1-tests` → `applied: T1-tests`.

- [ ] **Step 2: Run the Pro suite and watch it fail**

Run: `make test-pro 2>&1 | tail -40`. Expected: FAIL (red). `Tests: 708, Assertions: 2769, Failures: 7.`. Failing tests: `testItIsPaginatedTwentyFivePerCallWithTheLastAuditedIdAsTheCursor`, `testPagesCreatedOrDeletedBetweenCallsCannotShiftTheWindow`, `testPagesAndLayoutsShareOneIdOrder`, `testLinkLookupsAreMemoisedWithinOneCallButNotAcrossCalls`, `testTheSitesKnownStyleIdsAreReadOncePerCall`, `testTheStatusOfALinkedPostTheUserCannotReadIsNotRevealed`, `testItIsRegisteredAndNeedsEditPages`.

- [ ] **Step 3: Apply the source patch**

Apply `T1-src`:

`````diff
diff --git a/pro-addon/src/Compat.php b/pro-addon/src/Compat.php
index 419a5c3..b7497aa 100644
--- a/pro-addon/src/Compat.php
+++ b/pro-addon/src/Compat.php
@@ -23,6 +23,7 @@ final class Compat
         'AiEditorDivi5\\WP\\PageEditor',
         'AiEditorDivi5\\WP\\DryRun',
         'AiEditorDivi5\\WP\\StyleReferences',
+        'AiEditorDivi5\\WP\\GlobalStyles',
         'AiEditorDivi5\\WP\\ExtensionGuard',
         'AiEditorDivi5\\WP\\LayoutGate',
         'AiEditorDivi5\\WP\\LayoutDiff',
diff --git a/pro-addon/src/Tools/AuditSite.php b/pro-addon/src/Tools/AuditSite.php
index 4aab07d..b9d22b1 100644
--- a/pro-addon/src/Tools/AuditSite.php
+++ b/pro-addon/src/Tools/AuditSite.php
@@ -9,6 +9,7 @@ use AiEditorDivi5\Pro\ProTool;
 use AiEditorDivi5\Pro\Site\LinkScanner;
 use AiEditorDivi5\Pro\Site\SiteItems;
 use AiEditorDivi5\Pro\ThemeBuilder\ThemeLayouts;
+use AiEditorDivi5\WP\GlobalStyles;
 use AiEditorDivi5\WP\LayoutGate;
 use AiEditorDivi5\WP\StyleReferences;
 
@@ -20,8 +21,9 @@ if ( ! defined( 'ABSPATH' ) ) {
  * A read-only, deterministic audit of the site's Divi 5 pages and live header/footer: layout checker violations
  * (including custom code the plugin will not save), preset and global-color ids the site does not have, and internal
  * links that point at no page or at an unpublished one (codes LINK_NO_POST, LINK_NOT_PUBLISHED, UNKNOWN_PRESET_ID,
- * UNKNOWN_COLOR_ID, and LINKS_NOT_ALL_CHECKED when an item has more links than the scanner reads). 25 items per call with a cursor. No AI, no remote request,
- * nothing is ever written.
+ * UNKNOWN_COLOR_ID, and LINKS_NOT_ALL_CHECKED when an item has more links than the scanner reads). 25 items per call; the
+ * items are in post-id order and the cursor is the id of the last item audited, so pages created or deleted between two
+ * calls cannot shift the window. No AI, no remote request, nothing is ever written.
  */
 final class AuditSite implements ProTool
 {
@@ -37,12 +39,23 @@ final class AuditSite implements ProTool
 
     private \Closure $styleAdvisor;
 
+    /** @var \Closure():array{presets: ?array<string,true>, colors: ?array<string,true>} */
+    private \Closure $knownIds;
+
+    /** @var array{presets: ?array<string,true>, colors: ?array<string,true>}|null the site's ids, read once per run() */
+    private ?array $known = null;
+
+    /** @var array<string,array{id:int, status:string, readable:bool}> link path => what it resolves to, for one run() */
+    private array $links = [];
+
     /**
      * @param (\Closure(string):?array<string,mixed>)|null $styleAdvisor fn(content): the free plugin's style-reference advisory or null
+     * @param (\Closure():array{presets: ?array<string,true>, colors: ?array<string,true>})|null $knownIds fn(): the preset and color ids the site has
      */
-    public function __construct( ?\Closure $styleAdvisor = null, private int $maxFindings = self::MAX_FINDINGS )
+    public function __construct( ?\Closure $styleAdvisor = null, private int $maxFindings = self::MAX_FINDINGS, ?\Closure $knownIds = null )
     {
-        $this->styleAdvisor = $styleAdvisor ?? static fn ( string $content ): ?array => StyleReferences::attach( [], $content )['advisories']['style_references'] ?? null;
+        $this->knownIds     = $knownIds ?? static fn (): array => GlobalStyles::knownIds();
+        $this->styleAdvisor = $styleAdvisor ?? fn ( string $content ): ?array => StyleReferences::advise( $content, $this->known ??= ( $this->knownIds )() );
     }
 
     public function name(): string
@@ -59,12 +72,12 @@ final class AuditSite implements ProTool
     {
         return [
             'name'        => self::NAME,
-            'description' => 'Read-only audit of Divi 5 pages and the live header/footer: layout check violations, unknown preset or color ids, and internal links to missing or unpublished pages. 25 items per call; pass the returned next_cursor to continue. Makes no remote request.',
+            'description' => 'Read-only audit of Divi 5 pages and the live header/footer: layout check violations, unknown preset or color ids, and internal links to missing or unpublished pages. 25 items per call in id order; pass the returned next_cursor to continue. Makes no remote request.',
             'inputSchema' => [
                 'type'       => 'object',
                 'properties' => [
                     'scope'  => [ 'type' => 'string', 'description' => 'pages, layouts or all (default all)', 'enum' => SiteItems::SCOPES ],
-                    'cursor' => [ 'type' => 'integer', 'description' => 'Where to continue: the next_cursor of the previous call (default 0)' ],
+                    'cursor' => [ 'type' => 'integer', 'description' => 'Where to continue: the next_cursor of the previous call, the id of the last item audited (default 0 = from the start)' ],
                 ],
                 'required'   => [],
             ],
@@ -82,10 +95,16 @@ final class AuditSite implements ProTool
             throw new \InvalidArgumentException( 'You do not have permission to read header and footer layouts (edit_theme_options and Divi\'s Theme Builder permission).' );
         }
 
+        $this->known = null;
+        $this->links = [];
+
         $set   = SiteItems::collect( $scope, SiteItems::AUDIT_STATUSES, null );
-        $total = count( $set['items'] );
-        $slice = array_slice( $set['items'], $cursor, self::PAGE_SIZE );
-        $host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
+        $items = $set['items'];
+        usort( $items, static fn ( array $a, array $b ): int => $a['id'] <=> $b['id'] );
+        $total     = count( $items );
+        $remaining = array_values( array_filter( $items, static fn ( array $item ): bool => $item['id'] > $cursor ) );
+        $slice     = array_slice( $remaining, 0, self::PAGE_SIZE );
+        $host      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
 
         $findings = [];
         $totals   = [];
@@ -111,7 +130,7 @@ final class AuditSite implements ProTool
             'findings'           => array_slice( $findings, 0, $this->maxFindings ),
             'findings_truncated' => $truncated,
             'totals_by_code'     => $totals,
-            'next_cursor'        => $cursor + self::PAGE_SIZE < $total ? $cursor + self::PAGE_SIZE : null,
+            'next_cursor'        => count( $remaining ) > self::PAGE_SIZE ? $slice[ self::PAGE_SIZE - 1 ]['id'] : null,
         ];
         if ( $set['truncated'] ) {
             $out['note'] = 'This site has more than ' . SiteItems::PAGE_LIMIT . ' Divi 5 pages; only the first ' . SiteItems::PAGE_LIMIT . ' (by id) are audited.';
@@ -160,14 +179,16 @@ final class AuditSite implements ProTool
         $base    = ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? $host ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
         $paths   = LinkScanner::internalPaths( $content, $host );
         foreach ( $paths as $path ) {
-            $id = (int) url_to_postid( $base . $path );
-            if ( 0 === $id ) {
+            // One lookup per distinct link in a run: menus and footers repeat the same links on every page.
+            $this->links[ $path ] ??= self::resolve( $base . $path );
+            $link = $this->links[ $path ];
+            if ( 0 === $link['id'] ) {
                 $missing[] = $path;
                 continue;
             }
-            $status = (string) get_post_status( $id );
-            if ( 'publish' !== $status ) {
-                $draft[] = $path . ' (' . ( '' === $status ? 'unknown' : $status ) . ')';
+            if ( 'publish' !== $link['status'] ) {
+                $label   = $link['readable'] ? ( '' === $link['status'] ? 'unknown' : $link['status'] ) : 'not visible to you';
+                $draft[] = $path . ' (' . $label . ')';
             }
         }
         if ( [] !== $missing ) {
@@ -182,4 +203,20 @@ final class AuditSite implements ProTool
 
         return $findings;
     }
+
+    /**
+     * What an internal address points at. The status of a post the user may not read is not told: it is still a link
+     * that does not work for visitors, but who has a private draft is not the audit's to say.
+     *
+     * @return array{id:int, status:string, readable:bool}
+     */
+    private static function resolve( string $url ): array
+    {
+        $id = (int) url_to_postid( $url );
+        if ( 0 === $id ) {
+            return [ 'id' => 0, 'status' => '', 'readable' => false ];
+        }
+
+        return [ 'id' => $id, 'status' => (string) get_post_status( $id ), 'readable' => current_user_can( 'read_post', $id ) ];
+    }
 }
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md T1-src` → `applied: T1-src`.

- [ ] **Step 4: Run the suites and watch them pass**

Run: `make test-pro 2>&1 | tail -3` → `OK (708 tests, 2788 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -q -F - <<'EOF'
feat(pro): audit_site pages by the last audited id, memoises lookups per call, hides the status of unreadable posts

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B | tail -2
```

### Task 2: Preview tokens: bound to the user, single use, 30 minutes (both tools)

**What:** One token format for both tools. `PreviewToken::make( $argsHash, $items, $now, $userId )`, `open( $token, $argsHash, $now, $userId )` and the new `spend( $token )` (a transient keyed by the token's nonce, TTL 30 minutes). `find_replace_site` spends the token on its first accepted real run (also when every previewed item turned out to be gone). `manage_theme_template` stops using its deterministic state hash: its token now carries `[ template id => state hash ]`, the user, the time and a nonce, with a `?\Closure $clock` constructor argument for tests, and its `token` argument may be up to 8000 characters. The test shim `get_current_user_id()` becomes switchable (`$GLOBALS['__wp_current_user_id']`).

**Files:** Modify: `pro-addon/src/Site/PreviewToken.php`, `pro-addon/src/Tools/FindReplaceSite.php`, `pro-addon/src/Tools/ManageThemeTemplate.php`, `tests/bootstrap.php`; Test: `pro-addon/tests/PreviewTokenTest.php`, `pro-addon/tests/FindReplaceSiteTest.php`, `pro-addon/tests/ManageThemeTemplateTest.php`

**Interfaces:** Produces: `PreviewToken::make( string, array, int, int ): string`, `PreviewToken::open( string, string, int, int ): ?array`, `PreviewToken::spend( string ): void`, `PreviewToken::TTL = 1800`; `ManageThemeTemplate::__construct( BatchLog $log, ?\Closure $clock = null )`.

- [ ] **Step 1: Apply the tests patch**

Apply `T2-tests`:

`````diff
diff --git a/pro-addon/tests/FindReplaceSiteTest.php b/pro-addon/tests/FindReplaceSiteTest.php
index 73270fb..2b5d835 100644
--- a/pro-addon/tests/FindReplaceSiteTest.php
+++ b/pro-addon/tests/FindReplaceSiteTest.php
@@ -27,6 +27,7 @@ class FindReplaceSiteTest extends TestCase
     protected function setUp(): void
     {
         $this->setUpThemeBuilder();
+        $GLOBALS['__wp_transients'] = [];
         $this->tool = new FindReplaceSite( $this->log, null, fn (): int => $this->now );
         $GLOBALS['__wp_caps']['edit_posts'] = true;
         foreach ( [ 11, 12, 13 ] as $id ) {
@@ -44,6 +45,7 @@ class FindReplaceSiteTest extends TestCase
 
     protected function tearDown(): void
     {
+        unset( $GLOBALS['__wp_current_user_id'] );
         $this->tearDownThemeBuilder();
     }
 
@@ -135,6 +137,43 @@ class FindReplaceSiteTest extends TestCase
         $this->refused( $this->args( [ 'token' => $p['token'] . 'x' ] ), 'needs the token' );
     }
 
+    public function testATokenWorksOnlyOnceEvenWhenAnUndoPutTheContentBack(): void
+    {
+        $p     = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
+        $first = $this->tool->run( $this->args( [ 'token' => $p['token'] ] ) );
+        $this->assertTrue( $first['changed'] );
+        // What undo_batch does: the previewed content is back. The old token must not apply the change a second time.
+        foreach ( [ 11, 12, 13, 14, 201, 202 ] as $id ) {
+            $GLOBALS['__wp_posts'][ $id ]->post_content = str_replace( 'AIED-NEW', 'AIED-OLD', $this->layoutContent( $id ) );
+        }
+        $this->refused( $this->args( [ 'token' => $p['token'] ] ), 'needs the token' );
+    }
+
+    public function testATokenOnlyWorksForTheUserWhoPreviewed(): void
+    {
+        $GLOBALS['__wp_current_user_id'] = 7;
+        $p                               = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
+        $GLOBALS['__wp_current_user_id'] = 8;
+        $this->refused( $this->args( [ 'token' => $p['token'] ] ), 'needs the token' );
+        $GLOBALS['__wp_current_user_id'] = 7;
+        $r                               = $this->tool->run( $this->args( [ 'token' => $p['token'] ] ) );
+        $this->assertTrue( $r['changed'], 'the refusal for another user did not use the token up' );
+    }
+
+    public function testARunThatSavesNothingStillSpendsItsToken(): void
+    {
+        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
+        foreach ( [ 11, 12, 13, 14, 201, 202 ] as $id ) {
+            $GLOBALS['__wp_posts'][ $id ]->post_content = self::text( 'Edited away' );
+        }
+        $r = $this->tool->run( $this->args( [ 'token' => $p['token'] ] ) );
+        $this->assertFalse( $r['changed'] );
+        $this->assertNotEmpty( $r['skipped'], 'the previewed items are reported as gone' );
+        // The text comes back: the spent token does not report the old items again and cannot apply anything.
+        $GLOBALS['__wp_posts'][11]->post_content = self::text( 'Back AIED-OLD' );
+        $this->refused( $this->args( [ 'token' => $p['token'] ] ), 'needs the token' );
+    }
+
     public function testATokenExpires(): void
     {
         $p        = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
diff --git a/pro-addon/tests/ManageThemeTemplateTest.php b/pro-addon/tests/ManageThemeTemplateTest.php
index 0d1041e..6b09d11 100644
--- a/pro-addon/tests/ManageThemeTemplateTest.php
+++ b/pro-addon/tests/ManageThemeTemplateTest.php
@@ -17,13 +17,15 @@ class ManageThemeTemplateTest extends TestCase
     use ThemeLayoutFixture;
 
     private ManageThemeTemplate $tool;
+    private int $now = 1000000;
 
     protected function setUp(): void
     {
         $this->setUpThemeBuilder();
         $GLOBALS['__divi_tb_store_calls'] = [];
         $GLOBALS['__divi_store_fail']     = false;
-        $this->tool                       = new ManageThemeTemplate( $this->log );
+        $GLOBALS['__wp_transients']       = [];
+        $this->tool                       = new ManageThemeTemplate( $this->log, fn (): int => $this->now );
         // Template 200: not the default, enabled, applies to pages. Template 210: the default website template.
         $GLOBALS['__wp_postmeta'][200] += [
             '_et_default' => '0', '_et_enabled' => '1', '_et_autogenerated_title' => '0',
@@ -43,7 +45,7 @@ class ManageThemeTemplateTest extends TestCase
     {
         $this->tearDownThemeBuilder();
         $GLOBALS['__divi_store_fail'] = false;
-        unset( $GLOBALS['__divi_store_mutate'] );
+        unset( $GLOBALS['__divi_store_mutate'], $GLOBALS['__wp_current_user_id'] );
     }
 
     /** @return array<string,mixed> */
@@ -178,6 +180,45 @@ class ManageThemeTemplateTest extends TestCase
         $this->refused( [ 'template_id' => 200, 'action' => 'assign', 'use_on' => [ 'singular:all' ], 'token' => $preview['token'] ], 'needs the token' );
     }
 
+    public function testItsSchemaOffersTheDryRunAndTheTokenAWriteNeeds(): void
+    {
+        $props = $this->tool->definition()['inputSchema']['properties'];
+        $this->assertSame( 'boolean', $props['dry_run']['type'] );
+        $this->assertSame( 'string', $props['token']['type'] );
+        $this->assertStringContainsString( 'works once', $props['token']['description'] );
+    }
+
+    public function testATokenWorksOnlyOnceEvenWhenTheStateIsBack(): void
+    {
+        $args    = [ 'template_id' => 200, 'action' => 'disable' ];
+        $preview = $this->tool->run( $args + [ 'dry_run' => true ] );
+        $this->assertTrue( $this->tool->run( $args + [ 'token' => $preview['token'] ] )['changed'] );
+        // What undo_batch does: the template is back as it was previewed. The old token must not disable it again.
+        $GLOBALS['__wp_postmeta'][200]['_et_enabled'] = '1';
+        $this->refused( $args + [ 'token' => $preview['token'] ], 'needs the token' );
+    }
+
+    public function testATokenOnlyWorksForTheUserWhoPreviewed(): void
+    {
+        $args                            = [ 'template_id' => 200, 'action' => 'disable' ];
+        $GLOBALS['__wp_current_user_id'] = 7;
+        $preview                         = $this->tool->run( $args + [ 'dry_run' => true ] );
+        $GLOBALS['__wp_current_user_id'] = 8;
+        $this->refused( $args + [ 'token' => $preview['token'] ], 'needs the token' );
+        $GLOBALS['__wp_current_user_id'] = 7;
+        $this->assertTrue( $this->tool->run( $args + [ 'token' => $preview['token'] ] )['changed'] );
+    }
+
+    public function testATokenExpiresAfterThirtyMinutes(): void
+    {
+        $args      = [ 'template_id' => 200, 'action' => 'disable' ];
+        $preview   = $this->tool->run( $args + [ 'dry_run' => true ] );
+        $this->now += \AiEditorDivi5\Pro\Site\PreviewToken::TTL + 1;
+        $this->refused( $args + [ 'token' => $preview['token'] ], 'needs the token' );
+        $this->now -= 60;
+        $this->assertTrue( $this->tool->run( $args + [ 'token' => $preview['token'] ] )['changed'], 'inside the 30 minutes it still works' );
+    }
+
     public function testNoChangeChangesAndRecordsNothing(): void
     {
         $r = $this->tool->run( [ 'template_id' => 200, 'action' => 'enable' ] );
diff --git a/pro-addon/tests/PreviewTokenTest.php b/pro-addon/tests/PreviewTokenTest.php
index 4759791..504429a 100644
--- a/pro-addon/tests/PreviewTokenTest.php
+++ b/pro-addon/tests/PreviewTokenTest.php
@@ -11,43 +11,105 @@ require_once __DIR__ . '/../src/autoload.php';
 
 class PreviewTokenTest extends TestCase
 {
+    protected function setUp(): void
+    {
+        $GLOBALS['__wp_transients'] = [];
+    }
+
+    /** A token's payload (base64url JSON) re-signed as the site would sign it. @param array<string,mixed> $data */
+    private static function signed( array $data ): string
+    {
+        $payload = rtrim( strtr( base64_encode( (string) json_encode( $data ) ), '+/', '-_' ), '=' );
+
+        return $payload . '.' . wp_hash( 'aied-site|' . $payload );
+    }
+
     public function testItRoundTripsTheItemMap(): void
     {
-        $t = PreviewToken::make( 'args1', [ 11 => 'aaaa', 12 => 'bbbb' ], 1000 );
-        $this->assertSame( [ 11 => 'aaaa', 12 => 'bbbb' ], PreviewToken::open( $t, 'args1', 1000 ) );
-        $this->assertSame( [], PreviewToken::open( PreviewToken::make( 'args1', [], 1000 ), 'args1', 1000 ), 'an empty map is still a valid token' );
+        $t = PreviewToken::make( 'args1', [ 11 => 'aaaa', 12 => 'bbbb' ], 1000, 7 );
+        $this->assertSame( [ 11 => 'aaaa', 12 => 'bbbb' ], PreviewToken::open( $t, 'args1', 1000, 7 ) );
+        $this->assertSame( [], PreviewToken::open( PreviewToken::make( 'args1', [], 1000, 7 ), 'args1', 1000, 7 ), 'an empty map is still a valid token' );
     }
 
     public function testItIsBoundToTheArguments(): void
     {
-        $t = PreviewToken::make( 'args1', [ 11 => 'aaaa' ], 1000 );
-        $this->assertNull( PreviewToken::open( $t, 'args2', 1000 ) );
+        $t = PreviewToken::make( 'args1', [ 11 => 'aaaa' ], 1000, 7 );
+        $this->assertNull( PreviewToken::open( $t, 'args2', 1000, 7 ) );
+    }
+
+    public function testItIsBoundToTheUserWhoPreviewed(): void
+    {
+        $t = PreviewToken::make( 'a', [ 11 => 'aaaa' ], 1000, 7 );
+        $this->assertNull( PreviewToken::open( $t, 'a', 1000, 8 ) );
+        $this->assertNull( PreviewToken::open( $t, 'a', 1000, 0 ) );
+        $this->assertNotNull( PreviewToken::open( $t, 'a', 1000, 7 ), 'refusing another user does not use the token up' );
     }
 
     public function testItExpiresAndRefusesATimeFromTheFuture(): void
     {
-        $t = PreviewToken::make( 'a', [ 1 => 'x' ], 1000 );
-        $this->assertNotNull( PreviewToken::open( $t, 'a', 1000 + PreviewToken::TTL ) );
-        $this->assertNull( PreviewToken::open( $t, 'a', 1000 + PreviewToken::TTL + 1 ) );
-        $this->assertNull( PreviewToken::open( $t, 'a', 999 ), 'made in the future: refused' );
+        $t = PreviewToken::make( 'a', [ 1 => 'x' ], 1000, 7 );
+        $this->assertNotNull( PreviewToken::open( $t, 'a', 1000 + PreviewToken::TTL, 7 ) );
+        $this->assertNull( PreviewToken::open( $t, 'a', 1000 + PreviewToken::TTL + 1, 7 ) );
+        $this->assertNull( PreviewToken::open( $t, 'a', 999, 7 ), 'made in the future: refused' );
+    }
+
+    public function testASpentTokenIsRefusedAndOtherTokensAreNot(): void
+    {
+        $t     = PreviewToken::make( 'a', [ 1 => 'x' ], 1000, 7 );
+        $other = PreviewToken::make( 'a', [ 1 => 'x' ], 1000, 7 );
+        $this->assertNotSame( $t, $other, 'two previews of the same thing are two tokens' );
+        $this->assertNotNull( PreviewToken::open( $t, 'a', 1000, 7 ) );
+        PreviewToken::spend( $t );
+        $this->assertNull( PreviewToken::open( $t, 'a', 1000, 7 ) );
+        PreviewToken::spend( $t );
+        $this->assertNull( PreviewToken::open( $t, 'a', 1000, 7 ), 'spending twice changes nothing' );
+        $this->assertNotNull( PreviewToken::open( $other, 'a', 1000, 7 ) );
+    }
+
+    public function testTheSpentMarkLivesAsLongAsTheTokenCouldBeUsed(): void
+    {
+        $t = PreviewToken::make( 'a', [ 1 => 'x' ], 1000, 7 );
+        PreviewToken::spend( $t );
+        $this->assertCount( 1, $GLOBALS['__wp_transients'] );
+    }
+
+    public function testSpendingATamperedOrMalformedTokenRecordsNothing(): void
+    {
+        $t = PreviewToken::make( 'a', [ 1 => 'x' ], 1000, 7 );
+        foreach ( [ '', 'x', $t . 'x', strrev( $t ), 'a.b' ] as $bad ) {
+            PreviewToken::spend( $bad );
+        }
+        $this->assertSame( [], $GLOBALS['__wp_transients'] );
     }
 
     public function testATamperedOrMalformedTokenIsRefused(): void
     {
-        $t               = PreviewToken::make( 'a', [ 1 => 'x' ], 1000 );
+        $t                 = PreviewToken::make( 'a', [ 1 => 'x' ], 1000, 7 );
         [ $payload, $sig ] = explode( '.', $t );
-        $forged          = rtrim( strtr( base64_encode( (string) json_encode( [ 'v' => 1, 'a' => 'a', 'i' => [ 1 => 'x', 2 => 'y' ], 't' => 1000 ] ) ), '+/', '-_' ), '=' );
+        $forged            = rtrim( strtr( base64_encode( (string) json_encode( [ 'v' => 2, 'a' => 'a', 'i' => [ 1 => 'x', 2 => 'y' ], 't' => 1000, 'u' => 7, 'n' => 'abcd' ] ) ), '+/', '-_' ), '=' );
         foreach ( [ '', 'x', $payload, $payload . '.', '.' . $sig, $forged . '.' . $sig, $payload . '.' . $sig . '.extra', $payload . '.' . strrev( $sig ), "{$payload}x.{$sig}" ] as $bad ) {
-            $this->assertNull( PreviewToken::open( $bad, 'a', 1000 ), json_encode( $bad ) );
+            $this->assertNull( PreviewToken::open( $bad, 'a', 1000, 7 ), json_encode( $bad ) );
         }
     }
 
     public function testAValidSignatureOverAnUnexpectedPayloadIsRefused(): void
     {
-        foreach ( [ 'not json', json_encode( [ 'v' => 2, 'a' => 'a', 'i' => [], 't' => 1000 ] ), json_encode( [ 'v' => 1, 'a' => 'a', 'i' => 'x', 't' => 1000 ] ), json_encode( [ 'v' => 1, 'a' => 'a', 'i' => [], 't' => '1000' ] ) ] as $json ) {
-            $payload = rtrim( strtr( base64_encode( (string) $json ), '+/', '-_' ), '=' );
-            $token   = $payload . '.' . wp_hash( 'aied-site|' . $payload );
-            $this->assertNull( PreviewToken::open( $token, 'a', 1000 ), (string) $json );
+        $good = [ 'v' => 2, 'a' => 'a', 'i' => [], 't' => 1000, 'u' => 7, 'n' => 'abcd' ];
+        $this->assertSame( [], PreviewToken::open( self::signed( $good ), 'a', 1000, 7 ), 'the control: this shape is accepted' );
+        $bad = [
+            'version 1 (no user, no nonce)' => [ 'v' => 1, 'a' => 'a', 'i' => [], 't' => 1000 ],
+            'unknown version'               => [ 'v' => 3 ] + $good,
+            'items not a map'               => [ 'i' => 'x' ] + $good,
+            'time not an integer'           => [ 't' => '1000' ] + $good,
+            'user not an integer'           => [ 'u' => '7' ] + $good,
+            'no nonce'                      => array_diff_key( $good, [ 'n' => 1 ] ),
+            'empty nonce'                   => [ 'n' => '' ] + $good,
+            'nonce not a string'            => [ 'n' => 5 ] + $good,
+        ];
+        foreach ( $bad as $name => $data ) {
+            $this->assertNull( PreviewToken::open( self::signed( $data ), 'a', 1000, 7 ), $name );
         }
+        $payload = rtrim( strtr( base64_encode( 'not json' ), '+/', '-_' ), '=' );
+        $this->assertNull( PreviewToken::open( $payload . '.' . wp_hash( 'aied-site|' . $payload ), 'a', 1000, 7 ), 'not json' );
     }
 }
diff --git a/tests/bootstrap.php b/tests/bootstrap.php
index ae8de68..988a6ba 100644
--- a/tests/bootstrap.php
+++ b/tests/bootstrap.php
@@ -226,7 +226,7 @@ if ( ! function_exists( 'wp_cache_delete' ) ) {
     function wp_cache_delete( $key, $group = '' ) { $GLOBALS['__wp_cache_deleted'][] = [ $key, $group ]; return true; }
 }
 if ( ! function_exists( 'get_current_user_id' ) ) {
-    function get_current_user_id() { return 7; }
+    function get_current_user_id() { return $GLOBALS['__wp_current_user_id'] ?? 7; }
 }
 
 // ---- Hook shims (filters/actions) ------------------------------------------------------
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md T2-tests` → `applied: T2-tests`.

- [ ] **Step 2: Run the Pro suite and watch it fail**

Run: `make test-pro 2>&1 | tail -40`. Expected: FAIL (red). `Tests: 719, Assertions: 2800, Errors: 2, Failures: 10.`. Failing tests: `testTheSpentMarkLivesAsLongAsTheTokenCouldBeUsed`, `testSpendingATamperedOrMalformedTokenRecordsNothing`, `testATokenWorksOnlyOnceEvenWhenAnUndoPutTheContentBack`, `testATokenOnlyWorksForTheUserWhoPreviewed`, `testARunThatSavesNothingStillSpendsItsToken`, `testItsSchemaOffersTheDryRunAndTheTokenAWriteNeeds`, `testATokenWorksOnlyOnceEvenWhenTheStateIsBack`, `testATokenExpiresAfterThirtyMinutes`, `testItIsBoundToTheUserWhoPreviewed`, `testASpentTokenIsRefusedAndOtherTokensAreNot`, `testAValidSignatureOverAnUnexpectedPayloadIsRefused`.

- [ ] **Step 3: Apply the source patch**

Apply `T2-src`:

`````diff
diff --git a/pro-addon/src/Site/PreviewToken.php b/pro-addon/src/Site/PreviewToken.php
index fd6cc0d..ae81565 100644
--- a/pro-addon/src/Site/PreviewToken.php
+++ b/pro-addon/src/Site/PreviewToken.php
@@ -10,38 +10,43 @@ if ( ! defined( 'ABSPATH' ) ) {
 
 /**
  * The preview token of a site-wide write. It is self-contained and signed (`wp_hash`): it carries a hash of the
- * arguments, the id => content-hash of every item the preview would change, and the time it was made. A real run
- * must present it; items whose content changed since the preview are then skipped, never overwritten.
+ * arguments, the id => content-hash of every item the preview would change, the time it was made, the user who made
+ * it and a one-time nonce. A real run must present it: it only opens for that user, for those arguments, within
+ * `TTL` seconds and before it was spent, and items whose content changed since the preview are skipped, never
+ * overwritten. `spend()` records the nonce in a transient, so a token cannot be replayed (for example after an
+ * `undo_batch` put the previewed content back). The record is best effort: an object cache that evicts it can only
+ * forget a spent token inside its 30 minutes, where the changed-since check still applies.
  */
 final class PreviewToken
 {
     public const TTL = 1800;
 
+    private const SPENT = 'aied_pro_tok_';
+
     /** @param array<int,string> $items item id => content hash */
-    public static function make( string $argsHash, array $items, int $now ): string
+    public static function make( string $argsHash, array $items, int $now, int $userId ): string
     {
-        $payload = rtrim( strtr( base64_encode( (string) json_encode( [ 'v' => 1, 'a' => $argsHash, 'i' => $items, 't' => $now ] ) ), '+/', '-_' ), '=' );
+        $payload = rtrim( strtr( base64_encode( (string) json_encode( [ 'v' => 2, 'a' => $argsHash, 'i' => $items, 't' => $now, 'u' => $userId, 'n' => bin2hex( random_bytes( 8 ) ) ] ) ), '+/', '-_' ), '=' );
 
         return $payload . '.' . wp_hash( 'aied-site|' . $payload );
     }
 
     /**
-     * @return array<int,string>|null the item map when the token is genuine, for these arguments and not expired
+     * @return array<int,string>|null the item map when the token is genuine, for these arguments and this user, not expired and not spent
      */
-    public static function open( string $token, string $argsHash, int $now ): ?array
+    public static function open( string $token, string $argsHash, int $now, int $userId ): ?array
     {
-        $parts = explode( '.', $token );
-        if ( 2 !== count( $parts ) || ! hash_equals( wp_hash( 'aied-site|' . $parts[0] ), $parts[1] ) ) {
-            return null;
-        }
-        $data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true );
-        if ( ! is_array( $data ) || 1 !== ( $data['v'] ?? null ) || ! hash_equals( $argsHash, (string) ( $data['a'] ?? '' ) ) ) {
+        $data = self::decode( $token );
+        if ( null === $data || ! hash_equals( $argsHash, (string) ( $data['a'] ?? '' ) ) || $userId !== ( $data['u'] ?? null ) ) {
             return null;
         }
         $t = $data['t'] ?? null;
         if ( ! is_int( $t ) || $now < $t || $now - $t > self::TTL || ! is_array( $data['i'] ?? null ) ) {
             return null;
         }
+        if ( false !== get_transient( self::key( $data['n'] ) ) ) {
+            return null;
+        }
         $items = [];
         foreach ( $data['i'] as $id => $hash ) {
             $items[ (int) $id ] = (string) $hash;
@@ -49,4 +54,33 @@ final class PreviewToken
 
         return $items;
     }
+
+    /** Marks a genuine token as used; anything else is ignored. */
+    public static function spend( string $token ): void
+    {
+        $data = self::decode( $token );
+        if ( null !== $data ) {
+            set_transient( self::key( $data['n'] ), 1, self::TTL );
+        }
+    }
+
+    /** @return array<string,mixed>|null the payload of a genuinely signed version 2 token with a user and a nonce */
+    private static function decode( string $token ): ?array
+    {
+        $parts = explode( '.', $token );
+        if ( 2 !== count( $parts ) || ! hash_equals( wp_hash( 'aied-site|' . $parts[0] ), $parts[1] ) ) {
+            return null;
+        }
+        $data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true );
+        if ( ! is_array( $data ) || 2 !== ( $data['v'] ?? null ) || ! is_int( $data['u'] ?? null ) || ! is_string( $data['n'] ?? null ) || '' === $data['n'] ) {
+            return null;
+        }
+
+        return $data;
+    }
+
+    private static function key( string $nonce ): string
+    {
+        return self::SPENT . substr( hash( 'sha256', $nonce ), 0, 40 );
+    }
 }
diff --git a/pro-addon/src/Tools/FindReplaceSite.php b/pro-addon/src/Tools/FindReplaceSite.php
index b709655..ca7b239 100644
--- a/pro-addon/src/Tools/FindReplaceSite.php
+++ b/pro-addon/src/Tools/FindReplaceSite.php
@@ -70,7 +70,7 @@ final class FindReplaceSite implements ProTool
                     'scope'    => [ 'type' => 'string', 'description' => 'pages (default), layouts or all', 'enum' => SiteItems::SCOPES ],
                     'page_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Optional. Only these page or layout ids' ],
                     'dry_run'  => [ 'type' => 'boolean', 'description' => 'Preview only; returns the token a real run needs' ],
-                    'token'    => [ 'type' => 'string', 'description' => 'The token from the dry run of the same arguments' ],
+                    'token'    => [ 'type' => 'string', 'description' => 'The token from your dry run of the same arguments; it works once, within 30 minutes' ],
                 ],
                 'required'   => [ 'find', 'replace' ],
             ],
@@ -126,8 +126,10 @@ final class FindReplaceSite implements ProTool
         $argsHash = Canon::hash( [ 'find' => $find, 'replace' => $replace, 'scope' => $scope, 'ids' => $ids ] );
         if ( [] === $candidates ) {
             // A real run whose every previewed item has since stopped matching: report them, do not stay silent.
-            $gone = $dry || null === $token ? null : PreviewToken::open( $token, $argsHash, ( $this->clock )() );
+            $gone = $dry || null === $token ? null : PreviewToken::open( $token, $argsHash, ( $this->clock )(), (int) get_current_user_id() );
             if ( null !== $gone && [] !== $gone ) {
+                PreviewToken::spend( (string) $token );
+
                 return [ 'changed' => false, 'dry_run' => false, 'replaced_in' => 0, 'skipped' => self::dropped( $gone, [] ), 'message' => 'Nothing was saved.' ] + $extra;
             }
 
@@ -150,10 +152,11 @@ final class FindReplaceSite implements ProTool
             return $this->preview( $plan, $argsHash ) + $extra;
         }
 
-        $map = null === $token ? null : PreviewToken::open( $token, $argsHash, ( $this->clock )() );
+        $map = null === $token ? null : PreviewToken::open( $token, $argsHash, ( $this->clock )(), (int) get_current_user_id() );
         if ( null === $map ) {
-            throw new \InvalidArgumentException( 'A real run needs the token from a dry run of the same arguments, made in the last 30 minutes. Call again with dry_run=true.' );
+            throw new \InvalidArgumentException( 'A real run needs the token from a dry run of the same arguments, made by you in the last 30 minutes and not used before. Call again with dry_run=true.' );
         }
+        PreviewToken::spend( (string) $token );
 
         return $this->apply( $plan, $map, $find ) + $extra;
     }
@@ -187,7 +190,7 @@ final class FindReplaceSite implements ProTool
             'would_change' => count( $map ),
             'would_skip'   => $skip,
             'items'        => $items,
-            'token'        => PreviewToken::make( $argsHash, $map, ( $this->clock )() ),
+            'token'        => PreviewToken::make( $argsHash, $map, ( $this->clock )(), (int) get_current_user_id() ),
             'message'      => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this token to apply it.',
         ];
     }
diff --git a/pro-addon/src/Tools/ManageThemeTemplate.php b/pro-addon/src/Tools/ManageThemeTemplate.php
index 81d4c04..3adeb40 100644
--- a/pro-addon/src/Tools/ManageThemeTemplate.php
+++ b/pro-addon/src/Tools/ManageThemeTemplate.php
@@ -9,6 +9,7 @@ use AiEditorDivi5\Pro\Batch\BatchLog;
 use AiEditorDivi5\Pro\Canon;
 use AiEditorDivi5\Pro\Kinds\ThemeTemplateKind;
 use AiEditorDivi5\Pro\ProTool;
+use AiEditorDivi5\Pro\Site\PreviewToken;
 use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
 use AiEditorDivi5\Pro\ThemeBuilder\TemplateStore;
 
@@ -19,8 +20,8 @@ if ( ! defined( 'ABSPATH' ) ) {
 /**
  * Enable, disable or re-target (where it applies) ONE existing template of the live Theme Builder. It never creates,
  * duplicates or deletes a template and never changes which layouts a template uses. Every real run needs the token
- * from a dry run of the same arguments, and the token is bound to the template's current state, so a template that
- * changed since the preview is refused.
+ * from a dry run of the same arguments: it is bound to the template's state at the preview (a template that changed
+ * since is refused), to the user who previewed, and to 30 minutes, and it works once.
  */
 final class ManageThemeTemplate implements ProTool
 {
@@ -30,8 +31,12 @@ final class ManageThemeTemplate implements ProTool
     private const MAX_RULES = 50;
     private const CONDITION = '/^[A-Za-z0-9_:\-]{1,200}$/D';
 
-    public function __construct( private BatchLog $log )
+    private \Closure $clock;
+
+    /** @param (\Closure():int)|null $clock the time in seconds (the tests move it) */
+    public function __construct( private BatchLog $log, ?\Closure $clock = null )
     {
+        $this->clock = $clock ?? static fn (): int => time();
     }
 
     public function name(): string
@@ -57,7 +62,7 @@ final class ManageThemeTemplate implements ProTool
                     'use_on'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'assign only: where the template applies (replaces the current list)' ],
                     'exclude_from' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'assign only: where it must not apply (replaces the current list)' ],
                     'dry_run'      => [ 'type' => 'boolean', 'description' => 'Preview only; returns the token a real run needs' ],
-                    'token'        => [ 'type' => 'string', 'description' => 'The token from the dry run of the same arguments' ],
+                    'token'        => [ 'type' => 'string', 'description' => 'The token from your dry run of the same arguments; it works once, within 30 minutes' ],
                 ],
                 'required'   => [ 'template_id', 'action' ],
             ],
@@ -73,7 +78,7 @@ final class ManageThemeTemplate implements ProTool
             throw new \InvalidArgumentException( '"action" must be one of: ' . implode( ', ', self::ACTIONS ) . '.' );
         }
         $dry   = Args::bool( $arguments, 'dry_run' );
-        $token = Args::string( $arguments, 'token', false, 200 );
+        $token = Args::string( $arguments, 'token', false, 8000 );
         $rules = self::rules( $arguments, $action );
 
         $before = TemplateStore::read( $id );
@@ -90,13 +95,17 @@ final class ManageThemeTemplate implements ProTool
         if ( [] === $diff ) {
             return [ 'changed' => false, 'dry_run' => $dry, 'template' => $label, 'message' => 'The template already is exactly like that; nothing changed.' ];
         }
-        $expected = self::token( $id, $action, $rules, $before );
+        $argsHash  = Canon::hash( [ 'id' => $id, 'action' => $action, 'rules' => $rules ] );
+        $stateHash = Canon::hash( $before );
+        $userId    = (int) get_current_user_id();
         if ( $dry ) {
-            return [ 'changed' => false, 'dry_run' => true, 'template' => $label, 'would_change' => $diff, 'token' => $expected, 'message' => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this token to apply it; it affects the whole site.' ];
+            return [ 'changed' => false, 'dry_run' => true, 'template' => $label, 'would_change' => $diff, 'token' => PreviewToken::make( $argsHash, [ $id => $stateHash ], ( $this->clock )(), $userId ), 'message' => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this token to apply it; it affects the whole site.' ];
         }
-        if ( null === $token || ! hash_equals( $expected, $token ) ) {
-            throw new \InvalidArgumentException( 'A real run needs the token from a dry run of the same arguments, taken while the template was in its current state. Call again with dry_run=true.' );
+        $map = null === $token ? null : PreviewToken::open( $token, $argsHash, ( $this->clock )(), $userId );
+        if ( null === $map || ! isset( $map[ $id ] ) || ! hash_equals( $map[ $id ], $stateHash ) ) {
+            throw new \InvalidArgumentException( 'A real run needs the token from a dry run of the same arguments, made by you in the last 30 minutes, not used before and taken while the template was in its current state. Call again with dry_run=true.' );
         }
+        PreviewToken::spend( (string) $token );
 
         $written = TemplateStore::write( $after );
         if ( null === $written ) {
@@ -185,13 +194,4 @@ final class ManageThemeTemplate implements ProTool
 
         return $out;
     }
-
-    /**
-     * @param array{use_on:?list<string>, exclude_from:?list<string>} $rules
-     * @param array<string,mixed> $state
-     */
-    private static function token( int $id, string $action, array $rules, array $state ): string
-    {
-        return wp_hash( 'aied-template|' . Canon::hash( [ 'id' => $id, 'action' => $action, 'rules' => $rules, 'state' => $state ] ) );
-    }
 }
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md T2-src` → `applied: T2-src`.

- [ ] **Step 4: Run the suites and watch them pass**

Run: `make test-pro 2>&1 | tail -3` → `OK (719 tests, 2826 assertions)`. Run: `make test 2>&1 | tail -3` → `OK (726 tests, 5241 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -q -F - <<'EOF'
feat(pro): preview tokens are bound to the user, single use and expire (find_replace_site, manage_theme_template)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B | tail -2
```

### Task 3: REST: a body that was not parsed as JSON is a 400

**What:** `ProRest::handle()` returns a 400 `invalid_argument` when WordPress parsed no JSON but the raw body is not blank (a form or text/plain body would otherwise run a tool with its defaults); an empty or blank body is still "no arguments". The shared `WP_REST_Request` test shim learns a raw body (`get_body()`) and a "not parsed as JSON" state.

**Files:** Modify: `pro-addon/src/Rest/ProRest.php`, `tests/WpRestShims.php`; Test: `pro-addon/tests/ProRestTest.php`

**Interfaces:** Consumes: `WP_REST_Request::get_body()` and `get_json_params()` (null when not parsed as JSON).

- [ ] **Step 1: Apply the tests patch**

Apply `T3-tests`:

`````diff
diff --git a/pro-addon/tests/ProRestTest.php b/pro-addon/tests/ProRestTest.php
index 7d97cd1..78f7155 100644
--- a/pro-addon/tests/ProRestTest.php
+++ b/pro-addon/tests/ProRestTest.php
@@ -134,6 +134,30 @@ class ProRestTest extends TestCase
         $this->assertSame( 0, $tool->runs );
     }
 
+    public function testABodyThatWasNotParsedAsJsonIsA400EvenForAToolThatNeedsNoArguments(): void
+    {
+        $tool = new FakeTool();
+        $rest = $this->rest( [ $tool ] );
+        // Sent as a form or as text/plain: WordPress parses no JSON, so the tool would have run with its defaults.
+        foreach ( [ 'scope=layouts&cursor=3', '{"scope":"layouts"}', 'null', 'x' ] as $raw ) {
+            $r = $rest->handle( 'fake_pro_tool', new \WP_REST_Request( null, [], $raw ) );
+            $this->assertSame( 400, $r->data['status'], $raw );
+            $this->assertSame( 'invalid_argument', $r->code, $raw );
+            $this->assertStringContainsString( 'application/json', $r->get_error_message(), $raw );
+        }
+        $this->assertSame( 0, $tool->runs );
+    }
+
+    public function testAnEmptyOrBlankBodyWithoutJsonIsNoArguments(): void
+    {
+        $tool = new FakeTool();
+        foreach ( [ '', "  \n" ] as $raw ) {
+            $r = $this->rest( [ $tool ] )->handle( 'fake_pro_tool', new \WP_REST_Request( null, [], $raw ) );
+            $this->assertSame( 200, $r->get_status(), json_encode( $raw ) );
+        }
+        $this->assertSame( 2, $tool->runs );
+    }
+
     public function testAnEmptyBodyIsNoArguments(): void
     {
         $tool = new FakeTool();
diff --git a/tests/WpRestShims.php b/tests/WpRestShims.php
index 909cf74..b95cea2 100644
--- a/tests/WpRestShims.php
+++ b/tests/WpRestShims.php
@@ -19,9 +19,14 @@ if ( ! class_exists( 'WP_Post' ) ) {
 }
 if ( ! class_exists( 'WP_REST_Request' ) ) {
     class WP_REST_Request {
-        /** @param array<string,mixed> $json @param array<string,mixed> $params */
-        public function __construct( private array $json = [], private array $params = [] ) {}
+        /**
+         * @param array<string,mixed>|null $json what WordPress parsed from a JSON body (null: no JSON, as for a body sent without a JSON Content-Type)
+         * @param array<string,mixed> $params
+         * @param string|null $body the raw body (default: the JSON encoded again, or empty)
+         */
+        public function __construct( private ?array $json = [], private array $params = [], private ?string $body = null ) {}
         public function get_json_params() { return $this->json; }
+        public function get_body() { return $this->body ?? ( [] === $this->json || null === $this->json ? '' : json_encode( $this->json ) ); }
         public function get_param( $key ) { return $this->params[ $key ] ?? null; }
         public function get_header( $key ) { return null; }
     }
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md T3-tests` → `applied: T3-tests`.

- [ ] **Step 2: Run the Pro suite and watch it fail**

Run: `make test-pro 2>&1 | tail -40`. Expected: FAIL (red). `Tests: 721, Assertions: 2829, Errors: 1.`. Failing tests: `testABodyThatWasNotParsedAsJsonIsA400EvenForAToolThatNeedsNoArguments`.

- [ ] **Step 3: Apply the source patch**

Apply `T3-src`:

`````diff
diff --git a/pro-addon/src/Rest/ProRest.php b/pro-addon/src/Rest/ProRest.php
index d066529..6b79f5e 100644
--- a/pro-addon/src/Rest/ProRest.php
+++ b/pro-addon/src/Rest/ProRest.php
@@ -66,6 +66,10 @@ final class ProRest
             return new \WP_Error( 'pro_locked', 'The Pro tools are not unlocked on this site.', [ 'status' => 403 ] );
         }
         $body = $request->get_json_params();
+        // A body WordPress did not parse as JSON (sent as a form, or as text/plain) must not run the tool with its defaults.
+        if ( null === $body && '' !== trim( (string) $request->get_body() ) ) {
+            return new \WP_Error( 'invalid_argument', 'The request body must be a JSON object of arguments, sent with Content-Type: application/json.', [ 'status' => 400 ] );
+        }
         if ( null !== $body && ( ! is_array( $body ) || ( [] !== $body && array_is_list( $body ) ) ) ) {
             return new \WP_Error( 'invalid_argument', 'The request body must be a JSON object of arguments.', [ 'status' => 400 ] );
         }
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md T3-src` → `applied: T3-src`.

- [ ] **Step 4: Run the suites and watch them pass**

Run: `make test-pro 2>&1 | tail -3` → `OK (721 tests, 2842 assertions)`. Run: `make test 2>&1 | tail -3` → `OK (726 tests, 5241 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -q -F - <<'EOF'
fix(pro): REST refuses a body that WordPress did not parse as JSON instead of running with defaults

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B | tail -2
```

### Task 4: OpenAPI: clean operation summaries, 401 and 500 documented

**What:** `ProOpenApi::summary()` becomes public and clean: first sentence (a full stop outside brackets; an ellipsis is not an end), cut at a word boundary at 120 characters, never leaving an unclosed bracket or quote. Every Pro operation also documents `401` (authenticate) and `500` (unexpected failure, `ProError`).

**Files:** Modify: `pro-addon/src/Rest/ProOpenApi.php`; Create: `pro-addon/tests/ProOpenApiTest.php`

**Interfaces:** Produces: `ProOpenApi::summary( string $description ): string` (public static).

- [ ] **Step 1: Apply the tests patch**

Apply `T4-tests`:

`````diff
diff --git a/pro-addon/tests/ProOpenApiTest.php b/pro-addon/tests/ProOpenApiTest.php
new file mode 100644
index 0000000..fad1bda
--- /dev/null
+++ b/pro-addon/tests/ProOpenApiTest.php
@@ -0,0 +1,75 @@
+<?php
+
+declare(strict_types=1);
+
+namespace AiEditorDivi5\Pro\Tests;
+
+use AiEditorDivi5\Pro\Rest\ProOpenApi;
+use AiEditorDivi5\Pro\ToolRegistry;
+use AiEditorDivi5\Pro\Tools;
+use PHPUnit\Framework\Attributes\DataProvider;
+use PHPUnit\Framework\TestCase;
+
+require_once __DIR__ . '/../src/autoload.php';
+
+/** The generated operation summaries and the documented responses of the Pro ChatGPT/OpenAPI face. */
+class ProOpenApiTest extends TestCase
+{
+    /** @return array<string,array{string,string}> */
+    public static function summaries(): array
+    {
+        $menu = 'Build the primary menu ("Main Menu") from up to 30 items (a published page_id or a url each) and assign it to the theme\'s menu location. Replaces that menu\'s items.';
+
+        return [
+            'the first sentence, without its period'     => [ 'Set a published page as the front page. Recorded as a batch.', 'Set a published page as the front page' ],
+            'no period at all'                           => [ 'List recent changes', 'List recent changes' ],
+            'an ellipsis inside brackets is not an end'  => [ 'Propose a PHP snippet (post type, hook, shortcode...) for the owner. Say what it does.', 'Propose a PHP snippet (post type, hook, shortcode...) for the owner' ],
+            'a question mark ends it too'                => [ 'Which page? Then more.', 'Which page' ],
+            'a long sentence is cut at a word boundary'  => [ $menu, 'Build the primary menu ("Main Menu") from up to 30 items (a published page_id or a url each) and assign it to the...' ],
+            'a cut inside brackets drops the fragment'   => [ 'Enable or disable one live Divi Theme Builder template, or change where it applies (assign: use_on / exclude_from, in the ids list_theme_layouts shows). Affects the whole site.', 'Enable or disable one live Divi Theme Builder template, or change where it applies...' ],
+            'a cut inside quotes drops the fragment'     => [ 'Rename the page called "' . str_repeat( 'very long title ', 10 ) . '" to something else.', 'Rename the page called...' ],
+            'text with no space is cut hard'             => [ str_repeat( 'x', 200 ), str_repeat( 'x', 117 ) . '...' ],
+            'whitespace is collapsed'                    => [ "Replace  text\n across   pages.", 'Replace text across pages' ],
+        ];
+    }
+
+    #[DataProvider( 'summaries' )]
+    public function testSummaries( string $description, string $expected ): void
+    {
+        $this->assertSame( $expected, ProOpenApi::summary( $description ) );
+    }
+
+    public function testEveryRealSummaryIsShortCleanAndAPrefixOfItsDescription(): void
+    {
+        foreach ( Tools::all() as $tool ) {
+            $def     = $tool->definition();
+            $summary = ProOpenApi::summary( $def['description'] );
+            $text    = (string) preg_replace( '/\s+/', ' ', $def['description'] );
+            $this->assertNotSame( '', $summary, $def['name'] );
+            $this->assertLessThanOrEqual( 120, strlen( $summary ), $def['name'] );
+            $this->assertSame( substr_count( $summary, '(' ), substr_count( $summary, ')' ), "{$def['name']}: unbalanced brackets in '{$summary}'" );
+            $this->assertSame( 0, substr_count( $summary, '"' ) % 2, "{$def['name']}: unclosed quote in '{$summary}'" );
+            $stem = str_ends_with( $summary, '...' ) && ! str_contains( substr( $summary, 0, -3 ), '...' ) ? substr( $summary, 0, -3 ) : $summary;
+            $this->assertStringStartsWith( $stem, $text, $def['name'] );
+            $next = substr( $text, strlen( $stem ), 1 );
+            $this->assertTrue( '' === $next || ' ' === $next || '.' === $next, "{$def['name']}: '{$summary}' stops inside a word" );
+        }
+    }
+
+    public function testEveryOperationDocumentsTheStatusesAClientCanGet(): void
+    {
+        $registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
+        $paths    = ( new ProOpenApi( $registry ) )->paths( [], 'https://example.com' );
+        $this->assertCount( 13, $paths );
+        foreach ( $paths as $path => $methods ) {
+            $responses = $methods['post']['responses'];
+            $this->assertSame( [ '200', '400', '401', '403', '500' ], array_map( 'strval', array_keys( $responses ) ), $path );
+            $this->assertStringContainsString( 'authenticat', strtolower( $responses['401']['description'] ), $path );
+            $this->assertStringContainsString( 'unexpectedly', $responses['500']['description'], $path );
+            $this->assertSame( '#/components/schemas/ProError', $responses['500']['content']['application/json']['schema']['$ref'], $path );
+            foreach ( $responses as $code => $r ) {
+                $this->assertLessThanOrEqual( 300, strlen( $r['description'] ), "{$path} {$code}" );
+            }
+        }
+    }
+}
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md T4-tests` → `applied: T4-tests`.

- [ ] **Step 2: Run the Pro suite and watch it fail**

Run: `make test-pro 2>&1 | tail -40`. Expected: FAIL (red). `Tests: 732, Assertions: 2844, Errors: 10, Failures: 1.`. Failing tests: `testSummaries`, `testEveryRealSummaryIsShortCleanAndAPrefixOfItsDescription`, `testEveryOperationDocumentsTheStatusesAClientCanGet`.

- [ ] **Step 3: Apply the source patch**

Apply `T4-src`:

`````diff
diff --git a/pro-addon/src/Rest/ProOpenApi.php b/pro-addon/src/Rest/ProOpenApi.php
index 806cdb7..8e45de3 100644
--- a/pro-addon/src/Rest/ProOpenApi.php
+++ b/pro-addon/src/Rest/ProOpenApi.php
@@ -17,6 +17,8 @@ if ( ! defined( 'ABSPATH' ) ) {
  */
 final class ProOpenApi
 {
+    private const SUMMARY_MAX = 120;
+
     public function __construct( private ToolRegistry $registry )
     {
     }
@@ -47,7 +49,9 @@ final class ProOpenApi
                 'responses'   => [
                     '200' => [ 'description' => 'Success', 'content' => [ 'application/json' => [ 'schema' => [ '$ref' => '#/components/schemas/ProResult' ] ] ] ],
                     '400' => [ 'description' => 'A missing or invalid argument (the message says which)', 'content' => [ 'application/json' => [ 'schema' => [ '$ref' => '#/components/schemas/ProError' ] ] ] ],
+                    '401' => [ 'description' => 'Not authenticated: use the plugin API key or an Application Password' ],
                     '403' => [ 'description' => 'The user lacks the permission this tool needs, or the Pro tools are not unlocked' ],
+                    '500' => [ 'description' => 'The tool failed unexpectedly; nothing is leaked, so try again or check the site', 'content' => [ 'application/json' => [ 'schema' => [ '$ref' => '#/components/schemas/ProError' ] ] ] ],
                 ],
             ];
             $paths[ ProRest::PREFIX . $def['name'] ] = [ 'post' => $op ];
@@ -104,10 +108,67 @@ final class ProOpenApi
         return lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $toolName ) ) ) );
     }
 
-    private static function summary( string $description ): string
+    /** At most 120 characters: the first sentence, cut at a word boundary, never inside brackets or quotes. */
+    public static function summary( string $description ): string
+    {
+        $first = trim( self::firstSentence( trim( (string) preg_replace( '/\s+/', ' ', $description ) ) ) );
+        if ( strlen( $first ) <= self::SUMMARY_MAX ) {
+            return $first;
+        }
+        $cut = substr( $first, 0, self::SUMMARY_MAX - 3 );
+        if ( ' ' !== $first[ self::SUMMARY_MAX - 3 ] ) {
+            $space = strrpos( $cut, ' ' );
+            $cut   = false === $space || 0 === $space ? $cut : substr( $cut, 0, $space );
+        }
+        // Drop a fragment that opens a bracket or a quote and never closes it.
+        $open = self::unclosedAt( $cut );
+        while ( null !== $open && $open > 0 ) {
+            $cut  = substr( $cut, 0, $open );
+            $open = self::unclosedAt( rtrim( $cut ) );
+        }
+        $cut = rtrim( $cut, " ,;:-" );
+
+        return ( '' === $cut || null !== self::unclosedAt( $cut ) ? substr( $first, 0, self::SUMMARY_MAX - 3 ) : $cut ) . '...';
+    }
+
+    /** The text before the first full stop, question mark or exclamation mark outside brackets (an ellipsis is not one). */
+    private static function firstSentence( string $text ): string
     {
-        $first = trim( (string) preg_replace( '/\s+/', ' ', strtok( $description, '.' ) ?: $description ) );
+        $depth = 0;
+        $len   = strlen( $text );
+        for ( $i = 0; $i < $len; $i++ ) {
+            $c = $text[ $i ];
+            if ( '(' === $c ) {
+                ++$depth;
+            } elseif ( ')' === $c && $depth > 0 ) {
+                --$depth;
+            } elseif ( 0 === $depth && ( '.' === $c || '!' === $c || '?' === $c ) && ( 0 === $i || '.' !== $text[ $i - 1 ] ) && ( $i + 1 === $len || ' ' === $text[ $i + 1 ] ) ) {
+                return substr( $text, 0, $i );
+            }
+        }
+
+        return $text;
+    }
+
+    /** Where the outermost unclosed "(" starts, else the last quote of an odd number of them, else null. */
+    private static function unclosedAt( string $text ): ?int
+    {
+        $stack = [];
+        $len   = strlen( $text );
+        for ( $i = 0; $i < $len; $i++ ) {
+            if ( '(' === $text[ $i ] ) {
+                $stack[] = $i;
+            } elseif ( ')' === $text[ $i ] ) {
+                array_pop( $stack );
+            }
+        }
+        if ( [] !== $stack ) {
+            return $stack[0];
+        }
+        if ( 1 === substr_count( $text, '"' ) % 2 ) {
+            return (int) strrpos( $text, '"' );
+        }
 
-        return strlen( $first ) > 120 ? substr( $first, 0, 117 ) . '...' : $first;
+        return null;
     }
 }
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md T4-src` → `applied: T4-src`.

- [ ] **Step 4: Run the suites and watch them pass**

Run: `make test-pro 2>&1 | tail -3` → `OK (732 tests, 3125 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -q -F - <<'EOF'
fix(pro): OpenAPI summaries are cut at a word and never inside brackets; document 401 and 500

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B | tail -2
```

### Task 5: Small minors: no-change dry run verdict, no-op template write, alias guard

**What:** Three small deferred minors. `LayoutWriter`: a no-change DRY run also returns `valid` and `violations` (the preview shape). `ManageThemeTemplate`: when Divi accepts a write but the template reads back exactly as before, return `changed:false` with `differs_from_request` and record no batch. `ThemeBuilderGuardTest`: a whole-namespace import, alias or group use of `AiEditorDivi5\WP` (which the full-name scan cannot see) fails the guard.

**Files:** Modify: `pro-addon/src/ThemeBuilder/LayoutWriter.php`, `pro-addon/src/Tools/ManageThemeTemplate.php`; Test: `pro-addon/tests/UpdateThemeLayoutTest.php`, `pro-addon/tests/ManageThemeTemplateTest.php`, `pro-addon/tests/ThemeBuilderGuardTest.php`

**Interfaces:** None new.

- [ ] **Step 1: Apply the tests patch**

Apply `T5-tests`:

`````diff
diff --git a/pro-addon/tests/ManageThemeTemplateTest.php b/pro-addon/tests/ManageThemeTemplateTest.php
index 6b09d11..53b6115 100644
--- a/pro-addon/tests/ManageThemeTemplateTest.php
+++ b/pro-addon/tests/ManageThemeTemplateTest.php
@@ -415,6 +415,28 @@ class ManageThemeTemplateTest extends TestCase
         $this->assertSame( '1', $this->meta( 200 )['_et_enabled'] );
     }
 
+    public function testADiviWriteThatLeavesTheTemplateAsItWasIsReportedAsNoChangeAndRecordsNothing(): void
+    {
+        $GLOBALS['__divi_store_mutate'] = static function ( array $template ): array {
+            $template['enabled'] = '1';   // Divi keeps it enabled although the request disabled it
+
+            return $template;
+        };
+        $preview = $this->tool->run( [ 'template_id' => 200, 'action' => 'disable', 'dry_run' => true ] );
+        $rows    = count( $this->repo->rows );
+        $r       = $this->tool->run( [ 'template_id' => 200, 'action' => 'disable', 'token' => $preview['token'] ] );
+        unset( $GLOBALS['__divi_store_mutate'] );
+
+        $this->assertFalse( $r['changed'] );
+        $this->assertFalse( $r['dry_run'] );
+        $this->assertSame( [ 'enabled' => [ 'from' => '0', 'to' => '1' ] ], $r['differs_from_request'] );
+        $this->assertStringContainsString( 'exactly as it was', $r['message'] );
+        $this->assertArrayNotHasKey( 'changed_fields', $r );
+        $this->assertArrayNotHasKey( 'batch', $r );
+        $this->assertSame( $rows, count( $this->repo->rows ), 'nothing to undo, so nothing is recorded' );
+        $this->assertSame( '1', $this->meta( 200 )['_et_enabled'] );
+    }
+
     public function testAnExactWriteHasNoDiffersFromRequestKey(): void
     {
         $r = $this->apply( [ 'template_id' => 200, 'action' => 'disable' ] );
diff --git a/pro-addon/tests/ThemeBuilderGuardTest.php b/pro-addon/tests/ThemeBuilderGuardTest.php
index 4f4d9b5..ac0a28e 100644
--- a/pro-addon/tests/ThemeBuilderGuardTest.php
+++ b/pro-addon/tests/ThemeBuilderGuardTest.php
@@ -84,4 +84,43 @@ class ThemeBuilderGuardTest extends TestCase
             $this->assertContains( $class, \AiEditorDivi5\Pro\Compat::REQUIRED_CLASSES, "{$where} references {$class}, which Compat::REQUIRED_CLASSES does not list" );
         }
     }
+
+    /** @return array<string,array{string,bool}> */
+    public static function aliasForms(): array
+    {
+        return [
+            'a namespace import'              => [ 'use AiEditorDivi5\\WP;', true ],
+            'a leading backslash'             => [ 'use \\AiEditorDivi5\\WP;', true ],
+            'an alias of the namespace'       => [ 'use AiEditorDivi5\\WP as Free;', true ],
+            'a group use'                     => [ 'use AiEditorDivi5\\WP\\{HistoryService, EditTarget};', true ],
+            'a normal class import'           => [ 'use AiEditorDivi5\\WP\\HistoryService;', false ],
+            'the Pro namespace'               => [ 'use AiEditorDivi5\\Pro\\Compat;', false ],
+            'a longer namespace name'         => [ 'use AiEditorDivi5\\WPX;', false ],
+        ];
+    }
+
+    #[\PHPUnit\Framework\Attributes\DataProvider( 'aliasForms' )]
+    public function testTheAliasScanRecognisesTheFormsThatHideAFreeClassName( string $code, bool $hidden ): void
+    {
+        $this->assertSame( $hidden, self::hidesFreeClasses( $code ) );
+    }
+
+    /** The scan above sees only fully written names, so a namespace import, alias or group use would let a class slip past it. */
+    public function testNoAddOnFileHidesAFreePluginClassBehindANamespaceImportAliasOrGroupUse(): void
+    {
+        $scanned = 0;
+        foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root(), \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
+            if ( 'php' !== $file->getExtension() ) {
+                continue;
+            }
+            ++$scanned;
+            $this->assertFalse( self::hidesFreeClasses( self::code( (string) file_get_contents( $file->getPathname() ) ) ), $file->getFilename() . ' imports the free plugin namespace as a whole; write each class with its full name so Compat::REQUIRED_CLASSES can see it' );
+        }
+        $this->assertGreaterThan( 20, $scanned );
+    }
+
+    private static function hidesFreeClasses( string $code ): bool
+    {
+        return 1 === preg_match( '/\buse\s+\\\\?AiEditorDivi5\\\\WP(?:\s*;|\s+as\b|\\\\\{)/i', $code );
+    }
 }
diff --git a/pro-addon/tests/UpdateThemeLayoutTest.php b/pro-addon/tests/UpdateThemeLayoutTest.php
index 60b9038..a7e7e5d 100644
--- a/pro-addon/tests/UpdateThemeLayoutTest.php
+++ b/pro-addon/tests/UpdateThemeLayoutTest.php
@@ -55,6 +55,31 @@ class UpdateThemeLayoutTest extends TestCase
         $this->assertSame( self::header(), $this->layoutContent( 202 ), 'other layouts are untouched' );
     }
 
+    public function testADryRunOfContentThatIsAlreadyStoredStillReportsTheVerdict(): void
+    {
+        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::header(), 'dry_run' => true ] );
+        $this->assertFalse( $r['changed'] );
+        $this->assertTrue( $r['dry_run'] );
+        $this->assertTrue( $r['valid'] );
+        $this->assertSame( [], $r['violations'] );
+
+        // A stored layout that already fails the checks says so in the same shape (the preview of a change does).
+        $GLOBALS['__wp_posts'][201]->post_content = self::invalid();
+        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::invalid(), 'dry_run' => true ] );
+        $this->assertFalse( $r['changed'] );
+        $this->assertFalse( $r['valid'] );
+        $this->assertNotEmpty( $r['violations'] );
+        $this->assertArrayHasKey( 'code', $r['violations'][0] );
+    }
+
+    public function testARealRunOfContentThatIsAlreadyStoredHasNoVerdictFields(): void
+    {
+        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::header() ] );
+        $this->assertFalse( $r['changed'] );
+        $this->assertArrayNotHasKey( 'valid', $r );
+        $this->assertArrayNotHasKey( 'violations', $r );
+    }
+
     public function testAFooterWorksToo(): void
     {
         $r = $this->tool->run( [ 'layout_id' => 202, 'content' => self::otherHeader() ] );
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md T5-tests` → `applied: T5-tests`.

- [ ] **Step 2: Run the Pro suite and watch it fail**

Run: `make test-pro 2>&1 | tail -40`. Expected: FAIL (red). `Tests: 743, Assertions: 3197, Failures: 2, Warnings: 1.`. Failing tests: `testADiviWriteThatLeavesTheTemplateAsItWasIsReportedAsNoChangeAndRecordsNothing`, `testADryRunOfContentThatIsAlreadyStoredStillReportsTheVerdict`.

- [ ] **Step 3: Apply the source patch**

Apply `T5-src`:

`````diff
diff --git a/pro-addon/src/ThemeBuilder/LayoutWriter.php b/pro-addon/src/ThemeBuilder/LayoutWriter.php
index ed5f5b3..0376b22 100644
--- a/pro-addon/src/ThemeBuilder/LayoutWriter.php
+++ b/pro-addon/src/ThemeBuilder/LayoutWriter.php
@@ -52,7 +52,15 @@ final class LayoutWriter
         $layout = [ 'layout_id' => $layoutId, 'kind' => $kind, 'title' => $title ];
 
         if ( $new === $stored ) {
-            return [ 'changed' => false, 'dry_run' => $dry, 'layout' => $layout, 'message' => 'The layout already holds exactly this content; nothing changed.' ] + $extra;
+            $same = [ 'changed' => false, 'dry_run' => $dry, 'layout' => $layout, 'message' => 'The layout already holds exactly this content; nothing changed.' ];
+            if ( $dry ) {
+                // Same verdict fields as the preview of a change, so a client reads one shape.
+                $verdict            = LayoutGate::check( $new, $stored );
+                $same['valid']      = $verdict->isValid();
+                $same['violations'] = array_map( static fn ( $v ) => $v->toArray(), $verdict->violations() );
+            }
+
+            return $same + $extra;
         }
         if ( $dry ) {
             // DryRun::preview runs the same gate and returns valid:false as a normal preview; it never writes.
diff --git a/pro-addon/src/Tools/ManageThemeTemplate.php b/pro-addon/src/Tools/ManageThemeTemplate.php
index 3adeb40..98aebe9 100644
--- a/pro-addon/src/Tools/ManageThemeTemplate.php
+++ b/pro-addon/src/Tools/ManageThemeTemplate.php
@@ -112,8 +112,12 @@ final class ManageThemeTemplate implements ProTool
             // Divi refused, or the template is no longer a live one: nothing was changed.
             throw new \RuntimeException( 'Divi did not save the template as requested, so nothing is recorded. Check list_theme_layouts for its current state.' );
         }
-        // Divi accepted the write, so record what it REALLY wrote (a normal, undoable batch), even if that differs from the request.
         $differs = self::diff( $after, $written );
+        if ( [] === self::diff( $before, $written ) ) {
+            // Divi accepted the write but the template is as it was: nothing changed, so there is nothing to undo or record.
+            return [ 'changed' => false, 'dry_run' => false, 'template' => $label, 'differs_from_request' => $differs, 'message' => 'Divi accepted the write, but the template is exactly as it was; nothing changed and nothing is recorded. See differs_from_request.' ];
+        }
+        // Divi accepted the write, so record what it REALLY wrote (a normal, undoable batch), even if that differs from the request.
         $batch   = $this->log->record( self::NAME, ucfirst( $action ) . " Theme Builder template \"{$before['title']}\" (#{$id})", [ ThemeTemplateKind::itemFor( $before, $written ) ] );
         $result  = [ 'changed' => true, 'dry_run' => false, 'template' => $label, 'changed_fields' => self::diff( $before, $written ), 'note' => 'This applies to the whole site. Divi\'s caches were cleared.' ];
         if ( [] !== $differs ) {
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md T5-src` → `applied: T5-src`.

- [ ] **Step 4: Run the suites and watch them pass**

Run: `make test-pro 2>&1 | tail -3` → `OK (743 tests, 3209 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -q -F - <<'EOF'
fix(pro): no-change layout dry run reports its verdict; a no-op template write is changed:false; guard test sees namespace aliases

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B | tail -2
```

### Task 6: Dev guard: the real-site verify scripts refuse a non-local site

**What:** `scripts/dev-guard.php` (`aied_is_dev_host()`, `aied_require_dev_site()`): the six `verify-pro-*.php` scripts require it and call the guard before anything else, so they only run on localhost, 127.0.0.1, `*.local`, `*.test` or `*.localhost` (the dev site's environment type is `production`, so the host is the test). The unit test loads the guard file without WordPress and also checks that every script calls it before it changes anything.

**Files:** Create: `scripts/dev-guard.php`, `pro-addon/tests/DevGuardTest.php`; Modify: the six `scripts/verify-pro-*.php`

**Interfaces:** Produces: `aied_is_dev_host( string ): bool`, `aied_require_dev_site(): void`.

- [ ] **Step 1: Apply the tests patch**

Apply `T6-tests`:

`````diff
diff --git a/pro-addon/tests/DevGuardTest.php b/pro-addon/tests/DevGuardTest.php
new file mode 100644
index 0000000..e3030af
--- /dev/null
+++ b/pro-addon/tests/DevGuardTest.php
@@ -0,0 +1,53 @@
+<?php
+
+declare(strict_types=1);
+
+namespace AiEditorDivi5\Pro\Tests;
+
+use PHPUnit\Framework\Attributes\DataProvider;
+use PHPUnit\Framework\TestCase;
+
+require_once __DIR__ . '/../../scripts/dev-guard.php';
+
+/** The real-site verify scripts create, change and delete content: they must refuse to run on anything but a local site. */
+class DevGuardTest extends TestCase
+{
+    /** @return array<string,array{string,bool}> */
+    public static function hosts(): array
+    {
+        return [
+            'localhost'                 => [ 'localhost', true ],
+            'localhost in capitals'     => [ 'LocalHost', true ],
+            'the loopback address'      => [ '127.0.0.1', true ],
+            'the IPv6 loopback'         => [ '[::1]', true ],
+            'a .local site'             => [ 'divi.local', true ],
+            'a .test site'              => [ 'shop.divi.test', true ],
+            'a .localhost site'         => [ 'dev.localhost', true ],
+            'a real site'               => [ 'example.com', false ],
+            'a look-alike'              => [ 'localhost.evil.com', false ],
+            'local inside a name'       => [ 'mylocal.com', false ],
+            'a lan address'             => [ '192.168.1.20', false ],
+            'no host at all'            => [ '', false ],
+        ];
+    }
+
+    #[DataProvider( 'hosts' )]
+    public function testWhichHostsCountAsADevelopmentSite( string $host, bool $dev ): void
+    {
+        $this->assertSame( $dev, aied_is_dev_host( $host ), $host );
+    }
+
+    public function testEveryRealSiteVerifyScriptRunsTheGuardFirst(): void
+    {
+        $scripts = glob( __DIR__ . '/../../scripts/verify-pro-*.php' );
+        $this->assertCount( 6, $scripts );
+        foreach ( $scripts as $script ) {
+            $code = (string) file_get_contents( $script );
+            $this->assertStringContainsString( "require_once __DIR__ . '/dev-guard.php';", $code, basename( $script ) );
+            $this->assertStringContainsString( 'aied_require_dev_site();', $code, basename( $script ) );
+            $guard  = strpos( $code, 'aied_require_dev_site();' );
+            $effect = min( array_filter( [ strpos( $code, 'wp_insert_post' ), strpos( $code, 'update_option' ), strpos( $code, 'wp_delete_post' ), strpos( $code, '->run(' ) ], static fn ( $p ): bool => false !== $p ) ?: [ PHP_INT_MAX ] );
+            $this->assertLessThan( $effect, $guard, basename( $script ) . ' must run the guard before it changes anything' );
+        }
+    }
+}
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md T6-tests` → `applied: T6-tests`.

- [ ] **Step 2: Run the Pro suite and watch it fail**

Run: `make test-pro 2>&1 | tail -40`. Expected: the run aborts with a PHP fatal error (`Failed opening required` on `scripts/dev-guard.php`: the file does not exist yet), so no test result line is printed.

- [ ] **Step 3: Apply the source patch**

Apply `T6-src`:

`````diff
diff --git a/scripts/dev-guard.php b/scripts/dev-guard.php
new file mode 100644
index 0000000..180f763
--- /dev/null
+++ b/scripts/dev-guard.php
@@ -0,0 +1,26 @@
+<?php
+/**
+ * Shared by the verify-pro-*.php scripts. They create, change and delete real content (and evict old batch rows),
+ * so they only run on a local development site: host localhost, 127.0.0.1, ::1, *.local, *.test or *.localhost.
+ * Pure functions apart from the final check, so tests/DevGuardTest.php can load this file without WordPress.
+ */
+
+if ( ! function_exists( 'aied_is_dev_host' ) ) {
+    function aied_is_dev_host( string $host ): bool
+    {
+        $host = strtolower( trim( $host ) );
+
+        return in_array( $host, [ 'localhost', '127.0.0.1', '::1', '[::1]' ], true )
+            || 1 === preg_match( '/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:local|test|localhost)$/', $host );
+    }
+}
+
+if ( ! function_exists( 'aied_require_dev_site' ) ) {
+    function aied_require_dev_site(): void
+    {
+        $host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
+        if ( ! aied_is_dev_host( $host ) ) {
+            WP_CLI::error( "This script creates, changes and deletes content, so it only runs on a local development site (host localhost, 127.0.0.1, *.local, *.test). This site's host is '{$host}'." );
+        }
+    }
+}
diff --git a/scripts/verify-pro-rest.php b/scripts/verify-pro-rest.php
index 3af64a3..de94965 100644
--- a/scripts/verify-pro-rest.php
+++ b/scripts/verify-pro-rest.php
@@ -20,6 +20,9 @@ if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( \AiEd
     WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 and jhmg-ai-editor-for-divi-5-pro on this site first.' );
 }
 
+require_once __DIR__ . '/dev-guard.php';
+aied_require_dev_site();
+
 $failures = [];
 $check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
     WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
diff --git a/scripts/verify-pro-settings.php b/scripts/verify-pro-settings.php
index fe8db46..35cb9a2 100644
--- a/scripts/verify-pro-settings.php
+++ b/scripts/verify-pro-settings.php
@@ -28,6 +28,9 @@ if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) ) {
     WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro on this site first.' );
 }
 
+require_once __DIR__ . '/dev-guard.php';
+aied_require_dev_site();
+
 $failures = [];
 $check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
     WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
diff --git a/scripts/verify-pro-site-tools.php b/scripts/verify-pro-site-tools.php
index 78f6420..1747857 100644
--- a/scripts/verify-pro-site-tools.php
+++ b/scripts/verify-pro-site-tools.php
@@ -36,6 +36,9 @@ foreach ( [ 'et_theme_builder_get_theme_builder_post_id', 'et_theme_builder_inse
 
 global $wpdb;
 
+require_once __DIR__ . '/dev-guard.php';
+aied_require_dev_site();
+
 $failures = [];
 $check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
     WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
diff --git a/scripts/verify-pro-skeleton.php b/scripts/verify-pro-skeleton.php
index d7ec4c7..350ed40 100644
--- a/scripts/verify-pro-skeleton.php
+++ b/scripts/verify-pro-skeleton.php
@@ -11,6 +11,9 @@ if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) ) {
     WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro on this site first.' );
 }
 
+require_once __DIR__ . '/dev-guard.php';
+aied_require_dev_site();
+
 $failures = [];
 $check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
     WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
diff --git a/scripts/verify-pro-theme-layouts.php b/scripts/verify-pro-theme-layouts.php
index 8ba9aa1..0ed264e 100644
--- a/scripts/verify-pro-theme-layouts.php
+++ b/scripts/verify-pro-theme-layouts.php
@@ -37,6 +37,9 @@ foreach ( [ 'et_theme_builder_get_theme_builder_post_id', 'et_theme_builder_inse
 
 global $wpdb;
 
+require_once __DIR__ . '/dev-guard.php';
+aied_require_dev_site();
+
 $failures = [];
 $check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
     WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
diff --git a/scripts/verify-pro-theme-templates.php b/scripts/verify-pro-theme-templates.php
index 3fd16d2..2f403cd 100644
--- a/scripts/verify-pro-theme-templates.php
+++ b/scripts/verify-pro-theme-templates.php
@@ -34,6 +34,9 @@ foreach ( [ 'et_theme_builder_get_theme_builder_post_id', 'et_theme_builder_inse
 
 global $wpdb;
 
+require_once __DIR__ . '/dev-guard.php';
+aied_require_dev_site();
+
 $failures = [];
 $check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
     WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md T6-src` → `applied: T6-src`.

- [ ] **Step 4: Run the suites and watch them pass**

Run: `make test-pro 2>&1 | tail -3` → `OK (756 tests, 3240 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -q -F - <<'EOF'
feat(pro): the verify-pro scripts refuse to run on a site that is not local

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B | tail -2
```

### Task 7: Release housekeeping: 0.4.1, changelog, README, EXTENDING, CLAUDE.md

**What:** Version 0.4.1 in the main file, `AIED_PRO_VERSION`, the test bootstrap and `readme.txt` (Stable tag + changelog); README sections "Preview tokens" and "Known limits"; the REST/OpenAPI parity rule and the 30-operation budget in `docs/EXTENDING.md`; `CLAUDE.md` Current state (a 0.4.1 bullet; the 0.4.0 bullet no longer says "NOT merged").

**Files:** Modify: `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`, `pro-addon/readme.txt`, `pro-addon/README.md`, `pro-addon/tests/bootstrap.php`, `docs/EXTENDING.md`, `CLAUDE.md`

**Interfaces:** None.

- [ ] **Step 1: Apply the tests patch**

Apply `T7-tests`:

`````diff
diff --git a/pro-addon/tests/bootstrap.php b/pro-addon/tests/bootstrap.php
index 80174af..53660b6 100644
--- a/pro-addon/tests/bootstrap.php
+++ b/pro-addon/tests/bootstrap.php
@@ -11,7 +11,7 @@ require_once __DIR__ . '/../../tests/bootstrap.php';
 require_once __DIR__ . '/../../tests/WpRestShims.php';
 
 if ( ! defined( 'AIED_PRO_VERSION' ) ) {
-    define( 'AIED_PRO_VERSION', '0.4.0' );
+    define( 'AIED_PRO_VERSION', '0.4.1' );
 }
 if ( ! defined( 'AIED_PRO_FILE' ) ) {
     define( 'AIED_PRO_FILE', (string) realpath( __DIR__ . '/..' ) . '/jhmg-ai-editor-for-divi-5-pro.php' );
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md T7-tests` → `applied: T7-tests`.

- [ ] **Step 2: Run the Pro suite and watch it fail**

Run: `make test-pro 2>&1 | tail -40`. Expected: FAIL (red). `Tests: 756, Assertions: 3240, Failures: 1.`. Failing tests: `testTheStableTagIsTheCurrentVersion`.

- [ ] **Step 3: Apply the source patch**

Apply `T7-src`:

`````diff
diff --git a/CLAUDE.md b/CLAUDE.md
index c0d960d..46108b5 100644
--- a/CLAUDE.md
+++ b/CLAUDE.md
@@ -28,7 +28,7 @@ Three parts, one repo:
   `wp-plugin/validator/` and wraps it in the MCP server, REST API, AI-facing guides,
   the built-in image pack and the admin UI. No licensing, no remote calls.
 - **The Pro add-on** (`pro-addon/`) — a separate plugin ("JHMG AI Editor for Divi 5 Pro", sold at divi5lab.com,
-  never in the WordPress.org zip). 0.4.0: compat guard, licensing with the updater, a licence-gated tool
+  never in the WordPress.org zip). 0.4.1: compat guard, licensing with the updater, a licence-gated tool
   registry with 13 tools (settings, batches and undo, Theme Builder header/footer and template tools, site-wide find/replace and audit), REST and ChatGPT parity for all of them, the Pro admin tab, its own suite (`make test-pro`) and zip (`make build-pro`). Not tested
   by `make test` (see `pro-addon/README.md`).
 
@@ -224,7 +224,16 @@ Docker env and `make export-layouts`. Do not attempt to download it. If missing,
 
 ## Current state
 
-- **Pro add-on 0.4.0** (branch `feat/pro-site-tools`; NOT merged, NOT released, NOT pushed): `find_replace_site` (literal;
+- **Pro add-on 0.4.1** (hardening; branch `feat/pro-0-4-1`; NOT released, NOT pushed): preview tokens are bound to the user,
+  single-use (a transient marks a spent nonce) and 30 minutes for BOTH `find_replace_site` and `manage_theme_template`
+  (`PreviewToken` v2); `audit_site` pages by the id of the last audited item (pages and layouts in one id order), memoises link
+  lookups and the site's known style ids per call and hides the status of a linked post the user cannot `read_post`; REST
+  refuses a body WordPress did not parse as JSON (400); OpenAPI summaries are cut at a word, never inside brackets, and 401/500
+  are documented; no-change layout dry runs carry `valid`/`violations`; a template write that changes nothing reports
+  `changed:false`; the `verify-pro-*` scripts refuse non-local sites (`scripts/dev-guard.php`). Plan:
+  `docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md`. `make test-pro` 756 tests, `make test` 726; live gates
+  `make verify-pro-site-tools`, `make verify-pro-rest`, `make verify-pro-theme-templates` before merge.
+- **Pro add-on 0.4.0** (merged to LOCAL main 6ce824d; NOT released, NOT pushed): `find_replace_site` (literal;
   Divi 5 pages, the live header/footer only with scope and the Theme Builder permission; ONE batch of at most 50 items; a signed
   30-minute preview token carries the id => content-hash map so an item changed since the preview, or dropped out of it, is skipped and reported; every result
   through `LayoutGate::check($new, $stored)`, an invalid one skipped, never saved), `audit_site` (read-only: layout violations
diff --git a/docs/EXTENDING.md b/docs/EXTENDING.md
index 2567583..33449bf 100644
--- a/docs/EXTENDING.md
+++ b/docs/EXTENDING.md
@@ -60,6 +60,15 @@ different behaviour, it adds a new tool with its own name.
    `description` and `summary` at most 300 characters, and no bare
    `type: object` without `properties` (use `additionalProperties` or list the
    fields).
+6. **Offer a tool on every face from one definition.** MCP (`jhmg_aied_mcp_tools` +
+   `jhmg_aied_mcp_call`), REST (`jhmg_aied_register_rest_routes`, one `POST` route per
+   tool, authenticated with `RestController::require_edit_posts()` plus the tool's own
+   capability) and OpenAPI (`jhmg_aied_openapi_paths` + `jhmg_aied_openapi_schemas`) should
+   be generated from the same tool list, so they cannot drift. The ChatGPT importer
+   accepts at most 30 operations in total, so budget them: the base plugin has 16 and
+   the Pro add-on 13 (29 of 30). Document the 400, 401, 403 and 500 answers of each
+   operation, and refuse a request body that is not a JSON object with a 400 instead of
+   running with defaults.
 
 ## Image tokens
 
diff --git a/pro-addon/README.md b/pro-addon/README.md
index 4819844..ee8180f 100644
--- a/pro-addon/README.md
+++ b/pro-addon/README.md
@@ -4,7 +4,7 @@ The Pro add-on for the free WordPress.org plugin in `wp-plugin/`. It is a **sepa
 
 Spec: `docs/superpowers/specs/2026-10-02-pro-addon-design.md`. Plans: `docs/superpowers/plans/`.
 
-## What exists (0.4.0)
+## What exists (0.4.1)
 
 | Path | What it is |
 |---|---|
@@ -29,6 +29,17 @@ Spec: `docs/superpowers/specs/2026-10-02-pro-addon-design.md`. Plans: `docs/supe
 
 `make test-pro` runs the Pro tests; `make build-pro` builds `dist/jhmg-ai-editor-for-divi-5-pro.zip` (git-ignored); `make verify-pro-skeleton`, `make verify-pro-settings`, `make verify-pro-theme-layouts`, `make verify-pro-theme-templates`, `make verify-pro-site-tools` and `make verify-pro-rest` are the real-site checks (activate the free plugin and the add-on on the Docker site first).
 
+## Preview tokens
+
+`find_replace_site` and `manage_theme_template` need the token of a dry run of the same arguments (`src/Site/PreviewToken.php`): signed, bound to the arguments, the items' content hashes (or the template's state) and the user who previewed, valid 30 minutes, and spent by the first real run, so an `undo_batch` that puts the previewed content back cannot be followed by a replay. The spent mark is a transient: an object cache that evicts it can only make a spent token usable again inside its 30 minutes, where the changed-since check still protects every item.
+
+## Known limits
+
+- The audit's link check cannot tell a missing page from an archive or custom route (the finding says so), and reads at most 200 links per item (`LINKS_NOT_ALL_CHECKED`).
+- On a site with more than 1000 Divi 5 pages `audit_site` audits the first 1000 (by id) and says so, and `find_replace_site` refuses and asks for `page_ids`.
+- The ChatGPT importer allows 30 operations in total: 16 free + 13 Pro = 29, so a 14th Pro operation needs an explicit decision.
+- The `verify-pro-*` scripts create and delete content: they refuse to run unless the site host is localhost, 127.0.0.1, `*.local`, `*.test` or `*.localhost` (`scripts/dev-guard.php`).
+
 ## Dev override of the licence server
 
 `AIED_PRO_API_BASE` redirects the licence key to another host, so it is honoured only when `wp_get_environment_type()` is `local` or `development`; anywhere else it is ignored and `https://divi5lab.com` is used.
diff --git a/pro-addon/jhmg-ai-editor-for-divi-5-pro.php b/pro-addon/jhmg-ai-editor-for-divi-5-pro.php
index 6484a2d..6bc7e99 100644
--- a/pro-addon/jhmg-ai-editor-for-divi-5-pro.php
+++ b/pro-addon/jhmg-ai-editor-for-divi-5-pro.php
@@ -2,7 +2,7 @@
 /**
  * Plugin Name:       JHMG AI Editor for Divi 5 Pro
  * Description:       Pro add-on for JHMG AI Editor for Divi 5: set the front page and menu, publish or schedule pages, add managed custom CSS, edit the live Divi header and footer, find and replace text across your pages in one undoable batch, audit pages for layout problems and broken links, file PHP snippets for human review, and undo each Pro change in one step. Requires the free plugin.
- * Version:           0.4.0
+ * Version:           0.4.1
  * Requires at least: 6.0
  * Requires PHP:      8.1
  * Author:            JHMG
@@ -19,7 +19,7 @@ if ( ! defined( 'ABSPATH' ) ) {
     exit;
 }
 
-define( 'AIED_PRO_VERSION', '0.4.0' );
+define( 'AIED_PRO_VERSION', '0.4.1' );
 define( 'AIED_PRO_FILE', __FILE__ );
 define( 'AIED_PRO_PRODUCT', 'ai-editor-divi5-pro' );
 define( 'AIED_PRO_MIN_PHP', '8.1' );
diff --git a/pro-addon/readme.txt b/pro-addon/readme.txt
index e100299..c3f637f 100644
--- a/pro-addon/readme.txt
+++ b/pro-addon/readme.txt
@@ -2,7 +2,7 @@
 Contributors:      lucaslopvet
 Requires at least: 6.0
 Requires PHP:      8.1
-Stable tag:        0.4.0
+Stable tag:        0.4.1
 License:           GPLv2 or later
 License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 
@@ -51,6 +51,13 @@ Deleting the plugin removes its own data (licence state, batch history, saved co
 
 == Changelog ==
 
+= 0.4.1 =
+* Safer previews: the token a preview returns now only works for the person who made it, only once, and only within 30 minutes. A preview of a Theme Builder template change has the same rules (it used to have no time limit).
+* `audit_site` continues by page id, so pages added or deleted between two calls can no longer make it skip or repeat a page; it looks up each distinct link once per call, and it no longer says whether a draft or private post you cannot read is a draft or private.
+* REST: a request body that was not sent as JSON is refused with 400 instead of running the tool with its defaults.
+* ChatGPT spec: operation summaries are cut at a word and never inside brackets, and the 401 and 500 answers are documented.
+* A preview that changes nothing now also reports whether the stored layout passes the checks, and a Theme Builder template write that Divi accepts without changing anything is reported as no change.
+
 = 0.4.0 =
 * New: `find_replace_site` (literal find and replace across your Divi 5 pages as one previewed, undoable batch) and `audit_site` (a read-only check for layout problems, unknown preset or color ids and links to missing or unpublished pages).
 * New: every Pro tool is also available over REST (`POST /wp-json/ai-editor-divi5/v1/pro/<tool>`) and in the free plugin's ChatGPT OpenAPI spec, with the same permission checks as the MCP tools.
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md T7-src` → `applied: T7-src`.

- [ ] **Step 4: Run the suites and watch them pass**

Run: `make test-pro 2>&1 | tail -3` → `OK (756 tests, 3240 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -q -F - <<'EOF'
docs(pro): 0.4.1 - version, changelog, README tokens and limits, EXTENDING parity rule, CLAUDE.md state

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B | tail -2
```

### Task 8: Live proof on the real dev site

**What:** The three live scripts learn the new rules (the audit cursor is a strictly increasing run of real page ids; a spent token is refused after an undo put the content back, for `find_replace_site` and for `manage_theme_template`; a form body and a broken JSON body are 400 over the real REST server; every Pro operation in the live spec documents 200/400/401/403/500 and has a clean summary) and are run through the real registry on the Docker site with Divi 5.14. They create and delete their own throwaway content and restore every setting.

**Files:** Modify: `scripts/verify-pro-site-tools.php`, `scripts/verify-pro-theme-templates.php`, `scripts/verify-pro-rest.php`

**Interfaces:** None.

- [ ] **Step 1: Apply the three script patches**

Apply `T8-scripts-a`:

`````diff
diff --git a/scripts/verify-pro-site-tools.php b/scripts/verify-pro-site-tools.php
index 1747857..079d1b0 100644
--- a/scripts/verify-pro-site-tools.php
+++ b/scripts/verify-pro-site-tools.php
@@ -260,6 +260,8 @@ try {
     $check( 1 === $rows( $A1 ) && 1 === $rows( $A2 ), 'each replaced page has exactly one history snapshot' );
     $u = $call( 'undo_batch', [ 'batch_id' => (int) $r['result']['batch']['id'] ] );
     $check( true === ( $u['result']['fully_undone'] ?? null ) && $read( $A1 ) === $c0[ $A1 ] && $read( $A2 ) === $c0[ $A2 ] && $read( $A3 ) === $c0[ $A3 ], 'undo_batch puts all three pages back byte for byte, fully undone' );
+    $replay = $call( 'find_replace_site', $find + [ 'token' => $d['result']['token'] ] );
+    $check( $errHas( $replay, 'needs the token' ) && $read( $A1 ) === $c0[ $A1 ] && $read( $A2 ) === $c0[ $A2 ] && $read( $A3 ) === $c0[ $A3 ], 'the spent token cannot apply the change again after the undo put the content back' );
 
     // ---- 5b. an item changed since the preview is skipped -----------------------------------------------------
     $d = $call( 'find_replace_site', $find + [ 'dry_run' => true ] );
diff --git a/scripts/verify-pro-theme-templates.php b/scripts/verify-pro-theme-templates.php
index 2f403cd..6bb45a4 100644
--- a/scripts/verify-pro-theme-templates.php
+++ b/scripts/verify-pro-theme-templates.php
@@ -260,7 +260,8 @@ try {
     $d = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond ], 'dry_run' => true ] );
 
     // assign
-    $a = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond ], 'token' => $d['result']['token'] ] );
+    $assignToken = $d['result']['token'];
+    $a = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond ], 'token' => $assignToken ] );
     $check( null === $a['error'] && true === ( $a['result']['changed'] ?? null ) && true === ( $a['result']['batch']['undoable'] ?? null ), 'assign: changed, batch undoable' );
     clean_post_cache( $T );
     $metaA = $metaSnapshot( $T );
@@ -298,6 +299,9 @@ try {
     clean_post_cache( $T );
     $check( true === ( $u['result']['fully_undone'] ?? null ) && $sameExcept( $metaT0, $metaSnapshot( $T ), [] ) && get_post( $T )->post_title === $titleT0, 'undo of the assign: the template is identical to its state before this script touched it' );
     $check( $headerOf() !== $H, 'after undo: Divi no longer applies the template to the throwaway page' );
+    $replay = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond ], 'token' => $assignToken ] );
+    clean_post_cache( $T );
+    $check( $errHas( $replay, 'needs the token' ) && $sameExcept( $metaT0, $metaSnapshot( $T ), [] ), 'the spent token cannot assign again after the undo put the template back' );
     $diff = array_diff_key( $metaSnapshot( $T ), $metaT0 );
     WP_CLI::log( 'INFO  meta keys Divi\'s own function added that were not there before: ' . ( [] === $diff ? 'none' : implode( ', ', array_keys( $diff ) ) ) );
 
`````

Apply `T8-scripts-b`:

`````diff
diff --git a/scripts/verify-pro-rest.php b/scripts/verify-pro-rest.php
index de94965..431df96 100644
--- a/scripts/verify-pro-rest.php
+++ b/scripts/verify-pro-rest.php
@@ -40,9 +40,9 @@ $registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
 $GLOBALS['wp_rest_server'] = null;
 
 $ns   = '/ai-editor-divi5/v1';
-$send = static function ( string $route, array $body = [], ?string $rawBody = null, string $method = 'POST' ) use ( $ns ): array {
+$send = static function ( string $route, array $body = [], ?string $rawBody = null, string $method = 'POST', string $contentType = 'application/json' ) use ( $ns ): array {
     $req = new WP_REST_Request( $method, $ns . $route );
-    $req->set_header( 'content-type', 'application/json' );
+    $req->set_header( 'content-type', $contentType );
     $req->set_body( $rawBody ?? wp_json_encode( $body ) );
     $res  = rest_do_request( $req );
     $data = (array) $res->get_data();
@@ -80,6 +80,22 @@ $proPaths = array_filter( array_keys( $paths ), static fn ( string $p ): bool =>
 $check( count( $proPaths ) === count( $names ) && [] === array_diff( array_map( static fn ( string $n ): string => '/pro/' . $n, $names ), array_keys( $paths ) ), 'the live spec has one /pro/ operation for each of the ' . count( $names ) . ' Pro tools' );
 $check( $ops <= 30 && 0 === $long && count( $ids ) === count( array_unique( $ids ) ), "the live spec has {$ops} operations (limit 30), every description at most 300 characters, unique operationIds" );
 $check( isset( $spec['data']['components']['schemas']['ProResult'], $spec['data']['components']['schemas']['ProError'] ), 'the ProResult and ProError schemas are in the spec' );
+$undocumented = 0;
+foreach ( $paths as $path => $methods ) {
+    if ( 0 === strpos( (string) $path, '/pro/' ) ) {
+        $codes         = array_map( 'strval', array_keys( (array) ( $methods['post']['responses'] ?? [] ) ) );
+        $undocumented += [] === array_diff( [ '200', '400', '401', '403', '500' ], $codes ) ? 0 : 1;
+    }
+}
+$check( 0 === $undocumented, 'every Pro operation in the live spec documents 200, 400, 401, 403 and 500' );
+$badSummaries = 0;
+foreach ( $paths as $path => $methods ) {
+    if ( 0 === strpos( (string) $path, '/pro/' ) ) {
+        $sum           = (string) ( $methods['post']['summary'] ?? '' );
+        $badSummaries += ( '' === $sum || substr_count( $sum, '(' ) !== substr_count( $sum, ')' ) || strlen( $sum ) > 120 ) ? 1 : 0;
+    }
+}
+$check( 0 === $badSummaries, 'every Pro summary in the live spec is non-empty, at most 120 characters and has balanced brackets' );
 
 // ---- authentication -------------------------------------------------------------------------------------------------
 wp_set_current_user( 0 );
@@ -106,6 +122,12 @@ $nf = $send( '/pro/undo_batch', [ 'batch_id' => 987654321 ] );
 $check( 400 === $nf['status'] && false !== strpos( (string) ( $nf['data']['message'] ?? '' ), 'not found' ), 'undo_batch of a batch that does not exist is 400 "not found"' );
 $arr = $send( '/pro/list_batches', [], '[1,2,3]' );
 $check( 400 === $arr['status'], 'a JSON body that is a list, not an object, is 400' );
+$form = $send( '/pro/list_batches', [], 'scope=layouts', 'POST', 'application/x-www-form-urlencoded' );
+$check( 400 === $form['status'] && 'invalid_argument' === ( $form['data']['code'] ?? '' ), 'a body sent without a JSON content type is refused with 400 (a tool with no required argument does not run with its defaults)' );
+$broken = $send( '/pro/list_batches', [], '{"scope":' );
+$check( 400 === $broken['status'], 'a broken JSON body is refused with 400' );
+$none = $send( '/pro/list_batches', [], '' );
+$check( 200 === $none['status'], 'an empty body is no arguments and still works' );
 
 // ---- the tool's own capability, same gate as MCP ----------------------------------------------------------------------
 $strip = static fn ( $caps ) => array_merge( (array) $caps, [ 'edit_theme_options' => false ] );
`````

Apply `T8-scripts-c`:

`````diff
diff --git a/scripts/verify-pro-site-tools.php b/scripts/verify-pro-site-tools.php
index 079d1b0..df79f0c 100644
--- a/scripts/verify-pro-site-tools.php
+++ b/scripts/verify-pro-site-tools.php
@@ -342,6 +342,7 @@ try {
     $calls = 0;
     $total = null;
     $maxScanned = 0;
+    $cursors = [];
     do {
         $a = $call( 'audit_site', [ 'scope' => 'pages', 'cursor' => $cursor ] );
         if ( null !== $a['error'] ) {
@@ -355,9 +356,15 @@ try {
             $all[ $f['id'] ][ $f['code'] ] = $f;
         }
         $cursor = $a['result']['next_cursor'];
+        if ( null !== $cursor ) {
+            $cursors[] = $cursor;
+        }
     } while ( null !== $cursor && $calls < 200 );
     $diviPages = count( get_posts( [ 'post_type' => 'page', 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'fields' => 'ids', 'posts_per_page' => -1, 'meta_query' => [ [ 'key' => '_et_pb_use_divi_5', 'value' => 'on' ] ] ] ) );
     $check( $total === $diviPages && $maxScanned <= 25 && $calls === (int) ceil( $diviPages / 25 ), "audit pages through the whole site: {$diviPages} Divi 5 pages in {$calls} calls of at most 25" );
+    $sorted = $cursors;
+    sort( $sorted );
+    $check( $cursors === $sorted && count( $cursors ) === count( array_unique( $cursors ) ) && ( [] === $cursors || get_post( end( $cursors ) ) instanceof WP_Post ), 'the audit cursor is the id of the last audited page: strictly increasing, and each one is a real page id' );
     $check( empty( $all[ $AOK ] ) && empty( $all[ $PUB ] ), 'a page with a valid layout and a link to a published page has no findings' );
     $check( ! empty( $all[ $ABAD ] ), 'a page with an unknown module has layout violations' );
     $check( isset( $all[ $ACSS ]['CUSTOM_CSS_NOT_ALLOWED'] ), 'custom CSS already stored is reported (CUSTOM_CSS_NOT_ALLOWED)' );
`````

Run, one at a time: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-pro-0-4-1-hardening.md T8-scripts-a`, then `...-b`, then `...-c` → `applied: T8-scripts-a`, `applied: T8-scripts-b`, `applied: T8-scripts-c`. Then `for f in scripts/verify-pro-*.php; do php -l $f; done` → six times `No syntax errors detected`.

- [ ] **Step 2: Activate the Pro add-on for the runs**

```bash
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin activate jhmg-ai-editor-for-divi-5-pro
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin list --fields=name,status | grep jhmg
```
Expected: both `jhmg-ai-editor-for-divi-5` and `jhmg-ai-editor-for-divi-5-pro` are `active`.

- [ ] **Step 3: Run the three gates**

```bash
make verify-pro-site-tools 2>&1 | tail -60
make verify-pro-rest 2>&1 | tail -60
make verify-pro-theme-templates 2>&1 | tail -70
```
Expected: no line starts with `FAIL`, and each run ends with its success line: `Success: Pro site-wide tools verified through the real registry.`, `Success: Pro REST and OpenAPI faces verified on the real REST server.`, `Success: Pro manage_theme_template verified on the real Divi Theme Builder.` Among the `PASS` lines these new ones appear: "the audit cursor is the id of the last audited page", "the spent token cannot apply the change again after the undo put the content back", "the spent token cannot assign again after the undo put the template back", "a body sent without a JSON content type is refused with 400", "every Pro operation in the live spec documents 200, 400, 401, 403 and 500". If a gate fails because of a SCRIPT defect, fix the script, say exactly what you changed, and rerun; if it fails because of the PRODUCT code, stop and report.

- [ ] **Step 4: Deactivate the Pro add-on and confirm**

```bash
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin deactivate jhmg-ai-editor-for-divi-5-pro
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin list --fields=name,status | grep jhmg
```
Expected: the free plugin `active`, the Pro add-on `inactive`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -q -F - <<'EOF'
test(pro): live gates prove the id cursor, spent tokens, the REST body rule and clean spec summaries

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B | tail -2
```

### Task 9: Final checks

- [ ] **Step 1: Both suites**

Run: `make test-pro 2>&1 | tail -3` → `OK (756 tests, 3240 assertions)`; `make test 2>&1 | tail -3` → `OK (726 tests, 5241 assertions)`.

- [ ] **Step 2: The zip builds and ships no tests or scripts**

```bash
make build-pro 2>&1 | tail -2
unzip -l dist/jhmg-ai-editor-for-divi-5-pro.zip | grep -E "tests|scripts|\.md|staged" ; echo "exit=$?"
unzip -p dist/jhmg-ai-editor-for-divi-5-pro.zip jhmg-ai-editor-for-divi-5-pro/jhmg-ai-editor-for-divi-5-pro.php | grep -E " Version:"
```
Expected: `[build-pro] Packaging jhmg-ai-editor-for-divi-5-pro version 0.4.1`, the `grep` prints nothing and `exit=1`, and ` * Version:           0.4.1`.

- [ ] **Step 3: Working tree and branch state**

```bash
git status --short && git log --oneline main..HEAD
```
Expected: no `git status` output; eight commits (Tasks 1 to 8; Task 0 makes none) on `feat/pro-0-4-1`. Do not merge: the final whole-branch review (opus) comes first, then the owner decides.

## As built (deviations from the text above)

Recorded at the end of execution on 2026-10-03; the code in the repo is authoritative where it differs from the plan text.

- **Test counts are +10 from Task 4 on.** Task 4's review found two Important defects in the `summary()` algorithm that the plan's code contained (a leading bracket or quote kept unclosed; a full stop inside quotes ended the summary; a multibyte character could be split; degenerate inputs). Fix round 1 (492beae) added 10 tests, so every Pro count after Task 3 in this plan is 10 higher: the end state is `make test-pro` = `OK (766 tests, 18293 assertions)` after the final wave (18286 before it), `make test` = `OK (726 tests, 5241 assertions)`.
- **Task 7 fix round (2ab37f5):** CLAUDE.md said 756 (copied from this plan); token-eviction wording corrected in `README.md`, the `PreviewToken` docblock and the spec (if the object cache evicts the spent mark, a replay within 30 minutes by the same user with the same arguments is possible; a content change since the preview is still caught, a replay after an `undo_batch` that restored the previewed content is not); the verify-script and changelog wording narrowed.
- **Task 8 fix round (a6ada4b):** the live audit-cursor check compares against the real page ids (it logs SKIP on 25 or fewer pages) instead of "strictly increasing". Live results from the main checkout on Divi 5.14: site-tools 49, rest 21, theme-templates 60 PASS, 0 FAIL; the Pro add-on was deactivated afterwards.
- **Final-review wave:** the spent-mark TTL is now asserted (mutation-checked), the `DevGuardTest` order check anchors on `$failures = [];`, CLAUDE.md states the live results, this section.
- **Commits:** 11 plus the wave (the plan expected 8): three fix rounds.
- **Deferred on purpose (accepted by the final review):** `open()` + `spend()` are not atomic (changed-since, capability checks and `LayoutGate` still apply); the template token is spent before a write Divi may refuse; both tools sign under the same `aied-site|` prefix (different argument sets); `audit_site` still tells that a non-public post exists at a linked address (status hidden); the no-op template branch compares three fields (undo restores only those); the no-change dry run lacks the `saved`/diff/advisory keys; the alias-guard regex misses rare `use` forms; the `uninstall.php` does not delete the short-lived spent marks.
