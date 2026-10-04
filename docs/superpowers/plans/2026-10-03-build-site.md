# Pro Add-on 0.5.0: `build_site`, Whole-Site Generation as One Undoable Build — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an AI assistant build a whole multi-page Divi 5 site page by page into one growing batch of validated draft pages, then launch it (publish, front page, menu) in one previewed, single-use-token step, with one `undo_batch` that takes the whole build away again.

**Architecture:** One new Pro tool `build_site` (the 30th and last ChatGPT operation) with the actions `add_page`, `status` and `launch`. The batch store learns a batch that grows (`building` status, `append`); two new item kinds (`page_created`, `page_trashed`) make page creation undoable through the existing `BatchUndo`; the launch reuses the existing `post_status`, `front_page` and `menu` kinds and `PreviewToken` v2. Nothing under `wp-plugin/`, `src/` or the validator changes.

**Tech Stack:** PHP 8.1+, PHPUnit (`make test-pro`, shims in `pro-addon/tests/` and `tests/`), WordPress core functions, Divi 5.14 on the Docker site for the live gate.

**Spec:** `docs/superpowers/specs/2026-10-03-build-site-design.md` (owner-approved design; its "What the spike proved" section records the live WordPress facts that shaped it).

**Baseline:** `main` after the commit of this plan (code identical to `7f61d7a` plus the spec). `make test-pro` = `OK (766 tests, 18293 assertions)`; `make test` = `OK (727 tests, 5241 assertions)`. End state: `make test-pro` = `OK (841 tests, 18711 assertions)`; `make test` = `OK (727 tests, 5241 assertions)`.

## How this plan was made (read once)

The code was built and tested first, test-first, in a scratch worktree and run against the real dev site (`make verify-pro-build-site` and `make verify-pro-rest` passed); this plan is generated from those verified commits, and every patch below was re-applied in a clean worktree in this order with the suites run after each step (the "Expected" lines are measured). Each task is a **tests patch** (apply, run, watch it fail), then a **source patch** (apply, run, watch it pass), then a commit. Patches are applied with the extractor, never retyped:

```bash
php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-build-site.md <patch-name>
```

It finds the `Apply `<patch-name>`:` block below, runs `git apply --check`, then `git apply`, and prints `applied: <name>`. If it fails, STOP and report: do not hand-edit a patch.

## Global Constraints

- **The validator stays deterministic and untouched**: nothing in this plan edits `src/`, `wp-plugin/` or `wp-plugin/validator/`. Every page body goes through `\AiEditorDivi5\WP\LayoutGate::check()`; no custom CSS, JavaScript or PHP is ever saved.
- **One new operation only**: `build_site` brings the ChatGPT importer budget to exactly 16 free + 14 Pro = 30 of 30. Tool descriptions at most 300 characters (`ToolsTest`); OpenAPI descriptions and summaries at most 300 characters and no object schema without properties (`ProParityTest`); summaries at most 120 bytes and clean (`ProOpenApiTest`).
- **Extension contract**: the add-on uses only the `jhmg_aied_*` hooks and the free classes in `Compat::REQUIRED_CLASSES` (pinned by `FreeApiCompatTest`); this plan needs no new free class.
- **Per-tool capabilities stay** (WordPress.org round-3 note): the registry capability is `publish_pages` (like the free `create_page`); the stronger ones are checked inside `launch` and at undo (`MenuKind`, `FrontPageKind`, `PostStatusKind`, `PageCreatedKind`).
- **Nothing visible changes before `launch`**: pages are drafts, the live menu and front page are untouched until the previewed launch. A menu that points at a draft shows a dead link to visitors (live spike), so the menu is never built earlier.
- **No remote request, no PHP execution, no licence changes.** Nothing is pushed, uploaded, released or merged anywhere but local `main` (and that only when the owner says).
- Namespace `AiEditorDivi5\Pro`; PHP 8.1 (no standalone `null` return types, no `readonly` classes).

## Review Focus

Failure modes a happy-path test would miss, most likely first. Each is pinned by a test in the task named in brackets.

1. **The undo deleting or hiding the owner's work**: a page edited in the Divi builder after the build must be skipped by undo (never trashed), every part of a launched build must come back (menu, front page, statuses, then the pages trashed), and an undo can be undone (pages return as drafts) [T2: `testAPageEditedAfterTheBuildIsSkippedNotTrashed`, `testAnUndoThroughTheEngine...`; T4: `testUndoOfALaunchedBuildPutsEverythingBack`, `testAPageEditedAfterTheLaunch...`].
2. **Anything visible before launch, or a dead menu link**: while pages are added nothing on the live site changes; the menu is rebuilt only from pages that were really published, and a skipped page is never flagged front or put in the menu [T4: `testADryRunPreviewsEverythingAndChangesNothing`, `testAPageThatIsGoneChanged...`, `testTheValidPagesAreStillPublished...`; T6 live: anonymous menu render].
3. **Parallel tool calls losing a page**: two `add_page` calls (or an `add_page` and a `launch`) at once must not leave a created page outside the build, so the build lock refuses the second call BEFORE creating anything, and a launch step that fails after pages went live must leave them recorded [T3: `testAnotherAddPageStillRunning...`; T5: both tests].
4. **A launch against a stale picture**: a replayed or foreign token, a front page or menu that changed since the preview, a slug taken meanwhile, a page edited after the preview [T4: the token, stale-settings and skip tests].
5. **Slugs**: drafts get no unique slug in WordPress, so a taken slug is refused at `add_page` (trashed pages do not hold one), re-checked at launch, and a slug core changes at publish is reported [T3, T4: `testASlugCoreChangesAtPublishTime...`].
6. **Budget and surfaces**: the tool appears over MCP, REST and OpenAPI exactly once, the spec stays at 30 operations, a refused call changes nothing [T3: parity and count tests, `refused()` in every refusal test].

## Execution notes (read before Task 0)

- **Run the whole plan in the MAIN checkout on a branch**, not a worktree: the Docker site mounts `./pro-addon` and `./scripts` from the checkout where `docker compose` runs (Task 8 needs it). Task 0 creates `feat/pro-0-5-0` from `main`.
- **Task 8 is live**: the Pro add-on is INACTIVE on the dev site. Activate it for the runs and deactivate it afterwards, always with `php -d memory_limit=1G /usr/local/bin/wp ...` inside the container (Divi 5 exhausts the default 128 MB). Never `wp plugin delete` anything. The gates evict old batch rows on the dev site: that is expected there.
- **Never type a JSON-style unicode escape (a backslash followed by `u` and four hex digits) into a command or a file written through a tool call**: the tool channel turns it into the character itself. Patches are applied by the extractor, which copies bytes.
- **Commit trailer, exactly** `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; subagents must not substitute their own model name. Check `git log -1 --format=%B` after every implementer.
- zsh: do not `cd` into subfolders in the Bash tool (the directory persists); use absolute paths. `echo =====` is parsed as a command; do not use it.
- **Counts in prose go stale**: the final task re-checks every number written in `CLAUDE.md`, the READMEs and the changelog against a real run (a previous release shipped a wrong test count copied from a plan).
- **Reviewers**: Tasks 2, 4 and 5 (they write site-wide state: trash, publish, front page, menu) and the final whole-branch review run on the most capable model (opus); the others on a mid-tier model. Verify each reviewer claim with grep or a PHP run before acting on it; one fix dispatch per finding set. Do not push, upload or release anything.

---

## File Structure

| Path | Change |
|---|---|
| `pro-addon/src/Tools/BuildSite.php` (new) | the tool: `add_page`, `status`, `launch` dispatch, build rules, page creation |
| `pro-addon/src/Site/BuildLaunch.php` (new) | the launch: plan, preview, token, publish, front page, menu, recording |
| `pro-addon/src/Site/BuildLock.php` (new) | one add_page/launch at a time per user |
| `pro-addon/src/Kinds/PageCreatedKind.php`, `PageTrashedKind.php` (new) | undo of a created page and its inverse |
| `pro-addon/src/Batch/BatchRepository.php`, `WpdbBatchRepository.php`, `BatchLog.php` | a batch that grows; open builds are never trimmed |
| `pro-addon/src/Batch/Batches.php`, `src/Tools.php`, `src/autoload.php`, `src/Admin/BatchesPanel.php` | registration and the `building` label |
| `scripts/verify-pro-build-site.php` (new), `Makefile` | the live gate |
| `pro-addon/tests/*`, `tests/bootstrap.php`, `tests/WpRestShims.php`, `tests/WpInsertPostShims.php` | the tests of every task and the shared shims (additive) |
| `pro-addon/readme.txt`, `README.md`, `main file`, `docs/EXTENDING.md`, `CLAUDE.md` | 0.5.0 housekeeping |

---

### Task 0: Branch and baseline

- [ ] **Step 1: Create the branch from main**

```bash
git switch main && git status --short && git switch -c feat/pro-0-5-0
```
Expected: no output from `git status --short` (the spec and this plan are already committed on `main`), then `Switched to a new branch 'feat/pro-0-5-0'`.

- [ ] **Step 2: Confirm the baseline**

Run: `make test-pro 2>&1 | tail -3` → `OK (766 tests, 18293 assertions)`; `make test 2>&1 | tail -3` → `OK (727 tests, 5241 assertions)`.

### Task 1: Batch layer: a batch that grows (open, append, close, openBuild), status `building`

**What:** The batch store learns to hold a batch that grows. `BatchRepository` gets `append( id, items )` and `openBuild( userId )`; `trim()` never selects a `building` row; `BatchLog` gets `open( tool, summary )` (status `building`, no items), `append( id, items )`, `close( id, status )` and `openBuild( userId )`; the Pro tab labels the status.

**Files:** Modify: `pro-addon/src/Batch/BatchRepository.php`, `WpdbBatchRepository.php`, `BatchLog.php`, `pro-addon/src/Admin/BatchesPanel.php`; Test: `BatchLogTest`, `WpdbBatchRepositoryTest`, `BatchesPanelTest`, the stub wpdb and the in-memory repository.

**Interfaces:** Produces: `BatchLog::BUILDING = 'building'`, `BatchLog::open( string, string ): ?array`, `append( int, array ): bool`, `close( int, string ): bool`, `openBuild( int ): ?array`.

- [ ] **Step 1: Apply the tests patch**

Apply `T1-tests`:

`````diff
diff --git a/pro-addon/tests/BatchLogTest.php b/pro-addon/tests/BatchLogTest.php
index 82639ce..f4e132b 100644
--- a/pro-addon/tests/BatchLogTest.php
+++ b/pro-addon/tests/BatchLogTest.php
@@ -109,4 +109,65 @@ class BatchLogTest extends TestCase
         $this->assertFalse( $out['batch']['undoable'] );
         $this->assertStringContainsString( 'could not be recorded', $out['batch']['note'] );
     }
+
+    public function testOpenStoresAnEmptyBuildingBatch(): void
+    {
+        $d = $this->log->open( 'build_site', 'Site build' );
+        $this->assertSame( [ 1, 'build_site', 'building', 0, false ], [ $d['id'], $d['tool'], $d['status'], $d['items'], $d['undoable'] ] );
+        $this->assertSame( [], $this->repo->rows[1]['items'] );
+        $this->assertSame( 7, $this->repo->rows[1]['user_id'] );
+        $this->assertSame( 'Site build', $this->repo->rows[1]['summary'] );
+    }
+
+    public function testOpenReturnsNullWhenTheStoreFails(): void
+    {
+        $this->repo->failInsert = true;
+        $this->assertNull( $this->log->open( 'build_site', 'Site build' ) );
+    }
+
+    public function testAppendAddsItemsAfterTheExistingOnesAndKeepsTheStatus(): void
+    {
+        $this->log->open( 'build_site', 'Site build' );
+        $this->assertTrue( $this->log->append( 1, [ $this->item() ] ) );
+        $this->assertTrue( $this->log->append( 1, [ $this->item( false ), $this->item() ] ) );
+        $this->assertTrue( $this->log->append( 1, [] ), 'appending nothing is fine' );
+        $row = $this->repo->rows[1];
+        $this->assertSame( 'building', $row['status'] );
+        $this->assertCount( 3, $row['items'] );
+        $this->assertSame( [ true, false, true ], array_map( static fn ( array $i ): bool => $i['undo']['available'], $row['items'] ), 'in the order appended' );
+        $this->assertFalse( $this->log->append( 99, [ $this->item() ] ) );
+    }
+
+    public function testCloseSetsTheStatusAndKeepsTheItems(): void
+    {
+        $this->log->open( 'build_site', 'Site build' );
+        $this->log->append( 1, [ $this->item() ] );
+        $this->assertTrue( $this->log->close( 1, 'applied' ) );
+        $this->assertSame( 'applied', $this->repo->rows[1]['status'] );
+        $this->assertCount( 1, $this->repo->rows[1]['items'] );
+        $this->assertFalse( $this->log->close( 99, 'applied' ) );
+    }
+
+    public function testTheUsersOpenBuildIsFoundAndOnlyTheirs(): void
+    {
+        $this->assertNull( $this->log->openBuild( 7 ) );
+        $this->log->record( 'set_front_page', 'not a build', [ $this->item() ] );
+        $this->assertNull( $this->log->openBuild( 7 ), 'an applied batch is not an open build' );
+        $this->log->open( 'build_site', 'Site build' );
+        $this->assertSame( 2, $this->log->openBuild( 7 )['id'] );
+        $this->assertNull( $this->log->openBuild( 8 ) );
+        $this->log->close( 2, 'applied' );
+        $this->assertNull( $this->log->openBuild( 7 ), 'a closed build is not open' );
+    }
+
+    public function testAnOpenBuildIsNeverTrimmed(): void
+    {
+        $this->log->open( 'build_site', 'Site build' );
+        for ( $i = 1; $i <= 25; $i++ ) {
+            $this->log->record( 't', "batch {$i}", [ $this->item() ] );
+        }
+        $this->assertArrayHasKey( 1, $this->repo->rows, 'the open build survives although 25 newer batches were recorded' );
+        $this->assertCount( 21, $this->repo->rows, 'the 20 newest closed batches and the open build' );
+        $this->assertSame( 7, array_key_first( array_diff_key( $this->repo->rows, [ 1 => 1 ] ) ) );
+    }
 }
diff --git a/pro-addon/tests/BatchStubWpdb.php b/pro-addon/tests/BatchStubWpdb.php
index 329e980..20df12d 100644
--- a/pro-addon/tests/BatchStubWpdb.php
+++ b/pro-addon/tests/BatchStubWpdb.php
@@ -59,7 +59,17 @@ final class BatchStubWpdb
     public function get_row( string $query, mixed $output = null ): ?array
     {
         $this->calls[] = [ 'get_row', $query ];
-        preg_match( '/id = (\d+)/', $query, $m );
+        if ( str_contains( $query, "status = 'building'" ) ) {
+            preg_match( '/user_id = (\d+)/', $query, $u );
+            foreach ( array_reverse( $this->rows, true ) as $row ) {
+                if ( 'building' === $row['status'] && (int) $row['user_id'] === (int) ( $u[1] ?? 0 ) ) {
+                    return $row;
+                }
+            }
+
+            return null;
+        }
+        preg_match( '/WHERE id = (\d+)/', $query, $m );
 
         return $this->rows[ (int) ( $m[1] ?? 0 ) ] ?? null;
     }
diff --git a/pro-addon/tests/BatchesPanelTest.php b/pro-addon/tests/BatchesPanelTest.php
index 77ab952..4b5d10a 100644
--- a/pro-addon/tests/BatchesPanelTest.php
+++ b/pro-addon/tests/BatchesPanelTest.php
@@ -111,6 +111,12 @@ class BatchesPanelTest extends TestCase
         $this->assertStringContainsString( 'nonce-for-aied_pro_undo_batch', $html );
     }
 
+    public function testAnOpenBuildIsListedAsBuilding(): void
+    {
+        $this->log->open( 'build_site', 'Site build' );
+        $this->assertStringContainsString( 'building', $this->render() );
+    }
+
     public function testAnUndoneBatchHasNoUndoButton(): void
     {
         $GLOBALS['__fake_store']['a'] = 'one';
diff --git a/pro-addon/tests/InMemoryBatchRepository.php b/pro-addon/tests/InMemoryBatchRepository.php
index 88c5bb4..0b0d6f3 100644
--- a/pro-addon/tests/InMemoryBatchRepository.php
+++ b/pro-addon/tests/InMemoryBatchRepository.php
@@ -48,10 +48,33 @@ final class InMemoryBatchRepository implements BatchRepository
         return true;
     }
 
+    public function append( int $id, array $items ): bool
+    {
+        if ( ! isset( $this->rows[ $id ] ) ) {
+            return false;
+        }
+        $this->rows[ $id ]['items'] = array_merge( $this->rows[ $id ]['items'], array_values( $items ) );
+
+        return true;
+    }
+
+    public function openBuild( int $userId ): ?array
+    {
+        foreach ( array_reverse( $this->rows, true ) as $row ) {
+            if ( 'building' === $row['status'] && (int) $row['user_id'] === $userId ) {
+                return $row;
+            }
+        }
+
+        return null;
+    }
+
     public function trim( int $keep ): void
     {
-        while ( count( $this->rows ) > $keep ) {
-            unset( $this->rows[ array_key_first( $this->rows ) ] );
+        // Like the real store: an open build is never removed and does not count towards the kept batches.
+        $closed = array_keys( array_filter( $this->rows, static fn ( array $r ): bool => 'building' !== $r['status'] ) );
+        while ( count( $closed ) > $keep ) {
+            unset( $this->rows[ array_shift( $closed ) ] );
         }
     }
 }
diff --git a/pro-addon/tests/WpdbBatchRepositoryTest.php b/pro-addon/tests/WpdbBatchRepositoryTest.php
index 2d0f9ba..b281069 100644
--- a/pro-addon/tests/WpdbBatchRepositoryTest.php
+++ b/pro-addon/tests/WpdbBatchRepositoryTest.php
@@ -97,4 +97,40 @@ class WpdbBatchRepositoryTest extends TestCase
         $this->repo->trim( 20 );
         $this->assertSame( [], array_filter( $GLOBALS['wpdb']->calls, static fn ( array $c ): bool => 'query' === $c[0] ) );
     }
+
+    public function testAppendAddsToTheStoredItemsAndKeepsTheStatus(): void
+    {
+        $row           = $this->row();
+        $row['status'] = 'building';
+        $this->repo->insert( $row );
+        $this->assertTrue( $this->repo->append( 1, [ [ 'kind' => 'second' ], [ 'kind' => 'third' ] ] ) );
+        $b = $this->repo->find( 1 );
+        $this->assertSame( 'building', $b['status'] );
+        $this->assertSame( [ 'k', 'second', 'third' ], array_column( $b['items'], 'kind' ) );
+        $this->assertFalse( $this->repo->append( 99, [ [ 'kind' => 'x' ] ] ) );
+    }
+
+    public function testOpenBuildAsksForTheUsersNewestBuildingBatch(): void
+    {
+        $open           = $this->row( 'older build' );
+        $open['status'] = 'building';
+        $this->repo->insert( $open );
+        $this->repo->insert( $this->row( 'applied' ) );
+        $newer           = $this->row( 'newer build' );
+        $newer['status'] = 'building';
+        $this->repo->insert( $newer );
+        $found = $this->repo->openBuild( 7 );
+        $this->assertSame( 'newer build', $found['summary'] );
+        $last = end( $GLOBALS['wpdb']->calls );
+        $this->assertStringContainsString( "status = 'building'", $last[1] );
+        $this->assertStringContainsString( 'user_id = 7', $last[1] );
+        $this->assertNull( $this->repo->openBuild( 8 ) );
+    }
+
+    public function testTrimNeverSelectsAnOpenBuildForDeletion(): void
+    {
+        $GLOBALS['wpdb']->col = [ '5' ];
+        $this->repo->trim( 20 );
+        $this->assertStringContainsString( "status <> 'building'", $GLOBALS['wpdb']->calls[0][1] );
+    }
 }
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-build-site.md T1-tests` → `applied: T1-tests`.

- [ ] **Step 2: Run the Pro suite and watch it fail**

Run: `make test-pro 2>&1 | tail -40`. Expected: FAIL (red). `Tests: 776, Assertions: 18294, Errors: 9, Failures: 1.`. Failing tests include: `testOpenStoresAnEmptyBuildingBatch`, `testOpenReturnsNullWhenTheStoreFails`, `testAppendAddsItemsAfterTheExistingOnesAndKeepsTheStatus`, `testCloseSetsTheStatusAndKeepsTheItems`, `testTheUsersOpenBuildIsFoundAndOnlyTheirs`, `testAnOpenBuildIsNeverTrimmed`, `testAnOpenBuildIsListedAsBuilding`, `testAppendAddsToTheStoredItemsAndKeepsTheStatus`, `testOpenBuildAsksForTheUsersNewestBuildingBatch`, `testTrimNeverSelectsAnOpenBuildForDeletion`.

- [ ] **Step 3: Apply the source patch**

Apply `T1-src`:

`````diff
diff --git a/pro-addon/src/Admin/BatchesPanel.php b/pro-addon/src/Admin/BatchesPanel.php
index 9bb75c3..4ea34ff 100644
--- a/pro-addon/src/Admin/BatchesPanel.php
+++ b/pro-addon/src/Admin/BatchesPanel.php
@@ -116,6 +116,7 @@ final class BatchesPanel
         return match ( $status ) {
             'undone'        => __( 'undone', 'jhmg-ai-editor-for-divi-5-pro' ),
             'partly_undone' => __( 'partly undone', 'jhmg-ai-editor-for-divi-5-pro' ),
+            'building'      => __( 'building', 'jhmg-ai-editor-for-divi-5-pro' ),
             default         => __( 'applied', 'jhmg-ai-editor-for-divi-5-pro' ),
         };
     }
diff --git a/pro-addon/src/Batch/BatchLog.php b/pro-addon/src/Batch/BatchLog.php
index 53606e2..c470399 100644
--- a/pro-addon/src/Batch/BatchLog.php
+++ b/pro-addon/src/Batch/BatchLog.php
@@ -13,6 +13,9 @@ final class BatchLog
 {
     public const MAX = 20;
 
+    /** The status of a batch that is still growing (an open build). */
+    public const BUILDING = 'building';
+
     public function __construct( private BatchRepository $repo )
     {
     }
@@ -43,6 +46,50 @@ final class BatchLog
         return self::describe( [ 'id' => $id ] + $row );
     }
 
+    /**
+     * Opens a batch that will grow: status `building`, no items yet. Closed with close() (or by an undo).
+     *
+     * @return array<string,mixed>|null the described batch, or null when it could not be stored
+     */
+    public function open( string $tool, string $summary ): ?array
+    {
+        $row = [
+            'user_id'    => (int) get_current_user_id(),
+            'tool'       => $tool,
+            'created_at' => gmdate( 'Y-m-d H:i:s' ),
+            'summary'    => mb_substr( $summary, 0, 255 ),
+            'status'     => self::BUILDING,
+            'items'      => [],
+        ];
+        $id = $this->repo->insert( $row );
+        if ( null === $id ) {
+            return null;
+        }
+        $this->repo->trim( self::MAX );
+
+        return self::describe( [ 'id' => $id ] + $row );
+    }
+
+    /** @param list<array<string,mixed>> $items */
+    public function append( int $id, array $items ): bool
+    {
+        return $this->repo->append( $id, $items );
+    }
+
+    /** Sets the status of a batch (an open build becomes `applied`) and keeps its items. */
+    public function close( int $id, string $status ): bool
+    {
+        $batch = $this->repo->find( $id );
+
+        return null !== $batch && $this->repo->update( $id, $status, $batch['items'] );
+    }
+
+    /** @return array<string,mixed>|null the user's open build */
+    public function openBuild( int $userId ): ?array
+    {
+        return $this->repo->openBuild( $userId );
+    }
+
     /** @return array<string,mixed>|null */
     public function find( int $id ): ?array
     {
diff --git a/pro-addon/src/Batch/BatchRepository.php b/pro-addon/src/Batch/BatchRepository.php
index c094227..d04e226 100644
--- a/pro-addon/src/Batch/BatchRepository.php
+++ b/pro-addon/src/Batch/BatchRepository.php
@@ -9,9 +9,10 @@ if ( ! defined( 'ABSPATH' ) ) {
 }
 
 /**
- * The five storage operations the batch log needs. A row is
+ * The seven storage operations the batch log needs. A row is
  * ['id'=>int, 'user_id'=>int, 'tool'=>string, 'created_at'=>'Y-m-d H:i:s' (UTC), 'summary'=>string,
- *  'status'=>'applied'|'undone'|'partly_undone', 'items'=>list<array>].
+ *  'status'=>'applied'|'undone'|'partly_undone'|'building', 'items'=>list<array>]. A `building` row is an OPEN
+ * build (a batch that still grows): it is never trimmed and does not count towards the kept batches.
  */
 interface BatchRepository
 {
@@ -27,6 +28,12 @@ interface BatchRepository
     /** @param list<array<string,mixed>> $items */
     public function update( int $id, string $status, array $items ): bool;
 
-    /** Delete everything but the newest $keep rows. */
+    /** Add items after the stored ones, keeping the status. False when the batch does not exist or the write failed. @param list<array<string,mixed>> $items */
+    public function append( int $id, array $items ): bool;
+
+    /** The newest `building` batch of this user, or null. @return array<string,mixed>|null */
+    public function openBuild( int $userId ): ?array;
+
+    /** Delete everything but the newest $keep rows that are not open builds. */
     public function trim( int $keep ): void;
 }
diff --git a/pro-addon/src/Batch/WpdbBatchRepository.php b/pro-addon/src/Batch/WpdbBatchRepository.php
index 8a6f9c6..8daf3a7 100644
--- a/pro-addon/src/Batch/WpdbBatchRepository.php
+++ b/pro-addon/src/Batch/WpdbBatchRepository.php
@@ -67,12 +67,32 @@ final class WpdbBatchRepository implements BatchRepository
         return false !== $wpdb->update( BatchTable::name(), [ 'status' => $status, 'items' => $json ], [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );
     }
 
+    public function append( int $id, array $items ): bool
+    {
+        $row = $this->find( $id );
+        if ( null === $row ) {
+            return false;
+        }
+
+        return $this->update( $id, (string) $row['status'], array_merge( $row['items'], array_values( $items ) ) );
+    }
+
+    public function openBuild( int $userId ): ?array
+    {
+        global $wpdb;
+
+        $table = BatchTable::name();
+        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND user_id = %d ORDER BY id DESC LIMIT 1", 'building', $userId ), ARRAY_A );
+
+        return is_array( $row ) ? self::hydrate( $row ) : null;
+    }
+
     public function trim( int $keep ): void
     {
         global $wpdb;
 
         $table = BatchTable::name();
-        $ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1000 OFFSET %d", max( 0, $keep ) ) );
+        $ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status <> %s ORDER BY id DESC LIMIT 1000 OFFSET %d", 'building', max( 0, $keep ) ) );
         if ( ! is_array( $ids ) || [] === $ids ) {
             return;
         }
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-build-site.md T1-src` → `applied: T1-src`.

- [ ] **Step 4: Run the suites and watch them pass**

Run: `make test-pro 2>&1 | tail -3` → `OK (776 tests, 18327 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -q -F - <<'EOF'
feat(pro): batches that grow - open, append, close and openBuild, status building, open builds are never trimmed

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B | tail -2
```

### Task 2: Item kinds `page_created` and `page_trashed`

**What:** Two item kinds. `page_created` (`ref` = page id, `before` null, `after_hash` of title + content + slug, `meta` with the menu label and flags): undo trashes the page only while it is unchanged (the changed-since rule); its inverse `page_trashed` untrashes it (core returns a draft) and records a `page_created` again, so an undo can be undone; with `EMPTY_TRASH_DAYS` 0 the inverse has no undo point. Both are registered in `Batches::kinds()`.

**Files:** Create: `pro-addon/src/Kinds/PageCreatedKind.php`, `PageTrashedKind.php`, `pro-addon/tests/PageCreatedKindTest.php`, `pro-addon/tests/WpBuildShims.php`; Modify: `src/autoload.php`, `src/Batch/Batches.php`, `tests/ToolsTest.php`.

**Interfaces:** Produces: `PageCreatedKind::itemFor( object $post, array $meta ): array`, `::snapshot()`, `::trashEnabled()`, `::$trashEnabled` (test override); kind names `page_created`, `page_trashed`.

- [ ] **Step 1: Apply the tests patch**

Apply `T2-tests`:

`````diff
diff --git a/pro-addon/tests/PageCreatedKindTest.php b/pro-addon/tests/PageCreatedKindTest.php
new file mode 100644
index 0000000..764f017
--- /dev/null
+++ b/pro-addon/tests/PageCreatedKindTest.php
@@ -0,0 +1,172 @@
+<?php
+
+declare(strict_types=1);
+
+namespace AiEditorDivi5\Pro\Tests;
+
+use AiEditorDivi5\Pro\Batch\BatchLog;
+use AiEditorDivi5\Pro\Batch\BatchUndo;
+use AiEditorDivi5\Pro\Batch\Item;
+use AiEditorDivi5\Pro\Canon;
+use AiEditorDivi5\Pro\Kinds\PageCreatedKind;
+use AiEditorDivi5\Pro\Kinds\PageTrashedKind;
+use PHPUnit\Framework\TestCase;
+
+require_once __DIR__ . '/../src/autoload.php';
+require_once __DIR__ . '/InMemoryBatchRepository.php';
+require_once __DIR__ . '/WpBuildShims.php';
+
+/** A page the build created, and how an undo takes it away again (and brings it back). */
+class PageCreatedKindTest extends TestCase
+{
+    private InMemoryBatchRepository $repo;
+    private BatchLog $log;
+
+    protected function setUp(): void
+    {
+        $this->repo = new InMemoryBatchRepository();
+        $this->log  = new BatchLog( $this->repo );
+        $GLOBALS['__wp_posts'] = [ 50 => (object) [ 'ID' => 50, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'About us', 'post_content' => 'C0', 'post_name' => 'about' ] ];
+        $GLOBALS['__wp_caps']  = [ 'edit_post' => true, 'delete_post' => true ];
+        $GLOBALS['__wp_trash_fail']   = false;
+        $GLOBALS['__wp_untrash_fail'] = false;
+        PageCreatedKind::$trashEnabled = null;
+    }
+
+    protected function tearDown(): void
+    {
+        PageCreatedKind::$trashEnabled = null;
+        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_trash_fail'], $GLOBALS['__wp_untrash_fail'] );
+    }
+
+    /** @return array<string,mixed> */
+    private function item(): array
+    {
+        return PageCreatedKind::itemFor( $GLOBALS['__wp_posts'][50], [ 'in_menu' => true, 'is_front' => false, 'menu_label' => 'About' ] );
+    }
+
+    private function undo(): BatchUndo
+    {
+        return new BatchUndo( [ 'page_created' => new PageCreatedKind(), 'page_trashed' => new PageTrashedKind() ], $this->log );
+    }
+
+    public function testTheItemRecordsThePageAndWhatTheMenuNeeds(): void
+    {
+        $i = $this->item();
+        $this->assertSame( [ 'page_created', '50', null, 'applied' ], [ $i['kind'], $i['ref'], $i['before'], $i['state'] ] );
+        $this->assertStringContainsString( 'About us', $i['label'] );
+        $this->assertStringContainsString( '#50', $i['label'] );
+        $this->assertSame( Canon::hash( [ 'title' => 'About us', 'content' => 'C0', 'slug' => 'about' ] ), $i['after_hash'] );
+        $this->assertSame( [ 'available' => true ], $i['undo'] );
+        $this->assertSame( [ 'in_menu' => true, 'is_front' => false, 'menu_label' => 'About' ], $i['meta'] );
+    }
+
+    public function testCurrentIsTheSnapshotOfADraftAndNullForAGoneOrTrashedPage(): void
+    {
+        $kind = new PageCreatedKind();
+        $item = $this->item();
+        $this->assertSame( [ 'title' => 'About us', 'content' => 'C0', 'slug' => 'about' ], $kind->current( $item ) );
+        $this->assertSame( $item['after_hash'], Canon::hash( $kind->current( $item ) ) );
+        $GLOBALS['__wp_posts'][50]->post_content = 'edited in Divi';
+        $this->assertNotSame( $item['after_hash'], Canon::hash( $kind->current( $item ) ), 'an edit changes what the changed-since rule sees' );
+        $GLOBALS['__wp_posts'][50]->post_status = 'trash';
+        $this->assertNull( $kind->current( $item ) );
+        unset( $GLOBALS['__wp_posts'][50] );
+        $this->assertNull( $kind->current( $item ) );
+    }
+
+    public function testItNeedsTheRightToEditAndToDeleteThePage(): void
+    {
+        $kind = new PageCreatedKind();
+        $this->assertTrue( $kind->allowed( $this->item() ) );
+        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'delete_post' => false ];
+        $this->assertFalse( $kind->allowed( $this->item() ) );
+        $GLOBALS['__wp_caps'] = [ 'edit_post' => false, 'delete_post' => true ];
+        $this->assertFalse( $kind->allowed( $this->item() ) );
+    }
+
+    public function testRestoreTrashesThePageAndRecordsTheInverse(): void
+    {
+        $r = ( new PageCreatedKind() )->restore( $this->item() );
+        $this->assertTrue( $r['ok'] );
+        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][50]->post_status );
+        $this->assertSame( [ 'page_trashed', '50', 'trash' ], [ $r['inverse']['kind'], $r['inverse']['ref'], $r['inverse']['after_hash'] === Canon::hash( 'trash' ) ? 'trash' : 'other' ] );
+        $this->assertSame( [ 'available' => true ], $r['inverse']['undo'] );
+        $this->assertSame( [ 'in_menu' => true, 'is_front' => false, 'menu_label' => 'About' ], $r['inverse']['meta'], 'what the menu needs travels with the page' );
+    }
+
+    public function testRestoreOfAMissingPageOrAFailedTrashIsReported(): void
+    {
+        $GLOBALS['__wp_trash_fail'] = true;
+        $this->assertSame( [ 'ok' => false, 'reason' => 'trash_failed' ], ( new PageCreatedKind() )->restore( $this->item() ) );
+        $GLOBALS['__wp_trash_fail'] = false;
+        $item = $this->item();
+        unset( $GLOBALS['__wp_posts'][50] );
+        $this->assertSame( [ 'ok' => false, 'reason' => 'missing' ], ( new PageCreatedKind() )->restore( $item ) );
+    }
+
+    public function testWithTrashSwitchedOffTheUndoCannotBeUndoneAndSaysWhy(): void
+    {
+        PageCreatedKind::$trashEnabled = static fn (): bool => false;
+        $r = ( new PageCreatedKind() )->restore( $this->item() );
+        $this->assertTrue( $r['ok'] );
+        $this->assertSame( [ 'available' => false, 'reason' => 'trash_disabled' ], $r['inverse']['undo'] );
+    }
+
+    public function testTheTrashSettingDefaultsToOnAndFollowsTheConstant(): void
+    {
+        $this->assertTrue( PageCreatedKind::trashEnabled(), 'EMPTY_TRASH_DAYS is not defined in the tests: core defaults to 30' );
+    }
+
+    public function testAnUndoThroughTheEngineTrashesAnUntouchedPageAndAnUndoOfThatBringsItBackAsADraft(): void
+    {
+        $batch = $this->log->record( 'build_site', 'Site build', [ $this->item() ] );
+        $u     = $this->undo()->undo( $batch['id'] );
+        $this->assertTrue( $u['fully_undone'] );
+        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][50]->post_status );
+        $this->assertSame( 'about__trashed', $GLOBALS['__wp_posts'][50]->post_name );
+
+        $back = $this->undo()->undo( (int) $u['undo_batch']['id'] );
+        $this->assertTrue( $back['fully_undone'] );
+        $this->assertSame( [ 'draft', 'about', 'C0' ], [ $GLOBALS['__wp_posts'][50]->post_status, $GLOBALS['__wp_posts'][50]->post_name, $GLOBALS['__wp_posts'][50]->post_content ] );
+        $again = $this->repo->rows[ (int) $back['undo_batch']['id'] ]['items'][0];
+        $this->assertSame( 'page_created', $again['kind'], 'undoing the undo can be undone again' );
+        $this->assertSame( $this->item()['after_hash'], $again['after_hash'] );
+    }
+
+    public function testAPageEditedAfterTheBuildIsSkippedNotTrashed(): void
+    {
+        $batch = $this->log->record( 'build_site', 'Site build', [ $this->item() ] );
+        $GLOBALS['__wp_posts'][50]->post_content = 'edited in the Divi builder';
+        $u = $this->undo()->undo( $batch['id'] );
+        $this->assertFalse( $u['fully_undone'] );
+        $this->assertSame( 'changed_since', $u['items'][0]['result'] );
+        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][50]->post_status, 'never trashed behind the owner\'s back' );
+    }
+
+    public function testTheTrashedKindNeedsThePageToBeInTheTrashAndReportsFailures(): void
+    {
+        $kind = new PageTrashedKind();
+        $item = Item::make( 'page_trashed', '50', 'Created page "About us" (#50)', null, 'trash' ) + [ 'meta' => [ 'in_menu' => true ] ];
+        $this->assertSame( 'draft', $kind->current( $item ), 'a page that is not in the trash reads as its status' );
+        $this->assertSame( [ 'ok' => false, 'reason' => 'untrash_failed' ], $kind->restore( $item ), 'a draft cannot be untrashed' );
+        wp_trash_post( 50 );
+        $this->assertSame( 'trash', $kind->current( $item ) );
+        $GLOBALS['__wp_untrash_fail'] = true;
+        $this->assertSame( [ 'ok' => false, 'reason' => 'untrash_failed' ], $kind->restore( $item ) );
+        $GLOBALS['__wp_untrash_fail'] = false;
+        $this->assertTrue( $kind->restore( $item )['ok'] );
+        unset( $GLOBALS['__wp_posts'][50] );
+        $this->assertNull( $kind->current( $item ) );
+        $this->assertSame( [ 'ok' => false, 'reason' => 'missing' ], $kind->restore( $item ) );
+    }
+
+    public function testTheTrashedKindNeedsTheRightToEditAndDelete(): void
+    {
+        $kind = new PageTrashedKind();
+        $item = Item::make( 'page_trashed', '50', 'x', null, 'trash' );
+        $this->assertTrue( $kind->allowed( $item ) );
+        $GLOBALS['__wp_caps'] = [ 'edit_post' => true ];
+        $this->assertFalse( $kind->allowed( $item ) );
+    }
+}
diff --git a/pro-addon/tests/ToolsTest.php b/pro-addon/tests/ToolsTest.php
index 3d7591d..01860ce 100644
--- a/pro-addon/tests/ToolsTest.php
+++ b/pro-addon/tests/ToolsTest.php
@@ -51,7 +51,7 @@ class ToolsTest extends TestCase
     public function testEveryKindARecordedItemUsesIsRegisteredForUndo(): void
     {
         $this->assertEqualsCanonicalizing(
-            [ 'front_page', 'custom_css', 'menu', 'post_status', 'post_content', 'theme_layout_content', 'theme_template' ],
+            [ 'front_page', 'custom_css', 'menu', 'post_status', 'page_created', 'page_trashed', 'post_content', 'theme_layout_content', 'theme_template' ],
             array_keys( \AiEditorDivi5\Pro\Batch\Batches::kinds() )
         );
     }
diff --git a/pro-addon/tests/WpBuildShims.php b/pro-addon/tests/WpBuildShims.php
new file mode 100644
index 0000000..b5fbfbd
--- /dev/null
+++ b/pro-addon/tests/WpBuildShims.php
@@ -0,0 +1,46 @@
+<?php
+
+declare(strict_types=1);
+
+/**
+ * Trash, untrash, add_option and the page lookups the site-build code needs, over the in-memory `$GLOBALS['__wp_posts']`
+ * store (plain objects with post_name). Like core: trashing keeps the content, renames the slug with `__trashed` and
+ * remembers the status; untrashing restores the slug and brings the page back as a DRAFT. Guarded like every shim.
+ */
+if ( ! function_exists( 'wp_trash_post' ) ) {
+    function wp_trash_post( $id ) {
+        $p = $GLOBALS['__wp_posts'][ (int) $id ] ?? null;
+        if ( ! $p || 'trash' === $p->post_status || ! empty( $GLOBALS['__wp_trash_fail'] ) ) {
+            return false;
+        }
+        $p->_wp_trash_meta_status = $p->post_status;
+        $p->post_status           = 'trash';
+        $p->post_name             = ( $p->post_name ?? '' ) . '__trashed';
+        $GLOBALS['__wp_trash_calls'][] = (int) $id;
+
+        return $p;
+    }
+}
+if ( ! function_exists( 'wp_untrash_post' ) ) {
+    function wp_untrash_post( $id ) {
+        $p = $GLOBALS['__wp_posts'][ (int) $id ] ?? null;
+        if ( ! $p || 'trash' !== $p->post_status || ! empty( $GLOBALS['__wp_untrash_fail'] ) ) {
+            return false;
+        }
+        $p->post_status = 'draft';
+        $p->post_name   = (string) preg_replace( '/__trashed$/', '', (string) ( $p->post_name ?? '' ) );
+
+        return $p;
+    }
+}
+if ( ! function_exists( 'add_option' ) ) {
+    /** Like core: false when the option already exists. */
+    function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
+        if ( array_key_exists( $key, $GLOBALS['__wp_options'] ?? [] ) ) {
+            return false;
+        }
+        $GLOBALS['__wp_options'][ $key ] = $value;
+
+        return true;
+    }
+}
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-build-site.md T2-tests` → `applied: T2-tests`.

- [ ] **Step 2: Run the Pro suite and watch it fail**

Run: `make test-pro 2>&1 | tail -40`. Expected: FAIL (red). `Tests: 787, Assertions: 18327, Errors: 11, Failures: 1.`. Failing tests include: `testTheItemRecordsThePageAndWhatTheMenuNeeds`, `testCurrentIsTheSnapshotOfADraftAndNullForAGoneOrTrashedPage`, `testItNeedsTheRightToEditAndToDeleteThePage`, `testRestoreTrashesThePageAndRecordsTheInverse`, `testRestoreOfAMissingPageOrAFailedTrashIsReported`, `testWithTrashSwitchedOffTheUndoCannotBeUndoneAndSaysWhy`, `testTheTrashSettingDefaultsToOnAndFollowsTheConstant`, `testAnUndoThroughTheEngineTrashesAnUntouchedPageAndAnUndoOfThatBringsItBackAsADraft`, `testAPageEditedAfterTheBuildIsSkippedNotTrashed`, `testTheTrashedKindNeedsThePageToBeInTheTrashAndReportsFailures`, `testTheTrashedKindNeedsTheRightToEditAndDelete`, `testEveryKindARecordedItemUsesIsRegisteredForUndo`.

- [ ] **Step 3: Apply the source patch**

Apply `T2-src`:

`````diff
diff --git a/pro-addon/src/Batch/Batches.php b/pro-addon/src/Batch/Batches.php
index fce32ba..398f23b 100644
--- a/pro-addon/src/Batch/Batches.php
+++ b/pro-addon/src/Batch/Batches.php
@@ -7,6 +7,8 @@ namespace AiEditorDivi5\Pro\Batch;
 use AiEditorDivi5\Pro\Kinds\CssKind;
 use AiEditorDivi5\Pro\Kinds\FrontPageKind;
 use AiEditorDivi5\Pro\Kinds\MenuKind;
+use AiEditorDivi5\Pro\Kinds\PageCreatedKind;
+use AiEditorDivi5\Pro\Kinds\PageTrashedKind;
 use AiEditorDivi5\Pro\Kinds\PostContentKind;
 use AiEditorDivi5\Pro\Kinds\PostStatusKind;
 use AiEditorDivi5\Pro\Kinds\ThemeLayoutKind;
@@ -35,6 +37,8 @@ final class Batches
             new CssKind(),
             new MenuKind(),
             new PostStatusKind(),
+            new PageCreatedKind(),
+            new PageTrashedKind(),
             new PostContentKind(),
             new ThemeLayoutKind(),
             new ThemeTemplateKind(),
diff --git a/pro-addon/src/Kinds/PageCreatedKind.php b/pro-addon/src/Kinds/PageCreatedKind.php
new file mode 100644
index 0000000..5bcc380
--- /dev/null
+++ b/pro-addon/src/Kinds/PageCreatedKind.php
@@ -0,0 +1,87 @@
+<?php
+
+declare(strict_types=1);
+
+namespace AiEditorDivi5\Pro\Kinds;
+
+use AiEditorDivi5\Pro\Batch\Item;
+use AiEditorDivi5\Pro\Batch\ItemKind;
+
+if ( ! defined( 'ABSPATH' ) ) {
+    exit;
+}
+
+/**
+ * A page a site build created. `ref` is the page id, `before` is null (there was nothing), `after_hash` is a hash of the
+ * title, content and slug read back after the insert, and `meta` carries what the launch needs (menu label, in the menu,
+ * front page). Undo trashes the page, but only while it still looks the way the build left it (the changed-since rule), so a
+ * page the owner edited in the Divi builder is skipped and reported, never trashed behind their back. The inverse is a
+ * `page_trashed` item; with `EMPTY_TRASH_DAYS` 0 the trash is a permanent delete, so that inverse has no undo point.
+ */
+final class PageCreatedKind implements ItemKind
+{
+    public const NAME = 'page_created';
+
+    /** @var (\Closure():bool)|null tests switch the trash setting with this */
+    public static ?\Closure $trashEnabled = null;
+
+    public function name(): string
+    {
+        return self::NAME;
+    }
+
+    /** Whether trashing a page keeps it (core: EMPTY_TRASH_DAYS above 0, default 30). */
+    public static function trashEnabled(): bool
+    {
+        if ( null !== self::$trashEnabled ) {
+            return (bool) ( self::$trashEnabled )();
+        }
+
+        return ! defined( 'EMPTY_TRASH_DAYS' ) || (int) EMPTY_TRASH_DAYS > 0;
+    }
+
+    /** @return array{title:string, content:string, slug:string} */
+    public static function snapshot( object $post ): array
+    {
+        return [ 'title' => (string) $post->post_title, 'content' => (string) $post->post_content, 'slug' => (string) ( $post->post_name ?? '' ) ];
+    }
+
+    /**
+     * @param array<string,mixed> $meta
+     * @return array<string,mixed>
+     */
+    public static function itemFor( object $post, array $meta ): array
+    {
+        $title = (string) $post->post_title;
+
+        return Item::make( self::NAME, (string) $post->ID, "Created page \"{$title}\" (#{$post->ID})", null, self::snapshot( $post ) ) + [ 'meta' => $meta ];
+    }
+
+    public function current( array $item ): mixed
+    {
+        $post = get_post( (int) $item['ref'] );
+
+        return $post && 'trash' !== $post->post_status ? self::snapshot( $post ) : null;
+    }
+
+    public function allowed( array $item ): bool
+    {
+        $id = (int) $item['ref'];
+
+        return current_user_can( 'edit_post', $id ) && current_user_can( 'delete_post', $id );
+    }
+
+    public function restore( array $item ): array
+    {
+        $id = (int) $item['ref'];
+        if ( ! get_post( $id ) ) {
+            return [ 'ok' => false, 'reason' => 'missing' ];
+        }
+        if ( ! wp_trash_post( $id ) ) {
+            return [ 'ok' => false, 'reason' => 'trash_failed' ];
+        }
+        $undo = self::trashEnabled() ? null : [ 'available' => false, 'reason' => 'trash_disabled' ];
+
+        return [ 'ok' => true, 'inverse' => Item::make( PageTrashedKind::NAME, (string) $id, (string) $item['label'], null, 'trash', $undo ) + [ 'meta' => $item['meta'] ?? [] ] ];
+    }
+}
diff --git a/pro-addon/src/Kinds/PageTrashedKind.php b/pro-addon/src/Kinds/PageTrashedKind.php
new file mode 100644
index 0000000..eda9531
--- /dev/null
+++ b/pro-addon/src/Kinds/PageTrashedKind.php
@@ -0,0 +1,54 @@
+<?php
+
+declare(strict_types=1);
+
+namespace AiEditorDivi5\Pro\Kinds;
+
+use AiEditorDivi5\Pro\Batch\Item;
+use AiEditorDivi5\Pro\Batch\ItemKind;
+
+if ( ! defined( 'ABSPATH' ) ) {
+    exit;
+}
+
+/**
+ * The inverse of an undone `page_created`: the page is in the trash. Undoing it takes the page out of the trash again (core
+ * brings it back as a DRAFT with its slug restored) and records a `page_created` item, so an undo can always be undone.
+ */
+final class PageTrashedKind implements ItemKind
+{
+    public const NAME = 'page_trashed';
+
+    public function name(): string
+    {
+        return self::NAME;
+    }
+
+    public function current( array $item ): mixed
+    {
+        $post = get_post( (int) $item['ref'] );
+
+        return $post ? (string) $post->post_status : null;
+    }
+
+    public function allowed( array $item ): bool
+    {
+        $id = (int) $item['ref'];
+
+        return current_user_can( 'edit_post', $id ) && current_user_can( 'delete_post', $id );
+    }
+
+    public function restore( array $item ): array
+    {
+        $id = (int) $item['ref'];
+        if ( ! get_post( $id ) ) {
+            return [ 'ok' => false, 'reason' => 'missing' ];
+        }
+        if ( ! wp_untrash_post( $id ) ) {
+            return [ 'ok' => false, 'reason' => 'untrash_failed' ];
+        }
+        $post = get_post( $id );
+
+        return [ 'ok' => true, 'inverse' => PageCreatedKind::itemFor( $post, is_array( $item['meta'] ?? null ) ? $item['meta'] : [] ) ];
+    }
+}
diff --git a/pro-addon/src/autoload.php b/pro-addon/src/autoload.php
index 6703911..0b883b9 100644
--- a/pro-addon/src/autoload.php
+++ b/pro-addon/src/autoload.php
@@ -34,6 +34,8 @@ require_once __DIR__ . '/Tools/SetFrontPage.php';
 require_once __DIR__ . '/Tools/SetCustomCss.php';
 require_once __DIR__ . '/Tools/SetPrimaryMenu.php';
 require_once __DIR__ . '/Kinds/PostStatusKind.php';
+require_once __DIR__ . '/Kinds/PageCreatedKind.php';
+require_once __DIR__ . '/Kinds/PageTrashedKind.php';
 require_once __DIR__ . '/Tools/PublishPage.php';
 require_once __DIR__ . '/Settings/PhpProposals.php';
 require_once __DIR__ . '/Tools/ProposePhpSnippet.php';
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-build-site.md T2-src` → `applied: T2-src`.

- [ ] **Step 4: Run the suites and watch them pass**

Run: `make test-pro 2>&1 | tail -3` → `OK (791 tests, 18376 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -q -F - <<'EOF'
feat(pro): page_created and page_trashed item kinds (a page a build created, undone by trashing it)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B | tail -2
```

### Task 3: `build_site`: `add_page`, `status` and the add_page lock

**What:** The `build_site` tool with `add_page` and `status`. `add_page`: type-checked arguments, `LayoutGate::check`, the build rules (one open build per user, `build_id` of your own open build, at most 30 pages, one front page), a slug that no other page (any status but trash) uses, creation as a draft with the Divi 5 flags (`wp_slash`), the page recorded as a `page_created` item in the one growing batch, a lock (`BuildLock`) so parallel calls cannot lose a page. The tool is registered (14 Pro tools, 30 of 30 ChatGPT operations); the shared test shims gain `post_name`, `get_posts` by name and a failing insert.

**Files:** Create: `pro-addon/src/Site/BuildLock.php`, `pro-addon/src/Tools/BuildSite.php`, `BuildLockTest.php`, `BuildSiteTest.php`; Modify: `src/autoload.php`, `src/Tools.php`, `pro-addon/readme.txt` (the tool list), the shims `tests/WpRestShims.php`, `tests/WpInsertPostShims.php`, `pro-addon/tests/WpSiteShims.php`, `InMemoryBatchRepository.php`, and the count tests `ToolsTest`, `ProOpenApiTest`.

**Interfaces:** Produces: `BuildSite::__construct( BatchLog $log, ?BuildLock $lock = null )`, `BuildSite::pageItems( array $row ): array`, `::summary( array $row ): array`, `::state( array $row ): string`; `BuildLock::acquire( int ): bool`, `release( int ): void`.

- [ ] **Step 1: Apply the tests patch**

Apply `T3-tests`:

`````diff
diff --git a/pro-addon/tests/BuildLockTest.php b/pro-addon/tests/BuildLockTest.php
new file mode 100644
index 0000000..c3c22cd
--- /dev/null
+++ b/pro-addon/tests/BuildLockTest.php
@@ -0,0 +1,56 @@
+<?php
+
+declare(strict_types=1);
+
+namespace AiEditorDivi5\Pro\Tests;
+
+use AiEditorDivi5\Pro\Site\BuildLock;
+use PHPUnit\Framework\TestCase;
+
+require_once __DIR__ . '/../src/autoload.php';
+require_once __DIR__ . '/WpBuildShims.php';
+
+/** One add_page at a time per user: a parallel tool call must never create a page the build does not record. */
+class BuildLockTest extends TestCase
+{
+    protected function setUp(): void
+    {
+        $GLOBALS['__wp_options'] = [];
+    }
+
+    public function testItIsHeldUntilReleasedAndPerUser(): void
+    {
+        $lock = new BuildLock( 0 );
+        $this->assertTrue( $lock->acquire( 7 ) );
+        $this->assertFalse( $lock->acquire( 7 ), 'the same user cannot take it twice' );
+        $this->assertTrue( $lock->acquire( 8 ), 'another user has their own lock' );
+        $lock->release( 7 );
+        $this->assertTrue( $lock->acquire( 7 ) );
+    }
+
+    public function testALockLeftByACrashedRequestIsTakenOverAfterItsExpiry(): void
+    {
+        $lock = new BuildLock( 0, 15 );
+        $GLOBALS['__wp_options']['aied_pro_buildlock_7'] = (string) ( time() - 20 );
+        $this->assertTrue( $lock->acquire( 7 ), 'older than 15 seconds: taken over' );
+        $lock->release( 7 );
+        $GLOBALS['__wp_options']['aied_pro_buildlock_7'] = (string) ( time() - 3 );
+        $this->assertFalse( $lock->acquire( 7 ), 'a fresh lock is respected' );
+    }
+
+    public function testItWaitsForTheLockForAtMostTheGivenTime(): void
+    {
+        $GLOBALS['__wp_options']['aied_pro_buildlock_7'] = (string) time();
+        $start = microtime( true );
+        $this->assertFalse( ( new BuildLock( 250, 15 ) )->acquire( 7 ) );
+        $took = microtime( true ) - $start;
+        $this->assertGreaterThanOrEqual( 0.2, $took );
+        $this->assertLessThan( 2.0, $took );
+    }
+
+    public function testReleasingALockThatIsNotHeldIsHarmless(): void
+    {
+        ( new BuildLock( 0 ) )->release( 7 );
+        $this->assertSame( [], $GLOBALS['__wp_options'] );
+    }
+}
diff --git a/pro-addon/tests/BuildSiteTest.php b/pro-addon/tests/BuildSiteTest.php
new file mode 100644
index 0000000..f839201
--- /dev/null
+++ b/pro-addon/tests/BuildSiteTest.php
@@ -0,0 +1,304 @@
+<?php
+
+declare(strict_types=1);
+
+namespace AiEditorDivi5\Pro\Tests;
+
+use AiEditorDivi5\Pro\Batch\BatchLog;
+use AiEditorDivi5\Pro\Site\BuildLock;
+use AiEditorDivi5\Pro\Tools;
+use AiEditorDivi5\Pro\Tools\BuildSite;
+use PHPUnit\Framework\TestCase;
+
+require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
+require_once __DIR__ . '/../src/autoload.php';
+require_once __DIR__ . '/../../tests/WpInsertPostShims.php';
+require_once __DIR__ . '/InMemoryBatchRepository.php';
+require_once __DIR__ . '/WpSiteShims.php';
+require_once __DIR__ . '/WpBuildShims.php';
+
+/** build_site: add_page (one validated draft per call, one growing batch) and status. */
+class BuildSiteTest extends TestCase
+{
+    private InMemoryBatchRepository $repo;
+    private BatchLog $log;
+    private BuildSite $tool;
+
+    /** A valid layout whose heading says $word. */
+    private static function layout( string $word = 'Hello' ): string
+    {
+        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"' . $word . '"}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
+    }
+
+    protected function setUp(): void
+    {
+        $this->repo = new InMemoryBatchRepository();
+        $this->log  = new BatchLog( $this->repo );
+        $this->tool = new BuildSite( $this->log, new BuildLock( 0 ) );
+        $GLOBALS['__wp_posts']        = [];
+        $GLOBALS['__wp_postmeta']     = [];
+        $GLOBALS['__wp_options']      = [];
+        $GLOBALS['__wp_insert_fail']  = false;
+        $GLOBALS['__wp_inserts']      = [];
+        $GLOBALS['__wp_current_user_id'] = 7;
+        $GLOBALS['__wp_caps']         = [ 'publish_pages' => true, 'edit_post' => true, 'delete_post' => true ];
+    }
+
+    protected function tearDown(): void
+    {
+        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'] );
+        $GLOBALS['__wp_insert_fail'] = false;
+    }
+
+    /** @param array<string,mixed> $extra @return array<string,mixed> */
+    private function page( string $title, string $slug, array $extra = [] ): array
+    {
+        return $extra + [ 'action' => 'add_page', 'title' => $title, 'slug' => $slug, 'content' => self::layout( $title ) ];
+    }
+
+    private function refused( array $args, string $message ): void
+    {
+        $posts = $GLOBALS['__wp_posts'];
+        $rows  = $this->repo->rows;
+        try {
+            $this->tool->run( $args );
+            $this->fail( 'expected InvalidArgumentException: ' . $message );
+        } catch ( \InvalidArgumentException $e ) {
+            $this->assertStringContainsString( $message, $e->getMessage() );
+        }
+        $this->assertSame( $posts, $GLOBALS['__wp_posts'], 'a refused call creates and changes no page' );
+        $this->assertSame( $rows, $this->repo->rows, 'and records nothing' );
+    }
+
+    public function testItIsAProToolForPublishersWithOneActionArgument(): void
+    {
+        $d = $this->tool->definition();
+        $this->assertSame( 'build_site', $d['name'] );
+        $this->assertSame( 'publish_pages', $this->tool->capability() );
+        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
+        $this->assertSame( [ 'add_page', 'status' ], $d['inputSchema']['properties']['action']['enum'] );
+        $this->assertSame( [ 'action' ], $d['inputSchema']['required'] );
+        foreach ( [ 'title', 'slug', 'content', 'menu_label', 'in_menu', 'is_front', 'build_id' ] as $p ) {
+            $this->assertArrayHasKey( $p, $d['inputSchema']['properties'], $p );
+        }
+        $this->assertContains( 'build_site', array_map( static fn ( $t ) => $t->name(), Tools::all() ) );
+    }
+
+    public function testTheFirstPageOpensABuildAndIsCreatedAsADraft(): void
+    {
+        $r = $this->tool->run( $this->page( 'Home', 'home', [ 'is_front' => true ] ) );
+        $this->assertTrue( $r['created'] );
+        $this->assertSame( [ 'id' => 1, 'state' => 'open', 'pages' => 1, 'in_menu' => 1, 'front_page' => $r['page']['id'] ], $r['build'] );
+        $this->assertSame( [ 'Home', 'home', 'draft' ], [ $r['page']['title'], $r['page']['slug'], $r['page']['status'] ] );
+        $this->assertNotSame( '', $r['page']['link'] );
+
+        $post = $GLOBALS['__wp_posts'][ $r['page']['id'] ];
+        $this->assertSame( [ 'page', 'draft', 'home', self::layout( 'Home' ) ], [ $post->post_type, $post->post_status, $post->post_name, $post->post_content ] );
+        $this->assertSame( [ '_et_pb_use_divi_5' => 'on', '_et_pb_use_builder' => 'on' ], $GLOBALS['__wp_postmeta'][ $post->ID ], 'the Divi 5 builder flags, like the free create_page' );
+
+        $row = $this->repo->rows[1];
+        $this->assertSame( [ 'build_site', 'building', 7 ], [ $row['tool'], $row['status'], $row['user_id'] ] );
+        $this->assertCount( 1, $row['items'] );
+        $this->assertSame( [ 'page_created', (string) $post->ID ], [ $row['items'][0]['kind'], $row['items'][0]['ref'] ] );
+        $this->assertSame( [ 'in_menu' => true, 'is_front' => true, 'menu_label' => 'Home' ], $row['items'][0]['meta'] );
+    }
+
+    public function testLaterPagesJoinTheSameBuildInOrder(): void
+    {
+        $a = $this->tool->run( $this->page( 'Home', 'home' ) );
+        $b = $this->tool->run( $this->page( 'About us', 'about', [ 'build_id' => $a['build']['id'], 'menu_label' => 'About', 'in_menu' => false ] ) );
+        $this->assertSame( [ 1, 2, 1 ], [ $b['build']['id'], $b['build']['pages'], $b['build']['in_menu'] ] );
+        $items = $this->repo->rows[1]['items'];
+        $this->assertSame( [ (string) $a['page']['id'], (string) $b['page']['id'] ], array_column( $items, 'ref' ), 'add order' );
+        $this->assertSame( [ 'in_menu' => false, 'is_front' => false, 'menu_label' => 'About' ], $items[1]['meta'] );
+        $this->assertCount( 1, $this->repo->rows, 'one batch for the whole build' );
+    }
+
+    public function testASecondCallWithoutABuildIdIsRefusedAndNamesTheOpenBuild(): void
+    {
+        $this->tool->run( $this->page( 'Home', 'home' ) );
+        $this->refused( $this->page( 'About', 'about' ), 'build_id": 1' );
+        $this->refused( $this->page( 'About', 'about' ), 'undo_batch' );
+    }
+
+    public function testAnInvalidPageIsRefusedWithTheViolationsAndTheBuildKeepsGoing(): void
+    {
+        $this->refused( $this->page( 'Bad', 'bad', [ 'content' => str_replace( 'wp:divi/heading', 'wp:divi/not-a-module', self::layout() ) ] ), 'The page was not created: it failed the checks' );
+        $this->assertSame( [], $this->repo->rows, 'an invalid FIRST page does not even open a build' );
+
+        $a = $this->tool->run( $this->page( 'Home', 'home' ) );
+        $this->refused( $this->page( 'Bad', 'bad', [ 'build_id' => 1, 'content' => str_replace( 'wp:divi/heading', 'wp:divi/not-a-module', self::layout() ) ] ), 'not-a-module' );
+        $c = $this->tool->run( $this->page( 'Contact', 'contact', [ 'build_id' => 1 ] ) );
+        $this->assertSame( 2, $c['build']['pages'], 'the valid pages are kept' );
+        $this->assertSame( 'building', $this->repo->rows[1]['status'] );
+        $this->assertNotSame( $a['page']['id'], $c['page']['id'] );
+    }
+
+    public function testCustomCodeInAPageIsRefusedLikeEverywhereElse(): void
+    {
+        $css = str_replace( 'wp:divi/heading {', 'wp:divi/heading {"css":{"desktop":{"value":{"freeForm":"selector{color:red}"}}},', self::layout() );
+        $this->refused( $this->page( 'Styled', 'styled', [ 'content' => $css ] ), 'CUSTOM_CSS_NOT_ALLOWED' );
+    }
+
+    public function testASlugAlreadyUsedByAnotherPageIsRefusedButATrashedOneIsFree(): void
+    {
+        $GLOBALS['__wp_posts'][300] = (object) [ 'ID' => 300, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Old About', 'post_content' => 'x', 'post_name' => 'about' ];
+        $this->refused( $this->page( 'About', 'about' ), 'page #300' );
+        $GLOBALS['__wp_posts'][300]->post_status = 'trash';
+        $r = $this->tool->run( $this->page( 'About', 'about' ) );
+        $this->assertTrue( $r['created'], 'a trashed page does not hold its slug' );
+    }
+
+    public function testTwoPagesOfTheSameBuildCannotShareASlug(): void
+    {
+        $a = $this->tool->run( $this->page( 'About', 'about' ) );
+        $this->refused( $this->page( 'About again', 'about', [ 'build_id' => 1 ] ), 'page #' . $a['page']['id'] );
+    }
+
+    public function testTheSlugAndTitleAreCleanedAndMustSurviveCleaning(): void
+    {
+        $r = $this->tool->run( $this->page( '<b>Our</b> <script>alert(1)</script>Story', 'Our Story!!', [ 'content' => self::layout( 'Our Story' ) ] ) );
+        $this->assertSame( [ 'Our Story', 'our-story' ], [ $r['page']['title'], $r['page']['slug'] ] );
+        $this->refused( $this->page( '<b></b>', 'x', [ 'build_id' => 1 ] ), '"title" must contain text' );
+        $this->refused( $this->page( 'Ok', '!!!', [ 'build_id' => 1 ] ), '"slug" must contain letters or digits' );
+    }
+
+    public function testAtMostThirtyPagesAndOneFrontPage(): void
+    {
+        $this->tool->run( $this->page( 'Home', 'home', [ 'is_front' => true ] ) );
+        $this->refused( $this->page( 'Other home', 'other-home', [ 'build_id' => 1, 'is_front' => true ] ), 'already the front page' );
+        for ( $i = 2; $i <= 30; $i++ ) {
+            $this->tool->run( $this->page( "Page {$i}", "page-{$i}", [ 'build_id' => 1 ] ) );
+        }
+        $this->assertCount( 30, $this->repo->rows[1]['items'] );
+        $this->refused( $this->page( 'One too many', 'one-too-many', [ 'build_id' => 1 ] ), 'at most 30 pages' );
+    }
+
+    public function testABuildOfAnotherUserOrAFinishedOrUnknownBuildCannotBeUsed(): void
+    {
+        $this->tool->run( $this->page( 'Home', 'home' ) );
+        $GLOBALS['__wp_current_user_id'] = 8;
+        $this->refused( $this->page( 'About', 'about', [ 'build_id' => 1 ] ), 'Build 1 was not found' );
+        $GLOBALS['__wp_current_user_id'] = 7;
+        $this->refused( $this->page( 'About', 'about', [ 'build_id' => 99 ] ), 'Build 99 was not found' );
+        $this->log->close( 1, 'applied' );
+        $this->refused( $this->page( 'About', 'about', [ 'build_id' => 1 ] ), 'finished (launched)' );
+        $this->assertTrue( $this->tool->run( $this->page( 'About', 'about' ) )['created'], 'without a build_id a new build starts once none is open' );
+        $this->assertSame( 2, $this->repo->rows[2]['id'] );
+    }
+
+    public function testAnotherAddPageStillRunningRefusesTheCallBeforeAnyPageIsCreated(): void
+    {
+        $GLOBALS['__wp_options']['aied_pro_buildlock_7'] = (string) time();
+        $this->refused( $this->page( 'Home', 'home' ), 'still running' );
+        $this->assertSame( [], $GLOBALS['__wp_inserts'] );
+    }
+
+    public function testTheLockIsReleasedAfterASuccessAndAfterAFailure(): void
+    {
+        $this->tool->run( $this->page( 'Home', 'home' ) );
+        $this->assertArrayNotHasKey( 'aied_pro_buildlock_7', $GLOBALS['__wp_options'] );
+        try {
+            $this->tool->run( $this->page( 'Home again', 'home', [ 'build_id' => 1 ] ) );
+        } catch ( \InvalidArgumentException $e ) {
+            // the slug is taken
+        }
+        $this->assertArrayNotHasKey( 'aied_pro_buildlock_7', $GLOBALS['__wp_options'] );
+    }
+
+    public function testAFailedInsertLeavesNoPageAndClosesABuildItJustOpened(): void
+    {
+        $GLOBALS['__wp_insert_fail'] = true;
+        try {
+            $this->tool->run( $this->page( 'Home', 'home' ) );
+            $this->fail( 'expected a RuntimeException' );
+        } catch ( \RuntimeException $e ) {
+            $this->assertStringContainsString( 'simulated failure', $e->getMessage() );
+        }
+        $this->assertSame( [], $GLOBALS['__wp_posts'] );
+        $this->assertSame( 'undone', $this->repo->rows[1]['status'], 'the empty build is not left open' );
+        $this->assertNull( $this->log->openBuild( 7 ) );
+
+        $GLOBALS['__wp_insert_fail'] = false;
+        $this->tool->run( $this->page( 'Home', 'home' ) );
+        $GLOBALS['__wp_insert_fail'] = true;
+        try {
+            $this->tool->run( $this->page( 'About', 'about', [ 'build_id' => 2 ] ) );
+        } catch ( \RuntimeException $e ) {
+        }
+        $this->assertSame( 'building', $this->repo->rows[2]['status'], 'an existing build stays open when one page fails' );
+        $this->assertCount( 1, $this->repo->rows[2]['items'] );
+    }
+
+    public function testAPageThatCannotBeRecordedIsRemovedAgainRatherThanLeftOutsideTheBuild(): void
+    {
+        $this->tool->run( $this->page( 'Home', 'home' ) );
+        $this->repo->failAppend = true;
+        try {
+            $this->tool->run( $this->page( 'About', 'about', [ 'build_id' => 1 ] ) );
+            $this->fail( 'expected a RuntimeException' );
+        } catch ( \RuntimeException $e ) {
+            $this->assertStringContainsString( 'could not be recorded', $e->getMessage() );
+        }
+        $this->assertCount( 1, $GLOBALS['__wp_posts'], 'only the first page exists: the unrecorded one was deleted' );
+    }
+
+    public function testBadArgumentsAreRejectedNotCast(): void
+    {
+        foreach ( [
+            [ [ 'title' => 'x' ], '"action" is required.' ],
+            [ [ 'action' => 'launch' ], '"action" must be one of: add_page, status.' ],
+            [ [ 'action' => 5 ], '"action" must be a string.' ],
+            [ $this->page( 'x', 'x', [ 'title' => 5 ] ), '"title" must be a string.' ],
+            [ $this->page( 'x', 'x', [ 'content' => '' ] ), '"content" must not be empty.' ],
+            [ $this->page( 'x', 'x', [ 'in_menu' => 'yes' ] ), '"in_menu" must be true or false.' ],
+            [ $this->page( 'x', 'x', [ 'is_front' => 1 ] ), '"is_front" must be true or false.' ],
+            [ $this->page( 'x', 'x', [ 'build_id' => '1' ] ), '"build_id" must be an integer.' ],
+            [ $this->page( 'x', 'x', [ 'menu_label' => str_repeat( 'a', 101 ) ] ), '"menu_label" must be at most 100 bytes.' ],
+        ] as [ $args, $message ] ) {
+            $this->refused( $args, $message );
+        }
+    }
+
+    public function testTheMenuLabelDefaultsToTheTitleAndInMenuToTrue(): void
+    {
+        $this->tool->run( $this->page( 'Services', 'services' ) );
+        $this->assertSame( [ 'in_menu' => true, 'is_front' => false, 'menu_label' => 'Services' ], $this->repo->rows[1]['items'][0]['meta'] );
+    }
+
+    public function testStatusListsTheBuildWithTheLiveStateOfEveryPage(): void
+    {
+        $a = $this->tool->run( $this->page( 'Home', 'home', [ 'is_front' => true ] ) );
+        $b = $this->tool->run( $this->page( 'About', 'about', [ 'build_id' => 1, 'in_menu' => false ] ) );
+        $GLOBALS['__wp_posts'][ $b['page']['id'] ]->post_status = 'trash';
+        $s = $this->tool->run( [ 'action' => 'status' ] );
+        $this->assertSame( [ 'id' => 1, 'state' => 'open', 'pages' => 2, 'in_menu' => 1, 'front_page' => $a['page']['id'] ], $s['build'] );
+        $this->assertSame( [
+            [ 'id' => $a['page']['id'], 'title' => 'Home', 'slug' => 'home', 'status' => 'draft', 'in_menu' => true, 'is_front' => true, 'menu_label' => 'Home' ],
+            [ 'id' => $b['page']['id'], 'title' => 'About', 'slug' => 'about', 'status' => 'trash', 'in_menu' => false, 'is_front' => false, 'menu_label' => 'About' ],
+        ], $s['pages'] );
+        $this->assertSame( $s, $this->tool->run( [ 'action' => 'status', 'build_id' => 1 ] ) );
+    }
+
+    public function testStatusOfAFinishedBuildSaysSoAndNoOpenBuildIsAnError(): void
+    {
+        $this->assertSame( 'You have no open build. Call add_page to start one.', $this->expectRefusal( [ 'action' => 'status' ] ) );
+        $this->tool->run( $this->page( 'Home', 'home' ) );
+        $this->log->close( 1, 'applied' );
+        $this->assertSame( 'launched', $this->tool->run( [ 'action' => 'status', 'build_id' => 1 ] )['build']['state'] );
+        $this->log->close( 1, 'partly_undone' );
+        $this->assertSame( 'undone', $this->tool->run( [ 'action' => 'status', 'build_id' => 1 ] )['build']['state'] );
+        $GLOBALS['__wp_current_user_id'] = 8;
+        $this->assertSame( 'Build 1 was not found.', $this->expectRefusal( [ 'action' => 'status', 'build_id' => 1 ] ) );
+    }
+
+    private function expectRefusal( array $args ): string
+    {
+        try {
+            $this->tool->run( $args );
+        } catch ( \InvalidArgumentException $e ) {
+            return $e->getMessage();
+        }
+        $this->fail( 'expected InvalidArgumentException' );
+    }
+}
diff --git a/pro-addon/tests/InMemoryBatchRepository.php b/pro-addon/tests/InMemoryBatchRepository.php
index 0b0d6f3..2724a63 100644
--- a/pro-addon/tests/InMemoryBatchRepository.php
+++ b/pro-addon/tests/InMemoryBatchRepository.php
@@ -14,6 +14,7 @@ final class InMemoryBatchRepository implements BatchRepository
     /** @var array<int,array<string,mixed>> */
     public array $rows = [];
     public bool $failInsert = false;
+    public bool $failAppend = false;
     private int $next = 1;
 
     public function insert( array $row ): ?int
@@ -50,7 +51,7 @@ final class InMemoryBatchRepository implements BatchRepository
 
     public function append( int $id, array $items ): bool
     {
-        if ( ! isset( $this->rows[ $id ] ) ) {
+        if ( $this->failAppend || ! isset( $this->rows[ $id ] ) ) {
             return false;
         }
         $this->rows[ $id ]['items'] = array_merge( $this->rows[ $id ]['items'], array_values( $items ) );
diff --git a/pro-addon/tests/ProOpenApiTest.php b/pro-addon/tests/ProOpenApiTest.php
index 201a66f..fc1c0a2 100644
--- a/pro-addon/tests/ProOpenApiTest.php
+++ b/pro-addon/tests/ProOpenApiTest.php
@@ -121,7 +121,7 @@ class ProOpenApiTest extends TestCase
     {
         $registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
         $paths    = ( new ProOpenApi( $registry ) )->paths( [], 'https://example.com' );
-        $this->assertCount( 13, $paths );
+        $this->assertCount( 14, $paths );
         foreach ( $paths as $path => $methods ) {
             $responses = $methods['post']['responses'];
             $this->assertSame( [ '200', '400', '401', '403', '500' ], array_map( 'strval', array_keys( $responses ) ), $path );
diff --git a/pro-addon/tests/ToolsTest.php b/pro-addon/tests/ToolsTest.php
index 01860ce..0b6e6e4 100644
--- a/pro-addon/tests/ToolsTest.php
+++ b/pro-addon/tests/ToolsTest.php
@@ -43,7 +43,7 @@ class ToolsTest extends TestCase
     public function testThisReleaseShipsExactlyTheseTools(): void
     {
         $this->assertSame(
-            [ 'set_front_page', 'set_custom_css', 'set_primary_menu', 'publish_page', 'propose_php_snippet', 'list_batches', 'undo_batch', 'list_theme_layouts', 'update_theme_layout', 'edit_theme_layout', 'manage_theme_template', 'find_replace_site', 'audit_site' ],
+            [ 'set_front_page', 'set_custom_css', 'set_primary_menu', 'publish_page', 'propose_php_snippet', 'list_batches', 'undo_batch', 'list_theme_layouts', 'update_theme_layout', 'edit_theme_layout', 'manage_theme_template', 'find_replace_site', 'audit_site', 'build_site' ],
             array_map( static fn ( ProTool $t ): string => $t->name(), Tools::all() )
         );
     }
@@ -66,6 +66,6 @@ class ToolsTest extends TestCase
     public function testTheChatGptOperationBudgetIsRespected(): void
     {
         // The ChatGPT importer caps an OpenAPI spec at 30 operations: free built-ins plus Pro tools must fit.
-        $this->assertLessThanOrEqual( 29, count( $this->builtInToolNames() ) + count( Tools::all() ) );
+        $this->assertLessThanOrEqual( 30, count( $this->builtInToolNames() ) + count( Tools::all() ) );
     }
 }
diff --git a/pro-addon/tests/WpSiteShims.php b/pro-addon/tests/WpSiteShims.php
index b808cc6..2cf1572 100644
--- a/pro-addon/tests/WpSiteShims.php
+++ b/pro-addon/tests/WpSiteShims.php
@@ -12,6 +12,7 @@ if ( ! function_exists( 'get_posts' ) ) {
         $types    = (array) ( $args['post_type'] ?? 'post' );
         $statuses = (array) ( $args['post_status'] ?? 'publish' );
         $in       = isset( $args['post__in'] ) ? array_map( 'intval', (array) $args['post__in'] ) : null;
+        $name     = $args['name'] ?? null;
         $meta     = $args['meta_query'][0] ?? null;
         $out      = [];
         $posts    = $GLOBALS['__wp_posts'] ?? [];
@@ -26,6 +27,9 @@ if ( ! function_exists( 'get_posts' ) ) {
             if ( null !== $in && ! in_array( (int) $id, $in, true ) ) {
                 continue;
             }
+            if ( null !== $name && ( $p->post_name ?? '' ) !== $name ) {
+                continue;
+            }
             if ( null !== $meta && (string) ( $GLOBALS['__wp_postmeta'][ $id ][ $meta['key'] ] ?? '' ) !== (string) $meta['value'] ) {
                 continue;
             }
diff --git a/tests/WpInsertPostShims.php b/tests/WpInsertPostShims.php
index e8a598b..30e46db 100644
--- a/tests/WpInsertPostShims.php
+++ b/tests/WpInsertPostShims.php
@@ -8,6 +8,9 @@ declare(strict_types=1);
  */
 if ( ! function_exists( 'wp_insert_post' ) ) {
     function wp_insert_post( $arr, $wp_error = false ) {
+        if ( ! empty( $GLOBALS['__wp_insert_fail'] ) ) {
+            return new WP_Error( 'db_insert_error', 'simulated failure' );
+        }
         $arr          = wp_unslash( $arr );
         $id           = max( array_merge( [ 100 ], array_keys( $GLOBALS['__wp_posts'] ?? [] ) ) ) + 1;
         $p            = new WP_Post();
@@ -16,6 +19,10 @@ if ( ! function_exists( 'wp_insert_post' ) ) {
         $p->post_status  = (string) ( $arr['post_status'] ?? 'draft' );
         $p->post_title   = (string) ( $arr['post_title'] ?? '' );
         $p->post_content = (string) ( $arr['post_content'] ?? '' );
+        $p->post_name    = (string) ( $arr['post_name'] ?? '' );
+        foreach ( (array) ( $arr['meta_input'] ?? [] ) as $k => $v ) {
+            $GLOBALS['__wp_postmeta'][ $id ][ $k ] = $v;
+        }
         $GLOBALS['__wp_posts'][ $id ] = $p;
         $GLOBALS['__wp_inserts'][]    = $id;
 
diff --git a/tests/WpRestShims.php b/tests/WpRestShims.php
index b95cea2..fee4a7c 100644
--- a/tests/WpRestShims.php
+++ b/tests/WpRestShims.php
@@ -15,6 +15,7 @@ if ( ! class_exists( 'WP_Post' ) ) {
         public string $post_status = 'publish';
         public string $post_title = '';
         public string $post_content = '';
+        public string $post_name = '';
     }
 }
 if ( ! class_exists( 'WP_REST_Request' ) ) {
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-build-site.md T3-tests` → `applied: T3-tests`.

- [ ] **Step 2: Run the Pro suite and watch it fail**

Run: `make test-pro 2>&1 | tail -40`. Expected: FAIL (red). `Tests: 814, Assertions: 18194, Errors: 23, Failures: 2.`. Failing tests include: `testItIsHeldUntilReleasedAndPerUser`, `testALockLeftByACrashedRequestIsTakenOverAfterItsExpiry`, `testItWaitsForTheLockForAtMostTheGivenTime`, `testReleasingALockThatIsNotHeldIsHarmless`, `testItIsAProToolForPublishersWithOneActionArgument`, `testTheFirstPageOpensABuildAndIsCreatedAsADraft`, `testLaterPagesJoinTheSameBuildInOrder`, `testASecondCallWithoutABuildIdIsRefusedAndNamesTheOpenBuild`, `testAnInvalidPageIsRefusedWithTheViolationsAndTheBuildKeepsGoing`, `testCustomCodeInAPageIsRefusedLikeEverywhereElse`, `testASlugAlreadyUsedByAnotherPageIsRefusedButATrashedOneIsFree`, `testTwoPagesOfTheSameBuildCannotShareASlug` and 13 more.

- [ ] **Step 3: Apply the source patch**

Apply `T3-src`:

`````diff
diff --git a/pro-addon/readme.txt b/pro-addon/readme.txt
index bb7bae6..d275cbe 100644
--- a/pro-addon/readme.txt
+++ b/pro-addon/readme.txt
@@ -27,6 +27,7 @@ Every change a Pro tool makes is recorded as a batch with an undo point. `undo_b
 * `manage_theme_template` - enable or disable one live Divi Theme Builder template, or change where it applies. It affects the whole site, so every real run needs the token from a preview (`dry_run`). It cannot create or delete templates.
 * `find_replace_site` - replace literal text across your Divi 5 pages (and, with Theme Builder permission, the header and footer) as one undoable batch. You must preview first (`dry_run`) and repeat with the token; pages whose result would fail the layout checks are skipped; at most 50 items per run.
 * `audit_site` - a read-only check of your Divi 5 pages and header/footer: layout check violations (including custom code the plugin would not save), unknown preset or color ids, and internal links to missing or unpublished pages. 25 items per call. It never makes a remote request and never changes anything.
+* `build_site` - build a whole Divi 5 site as one undoable build: `add_page` creates one checked draft page per call (a page that fails the checks, or reuses a slug, is refused and the rest are kept), then the build is launched in one previewed step. Nothing goes live while pages are added, and `undo_batch` takes the whole build away again.
 * `list_batches` - list recent Pro changes.
 * `undo_batch` - undo one batch.
 
diff --git a/pro-addon/src/Site/BuildLock.php b/pro-addon/src/Site/BuildLock.php
new file mode 100644
index 0000000..c67ae73
--- /dev/null
+++ b/pro-addon/src/Site/BuildLock.php
@@ -0,0 +1,51 @@
+<?php
+
+declare(strict_types=1);
+
+namespace AiEditorDivi5\Pro\Site;
+
+if ( ! defined( 'ABSPATH' ) ) {
+    exit;
+}
+
+/**
+ * One add_page at a time per user. An MCP client may send tool calls in parallel; two of them must not both read the build,
+ * both create a page and both write the batch back (one page would then be missing from the build and from its undo). The lock
+ * is an option added atomically (`add_option` fails when it exists); a lock older than `$expirySeconds` belongs to a crashed
+ * request and is taken over.
+ */
+final class BuildLock
+{
+    private const STEP_MS = 100;
+    private const PREFIX  = 'aied_pro_buildlock_';
+
+    public function __construct( private int $waitMs = 5000, private int $expirySeconds = 15 )
+    {
+    }
+
+    public function acquire( int $userId ): bool
+    {
+        $key = self::PREFIX . $userId;
+        for ( $waited = 0; ; $waited += self::STEP_MS ) {
+            if ( add_option( $key, (string) time(), '', 'no' ) ) {
+                return true;
+            }
+            $held = (int) get_option( $key, 0 );
+            if ( $held > 0 && time() - $held >= $this->expirySeconds ) {
+                delete_option( $key );
+                if ( add_option( $key, (string) time(), '', 'no' ) ) {
+                    return true;
+                }
+            }
+            if ( $waited >= $this->waitMs ) {
+                return false;
+            }
+            usleep( self::STEP_MS * 1000 );
+        }
+    }
+
+    public function release( int $userId ): void
+    {
+        delete_option( self::PREFIX . $userId );
+    }
+}
diff --git a/pro-addon/src/Tools.php b/pro-addon/src/Tools.php
index d012218..e1d2585 100644
--- a/pro-addon/src/Tools.php
+++ b/pro-addon/src/Tools.php
@@ -6,6 +6,7 @@ namespace AiEditorDivi5\Pro;
 
 use AiEditorDivi5\Pro\Batch\Batches;
 use AiEditorDivi5\Pro\Tools\AuditSite;
+use AiEditorDivi5\Pro\Tools\BuildSite;
 use AiEditorDivi5\Pro\Tools\EditThemeLayout;
 use AiEditorDivi5\Pro\Tools\FindReplaceSite;
 use AiEditorDivi5\Pro\Tools\ListBatches;
@@ -45,6 +46,7 @@ final class Tools
             new ManageThemeTemplate( $log ),
             new FindReplaceSite( $log ),
             new AuditSite(),
+            new BuildSite( $log ),
         ];
     }
 }
diff --git a/pro-addon/src/Tools/BuildSite.php b/pro-addon/src/Tools/BuildSite.php
new file mode 100644
index 0000000..47092f0
--- /dev/null
+++ b/pro-addon/src/Tools/BuildSite.php
@@ -0,0 +1,292 @@
+<?php
+
+declare(strict_types=1);
+
+namespace AiEditorDivi5\Pro\Tools;
+
+use AiEditorDivi5\Pro\Args;
+use AiEditorDivi5\Pro\Batch\BatchLog;
+use AiEditorDivi5\Pro\Kinds\PageCreatedKind;
+use AiEditorDivi5\Pro\ProTool;
+use AiEditorDivi5\Pro\Site\BuildLock;
+use AiEditorDivi5\WP\LayoutGate;
+use AiEditorDivi5\WP\StyleReferences;
+
+if ( ! defined( 'ABSPATH' ) ) {
+    exit;
+}
+
+/**
+ * Whole-site generation as one undoable build. `add_page` creates ONE validated DRAFT page per call and records it in one
+ * growing batch (a "build"); an invalid page or a taken slug is refused at its own call and the build keeps every valid page.
+ * `status` lists the build. Nothing becomes visible on the live site while pages are only added: the launch (publish, front
+ * page, menu) is a separate, previewed step. Undo of the whole build is `undo_batch`.
+ */
+final class BuildSite implements ProTool
+{
+    public const NAME        = 'build_site';
+    public const MAX_PAGES   = 30;
+    public const MAX_CONTENT = 1000000;
+
+    private const ACTIONS = [ 'add_page', 'status' ];
+
+    private BuildLock $lock;
+
+    public function __construct( private BatchLog $log, ?BuildLock $lock = null )
+    {
+        $this->lock = $lock ?? new BuildLock();
+    }
+
+    public function name(): string
+    {
+        return self::NAME;
+    }
+
+    public function capability(): string
+    {
+        return 'publish_pages';
+    }
+
+    public function definition(): array
+    {
+        return [
+            'name'        => self::NAME,
+            'description' => 'Build a whole Divi 5 site as one undoable build. add_page creates one checked DRAFT page per call (an invalid page or a taken slug is refused, the build continues); status lists the build. Nothing goes live while pages are added. Undo it all with undo_batch.',
+            'inputSchema' => [
+                'type'       => 'object',
+                'properties' => [
+                    'action'     => [ 'type' => 'string', 'description' => 'add_page or status', 'enum' => self::ACTIONS ],
+                    'title'      => [ 'type' => 'string', 'description' => 'add_page: the page title (plain text)' ],
+                    'slug'       => [ 'type' => 'string', 'description' => 'add_page: the page slug; it must not be used by another page' ],
+                    'content'    => [ 'type' => 'string', 'description' => 'add_page: the Divi 5 layout (it must pass every check)' ],
+                    'menu_label' => [ 'type' => 'string', 'description' => 'add_page: the menu label (default: the title), at most 100 bytes' ],
+                    'in_menu'    => [ 'type' => 'boolean', 'description' => 'add_page: put the page in the primary menu at launch (default true)' ],
+                    'is_front'   => [ 'type' => 'boolean', 'description' => 'add_page: make this the front page at launch (one page per build)' ],
+                    'build_id'   => [ 'type' => 'integer', 'description' => 'The build to use: returned by the first add_page; required for later pages' ],
+                ],
+                'required'   => [ 'action' ],
+            ],
+        ];
+    }
+
+    public function run( array $arguments ): array
+    {
+        $action = (string) Args::string( $arguments, 'action', true, 20 );
+        if ( ! in_array( $action, self::ACTIONS, true ) ) {
+            throw new \InvalidArgumentException( '"action" must be one of: ' . implode( ', ', self::ACTIONS ) . '.' );
+        }
+
+        return 'add_page' === $action ? $this->addPage( $arguments ) : $this->status( $arguments );
+    }
+
+    /** @param array<string,mixed> $a @return array<string,mixed> */
+    private function addPage( array $a ): array
+    {
+        $title = trim( sanitize_text_field( (string) Args::string( $a, 'title', true, 200 ) ) );
+        if ( '' === $title ) {
+            throw new \InvalidArgumentException( '"title" must contain text.' );
+        }
+        $slug = sanitize_title( (string) Args::string( $a, 'slug', true, 200 ) );
+        if ( '' === $slug ) {
+            throw new \InvalidArgumentException( '"slug" must contain letters or digits.' );
+        }
+        $content = (string) Args::string( $a, 'content', true, self::MAX_CONTENT );
+        $label   = array_key_exists( 'menu_label', $a ) && null !== $a['menu_label'] ? trim( sanitize_text_field( (string) Args::string( $a, 'menu_label', true, 100 ) ) ) : '';
+        $inMenu  = ! array_key_exists( 'in_menu', $a ) || null === $a['in_menu'] ? true : Args::bool( $a, 'in_menu' );
+        $isFront = Args::bool( $a, 'is_front' );
+        $buildId = Args::int( $a, 'build_id', false );
+
+        $verdict = LayoutGate::check( $content );
+        if ( ! $verdict->isValid() ) {
+            throw new \InvalidArgumentException( self::refusal( $verdict->violations() ) );
+        }
+
+        $userId = (int) get_current_user_id();
+        if ( ! $this->lock->acquire( $userId ) ) {
+            throw new \InvalidArgumentException( 'Another add_page call is still running for you. Wait a moment and call again.' );
+        }
+        try {
+            return $this->create( $userId, $buildId, $title, $slug, $content, '' === $label ? $title : $label, $inMenu, $isFront );
+        } finally {
+            $this->lock->release( $userId );
+        }
+    }
+
+    /** @return array<string,mixed> */
+    private function create( int $userId, ?int $buildId, string $title, string $slug, string $content, string $label, bool $inMenu, bool $isFront ): array
+    {
+        $build = $this->buildFor( $buildId, $userId );
+        $items = null === $build ? [] : self::pageItems( $build );
+        if ( count( $items ) >= self::MAX_PAGES ) {
+            throw new \InvalidArgumentException( 'A build holds at most ' . self::MAX_PAGES . ' pages. Launch it or undo it with undo_batch, then start another.' );
+        }
+        if ( $isFront ) {
+            foreach ( $items as $item ) {
+                if ( ! empty( $item['meta']['is_front'] ) ) {
+                    throw new \InvalidArgumentException( 'Page #' . $item['ref'] . ' is already the front page of this build; only one page can be.' );
+                }
+            }
+        }
+        $taken = get_posts( [ 'post_type' => 'page', 'name' => $slug, 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true ] );
+        if ( [] !== $taken ) {
+            throw new \InvalidArgumentException( 'The slug "' . $slug . '" is already used by page #' . (int) $taken[0] . '. Choose a different slug.' );
+        }
+
+        $fresh = null === $build;
+        if ( $fresh ) {
+            $opened = $this->log->open( self::NAME, 'Site build' );
+            if ( null === $opened ) {
+                throw new \RuntimeException( 'The build could not be started.' );
+            }
+            $buildId = (int) $opened['id'];
+        } else {
+            $buildId = (int) $build['id'];
+        }
+
+        // Always a draft; the Divi 5 builder flags make the page open in the Divi 5 editor. wp_slash: wp_insert_post unslashes.
+        $id = wp_insert_post( wp_slash( [
+            'post_type'    => 'page',
+            'post_title'   => $title,
+            'post_content' => $content,
+            'post_status'  => 'draft',
+            'post_name'    => $slug,
+            'meta_input'   => [ '_et_pb_use_divi_5' => 'on', '_et_pb_use_builder' => 'on' ],
+        ] ), true );
+        if ( is_wp_error( $id ) ) {
+            if ( $fresh ) {
+                $this->log->close( $buildId, 'undone' );
+            }
+            throw new \RuntimeException( $id->get_error_message() );
+        }
+        $post = get_post( (int) $id );
+        $item = PageCreatedKind::itemFor( $post, [ 'in_menu' => $inMenu, 'is_front' => $isFront, 'menu_label' => $label ] );
+        if ( ! $this->log->append( $buildId, [ $item ] ) ) {
+            wp_delete_post( (int) $id, true );
+            if ( $fresh ) {
+                $this->log->close( $buildId, 'undone' );
+            }
+            throw new \RuntimeException( 'The page could not be recorded in the build, so it was removed again. Try again.' );
+        }
+
+        return StyleReferences::attach( [
+            'created' => true,
+            'build'   => self::summary( (array) $this->log->find( $buildId ) ),
+            'page'    => [ 'id' => (int) $id, 'title' => $title, 'slug' => $slug, 'status' => 'draft', 'link' => get_permalink( (int) $id ) ],
+        ], $content );
+    }
+
+    /** @param array<string,mixed> $a @return array<string,mixed> */
+    private function status( array $a ): array
+    {
+        $userId  = (int) get_current_user_id();
+        $buildId = Args::int( $a, 'build_id', false );
+        if ( null === $buildId ) {
+            $row = $this->log->openBuild( $userId );
+            if ( null === $row ) {
+                throw new \InvalidArgumentException( 'You have no open build. Call add_page to start one.' );
+            }
+        } else {
+            $row = $this->owned( $buildId, $userId );
+        }
+        $pages = [];
+        foreach ( self::pageItems( $row ) as $item ) {
+            $post    = get_post( (int) $item['ref'] );
+            $pages[] = [
+                'id'         => (int) $item['ref'],
+                'title'      => $post ? (string) $post->post_title : '',
+                'slug'       => $post ? self::cleanSlug( (string) ( $post->post_name ?? '' ) ) : '',
+                'status'     => $post ? (string) $post->post_status : 'missing',
+                'in_menu'    => ! empty( $item['meta']['in_menu'] ),
+                'is_front'   => ! empty( $item['meta']['is_front'] ),
+                'menu_label' => (string) ( $item['meta']['menu_label'] ?? '' ),
+            ];
+        }
+
+        return [ 'build' => self::summary( $row ), 'pages' => $pages ];
+    }
+
+    /** The user's own build by id, whatever its state; anything else is "not found" (nothing leaks about other users' batches). @return array<string,mixed> */
+    private function owned( int $buildId, int $userId ): array
+    {
+        $row = $this->log->find( $buildId );
+        if ( null === $row || self::NAME !== $row['tool'] || (int) $row['user_id'] !== $userId ) {
+            throw new \InvalidArgumentException( "Build {$buildId} was not found." );
+        }
+
+        return $row;
+    }
+
+    /** @return array<string,mixed>|null the open build to add to, or null: start a new one */
+    private function buildFor( ?int $buildId, int $userId ): ?array
+    {
+        if ( null !== $buildId ) {
+            $row = $this->owned( $buildId, $userId );
+            if ( BatchLog::BUILDING !== $row['status'] ) {
+                throw new \InvalidArgumentException( "Build {$buildId} is finished (" . self::state( $row ) . '). Call add_page without build_id to start a new build.' );
+            }
+
+            return $row;
+        }
+        $open = $this->log->openBuild( $userId );
+        if ( null !== $open ) {
+            throw new \InvalidArgumentException( 'You already have an open build (#' . $open['id'] . '). Pass "build_id": ' . $open['id'] . ' to add this page to it, or undo it with undo_batch to start over.' );
+        }
+
+        return null;
+    }
+
+    /** @param array<string,mixed> $row @return list<array<string,mixed>> the page_created items of a build, in add order */
+    public static function pageItems( array $row ): array
+    {
+        return array_values( array_filter( (array) ( $row['items'] ?? [] ), static fn ( $i ): bool => is_array( $i ) && PageCreatedKind::NAME === ( $i['kind'] ?? '' ) ) );
+    }
+
+    /** @param array<string,mixed> $row */
+    public static function state( array $row ): string
+    {
+        return match ( (string) $row['status'] ) {
+            BatchLog::BUILDING => 'open',
+            'applied'          => 'launched',
+            default            => 'undone',
+        };
+    }
+
+    /** @param array<string,mixed> $row @return array<string,mixed> */
+    public static function summary( array $row ): array
+    {
+        $items = self::pageItems( $row );
+        $front = null;
+        foreach ( $items as $item ) {
+            if ( ! empty( $item['meta']['is_front'] ) ) {
+                $front = (int) $item['ref'];
+            }
+        }
+
+        return [
+            'id'         => (int) $row['id'],
+            'state'      => self::state( $row ),
+            'pages'      => count( $items ),
+            'in_menu'    => count( array_filter( $items, static fn ( $i ): bool => ! empty( $i['meta']['in_menu'] ) ) ),
+            'front_page' => $front,
+        ];
+    }
+
+    /** A trashed page's slug carries core's `__trashed` suffix. */
+    private static function cleanSlug( string $slug ): string
+    {
+        return (string) preg_replace( '/__trashed$/', '', $slug );
+    }
+
+    /** @param list<\Divi5Validator\Violation> $violations */
+    private static function refusal( array $violations ): string
+    {
+        $lines = [];
+        foreach ( array_slice( $violations, 0, 10 ) as $v ) {
+            $a       = $v->toArray();
+            $lines[] = $a['code'] . ' at ' . $a['path'] . ': ' . $a['message'];
+        }
+        $more = count( $violations ) > 10 ? ' (and ' . ( count( $violations ) - 10 ) . ' more)' : '';
+
+        return 'The page was not created: it failed the checks. ' . implode( '; ', $lines ) . $more;
+    }
+}
diff --git a/pro-addon/src/autoload.php b/pro-addon/src/autoload.php
index 0b883b9..d8aa81d 100644
--- a/pro-addon/src/autoload.php
+++ b/pro-addon/src/autoload.php
@@ -37,6 +37,8 @@ require_once __DIR__ . '/Kinds/PostStatusKind.php';
 require_once __DIR__ . '/Kinds/PageCreatedKind.php';
 require_once __DIR__ . '/Kinds/PageTrashedKind.php';
 require_once __DIR__ . '/Tools/PublishPage.php';
+require_once __DIR__ . '/Site/BuildLock.php';
+require_once __DIR__ . '/Tools/BuildSite.php';
 require_once __DIR__ . '/Settings/PhpProposals.php';
 require_once __DIR__ . '/Tools/ProposePhpSnippet.php';
 require_once __DIR__ . '/Kinds/PostContentKind.php';
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-build-site.md T3-src` → `applied: T3-src`.

- [ ] **Step 4: Run the suites and watch them pass**

Run: `make test-pro 2>&1 | tail -3` → `OK (818 tests, 18562 assertions)`. Run: `make test 2>&1 | tail -3` → `OK (727 tests, 5241 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -q -F - <<'EOF'
feat(pro): build_site tool - add_page creates one checked draft page per call into one growing batch; status; per-user lock

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B | tail -2
```

### Task 4: `build_site launch`: preview, token, publish, front page, menu, partial skips, undo

**What:** `launch`, the only step that changes what visitors see. `BuildLaunch`: a dry run previews pages (will publish or skipped with a reason), the front-page change, the menu change and returns a `PreviewToken` (v2: user-bound, single use, 30 minutes) whose map holds each page hash plus the live front-page and menu hashes; a real run publishes each page that is still an unchanged draft with a free slug, sets the flagged front page, rebuilds the live "Main Menu" from the published `in_menu` pages in add order, appends everything to the build's own batch and closes the build. Partial by design (skips: `not_available`, `slug_taken`, `changed_since_preview`, `publish_failed`); a changed live front page or menu since the preview refuses the whole run; WordPress changing a slug at publish is reported as `slug_changed`. Permissions per part: `publish_pages`, `edit_theme_options` for a menu, `manage_options` for a front page. Undo of a launched or an open build goes through the existing `BatchUndo`.

**Files:** Create: `pro-addon/src/Site/BuildLaunch.php`, `pro-addon/tests/BuildLaunchTest.php`; Modify: `pro-addon/src/Tools/BuildSite.php`, `src/autoload.php`, `tests/bootstrap.php` (an after-update hook), `tests/WpRestShims.php`, `pro-addon/tests/WpSettingsShims.php`, `BuildSiteTest.php`.

**Interfaces:** Produces: `BuildSite::__construct( BatchLog, ?BuildLock, ?\Closure $clock )`, action `launch` with `build_id`, `dry_run`, `token`; `BuildLaunch::run( array $row, bool $dry, ?string $token ): array`.

- [ ] **Step 1: Apply the tests patch**

Apply `T4-tests`:

`````diff
diff --git a/pro-addon/tests/BuildLaunchTest.php b/pro-addon/tests/BuildLaunchTest.php
new file mode 100644
index 0000000..2bb6a94
--- /dev/null
+++ b/pro-addon/tests/BuildLaunchTest.php
@@ -0,0 +1,374 @@
+<?php
+
+declare(strict_types=1);
+
+namespace AiEditorDivi5\Pro\Tests;
+
+use AiEditorDivi5\Pro\Batch\BatchLog;
+use AiEditorDivi5\Pro\Batch\BatchUndo;
+use AiEditorDivi5\Pro\Batch\Batches;
+use AiEditorDivi5\Pro\Settings\MenuBuilder;
+use AiEditorDivi5\Pro\Site\BuildLock;
+use AiEditorDivi5\Pro\Site\PreviewToken;
+use AiEditorDivi5\Pro\Tools\BuildSite;
+use PHPUnit\Framework\TestCase;
+
+require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
+require_once __DIR__ . '/../src/autoload.php';
+require_once __DIR__ . '/../../tests/WpInsertPostShims.php';
+require_once __DIR__ . '/InMemoryBatchRepository.php';
+require_once __DIR__ . '/WpSiteShims.php';
+require_once __DIR__ . '/WpSettingsShims.php';
+require_once __DIR__ . '/WpBuildShims.php';
+
+/** build_site launch: a previewed, single-use-token step that publishes, sets the front page and builds the menu, as one undoable batch. */
+class BuildLaunchTest extends TestCase
+{
+    private InMemoryBatchRepository $repo;
+    private BatchLog $log;
+    private BuildSite $tool;
+    private int $now = 1000000;
+
+    private static function layout( string $word ): string
+    {
+        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"' . $word . '"}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
+    }
+
+    protected function setUp(): void
+    {
+        $this->repo = new InMemoryBatchRepository();
+        $this->log  = new BatchLog( $this->repo );
+        $this->tool = new BuildSite( $this->log, new BuildLock( 0 ), fn (): int => $this->now );
+        $GLOBALS['__wp_posts']            = [];
+        $GLOBALS['__wp_postmeta']         = [];
+        $GLOBALS['__wp_options']          = [ 'show_on_front' => 'posts', 'page_on_front' => 0 ];
+        $GLOBALS['__wp_transients']       = [];
+        $GLOBALS['__wp_menus']            = [];
+        $GLOBALS['__wp_menu_items']       = [];
+        $GLOBALS['__wp_theme_mods']       = [];
+        $GLOBALS['__wp_registered_menus'] = [ 'primary-menu' => 'Primary Menu' ];
+        $GLOBALS['__wp_menu_item_fail_at'] = 0;
+        $GLOBALS['__wp_menu_item_calls']   = 0;
+        $GLOBALS['__wp_update_fail']      = false;
+        $GLOBALS['__wp_current_user_id']  = 7;
+        $GLOBALS['__wp_caps']             = [ 'publish_pages' => true, 'edit_post' => true, 'delete_post' => true, 'edit_theme_options' => true, 'manage_options' => true ];
+        unset( $GLOBALS['__wp_update_hook'] );
+    }
+
+    protected function tearDown(): void
+    {
+        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'], $GLOBALS['__wp_update_hook'] );
+        $GLOBALS['__wp_update_fail'] = false;
+    }
+
+    /** Home (front page), About (menu), Hidden (not in the menu): ids by slug. @return array<string,int> */
+    private function build( bool $front = true ): array
+    {
+        $ids = [];
+        foreach ( [ [ 'Home', 'home', [ 'is_front' => $front ] ], [ 'About', 'about', [] ], [ 'Hidden', 'hidden', [ 'in_menu' => false ] ] ] as [ $title, $slug, $extra ] ) {
+            $r = $this->tool->run( [ 'action' => 'add_page', 'title' => $title, 'slug' => $slug, 'content' => self::layout( $title ) ] + $extra + ( [] === $ids ? [] : [ 'build_id' => 1 ] ) );
+            $ids[ $slug ] = $r['page']['id'];
+        }
+
+        return $ids;
+    }
+
+    /** @return array<string,mixed> */
+    private function preview( int $build = 1 ): array
+    {
+        return $this->tool->run( [ 'action' => 'launch', 'build_id' => $build, 'dry_run' => true ] );
+    }
+
+    /** @return array<string,mixed> */
+    private function launch( array $preview, int $build = 1 ): array
+    {
+        return $this->tool->run( [ 'action' => 'launch', 'build_id' => $build, 'token' => $preview['token'] ] );
+    }
+
+    private function refused( array $args, string $message ): void
+    {
+        $posts   = $GLOBALS['__wp_posts'];
+        $options = $GLOBALS['__wp_options'];
+        $rows    = $this->repo->rows;
+        try {
+            $this->tool->run( $args );
+            $this->fail( 'expected InvalidArgumentException: ' . $message );
+        } catch ( \InvalidArgumentException $e ) {
+            $this->assertStringContainsString( $message, $e->getMessage() );
+        }
+        $this->assertSame( [ $posts, $options, $rows ], [ $GLOBALS['__wp_posts'], $GLOBALS['__wp_options'], $this->repo->rows ], 'a refused launch changes nothing' );
+    }
+
+    private function undo(): BatchUndo
+    {
+        return new BatchUndo( Batches::kinds(), $this->log );
+    }
+
+    /** @return list<string> the titles of the menu items now in "Main Menu" */
+    private function menuTitles(): array
+    {
+        $menu = wp_get_nav_menu_object( MenuBuilder::MENU_NAME );
+
+        return $menu ? array_map( static fn ( $o ) => (string) $o->title, array_values( (array) wp_get_nav_menu_items( (int) $menu->term_id ) ) ) : [];
+    }
+
+    public function testTheDefinitionOffersLaunchWithADryRunAndAToken(): void
+    {
+        $d = $this->tool->definition();
+        $this->assertSame( [ 'add_page', 'status', 'launch' ], $d['inputSchema']['properties']['action']['enum'] );
+        $this->assertSame( 'boolean', $d['inputSchema']['properties']['dry_run']['type'] );
+        $this->assertSame( 'string', $d['inputSchema']['properties']['token']['type'] );
+        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
+        $this->assertStringContainsString( 'launch', $d['description'] );
+    }
+
+    public function testADryRunPreviewsEverythingAndChangesNothing(): void
+    {
+        $ids = $this->build();
+        $r   = $this->preview();
+        $this->assertSame( [ false, true, 3, 0 ], [ $r['changed'], $r['dry_run'], $r['would_publish'], $r['would_skip'] ] );
+        $this->assertSame( [ 'id' => 1, 'state' => 'open', 'pages' => 3, 'in_menu' => 2, 'front_page' => $ids['home'] ], $r['build'] );
+        $this->assertSame( [ [ 'id' => $ids['home'], 'title' => 'Home', 'slug' => 'home', 'will_publish' => true ], [ 'id' => $ids['about'], 'title' => 'About', 'slug' => 'about', 'will_publish' => true ], [ 'id' => $ids['hidden'], 'title' => 'Hidden', 'slug' => 'hidden', 'will_publish' => true ] ], $r['pages'] );
+        $this->assertSame( [ 'from' => 0, 'to' => $ids['home'] ], $r['front_page'] );
+        $this->assertSame( [ 'location' => 'primary-menu', 'replaces_items' => 0, 'new_items' => 2 ], $r['menu'] );
+        $this->assertNotSame( '', $r['token'] );
+        foreach ( $ids as $id ) {
+            $this->assertSame( 'draft', $GLOBALS['__wp_posts'][ $id ]->post_status );
+        }
+        $this->assertSame( [ 'posts', 0 ], [ $GLOBALS['__wp_options']['show_on_front'], $GLOBALS['__wp_options']['page_on_front'] ] );
+        $this->assertSame( [], $this->menuTitles() );
+        $this->assertSame( 'building', $this->repo->rows[1]['status'] );
+        $this->assertCount( 3, $this->repo->rows[1]['items'] );
+    }
+
+    public function testARealRunNeedsTheTokenOfAPreviewOfThisBuild(): void
+    {
+        $this->build();
+        $this->refused( [ 'action' => 'launch', 'build_id' => 1 ], 'needs the token' );
+        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'token' => 'nope' ], 'needs the token' );
+        $p = $this->preview();
+        $this->now += PreviewToken::TTL + 1;
+        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'token' => $p['token'] ], 'needs the token' );
+    }
+
+    public function testLaunchPublishesSetsTheFrontPageAndBuildsTheMenuAsOneBatch(): void
+    {
+        $ids = $this->build();
+        $r   = $this->launch( $this->preview() );
+        $this->assertTrue( $r['changed'] );
+        $this->assertSame( [ $ids['home'], $ids['about'], $ids['hidden'] ], array_column( $r['published'], 'id' ) );
+        $this->assertSame( [], $r['skipped'] );
+        $this->assertSame( [ 'home', 'about', 'hidden' ], array_column( $r['published'], 'slug' ) );
+        $this->assertNotSame( '', $r['published'][0]['link'] );
+        $this->assertSame( $ids['home'], $r['front_page'] );
+        $this->assertSame( [ 'items' => 2, 'location' => 'primary-menu' ], $r['menu'] );
+        $this->assertSame( 'launched', $r['build']['state'] );
+        $this->assertTrue( $r['batch']['undoable'] );
+        $this->assertSame( 1, $r['batch']['id'] );
+
+        foreach ( $ids as $id ) {
+            $this->assertSame( 'publish', $GLOBALS['__wp_posts'][ $id ]->post_status );
+        }
+        $this->assertSame( [ 'page', $ids['home'] ], [ $GLOBALS['__wp_options']['show_on_front'], $GLOBALS['__wp_options']['page_on_front'] ] );
+        $this->assertSame( [ 'Home', 'About' ], $this->menuTitles(), 'the menu has the in_menu pages in add order' );
+        $this->assertSame( 'applied', $this->repo->rows[1]['status'] );
+        $this->assertSame( [ 'page_created', 'page_created', 'page_created', 'post_status', 'post_status', 'post_status', 'front_page', 'menu' ], array_column( $this->repo->rows[1]['items'], 'kind' ), 'creation, then statuses, then the front page, then the menu' );
+        $this->assertCount( 1, $this->repo->rows, 'one batch for the whole build' );
+        $this->refused( [ 'action' => 'add_page', 'title' => 'Late', 'slug' => 'late', 'content' => self::layout( 'Late' ), 'build_id' => 1 ], 'finished (launched)' );
+    }
+
+    public function testTheMenuTitleIsTheMenuLabelAndALaunchWithoutMenuPagesLeavesTheMenuAlone(): void
+    {
+        $this->tool->run( [ 'action' => 'add_page', 'title' => 'Our Services', 'slug' => 'services', 'content' => self::layout( 'S' ), 'menu_label' => 'Services' ] );
+        $this->launch( $this->preview() );
+        $this->assertSame( [ 'Services' ], $this->menuTitles() );
+
+        $GLOBALS['__wp_menus'] = []; $GLOBALS['__wp_menu_items'] = [];
+        $this->tool->run( [ 'action' => 'add_page', 'title' => 'Legal', 'slug' => 'legal', 'content' => self::layout( 'L' ), 'in_menu' => false ] );
+        $r = $this->preview( 2 );
+        $this->assertNull( $r['menu'] );
+        $this->assertNull( $r['front_page'] );
+        $this->launch( $r, 2 );
+        $this->assertSame( [], $this->menuTitles(), 'no menu page, no menu change' );
+        $this->assertSame( [ 'page_created', 'post_status' ], array_column( $this->repo->rows[2]['items'], 'kind' ), 'no front page and no menu item were recorded' );
+    }
+
+    public function testWithoutAThemeMenuLocationTheMenuIsBuiltUnassignedAndTheResultSaysSo(): void
+    {
+        $GLOBALS['__wp_registered_menus'] = [];
+        $this->build();
+        $r = $this->launch( $this->preview() );
+        $this->assertSame( [ 'items' => 2, 'location' => null ], $r['menu'] );
+        $this->assertStringContainsString( 'no menu location', $r['message'] );
+    }
+
+    public function testAPageThatIsGoneChangedOrWhoseSlugWasTakenIsSkippedNeverPublishedFlaggedOrInTheMenu(): void
+    {
+        $ids = $this->build();
+        $p   = $this->preview();
+        $GLOBALS['__wp_posts'][ $ids['home'] ]->post_status  = 'trash';                    // the front page is gone
+        $GLOBALS['__wp_posts'][ $ids['about'] ]->post_content = 'edited in the builder';  // edited after the preview
+        $GLOBALS['__wp_posts'][700] = (object) [ 'ID' => 700, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Hidden', 'post_content' => 'x', 'post_name' => 'hidden' ];
+        $r = $this->launch( $p );
+        $this->assertFalse( $r['changed'] );
+        $this->assertSame( [], $r['published'] );
+        $this->assertEqualsCanonicalizing( [ [ 'id' => $ids['home'], 'title' => 'Home', 'reason' => 'not_available' ], [ 'id' => $ids['about'], 'title' => 'About', 'reason' => 'changed_since_preview' ], [ 'id' => $ids['hidden'], 'title' => 'Hidden', 'reason' => 'slug_taken' ] ], $r['skipped'] );
+        $this->assertSame( 'building', $this->repo->rows[1]['status'], 'nothing was published, so the build stays open' );
+        $this->assertSame( 0, $GLOBALS['__wp_options']['page_on_front'] );
+        $this->assertSame( [], $this->menuTitles() );
+    }
+
+    public function testTheValidPagesAreStillPublishedWhenOthersAreSkipped(): void
+    {
+        $ids = $this->build();
+        $p   = $this->preview();
+        $GLOBALS['__wp_posts'][ $ids['home'] ]->post_status = 'trash';
+        $r = $this->launch( $p );
+        $this->assertTrue( $r['changed'] );
+        $this->assertSame( [ $ids['about'], $ids['hidden'] ], array_column( $r['published'], 'id' ) );
+        $this->assertSame( [ [ 'id' => $ids['home'], 'title' => 'Home', 'reason' => 'not_available' ] ], $r['skipped'] );
+        $this->assertNull( $r['front_page'], 'a skipped page is never the front page' );
+        $this->assertSame( 0, $GLOBALS['__wp_options']['page_on_front'] );
+        $this->assertSame( [ 'About' ], $this->menuTitles() );
+        $this->assertSame( 'launched', $r['build']['state'] );
+    }
+
+    public function testALaunchIsRefusedWhenTheLiveFrontPageOrMenuChangedSinceThePreview(): void
+    {
+        $this->build();
+        $p = $this->preview();
+        $GLOBALS['__wp_options']['page_on_front'] = 999;
+        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'token' => $p['token'] ], 'changed since the preview' );
+        $GLOBALS['__wp_options']['page_on_front'] = 0;
+
+        $p = $this->preview();
+        MenuBuilder::build( [ [ 'title' => 'Old', 'type' => 'custom', 'object' => 'custom', 'object_id' => 0, 'url' => '/old/', 'parent' => -1, 'classes' => '', 'target' => '', 'attr_title' => '', 'xfn' => '', 'description' => '' ] ], 'primary-menu' );
+        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'token' => $p['token'] ], 'changed since the preview' );
+    }
+
+    public function testEachPartNeedsItsOwnCapabilityBeforeAnythingIsDone(): void
+    {
+        $this->build();
+        $GLOBALS['__wp_caps']['edit_theme_options'] = false;
+        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ], 'edit_theme_options' );
+        $GLOBALS['__wp_caps']['edit_theme_options'] = true;
+        $GLOBALS['__wp_caps']['manage_options']     = false;
+        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ], 'manage_options' );
+    }
+
+    public function testNoMenuPagesAndNoFrontPageNeedNeitherMenuNorSettingsRights(): void
+    {
+        $this->tool->run( [ 'action' => 'add_page', 'title' => 'Legal', 'slug' => 'legal', 'content' => self::layout( 'L' ), 'in_menu' => false ] );
+        $GLOBALS['__wp_caps'] = [ 'publish_pages' => true, 'edit_post' => true, 'delete_post' => true ];
+        $r = $this->launch( $this->preview() );
+        $this->assertTrue( $r['changed'] );
+        $this->assertSame( 'publish', $GLOBALS['__wp_posts'][ $r['published'][0]['id'] ]->post_status );
+    }
+
+    public function testATokenIsSingleUseAndBoundToTheUserEvenAfterAnUndo(): void
+    {
+        $this->build();
+        $p = $this->preview();
+        $GLOBALS['__wp_current_user_id'] = 8;
+        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'token' => $p['token'] ], 'Build 1 was not found' );
+        $GLOBALS['__wp_current_user_id'] = 7;
+        $this->launch( $p );
+        $this->assertTrue( $this->undo()->undo( 1 )['fully_undone'] );
+        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'token' => $p['token'] ], 'finished' );
+    }
+
+    public function testUndoOfALaunchedBuildPutsEverythingBack(): void
+    {
+        $GLOBALS['__wp_options']['show_on_front'] = 'page';
+        $GLOBALS['__wp_options']['page_on_front'] = 42;
+        MenuBuilder::build( [ [ 'title' => 'Old', 'type' => 'custom', 'object' => 'custom', 'object_id' => 0, 'url' => '/old/', 'parent' => -1, 'classes' => '', 'target' => '', 'attr_title' => '', 'xfn' => '', 'description' => '' ] ], 'primary-menu' );
+        $ids = $this->build();
+        $this->launch( $this->preview() );
+        $this->assertSame( [ 'Home', 'About' ], $this->menuTitles() );
+
+        $u = $this->undo()->undo( 1 );
+        $this->assertTrue( $u['fully_undone'] );
+        $this->assertSame( 8, $u['restored'], '3 created pages, 3 statuses, the front page and the menu' );
+        $this->assertSame( [ 'Old' ], $this->menuTitles(), 'the menu that was there before is back' );
+        $this->assertSame( [ 'page', 42 ], [ $GLOBALS['__wp_options']['show_on_front'], $GLOBALS['__wp_options']['page_on_front'] ] );
+        foreach ( $ids as $id ) {
+            $this->assertSame( 'trash', $GLOBALS['__wp_posts'][ $id ]->post_status, 'back to draft, then trashed' );
+        }
+        $this->assertSame( 'undone', $this->repo->rows[1]['status'] );
+    }
+
+    public function testAPageEditedAfterTheLaunchIsGoneBackToDraftButKeptNotTrashed(): void
+    {
+        $ids = $this->build();
+        $this->launch( $this->preview() );
+        $GLOBALS['__wp_posts'][ $ids['about'] ]->post_content = 'owner edit after launch';
+        $u = $this->undo()->undo( 1 );
+        $this->assertFalse( $u['fully_undone'] );
+        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][ $ids['about'] ]->post_status );
+        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][ $ids['home'] ]->post_status );
+        $this->assertContains( 'changed_since', array_column( $u['items'], 'result' ) );
+        $this->assertSame( 'partly_undone', $this->repo->rows[1]['status'] );
+    }
+
+    public function testAnOpenBuildCanBeAbandonedWithUndoBatch(): void
+    {
+        $ids = $this->build();
+        $u   = $this->undo()->undo( 1 );
+        $this->assertTrue( $u['fully_undone'] );
+        foreach ( $ids as $id ) {
+            $this->assertSame( 'trash', $GLOBALS['__wp_posts'][ $id ]->post_status );
+        }
+        $this->assertSame( 'undone', $this->repo->rows[1]['status'] );
+        $this->assertNull( $this->log->openBuild( 7 ), 'the user can start a new build now' );
+        $this->refused( [ 'action' => 'add_page', 'title' => 'More', 'slug' => 'more', 'content' => self::layout( 'M' ), 'build_id' => 1 ], 'finished (undone)' );
+    }
+
+    public function testASlugCoreChangesAtPublishTimeIsReportedAndNothingElseBreaks(): void
+    {
+        $ids = $this->build();
+        $p   = $this->preview();
+        $GLOBALS['__wp_update_hook'] = static function ( int $id, array $arr ): void {
+            if ( 'publish' === ( $arr['post_status'] ?? '' ) && 'about' === $GLOBALS['__wp_posts'][ $id ]->post_name ) {
+                $GLOBALS['__wp_posts'][ $id ]->post_name = 'about-2';
+            }
+        };
+        $r = $this->launch( $p );
+        $this->assertSame( [ 'planned' => 'about', 'real' => 'about-2' ], $r['published'][1]['slug_changed'] );
+        $this->assertSame( 'about-2', $r['published'][1]['slug'] );
+        $this->assertArrayNotHasKey( 'slug_changed', $r['published'][0] );
+        $this->assertSame( [ 'Home', 'About' ], $this->menuTitles(), 'the menu points at page ids, not slugs' );
+        $this->assertSame( $ids['about'], $r['published'][1]['id'] );
+    }
+
+    public function testAFailedPublishIsASkipNotAnAbort(): void
+    {
+        $this->build();
+        $p = $this->preview();
+        $GLOBALS['__wp_update_fail'] = true;
+        $r = $this->launch( $p );
+        $this->assertFalse( $r['changed'] );
+        $this->assertSame( [ 'publish_failed', 'publish_failed', 'publish_failed' ], array_column( $r['skipped'], 'reason' ) );
+        $this->assertSame( 'building', $this->repo->rows[1]['status'] );
+    }
+
+    public function testLaunchArgumentsAndBuildsAreChecked(): void
+    {
+        $this->refused( [ 'action' => 'launch' ], '"build_id" is required.' );
+        $this->refused( [ 'action' => 'launch', 'build_id' => '1' ], '"build_id" must be an integer.' );
+        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => 'yes' ], '"dry_run" must be true or false.' );
+        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'token' => 5 ], '"token" must be a string.' );
+        $this->refused( [ 'action' => 'launch', 'build_id' => 9, 'dry_run' => true ], 'Build 9 was not found.' );
+        $this->build();
+        $GLOBALS['__wp_current_user_id'] = 8;
+        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ], 'Build 1 was not found.' );
+        $GLOBALS['__wp_current_user_id'] = 7;
+        $this->launch( $this->preview() );
+        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ], 'finished (launched)' );
+    }
+
+    public function testABuildWithoutPagesCannotBeLaunched(): void
+    {
+        $this->log->open( 'build_site', 'Site build' );
+        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ], 'has no pages' );
+    }
+}
diff --git a/pro-addon/tests/BuildSiteTest.php b/pro-addon/tests/BuildSiteTest.php
index f839201..3e7d63f 100644
--- a/pro-addon/tests/BuildSiteTest.php
+++ b/pro-addon/tests/BuildSiteTest.php
@@ -76,7 +76,7 @@ class BuildSiteTest extends TestCase
         $this->assertSame( 'build_site', $d['name'] );
         $this->assertSame( 'publish_pages', $this->tool->capability() );
         $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
-        $this->assertSame( [ 'add_page', 'status' ], $d['inputSchema']['properties']['action']['enum'] );
+        $this->assertSame( [ 'add_page', 'status', 'launch' ], $d['inputSchema']['properties']['action']['enum'] );
         $this->assertSame( [ 'action' ], $d['inputSchema']['required'] );
         foreach ( [ 'title', 'slug', 'content', 'menu_label', 'in_menu', 'is_front', 'build_id' ] as $p ) {
             $this->assertArrayHasKey( $p, $d['inputSchema']['properties'], $p );
@@ -247,7 +247,7 @@ class BuildSiteTest extends TestCase
     {
         foreach ( [
             [ [ 'title' => 'x' ], '"action" is required.' ],
-            [ [ 'action' => 'launch' ], '"action" must be one of: add_page, status.' ],
+            [ [ 'action' => 'explode' ], '"action" must be one of: add_page, status, launch.' ],
             [ [ 'action' => 5 ], '"action" must be a string.' ],
             [ $this->page( 'x', 'x', [ 'title' => 5 ] ), '"title" must be a string.' ],
             [ $this->page( 'x', 'x', [ 'content' => '' ] ), '"content" must not be empty.' ],
diff --git a/tests/WpRestShims.php b/tests/WpRestShims.php
index fee4a7c..8deaba7 100644
--- a/tests/WpRestShims.php
+++ b/tests/WpRestShims.php
@@ -16,6 +16,7 @@ if ( ! class_exists( 'WP_Post' ) ) {
         public string $post_title = '';
         public string $post_content = '';
         public string $post_name = '';
+        public ?string $_wp_trash_meta_status = null;
     }
 }
 if ( ! class_exists( 'WP_REST_Request' ) ) {
diff --git a/tests/bootstrap.php b/tests/bootstrap.php
index 284d40f..bfc72a5 100644
--- a/tests/bootstrap.php
+++ b/tests/bootstrap.php
@@ -202,6 +202,9 @@ if ( ! function_exists( 'wp_update_post' ) ) {
             }
         }
         $GLOBALS['__wp_update_calls'] = ( $GLOBALS['__wp_update_calls'] ?? 0 ) + 1; // lets a test prove no save happened
+        if ( isset( $GLOBALS['__wp_update_hook'] ) && is_callable( $GLOBALS['__wp_update_hook'] ) ) {
+            ( $GLOBALS['__wp_update_hook'] )( $id, $arr ); // a test can make core change something the caller did not ask for
+        }
         return $id;
     }
 }
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-build-site.md T4-tests` → `applied: T4-tests`.

- [ ] **Step 2: Run the Pro suite and watch it fail**

Run: `make test-pro 2>&1 | tail -40`. Expected: FAIL (red). `Tests: 837, Assertions: 18543, Errors: 13, Failures: 7.`. Failing tests include: `testADryRunPreviewsEverythingAndChangesNothing`, `testLaunchPublishesSetsTheFrontPageAndBuildsTheMenuAsOneBatch`, `testTheMenuTitleIsTheMenuLabelAndALaunchWithoutMenuPagesLeavesTheMenuAlone`, `testWithoutAThemeMenuLocationTheMenuIsBuiltUnassignedAndTheResultSaysSo`, `testAPageThatIsGoneChangedOrWhoseSlugWasTakenIsSkippedNeverPublishedFlaggedOrInTheMenu`, `testTheValidPagesAreStillPublishedWhenOthersAreSkipped`, `testALaunchIsRefusedWhenTheLiveFrontPageOrMenuChangedSinceThePreview`, `testNoMenuPagesAndNoFrontPageNeedNeitherMenuNorSettingsRights`, `testATokenIsSingleUseAndBoundToTheUserEvenAfterAnUndo`, `testUndoOfALaunchedBuildPutsEverythingBack`, `testAPageEditedAfterTheLaunchIsGoneBackToDraftButKeptNotTrashed`, `testASlugCoreChangesAtPublishTimeIsReportedAndNothingElseBreaks` and 8 more.

- [ ] **Step 3: Apply the source patch**

Apply `T4-src`:

`````diff
diff --git a/pro-addon/src/Site/BuildLaunch.php b/pro-addon/src/Site/BuildLaunch.php
new file mode 100644
index 0000000..92cc7ad
--- /dev/null
+++ b/pro-addon/src/Site/BuildLaunch.php
@@ -0,0 +1,256 @@
+<?php
+
+declare(strict_types=1);
+
+namespace AiEditorDivi5\Pro\Site;
+
+use AiEditorDivi5\Pro\Batch\BatchLog;
+use AiEditorDivi5\Pro\Batch\Item;
+use AiEditorDivi5\Pro\Canon;
+use AiEditorDivi5\Pro\Kinds\FrontPageKind;
+use AiEditorDivi5\Pro\Settings\MenuBuilder;
+use AiEditorDivi5\Pro\Tools\BuildSite;
+
+if ( ! defined( 'ABSPATH' ) ) {
+    exit;
+}
+
+/**
+ * The launch of a build: the only step that changes what visitors see. It publishes the build's draft pages, sets the page
+ * flagged as the front page and rebuilds the live "Main Menu" from the published menu pages, as ONE previewed step (a dry
+ * run returns a single-use, user-bound token) and ONE batch: the build's own batch grows by the status changes, the front
+ * page and the menu, so one undo takes the whole site away again. Partial by design: a page that is gone, edited since the
+ * preview, or whose slug was taken meanwhile is skipped (never published, never the front page, never in the menu) and the
+ * rest goes live. A changed live front page or menu since the preview refuses the whole run instead: site settings are
+ * never applied against a stale picture.
+ */
+final class BuildLaunch
+{
+    private const FRONT = -1;
+    private const MENU  = -2;
+
+    /** @param \Closure():int $clock */
+    public function __construct( private BatchLog $log, private \Closure $clock )
+    {
+    }
+
+    /** @param array<string,mixed> $row the open build @return array<string,mixed> */
+    public function run( array $row, bool $dry, ?string $token ): array
+    {
+        $items = BuildSite::pageItems( $row );
+        if ( [] === $items ) {
+            throw new \InvalidArgumentException( 'This build has no pages yet. Add pages with add_page first.' );
+        }
+        $wantsMenu  = false;
+        $wantsFront = false;
+        foreach ( $items as $item ) {
+            $wantsMenu  = $wantsMenu || ! empty( $item['meta']['in_menu'] );
+            $wantsFront = $wantsFront || ! empty( $item['meta']['is_front'] );
+        }
+        if ( ! current_user_can( 'publish_pages' ) ) {
+            throw new \InvalidArgumentException( 'You do not have permission to publish pages.' );
+        }
+        if ( $wantsMenu && ! current_user_can( 'edit_theme_options' ) ) {
+            throw new \InvalidArgumentException( 'Launching this build changes the primary menu, which needs the edit_theme_options permission.' );
+        }
+        if ( $wantsFront && ! current_user_can( 'manage_options' ) ) {
+            throw new \InvalidArgumentException( 'Launching this build sets the front page, which needs the manage_options permission.' );
+        }
+
+        $buildId     = (int) $row['id'];
+        $location    = MenuBuilder::targetLocation();
+        $frontBefore = FrontPageKind::read();
+        $menuBefore  = MenuBuilder::snapshot( $location );
+        $plan        = self::plan( $items );
+        $map         = [ self::FRONT => Canon::hash( $frontBefore ), self::MENU => Canon::hash( MenuBuilder::canonical( $menuBefore ) ) ];
+        foreach ( $plan as $p ) {
+            if ( $p['will'] ) {
+                $map[ $p['id'] ] = (string) $p['hash'];
+            }
+        }
+        $argsHash = Canon::hash( [ 'build' => $buildId ] );
+        $userId   = (int) get_current_user_id();
+
+        if ( $dry ) {
+            return $this->preview( $row, $plan, $frontBefore, $menuBefore, $location, PreviewToken::make( $argsHash, $map, ( $this->clock )(), $userId ) );
+        }
+
+        $opened = null === $token ? null : PreviewToken::open( $token, $argsHash, ( $this->clock )(), $userId );
+        if ( null === $opened ) {
+            throw new \InvalidArgumentException( 'A real launch needs the token from a dry run of the same build, made by you in the last 30 minutes and not used before. Call again with dry_run=true.' );
+        }
+        if ( ( $opened[ self::FRONT ] ?? null ) !== $map[ self::FRONT ] || ( $opened[ self::MENU ] ?? null ) !== $map[ self::MENU ] ) {
+            throw new \InvalidArgumentException( 'The live front page or menu changed since the preview. Call again with dry_run=true.' );
+        }
+        PreviewToken::spend( (string) $token );
+
+        return $this->apply( $row, $plan, $opened, $frontBefore, $menuBefore, $location );
+    }
+
+    /**
+     * What each page would do: publish, or be skipped and why.
+     *
+     * @param list<array<string,mixed>> $items
+     * @return list<array{id:int, title:string, slug:string, meta:array<string,mixed>, will:bool, reason:?string, hash:?string}>
+     */
+    private static function plan( array $items ): array
+    {
+        $plan = [];
+        foreach ( $items as $item ) {
+            $id    = (int) $item['ref'];
+            $meta  = is_array( $item['meta'] ?? null ) ? $item['meta'] : [];
+            $post  = get_post( $id );
+            $entry = [ 'id' => $id, 'title' => $post ? (string) $post->post_title : (string) ( $meta['menu_label'] ?? '' ), 'slug' => $post ? (string) ( $post->post_name ?? '' ) : '', 'meta' => $meta, 'will' => false, 'reason' => 'not_available', 'hash' => null ];
+            if ( $post && 'page' === ( $post->post_type ?? 'page' ) && 'draft' === $post->post_status && current_user_can( 'edit_post', $id ) ) {
+                $others = array_filter( array_map( 'intval', (array) get_posts( [ 'post_type' => 'page', 'name' => $entry['slug'], 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'fields' => 'ids', 'posts_per_page' => 5, 'no_found_rows' => true ] ) ), static fn ( int $o ): bool => $o !== $id );
+                if ( '' !== $entry['slug'] && [] !== $others ) {
+                    $entry['reason'] = 'slug_taken';
+                } else {
+                    $entry['will']   = true;
+                    $entry['reason'] = null;
+                    $entry['hash']   = Canon::hash( [ 'title' => (string) $post->post_title, 'content' => (string) $post->post_content, 'slug' => $entry['slug'], 'status' => (string) $post->post_status ] );
+                }
+            }
+            $plan[] = $entry;
+        }
+
+        return $plan;
+    }
+
+    /**
+     * @param array<string,mixed> $row
+     * @param list<array<string,mixed>> $plan
+     * @param array{show_on_front:string, page_on_front:int} $frontBefore
+     * @param array<string,mixed> $menuBefore
+     * @return array<string,mixed>
+     */
+    private function preview( array $row, array $plan, array $frontBefore, array $menuBefore, string $location, string $token ): array
+    {
+        $pages = [];
+        $front = null;
+        $menu  = 0;
+        foreach ( $plan as $p ) {
+            $line = [ 'id' => $p['id'], 'title' => $p['title'], 'slug' => $p['slug'], 'will_publish' => $p['will'] ];
+            if ( ! $p['will'] ) {
+                $line['reason'] = $p['reason'];
+            } else {
+                $front = ! empty( $p['meta']['is_front'] ) ? $p['id'] : $front;
+                $menu += ! empty( $p['meta']['in_menu'] ) ? 1 : 0;
+            }
+            $pages[] = $line;
+        }
+        $publish = count( array_filter( $plan, static fn ( array $p ): bool => $p['will'] ) );
+
+        return [
+            'changed'       => false,
+            'dry_run'       => true,
+            'build'         => BuildSite::summary( $row ),
+            'would_publish' => $publish,
+            'would_skip'    => count( $plan ) - $publish,
+            'pages'         => $pages,
+            'front_page'    => null === $front ? null : [ 'from' => $frontBefore['page_on_front'], 'to' => $front ],
+            'menu'          => 0 === $menu ? null : [ 'location' => '' === $location ? null : $location, 'replaces_items' => count( (array) $menuBefore['items'] ), 'new_items' => $menu ],
+            'token'         => $token,
+            'message'       => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this token to publish these pages' . ( null === $front ? '' : ', set the front page' ) . ( 0 === $menu ? '' : ' and rebuild the menu' ) . '. Pages skipped now stay drafts.',
+        ];
+    }
+
+    /**
+     * @param array<string,mixed> $row
+     * @param list<array<string,mixed>> $plan
+     * @param array<int,string> $opened the preview's page => hash map (and the front/menu hashes)
+     * @param array{show_on_front:string, page_on_front:int} $frontBefore
+     * @param array<string,mixed> $menuBefore
+     * @return array<string,mixed>
+     */
+    private function apply( array $row, array $plan, array $opened, array $frontBefore, array $menuBefore, string $location ): array
+    {
+        $buildId   = (int) $row['id'];
+        $recorded  = [];
+        $published = [];
+        $skipped   = [];
+        $live      = [];
+        foreach ( $plan as $p ) {
+            $base = [ 'id' => $p['id'], 'title' => $p['title'] ];
+            if ( ! $p['will'] ) {
+                $skipped[] = $base + [ 'reason' => $p['reason'] ];
+                continue;
+            }
+            if ( ! array_key_exists( $p['id'], $opened ) ) {
+                $skipped[] = $base + [ 'reason' => 'not_in_preview' ];
+                continue;
+            }
+            if ( $opened[ $p['id'] ] !== $p['hash'] ) {
+                $skipped[] = $base + [ 'reason' => 'changed_since_preview' ];
+                continue;
+            }
+            if ( is_wp_error( wp_update_post( [ 'ID' => $p['id'], 'post_status' => 'publish' ], true ) ) ) {
+                $skipped[] = $base + [ 'reason' => 'publish_failed' ];
+                continue;
+            }
+            $now      = get_post( $p['id'] );
+            $status   = $now ? (string) $now->post_status : 'publish';
+            $realSlug = $now ? (string) ( $now->post_name ?? '' ) : $p['slug'];
+            $line     = $base + [ 'slug' => $realSlug, 'link' => get_permalink( $p['id'] ) ];
+            if ( $realSlug !== $p['slug'] ) {
+                $line['slug_changed'] = [ 'planned' => $p['slug'], 'real' => $realSlug ];
+            }
+            $published[] = $line;
+            $recorded[]  = Item::make( 'post_status', (string) $p['id'], 'Status of "' . $p['title'] . '" (#' . $p['id'] . ')', 'draft', $status );
+            if ( 'publish' === $status ) {
+                $live[] = $p;
+            }
+        }
+
+        if ( [] === $published ) {
+            return [ 'changed' => false, 'dry_run' => false, 'published' => [], 'skipped' => $skipped, 'build' => BuildSite::summary( $row ), 'message' => 'Nothing was published; the build is still open. Pages skipped now stay drafts.' ];
+        }
+
+        $frontId = null;
+        foreach ( $live as $p ) {
+            if ( ! empty( $p['meta']['is_front'] ) ) {
+                $frontId = $p['id'];
+                break;
+            }
+        }
+        if ( null !== $frontId ) {
+            FrontPageKind::set( 'page', $frontId );
+            $recorded[] = Item::make( 'front_page', 'front_page', 'Front page', $frontBefore, FrontPageKind::read() );
+        }
+
+        $menu    = null;
+        $message = 'The build was launched.';
+        $menuIn  = array_values( array_filter( $live, static fn ( array $p ): bool => ! empty( $p['meta']['in_menu'] ) ) );
+        if ( [] !== $menuIn ) {
+            $menuItems = array_map( static fn ( array $p ): array => [
+                'title' => '' !== (string) ( $p['meta']['menu_label'] ?? '' ) ? (string) $p['meta']['menu_label'] : $p['title'], 'type' => 'post_type', 'object' => 'page', 'object_id' => $p['id'], 'url' => '',
+                'parent' => -1, 'classes' => '', 'target' => '', 'attr_title' => '', 'xfn' => '', 'description' => '',
+            ], $menuIn );
+            $added     = MenuBuilder::build( $menuItems, $location );
+            $after     = MenuBuilder::snapshot( $location );
+            $recorded[] = Item::make( 'menu', $location, 'Main Menu (primary navigation)', $menuBefore, MenuBuilder::canonical( $after ) );
+            $menu      = [ 'items' => $added, 'location' => '' === $location ? null : $location ];
+            if ( '' === $location ) {
+                $message .= ' The menu "' . MenuBuilder::MENU_NAME . '" was built, but this theme has no menu location, so it was not assigned. Assign it under Appearance > Menus.';
+            }
+            if ( $added < count( $menuItems ) ) {
+                $message .= ' ' . ( count( $menuItems ) - $added ) . ' of ' . count( $menuItems ) . ' menu items could not be added.';
+            }
+        }
+
+        $stored = $this->log->append( $buildId, $recorded ) && $this->log->close( $buildId, 'applied' );
+        $batch  = $stored ? BatchLog::describe( (array) $this->log->find( $buildId ) ) : [ 'id' => null, 'undoable' => false, 'note' => 'The build went live, but this could not be recorded, so undo_batch cannot undo it.' ];
+
+        return [
+            'changed'    => true,
+            'dry_run'    => false,
+            'published'  => $published,
+            'skipped'    => $skipped,
+            'front_page' => $frontId,
+            'menu'       => $menu,
+            'build'      => BuildSite::summary( (array) ( $this->log->find( $buildId ) ?? $row ) ),
+            'batch'      => $batch,
+            'message'    => $message,
+        ];
+    }
+}
diff --git a/pro-addon/src/Tools/BuildSite.php b/pro-addon/src/Tools/BuildSite.php
index 47092f0..9a659ee 100644
--- a/pro-addon/src/Tools/BuildSite.php
+++ b/pro-addon/src/Tools/BuildSite.php
@@ -8,6 +8,7 @@ use AiEditorDivi5\Pro\Args;
 use AiEditorDivi5\Pro\Batch\BatchLog;
 use AiEditorDivi5\Pro\Kinds\PageCreatedKind;
 use AiEditorDivi5\Pro\ProTool;
+use AiEditorDivi5\Pro\Site\BuildLaunch;
 use AiEditorDivi5\Pro\Site\BuildLock;
 use AiEditorDivi5\WP\LayoutGate;
 use AiEditorDivi5\WP\StyleReferences;
@@ -28,13 +29,18 @@ final class BuildSite implements ProTool
     public const MAX_PAGES   = 30;
     public const MAX_CONTENT = 1000000;
 
-    private const ACTIONS = [ 'add_page', 'status' ];
+    private const ACTIONS = [ 'add_page', 'status', 'launch' ];
 
     private BuildLock $lock;
 
-    public function __construct( private BatchLog $log, ?BuildLock $lock = null )
+    /** @var \Closure():int */
+    private \Closure $clock;
+
+    /** @param (\Closure():int)|null $clock the time in seconds (the tests move it) */
+    public function __construct( private BatchLog $log, ?BuildLock $lock = null, ?\Closure $clock = null )
     {
-        $this->lock = $lock ?? new BuildLock();
+        $this->lock  = $lock ?? new BuildLock();
+        $this->clock = $clock ?? static fn (): int => time();
     }
 
     public function name(): string
@@ -51,18 +57,20 @@ final class BuildSite implements ProTool
     {
         return [
             'name'        => self::NAME,
-            'description' => 'Build a whole Divi 5 site as one undoable build. add_page creates one checked DRAFT page per call (an invalid page or a taken slug is refused, the build continues); status lists the build. Nothing goes live while pages are added. Undo it all with undo_batch.',
+            'description' => 'Build a whole Divi 5 site as one undoable build. add_page creates one checked DRAFT page per call (an invalid page or a taken slug is refused, the build continues); status lists it; launch publishes it (dry_run first, then repeat with the token). Undo it all: undo_batch.',
             'inputSchema' => [
                 'type'       => 'object',
                 'properties' => [
-                    'action'     => [ 'type' => 'string', 'description' => 'add_page or status', 'enum' => self::ACTIONS ],
+                    'action'     => [ 'type' => 'string', 'description' => 'add_page, status or launch', 'enum' => self::ACTIONS ],
                     'title'      => [ 'type' => 'string', 'description' => 'add_page: the page title (plain text)' ],
                     'slug'       => [ 'type' => 'string', 'description' => 'add_page: the page slug; it must not be used by another page' ],
                     'content'    => [ 'type' => 'string', 'description' => 'add_page: the Divi 5 layout (it must pass every check)' ],
                     'menu_label' => [ 'type' => 'string', 'description' => 'add_page: the menu label (default: the title), at most 100 bytes' ],
                     'in_menu'    => [ 'type' => 'boolean', 'description' => 'add_page: put the page in the primary menu at launch (default true)' ],
                     'is_front'   => [ 'type' => 'boolean', 'description' => 'add_page: make this the front page at launch (one page per build)' ],
-                    'build_id'   => [ 'type' => 'integer', 'description' => 'The build to use: returned by the first add_page; required for later pages' ],
+                    'build_id'   => [ 'type' => 'integer', 'description' => 'The build to use: returned by the first add_page; required for later pages and for launch' ],
+                    'dry_run'    => [ 'type' => 'boolean', 'description' => 'launch: preview only; returns the token a real launch needs' ],
+                    'token'      => [ 'type' => 'string', 'description' => 'launch: the token from your dry run of the same build; it works once, within 30 minutes' ],
                 ],
                 'required'   => [ 'action' ],
             ],
@@ -76,7 +84,11 @@ final class BuildSite implements ProTool
             throw new \InvalidArgumentException( '"action" must be one of: ' . implode( ', ', self::ACTIONS ) . '.' );
         }
 
-        return 'add_page' === $action ? $this->addPage( $arguments ) : $this->status( $arguments );
+        return match ( $action ) {
+            'add_page' => $this->addPage( $arguments ),
+            'status'   => $this->status( $arguments ),
+            default    => $this->launch( $arguments ),
+        };
     }
 
     /** @param array<string,mixed> $a @return array<string,mixed> */
@@ -205,6 +217,20 @@ final class BuildSite implements ProTool
         return [ 'build' => self::summary( $row ), 'pages' => $pages ];
     }
 
+    /** @param array<string,mixed> $a @return array<string,mixed> */
+    private function launch( array $a ): array
+    {
+        $buildId = (int) Args::int( $a, 'build_id' );
+        $dry     = Args::bool( $a, 'dry_run' );
+        $token   = Args::string( $a, 'token', false, 8000 );
+        $row     = $this->owned( $buildId, (int) get_current_user_id() );
+        if ( BatchLog::BUILDING !== $row['status'] ) {
+            throw new \InvalidArgumentException( "Build {$buildId} is finished (" . self::state( $row ) . ') and cannot be launched again.' );
+        }
+
+        return ( new BuildLaunch( $this->log, $this->clock ) )->run( $row, $dry, $token );
+    }
+
     /** The user's own build by id, whatever its state; anything else is "not found" (nothing leaks about other users' batches). @return array<string,mixed> */
     private function owned( int $buildId, int $userId ): array
     {
diff --git a/pro-addon/src/autoload.php b/pro-addon/src/autoload.php
index d8aa81d..f22efab 100644
--- a/pro-addon/src/autoload.php
+++ b/pro-addon/src/autoload.php
@@ -38,6 +38,7 @@ require_once __DIR__ . '/Kinds/PageCreatedKind.php';
 require_once __DIR__ . '/Kinds/PageTrashedKind.php';
 require_once __DIR__ . '/Tools/PublishPage.php';
 require_once __DIR__ . '/Site/BuildLock.php';
+require_once __DIR__ . '/Site/BuildLaunch.php';
 require_once __DIR__ . '/Tools/BuildSite.php';
 require_once __DIR__ . '/Settings/PhpProposals.php';
 require_once __DIR__ . '/Tools/ProposePhpSnippet.php';
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-build-site.md T4-src` → `applied: T4-src`.

- [ ] **Step 4: Run the suites and watch them pass**

Run: `make test-pro 2>&1 | tail -3` → `OK (839 tests, 18691 assertions)`. Run: `make test 2>&1 | tail -3` → `OK (727 tests, 5241 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -q -F - <<'EOF'
feat(pro): build_site launch - previewed, single-use-token publish of the build, front page and menu as one undoable batch

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B | tail -2
```

### Task 5: Launch robustness: the build lock and recording each step as it happens

**What:** Two hardening changes found in self-review of T4: `launch` takes the same per-user lock as `add_page` (a page must not be appended while the build is being launched and closed), and each launch step is appended to the batch as soon as it has happened, so if a later step (the menu) fails, what already went live is recorded and `undo_batch` can still take it back.

**Files:** Modify: `pro-addon/src/Tools/BuildSite.php`, `pro-addon/src/Site/BuildLaunch.php`; Test: `BuildLaunchTest.php`, `WpSettingsShims.php`.

**Interfaces:** None new.

- [ ] **Step 1: Apply the tests patch**

Apply `T5-tests`:

`````diff
diff --git a/pro-addon/tests/BuildLaunchTest.php b/pro-addon/tests/BuildLaunchTest.php
index 2bb6a94..f21498a 100644
--- a/pro-addon/tests/BuildLaunchTest.php
+++ b/pro-addon/tests/BuildLaunchTest.php
@@ -52,12 +52,12 @@ class BuildLaunchTest extends TestCase
         $GLOBALS['__wp_update_fail']      = false;
         $GLOBALS['__wp_current_user_id']  = 7;
         $GLOBALS['__wp_caps']             = [ 'publish_pages' => true, 'edit_post' => true, 'delete_post' => true, 'edit_theme_options' => true, 'manage_options' => true ];
-        unset( $GLOBALS['__wp_update_hook'] );
+        unset( $GLOBALS['__wp_update_hook'], $GLOBALS['__wp_menu_create_fail'] );
     }
 
     protected function tearDown(): void
     {
-        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'], $GLOBALS['__wp_update_hook'] );
+        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'], $GLOBALS['__wp_update_hook'], $GLOBALS['__wp_menu_create_fail'] );
         $GLOBALS['__wp_update_fail'] = false;
     }
 
@@ -371,4 +371,49 @@ class BuildLaunchTest extends TestCase
         $this->log->open( 'build_site', 'Site build' );
         $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ], 'has no pages' );
     }
+
+    public function testALaunchWhileAnotherBuildCallIsRunningIsRefusedAndTheLockIsReleasedAfterwards(): void
+    {
+        $this->build();
+        $GLOBALS['__wp_options']['aied_pro_buildlock_7'] = (string) time();
+        $this->refused( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ], 'still running' );
+        unset( $GLOBALS['__wp_options']['aied_pro_buildlock_7'] );
+        $p = $this->preview();
+        $this->assertArrayNotHasKey( 'aied_pro_buildlock_7', $GLOBALS['__wp_options'], 'released after a preview' );
+        $this->launch( $p );
+        $this->assertArrayNotHasKey( 'aied_pro_buildlock_7', $GLOBALS['__wp_options'], 'released after the real run' );
+        try {
+            $this->tool->run( [ 'action' => 'launch', 'build_id' => 1, 'dry_run' => true ] );
+        } catch ( \InvalidArgumentException $e ) {
+            // finished
+        }
+        $this->assertArrayNotHasKey( 'aied_pro_buildlock_7', $GLOBALS['__wp_options'], 'released after a refusal' );
+    }
+
+    public function testWhatWasPublishedIsRecordedEvenWhenALaterStepFailsSoItCanStillBeUndone(): void
+    {
+        $ids = $this->build();
+        $p   = $this->preview();
+        $GLOBALS['__wp_menu_create_fail'] = true;
+        try {
+            $this->launch( $p );
+            $this->fail( 'expected the menu failure to surface' );
+        } catch ( \RuntimeException $e ) {
+            $this->assertStringContainsString( 'simulated menu failure', $e->getMessage() );
+        }
+        $GLOBALS['__wp_menu_create_fail'] = false;
+        foreach ( $ids as $id ) {
+            $this->assertSame( 'publish', $GLOBALS['__wp_posts'][ $id ]->post_status, 'the pages did go live before the menu step failed' );
+        }
+        $row = $this->repo->rows[1];
+        $this->assertSame( 'building', $row['status'], 'the build is not marked launched' );
+        $this->assertSame( [ 'page_created', 'page_created', 'page_created', 'post_status', 'post_status', 'post_status', 'front_page' ], array_column( $row['items'], 'kind' ), 'the publishes and the front page were recorded as they happened' );
+
+        $u = $this->undo()->undo( 1 );
+        $this->assertTrue( $u['fully_undone'] );
+        $this->assertSame( [ 'posts', 0 ], [ $GLOBALS['__wp_options']['show_on_front'], $GLOBALS['__wp_options']['page_on_front'] ] );
+        foreach ( $ids as $id ) {
+            $this->assertSame( 'trash', $GLOBALS['__wp_posts'][ $id ]->post_status );
+        }
+    }
 }
diff --git a/pro-addon/tests/WpSettingsShims.php b/pro-addon/tests/WpSettingsShims.php
index d17f040..66f11eb 100644
--- a/pro-addon/tests/WpSettingsShims.php
+++ b/pro-addon/tests/WpSettingsShims.php
@@ -46,6 +46,9 @@ if ( ! function_exists( 'wp_get_nav_menu_object' ) ) {
 }
 if ( ! function_exists( 'wp_create_nav_menu' ) ) {
     function wp_create_nav_menu( $name ) {
+        if ( ! empty( $GLOBALS['__wp_menu_create_fail'] ) ) {
+            return new WP_Error( 'menu_failed', 'simulated menu failure' );
+        }
         if ( false !== wp_get_nav_menu_object( $name ) ) {
             return new WP_Error( 'menu_exists', 'The menu name already exists.' );
         }
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-build-site.md T5-tests` → `applied: T5-tests`.

- [ ] **Step 2: Run the Pro suite and watch it fail**

Run: `make test-pro 2>&1 | tail -40`. Expected: FAIL (red). `Tests: 841, Assertions: 18698, Failures: 2.`. Failing tests include: `testALaunchWhileAnotherBuildCallIsRunningIsRefusedAndTheLockIsReleasedAfterwards`, `testWhatWasPublishedIsRecordedEvenWhenALaterStepFailsSoItCanStillBeUndone`.

- [ ] **Step 3: Apply the source patch**

Apply `T5-src`:

`````diff
diff --git a/pro-addon/src/Site/BuildLaunch.php b/pro-addon/src/Site/BuildLaunch.php
index 92cc7ad..3440491 100644
--- a/pro-addon/src/Site/BuildLaunch.php
+++ b/pro-addon/src/Site/BuildLaunch.php
@@ -166,6 +166,12 @@ final class BuildLaunch
     private function apply( array $row, array $plan, array $opened, array $frontBefore, array $menuBefore, string $location ): array
     {
         $buildId   = (int) $row['id'];
+        $stored    = true;
+        // Each step is written to the build's batch as soon as it has happened: if a later step fails, what already went live
+        // is recorded and `undo_batch` can still take it back (the build then simply stays open).
+        $record    = function ( array $items ) use ( $buildId, &$stored ): void {
+            $stored = $this->log->append( $buildId, $items ) && $stored;
+        };
         $recorded  = [];
         $published = [];
         $skipped   = [];
@@ -206,6 +212,7 @@ final class BuildLaunch
             return [ 'changed' => false, 'dry_run' => false, 'published' => [], 'skipped' => $skipped, 'build' => BuildSite::summary( $row ), 'message' => 'Nothing was published; the build is still open. Pages skipped now stay drafts.' ];
         }
 
+        $record( $recorded );
         $frontId = null;
         foreach ( $live as $p ) {
             if ( ! empty( $p['meta']['is_front'] ) ) {
@@ -215,7 +222,7 @@ final class BuildLaunch
         }
         if ( null !== $frontId ) {
             FrontPageKind::set( 'page', $frontId );
-            $recorded[] = Item::make( 'front_page', 'front_page', 'Front page', $frontBefore, FrontPageKind::read() );
+            $record( [ Item::make( 'front_page', 'front_page', 'Front page', $frontBefore, FrontPageKind::read() ) ] );
         }
 
         $menu    = null;
@@ -228,7 +235,7 @@ final class BuildLaunch
             ], $menuIn );
             $added     = MenuBuilder::build( $menuItems, $location );
             $after     = MenuBuilder::snapshot( $location );
-            $recorded[] = Item::make( 'menu', $location, 'Main Menu (primary navigation)', $menuBefore, MenuBuilder::canonical( $after ) );
+            $record( [ Item::make( 'menu', $location, 'Main Menu (primary navigation)', $menuBefore, MenuBuilder::canonical( $after ) ) ] );
             $menu      = [ 'items' => $added, 'location' => '' === $location ? null : $location ];
             if ( '' === $location ) {
                 $message .= ' The menu "' . MenuBuilder::MENU_NAME . '" was built, but this theme has no menu location, so it was not assigned. Assign it under Appearance > Menus.';
@@ -238,7 +245,7 @@ final class BuildLaunch
             }
         }
 
-        $stored = $this->log->append( $buildId, $recorded ) && $this->log->close( $buildId, 'applied' );
+        $stored = $stored && $this->log->close( $buildId, 'applied' );
         $batch  = $stored ? BatchLog::describe( (array) $this->log->find( $buildId ) ) : [ 'id' => null, 'undoable' => false, 'note' => 'The build went live, but this could not be recorded, so undo_batch cannot undo it.' ];
 
         return [
diff --git a/pro-addon/src/Tools/BuildSite.php b/pro-addon/src/Tools/BuildSite.php
index 9a659ee..84a662d 100644
--- a/pro-addon/src/Tools/BuildSite.php
+++ b/pro-addon/src/Tools/BuildSite.php
@@ -223,12 +223,20 @@ final class BuildSite implements ProTool
         $buildId = (int) Args::int( $a, 'build_id' );
         $dry     = Args::bool( $a, 'dry_run' );
         $token   = Args::string( $a, 'token', false, 8000 );
-        $row     = $this->owned( $buildId, (int) get_current_user_id() );
+        $userId  = (int) get_current_user_id();
+        $row     = $this->owned( $buildId, $userId );
         if ( BatchLog::BUILDING !== $row['status'] ) {
             throw new \InvalidArgumentException( "Build {$buildId} is finished (" . self::state( $row ) . ') and cannot be launched again.' );
         }
-
-        return ( new BuildLaunch( $this->log, $this->clock ) )->run( $row, $dry, $token );
+        // The same per-user lock as add_page: a page must not be appended to a build while it is being launched and closed.
+        if ( ! $this->lock->acquire( $userId ) ) {
+            throw new \InvalidArgumentException( 'Another build_site call is still running for you. Wait a moment and call again.' );
+        }
+        try {
+            return ( new BuildLaunch( $this->log, $this->clock ) )->run( $row, $dry, $token );
+        } finally {
+            $this->lock->release( $userId );
+        }
     }
 
     /** The user's own build by id, whatever its state; anything else is "not found" (nothing leaks about other users' batches). @return array<string,mixed> */
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-build-site.md T5-src` → `applied: T5-src`.

- [ ] **Step 4: Run the suites and watch them pass**

Run: `make test-pro 2>&1 | tail -3` → `OK (841 tests, 18707 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -q -F - <<'EOF'
fix(pro): build_site launch takes the build lock and records each step as it happens

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B | tail -2
```

### Task 6: Live gate script `verify-pro-build-site` and its Makefile target

**What:** The live gate `scripts/verify-pro-build-site.php` (dev guard, self-cleaning, baseline proven): build, invalid page, taken slug, one open build, status, preview, token, launch, the live menu rendered as an anonymous visitor, undo of a launched build, an abandoned build, an edited page, a slug taken before launch; plus its Makefile target. The guard test now expects seven verify scripts.

**Files:** Create: `scripts/verify-pro-build-site.php`; Modify: `Makefile`, `pro-addon/tests/DevGuardTest.php`.

**Interfaces:** None.

- [ ] **Step 1: Apply the tests patch**

Apply `T6-tests`:

`````diff
diff --git a/pro-addon/tests/DevGuardTest.php b/pro-addon/tests/DevGuardTest.php
index f5ab1ec..7a16145 100644
--- a/pro-addon/tests/DevGuardTest.php
+++ b/pro-addon/tests/DevGuardTest.php
@@ -40,7 +40,7 @@ class DevGuardTest extends TestCase
     public function testEveryRealSiteVerifyScriptRunsTheGuardFirst(): void
     {
         $scripts = glob( __DIR__ . '/../../scripts/verify-pro-*.php' );
-        $this->assertCount( 6, $scripts );
+        $this->assertCount( 7, $scripts );
         foreach ( $scripts as $script ) {
             $code = (string) file_get_contents( $script );
             $this->assertStringContainsString( "require_once __DIR__ . '/dev-guard.php';", $code, basename( $script ) );
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-build-site.md T6-tests` → `applied: T6-tests`.

- [ ] **Step 2: Run the Pro suite and watch it fail**

Run: `make test-pro 2>&1 | tail -40`. Expected: FAIL (red). `Tests: 841, Assertions: 18683, Failures: 1.`. Failing tests include: `testEveryRealSiteVerifyScriptRunsTheGuardFirst`.

- [ ] **Step 3: Apply the source patch**

Apply `T6-src`:

`````diff
diff --git a/Makefile b/Makefile
index c1b768b..64e8c7e 100644
--- a/Makefile
+++ b/Makefile
@@ -1,4 +1,4 @@
-.PHONY: up down clean test test-pro build-pro verify-pro-skeleton verify-pro-settings verify-pro-theme-layouts verify-pro-theme-templates verify-pro-site-tools verify-pro-rest validate install shell-wp shell-wpcli export-layouts app-password schema-gap verify-modules verify-history verify-dry-run verify-global-styles
+.PHONY: up down clean test test-pro build-pro verify-pro-skeleton verify-pro-settings verify-pro-theme-layouts verify-pro-theme-templates verify-pro-site-tools verify-pro-rest verify-pro-build-site validate install shell-wp shell-wpcli export-layouts app-password schema-gap verify-modules verify-history verify-dry-run verify-global-styles
 
 # ---------------------------------------------------------------
 # up — bootstrap the full environment (idempotent)
@@ -87,6 +87,12 @@ verify-pro-site-tools:
 verify-pro-rest:
 	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-rest.php --user=1
 
+# ---------------------------------------------------------------
+# verify-pro-build-site — real-site check of build_site (build, launch, anonymous menu, undo; self-cleaning; free + Pro plugins must be active; dev site only: it evicts old batch rows)
+# ---------------------------------------------------------------
+verify-pro-build-site:
+	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-build-site.php --user=1
+
 # ---------------------------------------------------------------
 # validate — run the validator against an arbitrary JSON file
 # Usage: make validate FILE=fixtures/valid/my-layout.json
diff --git a/scripts/verify-pro-build-site.php b/scripts/verify-pro-build-site.php
new file mode 100644
index 0000000..bd3808c
--- /dev/null
+++ b/scripts/verify-pro-build-site.php
@@ -0,0 +1,193 @@
+<?php
+/**
+ * Real-site check of build_site (whole-site generation as one undoable build): the real tool, the real batch table, the real
+ * validator, WordPress's own menus and options. It builds a throwaway three-page site, launches it, looks at the live menu as
+ * an anonymous visitor, and undoes it; it also abandons a build, edits a page behind a build's back, and takes a slug before a
+ * launch. Self-cleaning: it restores the front page options and the Main Menu and its location, deletes every page and batch row
+ * it created, and proves the baseline afterwards.
+ *
+ * WARNING: opening a build trims the batch store to the newest 20, so on a site that already has batches it evicts the oldest real
+ * ones. Run it on a throwaway dev site only (the dev guard refuses any other host).
+ *
+ * Run: make verify-pro-build-site   (needs `make up`, the free plugin and the Pro add-on active; no licence is needed: the tool is
+ * called directly, not through the licence-gated registry)
+ */
+
+use AiEditorDivi5\Pro\Batch\Batches;
+use AiEditorDivi5\Pro\Batch\BatchTable;
+use AiEditorDivi5\Pro\Kinds\FrontPageKind;
+use AiEditorDivi5\Pro\Settings\MenuBuilder;
+use AiEditorDivi5\Pro\Tools\BuildSite;
+
+if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( BuildSite::class ) ) {
+    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 and jhmg-ai-editor-for-divi-5-pro on this site first.' );
+}
+
+require_once __DIR__ . '/dev-guard.php';
+aied_require_dev_site();
+
+$failures = [];
+$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
+    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
+    if ( ! $ok ) {
+        $failures[] = $label;
+    }
+};
+
+global $wpdb;
+$location = MenuBuilder::targetLocation();
+$orig     = [
+    'front_val' => FrontPageKind::read(),
+    'mods' => get_theme_mod( 'nav_menu_locations', null ),
+    'menu' => MenuBuilder::snapshot( $location ),
+    'pages' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" ), // phpcs:ignore
+];
+$pages    = [];
+$batchIds = [];
+
+BatchTable::ensureReady( true );
+$check( BatchTable::exists(), 'the batch table exists on the real database (' . BatchTable::name() . ')' );
+$log  = Batches::log();
+$undo = Batches::undo();
+$tool = new BuildSite( $log );
+
+$layout = static fn ( string $word ): string => '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"' . $word . '"}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
+$add = static function ( string $title, string $slug, array $extra = [] ) use ( $tool, $layout, &$pages, &$batchIds ): array {
+    $r = $tool->run( [ 'action' => 'add_page', 'title' => $title, 'slug' => $slug, 'content' => $layout( $title ) ] + $extra );
+    $pages[]    = $r['page']['id'];
+    $batchIds[] = $r['build']['id'];
+
+    return $r;
+};
+$refusal = static function ( callable $fn ): string {
+    try {
+        $fn();
+    } catch ( \InvalidArgumentException $e ) {
+        return $e->getMessage();
+    }
+
+    return '';
+};
+$menuTitles = static function () use ( $location ): array {
+    $menu = wp_get_nav_menu_object( MenuBuilder::MENU_NAME );
+
+    return $menu ? array_map( static fn ( $o ) => (string) $o->title, array_values( (array) wp_get_nav_menu_items( (int) $menu->term_id ) ) ) : [];
+};
+$sameSettings = static fn (): bool => FrontPageKind::read() === $orig['front_val'] && MenuBuilder::canonical( MenuBuilder::snapshot( $location ) ) === MenuBuilder::canonical( $orig['menu'] );
+
+try {
+    // ---- building: valid, invalid, duplicate, one open build ----------------------------------------------------
+    $a = $add( 'AIED Verify Home', 'aied-verify-home', [ 'is_front' => true ] );
+    $homeId = $a['page']['id'];
+    $buildId = $a['build']['id'];
+    $check( true === $a['created'] && 'draft' === get_post_status( $homeId ) && 'open' === $a['build']['state'], 'add_page: the first page opens a build and is a DRAFT' );
+    $check( 'on' === get_post_meta( $homeId, '_et_pb_use_divi_5', true ) && 'aied-verify-home' === get_post( $homeId )->post_name, 'the page carries the Divi 5 flags and its planned slug' );
+    $row = $log->find( $buildId );
+    $check( null !== $row && 'building' === $row['status'] && 'page_created' === ( $row['items'][0]['kind'] ?? '' ), 'the build is ONE batch row with status building (real table)' );
+
+    $bad = $refusal( static fn () => $tool->run( [ 'action' => 'add_page', 'build_id' => $buildId, 'title' => 'AIED Verify Bad', 'slug' => 'aied-verify-bad', 'content' => str_replace( 'wp:divi/heading', 'wp:divi/not-a-module', $layout( 'x' ) ) ] ) );
+    $check( false !== strpos( $bad, 'The page was not created' ) && false !== strpos( $bad, 'not-a-module' ), 'an invalid page is refused with the exact violations' );
+    $check( [] === get_posts( [ 'post_type' => 'page', 'name' => 'aied-verify-bad', 'post_status' => 'any', 'fields' => 'ids' ] ), 'and nothing was created for it' );
+    $dup = $refusal( static fn () => $tool->run( [ 'action' => 'add_page', 'build_id' => $buildId, 'title' => 'AIED Verify Home 2', 'slug' => 'aied-verify-home', 'content' => $layout( 'x' ) ] ) );
+    $check( false !== strpos( $dup, 'page #' . $homeId ), 'a slug already taken by a draft of the build is refused, naming that page' );
+    $noId = $refusal( static fn () => $tool->run( [ 'action' => 'add_page', 'title' => 'AIED Verify About', 'slug' => 'aied-verify-about', 'content' => $layout( 'x' ) ] ) );
+    $check( false !== strpos( $noId, '"build_id": ' . $buildId ), 'a second call without build_id is refused and names the open build' );
+
+    $b = $add( 'AIED Verify About', 'aied-verify-about', [ 'build_id' => $buildId, 'menu_label' => 'About' ] );
+    $c = $add( 'AIED Verify Hidden', 'aied-verify-hidden', [ 'build_id' => $buildId, 'in_menu' => false ] );
+    $aboutId = $b['page']['id'];
+    $hiddenId = $c['page']['id'];
+    $s = $tool->run( [ 'action' => 'status' ] );
+    $check( 3 === $s['build']['pages'] && 2 === $s['build']['in_menu'] && $homeId === $s['build']['front_page'] && [ 'draft', 'draft', 'draft' ] === array_column( $s['pages'], 'status' ), 'status lists the three drafts in add order with the menu and front flags' );
+    $check( $sameSettings(), 'while pages are only added the live front page and the live menu are untouched' );
+
+    // ---- launch: preview, token, real run ------------------------------------------------------------------------
+    $d = $tool->run( [ 'action' => 'launch', 'build_id' => $buildId, 'dry_run' => true ] );
+    $check( 3 === $d['would_publish'] && 0 === $d['would_skip'] && is_string( $d['token'] ) && [ $homeId, $aboutId, $hiddenId ] === array_column( $d['pages'], 'id' ), 'launch dry run: three pages would go live, and a token is returned' );
+    $check( 'draft' === get_post_status( $homeId ) && $sameSettings(), 'the dry run changed nothing' );
+    $check( false !== strpos( $refusal( static fn () => $tool->run( [ 'action' => 'launch', 'build_id' => $buildId ] ) ), 'needs the token' ), 'a real launch without the token is refused' );
+
+    $l = $tool->run( [ 'action' => 'launch', 'build_id' => $buildId, 'token' => $d['token'] ] );
+    $check( true === $l['changed'] && 3 === count( $l['published'] ) && [] === $l['skipped'] && 'launched' === $l['build']['state'], 'launch: three pages published, none skipped, the build is launched' );
+    $check( 'publish' === get_post_status( $homeId ) && 'publish' === get_post_status( $aboutId ) && 'publish' === get_post_status( $hiddenId ), 'the real post statuses are publish' );
+    $check( 'page' === get_option( 'show_on_front' ) && $homeId === (int) get_option( 'page_on_front' ), 'the front page option points at the flagged page' );
+    $check( [ 'AIED Verify Home', 'About' ] === $menuTitles(), 'the Main Menu is rebuilt from the in_menu pages, in add order, with the menu labels' );
+    $check( $l['published'][1]['link'] === get_permalink( $aboutId ) && false === strpos( $l['published'][1]['link'], 'page_id=' ), 'the result carries the real pretty permalink of a published page' );
+    if ( '' !== $location ) {
+        wp_set_current_user( 0 );
+        $html = (string) wp_nav_menu( [ 'theme_location' => $location, 'echo' => false, 'container' => false, 'fallback_cb' => false ] );
+        wp_set_current_user( 1 );
+        $check( false !== strpos( $html, get_permalink( $aboutId ) ) && false === strpos( $html, 'page_id=' . $aboutId ), 'an anonymous visitor sees the About link with its real permalink, not a dead ?page_id link' );
+    }
+    $row = $log->find( $buildId );
+    $check( 'applied' === $row['status'] && [ 'page_created', 'page_created', 'page_created', 'post_status', 'post_status', 'post_status', 'front_page', 'menu' ] === array_column( $row['items'], 'kind' ), 'one batch: creation, statuses, front page, menu' );
+    $check( false !== strpos( $refusal( static fn () => $tool->run( [ 'action' => 'launch', 'build_id' => $buildId, 'token' => $d['token'] ] ) ), 'finished' ), 'a launched build cannot be launched again (the token is spent)' );
+    $check( false !== strpos( $refusal( static fn () => $tool->run( [ 'action' => 'add_page', 'build_id' => $buildId, 'title' => 'Late', 'slug' => 'aied-verify-late', 'content' => $layout( 'x' ) ] ) ), 'finished (launched)' ), 'a page cannot be added to a launched build' );
+
+    // ---- undo of the launched build -------------------------------------------------------------------------------
+    $u = $undo->undo( $buildId );
+    if ( isset( $u['undo_batch']['id'] ) ) {
+        $batchIds[] = (int) $u['undo_batch']['id'];
+    }
+    $check( true === $u['fully_undone'] && 8 === $u['restored'], 'undo_batch of the launched build restores all eight items' );
+    $check( $sameSettings(), 'the front page options and the Main Menu are exactly as before the build' );
+    $check( 'trash' === get_post_status( $homeId ) && 'trash' === get_post_status( $aboutId ) && 'trash' === get_post_status( $hiddenId ), 'the created pages are in the trash (back to draft first, then trashed)' );
+    $check( 'undone' === $log->find( $buildId )['status'], 'the build batch is marked undone' );
+
+    // ---- abandon an open build, then an edit behind a build\'s back -------------------------------------------------
+    $e = $add( 'AIED Verify Abandoned', 'aied-verify-abandoned' );
+    $u = $undo->undo( $e['build']['id'] );
+    if ( isset( $u['undo_batch']['id'] ) ) {
+        $batchIds[] = (int) $u['undo_batch']['id'];
+    }
+    $check( true === $u['fully_undone'] && 'trash' === get_post_status( $e['page']['id'] ) && null === $log->openBuild( get_current_user_id() ), 'an open build can be abandoned with undo_batch, and a new one can start' );
+
+    $f = $add( 'AIED Verify Edited', 'aied-verify-edited' );
+    wp_update_post( wp_slash( [ 'ID' => $f['page']['id'], 'post_content' => $layout( 'Edited in the builder' ) ] ) );
+    $u = $undo->undo( $f['build']['id'] );
+    if ( isset( $u['undo_batch']['id'] ) ) {
+        $batchIds[] = (int) $u['undo_batch']['id'];
+    }
+    $check( false === $u['fully_undone'] && 'changed_since' === ( $u['items'][0]['result'] ?? '' ) && 'draft' === get_post_status( $f['page']['id'] ), 'a page edited after the build is skipped by undo (changed_since) and never trashed' );
+    $undo->undo( $f['build']['id'], true );
+
+    // ---- a slug taken between add_page and launch -----------------------------------------------------------------------
+    $g = $add( 'AIED Verify Clash', 'aied-verify-clash' );
+    // Another DRAFT with the same slug (core keeps a draft's slug as given; it only resolves clashes when a page is published, and
+    // then renames the page being published): the realistic way a slug gets taken between add_page and launch.
+    $taker = wp_insert_post( wp_slash( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'AIED Verify Taker', 'post_name' => 'aied-verify-clash', 'post_content' => '' ] ), true );
+    $pages[] = (int) $taker;
+    $p = $tool->run( [ 'action' => 'launch', 'build_id' => $g['build']['id'], 'dry_run' => true ] );
+    $check( 0 === $p['would_publish'] && 'slug_taken' === ( $p['pages'][0]['reason'] ?? '' ), 'launch preview: a slug taken meanwhile skips that page (slug_taken)' );
+    $r = $tool->run( [ 'action' => 'launch', 'build_id' => $g['build']['id'], 'token' => $p['token'] ] );
+    $check( false === $r['changed'] && 'draft' === get_post_status( $g['page']['id'] ) && 'open' === $tool->run( [ 'action' => 'status', 'build_id' => $g['build']['id'] ] )['build']['state'], 'nothing publishable: the build stays open and the page stays a draft' );
+    $u = $undo->undo( $g['build']['id'] );
+    if ( isset( $u['undo_batch']['id'] ) ) {
+        $batchIds[] = (int) $u['undo_batch']['id'];
+    }
+    $check( true === $u['fully_undone'], 'and it can be abandoned' );
+} catch ( \Throwable $ex ) {
+    $check( false, 'unexpected error: ' . get_class( $ex ) . ': ' . $ex->getMessage() );
+} finally {
+    // ---- cleanup -----------------------------------------------------------------------------------------------------------------
+    FrontPageKind::set( $orig['front_val']['show_on_front'], $orig['front_val']['page_on_front'] );
+    MenuBuilder::restore( $orig['menu'] );
+    foreach ( array_unique( $pages ) as $id ) {
+        wp_delete_post( (int) $id, true );
+    }
+    if ( [] !== $batchIds ) {
+        $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_unique( $batchIds ) ) ) . ')' ); // phpcs:ignore
+    }
+    delete_option( 'aied_pro_buildlock_' . get_current_user_id() );
+}
+
+$check( FrontPageKind::read() === $orig['front_val'], 'cleanup: the front page settings are as before (show_on_front and page_on_front, as the plugin reads them)' );
+$check( MenuBuilder::canonical( $orig['menu'] ) === MenuBuilder::canonical( MenuBuilder::snapshot( $location ) ), 'cleanup: the Main Menu and its location are as before' );
+$check( $orig['pages'] === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" ), 'cleanup: every throwaway page is deleted' ); // phpcs:ignore
+$left = [] === $batchIds ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_unique( $batchIds ) ) ) . ')' ); // phpcs:ignore
+$check( 0 === $left, 'cleanup: every batch row this script created is deleted' );
+
+if ( $failures ) {
+    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
+}
+WP_CLI::success( 'build_site verified on the real site: build, launch, anonymous menu, undo, abandon, edited page, taken slug.' );
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-build-site.md T6-src` → `applied: T6-src`.

- [ ] **Step 4: Run the suites and watch them pass**

Run: `make test-pro 2>&1 | tail -3` → `OK (841 tests, 18711 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -q -F - <<'EOF'
test(pro): live gate for build_site (verify-pro-build-site) and its Makefile target

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B | tail -2
```

### Task 7: Release housekeeping: 0.5.0, changelog, README, EXTENDING, CLAUDE.md

**What:** Version 0.5.0 in the main file (also the plugin description), `AIED_PRO_VERSION`, the test bootstrap and `readme.txt` (Stable tag + changelog); README ("Site builds", fourteen tools, limits); the 30-of-30 budget in `docs/EXTENDING.md`; `CLAUDE.md` (the entry-point table row, the 14 tools, a 0.5.0 Current-state bullet).

**Files:** Modify: `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`, `pro-addon/readme.txt`, `pro-addon/README.md`, `pro-addon/tests/bootstrap.php`, `docs/EXTENDING.md`, `CLAUDE.md`.

**Interfaces:** None.

- [ ] **Step 1: Apply the tests patch**

Apply `T7-tests`:

`````diff
diff --git a/pro-addon/tests/bootstrap.php b/pro-addon/tests/bootstrap.php
index 53660b6..69d70aa 100644
--- a/pro-addon/tests/bootstrap.php
+++ b/pro-addon/tests/bootstrap.php
@@ -11,7 +11,7 @@ require_once __DIR__ . '/../../tests/bootstrap.php';
 require_once __DIR__ . '/../../tests/WpRestShims.php';
 
 if ( ! defined( 'AIED_PRO_VERSION' ) ) {
-    define( 'AIED_PRO_VERSION', '0.4.1' );
+    define( 'AIED_PRO_VERSION', '0.5.0' );
 }
 if ( ! defined( 'AIED_PRO_FILE' ) ) {
     define( 'AIED_PRO_FILE', (string) realpath( __DIR__ . '/..' ) . '/jhmg-ai-editor-for-divi-5-pro.php' );
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-build-site.md T7-tests` → `applied: T7-tests`.

- [ ] **Step 2: Run the Pro suite and watch it fail**

Run: `make test-pro 2>&1 | tail -40`. Expected: FAIL (red). `Tests: 841, Assertions: 18711, Failures: 1.`. Failing tests include: `testTheStableTagIsTheCurrentVersion`.

- [ ] **Step 3: Apply the source patch**

Apply `T7-src`:

`````diff
diff --git a/CLAUDE.md b/CLAUDE.md
index 236e24a..ca0d45d 100644
--- a/CLAUDE.md
+++ b/CLAUDE.md
@@ -28,8 +28,8 @@ Three parts, one repo:
   `wp-plugin/validator/` and wraps it in the MCP server, REST API, AI-facing guides,
   the built-in image pack and the admin UI. No licensing, no remote calls.
 - **The Pro add-on** (`pro-addon/`) — a separate plugin ("JHMG AI Editor for Divi 5 Pro", sold at divi5lab.com,
-  never in the WordPress.org zip). 0.4.1: compat guard, licensing with the updater, a licence-gated tool
-  registry with 13 tools (settings, batches and undo, Theme Builder header/footer and template tools, site-wide find/replace and audit), REST and ChatGPT parity for all of them, the Pro admin tab, its own suite (`make test-pro`) and zip (`make build-pro`). Not tested
+  never in the WordPress.org zip). 0.5.0: compat guard, licensing with the updater, a licence-gated tool
+  registry with 14 tools (settings, batches and undo, Theme Builder header/footer and template tools, site-wide find/replace and audit, whole-site build), REST and ChatGPT parity for all of them, the Pro admin tab, its own suite (`make test-pro`) and zip (`make build-pro`). Not tested
   by `make test` (see `pro-addon/README.md`).
 
 ## Hard constraints — do not violate
@@ -185,6 +185,7 @@ Docker env + validator workflows go through `make`:
 | `make verify-pro-theme-templates` | Real-site evidence gate for manage_theme_template (self-cleaning; dev site only) |
 | `make verify-pro-site-tools` | Real-site check of find_replace_site and audit_site through the real registry (self-cleaning; dev site only: it evicts old batch rows) |
 | `make verify-pro-rest` | Real-site check of the Pro REST routes and the live OpenAPI spec (read-only) |
+| `make verify-pro-build-site` | Real-site check of `build_site` (build, launch, anonymous menu render, undo, abandon; self-cleaning; dev site only: it evicts old batch rows) |
 | `make clean` | Destroy volumes (prompts for confirmation) |
 
 Tests run via PHPUnit (`phpunit.xml`) and cover both the validator
@@ -224,6 +225,17 @@ Docker env and `make export-layouts`. Do not attempt to download it. If missing,
 
 ## Current state
 
+- **Pro add-on 0.5.0** (whole-site generation; branch `feat/pro-0-5-0`; NOT merged, NOT released, NOT pushed): the 14th and LAST Pro tool
+  `build_site` (16 free + 14 Pro = 30 of the importer's 30 operations: the budget is used up). `add_page` creates ONE validated DRAFT
+  page per call (invalid layout or taken slug: refused with the reasons, the build keeps its valid pages) and appends it to ONE growing
+  batch (status `building`; one open build per user; `BatchRepository::append`/`openBuild`, `BatchLog::open`/`append`/`close`; an open build is
+  never trimmed); `launch` (dry_run + `PreviewToken`) publishes, sets the flagged front page and rebuilds the live Main Menu, skipping
+  any page that is gone, changed since the preview, or whose slug was taken; `undo_batch` takes the whole build away (item kinds
+  `page_created` -> trash, inverse `page_trashed` -> untrash as a draft). Spike facts that shaped it (live, WP 7.1): a menu item
+  pointing at a draft shows a DEAD link to visitors (so the menu is built only at launch), drafts get no unique slug (clash resolved at
+  publish: refused at add_page), untrash returns a draft. Spec `docs/superpowers/specs/2026-10-03-build-site-design.md`; plan
+  `docs/superpowers/plans/2026-10-03-build-site.md`; `make test-pro` 841 tests, `make test` 727; live gates `make verify-pro-build-site`
+  and `make verify-pro-rest` (30 operations) passed on the Docker dev site.
 - **Pro add-on 0.4.1** (hardening; merged to LOCAL main da340e3; NOT released, NOT pushed): preview tokens are bound to the user,
   single-use (a transient marks a spent nonce) and 30 minutes for BOTH `find_replace_site` and `manage_theme_template`
   (`PreviewToken` v2); `audit_site` pages by the id of the last audited item (pages and layouts in one id order), memoises link
diff --git a/docs/EXTENDING.md b/docs/EXTENDING.md
index 33449bf..7bf18fb 100644
--- a/docs/EXTENDING.md
+++ b/docs/EXTENDING.md
@@ -66,7 +66,7 @@ different behaviour, it adds a new tool with its own name.
    capability) and OpenAPI (`jhmg_aied_openapi_paths` + `jhmg_aied_openapi_schemas`) should
    be generated from the same tool list, so they cannot drift. The ChatGPT importer
    accepts at most 30 operations in total, so budget them: the base plugin has 16 and
-   the Pro add-on 13 (29 of 30). Document the 400, 401, 403 and 500 answers of each
+   the Pro add-on 14 (30 of 30: the budget is used up). Document the 400, 401, 403 and 500 answers of each
    operation, and refuse a request body that is not a JSON object with a 400 instead of
    running with defaults.
 
diff --git a/pro-addon/README.md b/pro-addon/README.md
index af49438..a572bfb 100644
--- a/pro-addon/README.md
+++ b/pro-addon/README.md
@@ -4,22 +4,22 @@ The Pro add-on for the free WordPress.org plugin in `wp-plugin/`. It is a **sepa
 
 Spec: `docs/superpowers/specs/2026-10-02-pro-addon-design.md`. Plans: `docs/superpowers/plans/`.
 
-## What exists (0.4.1)
+## What exists (0.5.0)
 
 | Path | What it is |
 |---|---|
 | `jhmg-ai-editor-for-divi-5-pro.php` | main plugin file; boots on `plugins_loaded` 20 |
 | `src/Compat.php`, `src/Plugin.php` | only runs against a free plugin >= `Compat::MIN_FREE` whose classes exist; otherwise registers nothing and shows one admin notice |
 | `src/Licensing.php`, `src/Licensing/LicenseClient.php` | sticky-unlock licensing; the client is GENERATED by `scripts/sync-pro-license-client.sh` from the canonical divi5lab client (`../layoutlab`, read-only) and carries the updater |
-| `src/ProTool.php`, `src/ToolRegistry.php`, `src/Tools.php` | the tool contract and the licence-gated registry; `Tools::all()` lists the thirteen tools; `src/ToolRunner.php` is the one call path (capability check, argument errors, no leaked failures) shared by MCP and REST |
+| `src/ProTool.php`, `src/ToolRegistry.php`, `src/Tools.php` | the tool contract and the licence-gated registry; `Tools::all()` lists the fourteen tools; `src/ToolRunner.php` is the one call path (capability check, argument errors, no leaked failures) shared by MCP and REST |
 | `src/ProPage.php` | the "Pro" admin tab (licence entry, tool list) |
 | `src/Args.php`, `src/Canon.php` | strict argument readers; canonical hashes for the changed-since rule |
 | `src/Batch/` | the batch store (`{prefix}aied_pro_batches`, last 20), `BatchLog`, and the undo engine (`BatchUndo`, `ItemKind`/`ValueKind`) |
-| `src/Kinds/` | how each target is read and put back on undo: front page, Additional CSS block, menu, page status, page content, header/footer content, Theme Builder template |
+| `src/Kinds/` | how each target is read and put back on undo: front page, Additional CSS block, menu, page status, page created and trashed (the pages of a site build), page content, header/footer content, Theme Builder template |
 | `src/ThemeBuilder/` | the live Theme Builder read model (ThemeLayouts, LayoutTarget: which post a layout tool may touch), the one layout write path (LayoutWriter), Divi cache clearing, and TemplateStore (reads/writes one template through Divi's own store function) |
 | `src/Settings/` | `CustomCss` (managed block), `MenuBuilder`, `PhpProposals` |
-| `src/Tools/` | the thirteen tools: `set_front_page`, `set_primary_menu`, `publish_page`, `set_custom_css`, `propose_php_snippet`, `list_batches`, `undo_batch`, `list_theme_layouts`, `update_theme_layout`, `edit_theme_layout`, `manage_theme_template`, `find_replace_site`, `audit_site` |
-| `src/Site/` | what "the site" means for a site-wide tool (`SiteItems`: Divi 5 pages the user can edit and, with the Theme Builder permission, the live header/footer), the signed preview token (`PreviewToken`), and the internal-link reader (`LinkScanner`, no request is ever made) |
+| `src/Tools/` | the fourteen tools: `set_front_page`, `set_primary_menu`, `publish_page`, `set_custom_css`, `propose_php_snippet`, `list_batches`, `undo_batch`, `list_theme_layouts`, `update_theme_layout`, `edit_theme_layout`, `manage_theme_template`, `find_replace_site`, `audit_site`, `build_site` |
+| `src/Site/` | what "the site" means for a site-wide tool (`SiteItems`: Divi 5 pages the user can edit and, with the Theme Builder permission, the live header/footer), the signed preview token (`PreviewToken`), the launch of a site build (`BuildLaunch`) and its per-user add_page lock (`BuildLock`), and the internal-link reader (`LinkScanner`, no request is ever made) |
 | `src/Rest/` | the REST and ChatGPT/OpenAPI faces of every Pro tool (`ProRest`: `POST /pro/<tool>`; `ProOpenApi`), generated from the tool list so MCP, REST and the spec cannot drift |
 | `src/Admin/` | the "Recent Pro changes" and "Code proposals" panels on the Pro tab |
 | `readme.txt` | the add-on's own readme; discloses the divi5lab.com calls (shipped in the zip) |
@@ -27,18 +27,26 @@ Spec: `docs/superpowers/specs/2026-10-02-pro-addon-design.md`. Plans: `docs/supe
 | `tests/` | the Pro suite: `make test-pro` |
 | `staged-4.3.0/` | the 4.3.0 header/footer editing removed from the free plugin (copies; see its note below) |
 
-`make test-pro` runs the Pro tests; `make build-pro` builds `dist/jhmg-ai-editor-for-divi-5-pro.zip` (git-ignored); `make verify-pro-skeleton`, `make verify-pro-settings`, `make verify-pro-theme-layouts`, `make verify-pro-theme-templates`, `make verify-pro-site-tools` and `make verify-pro-rest` are the real-site checks (activate the free plugin and the add-on on the Docker site first).
+`make test-pro` runs the Pro tests; `make build-pro` builds `dist/jhmg-ai-editor-for-divi-5-pro.zip` (git-ignored); `make verify-pro-skeleton`, `make verify-pro-settings`, `make verify-pro-theme-layouts`, `make verify-pro-theme-templates`, `make verify-pro-site-tools`, `make verify-pro-build-site` and `make verify-pro-rest` are the real-site checks (activate the free plugin and the add-on on the Docker site first).
 
 ## Preview tokens
 
-`find_replace_site` and `manage_theme_template` need the token of a dry run of the same arguments (`src/Site/PreviewToken.php`): signed, bound to the arguments, the items' content hashes (or the template's state) and the user who previewed, valid 30 minutes, and spent by the first real run, so an `undo_batch` that puts the previewed content back cannot be followed by a replay. The spent mark is a transient: an object cache that evicts it can make a spent token usable again inside its 30 minutes, for the same user and the same arguments only. A content change since the preview is still caught (the changed-since check), but a replay after an `undo_batch` that restored the previewed content is not; every capability check and `LayoutGate` still run on a replay.
+`find_replace_site`, `manage_theme_template` and the launch of `build_site` need the token of a dry run of the same arguments (`src/Site/PreviewToken.php`): signed, bound to the arguments, the items' content hashes (or the template's state) and the user who previewed, valid 30 minutes, and spent by the first real run, so an `undo_batch` that puts the previewed content back cannot be followed by a replay. The spent mark is a transient: an object cache that evicts it can make a spent token usable again inside its 30 minutes, for the same user and the same arguments only. A content change since the preview is still caught (the changed-since check), but a replay after an `undo_batch` that restored the previewed content is not; every capability check and `LayoutGate` still run on a replay.
+
+## Site builds (`build_site`)
+
+A build is ONE batch that grows. `add_page` validates one page (`LayoutGate`), refuses a taken slug, creates it as a draft and appends a `page_created` item; the first page opens the build (one open build per user; the first call returns its `build_id`). `launch` is the only step that changes what visitors see: a previewed, single-use-token step that publishes the pages, sets the page flagged `is_front` and rebuilds the "Main Menu" from the published `in_menu` pages, appending those items to the same batch. `undo_batch` walks it back in reverse (menu, front page, statuses, then the created pages are trashed unless they changed since); it also abandons an open build. `build_site` needs `publish_pages`; `launch` additionally needs `edit_theme_options` for the menu and `manage_options` for the front page.
 
 ## Known limits
 
+- WordPress gives draft pages no unique slug and resolves a clash only when a page is published, so `build_site` refuses a taken slug at `add_page`, re-checks at launch (`slug_taken` skips the page) and reports a slug core still changed at publish (`slug_changed`). A menu that points at a draft page shows a dead link to visitors, which is why the menu is only rebuilt at launch.
+- With `EMPTY_TRASH_DAYS` set to 0 undoing a build deletes its pages permanently (the undo of that undo is then recorded as unavailable).
+- `undo_batch` of an open build while an `add_page` is running can drop the page that call was adding: the build lock covers `add_page` only.
+
 - The audit's link check cannot tell a missing page from an archive or custom route (the finding says so), and reads at most 200 links per item (`LINKS_NOT_ALL_CHECKED`).
 - On a site with more than 1000 Divi 5 pages `audit_site` audits the first 1000 (by id) and says so, and `find_replace_site` refuses and asks for `page_ids`.
-- The ChatGPT importer allows 30 operations in total: 16 free + 13 Pro = 29, so a 14th Pro operation needs an explicit decision.
-- The `verify-pro-*` scripts create and delete throwaway content (`verify-pro-rest` is read-only): all six refuse to run unless the site host is localhost, 127.0.0.1, ::1, `*.local`, `*.test` or `*.localhost` (`scripts/dev-guard.php`).
+- The ChatGPT importer allows 30 operations in total: 16 free + 14 Pro = 30, so the budget is used up: a 15th Pro operation means merging two tools.
+- The `verify-pro-*` scripts create and delete throwaway content (`verify-pro-rest` is read-only): all seven refuse to run unless the site host is localhost, 127.0.0.1, ::1, `*.local`, `*.test` or `*.localhost` (`scripts/dev-guard.php`).
 
 ## Dev override of the licence server
 
diff --git a/pro-addon/jhmg-ai-editor-for-divi-5-pro.php b/pro-addon/jhmg-ai-editor-for-divi-5-pro.php
index 6bc7e99..cc434e7 100644
--- a/pro-addon/jhmg-ai-editor-for-divi-5-pro.php
+++ b/pro-addon/jhmg-ai-editor-for-divi-5-pro.php
@@ -1,8 +1,8 @@
 <?php
 /**
  * Plugin Name:       JHMG AI Editor for Divi 5 Pro
- * Description:       Pro add-on for JHMG AI Editor for Divi 5: set the front page and menu, publish or schedule pages, add managed custom CSS, edit the live Divi header and footer, find and replace text across your pages in one undoable batch, audit pages for layout problems and broken links, file PHP snippets for human review, and undo each Pro change in one step. Requires the free plugin.
- * Version:           0.4.1
+ * Description:       Pro add-on for JHMG AI Editor for Divi 5: set the front page and menu, publish or schedule pages, add managed custom CSS, edit the live Divi header and footer, find and replace text across your pages in one undoable batch, audit pages for layout problems and broken links, build a whole site page by page and launch it in one undoable step, file PHP snippets for human review, and undo each Pro change in one step. Requires the free plugin.
+ * Version:           0.5.0
  * Requires at least: 6.0
  * Requires PHP:      8.1
  * Author:            JHMG
@@ -19,7 +19,7 @@ if ( ! defined( 'ABSPATH' ) ) {
     exit;
 }
 
-define( 'AIED_PRO_VERSION', '0.4.1' );
+define( 'AIED_PRO_VERSION', '0.5.0' );
 define( 'AIED_PRO_FILE', __FILE__ );
 define( 'AIED_PRO_PRODUCT', 'ai-editor-divi5-pro' );
 define( 'AIED_PRO_MIN_PHP', '8.1' );
diff --git a/pro-addon/readme.txt b/pro-addon/readme.txt
index d275cbe..d237171 100644
--- a/pro-addon/readme.txt
+++ b/pro-addon/readme.txt
@@ -2,11 +2,11 @@
 Contributors:      lucaslopvet
 Requires at least: 6.0
 Requires PHP:      8.1
-Stable tag:        0.4.1
+Stable tag:        0.5.0
 License:           GPLv2 or later
 License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 
-Pro add-on for JHMG AI Editor for Divi 5. Lets your AI assistant set the front page and menu, publish pages, add CSS, edit the live Divi header and footer, find and replace text across your pages and audit them for layout problems and broken links, and lets you undo each change in one step.
+Pro add-on for JHMG AI Editor for Divi 5. Lets your AI assistant set the front page and menu, publish pages, add CSS, edit the live Divi header and footer, find and replace text across your pages, audit them for layout problems and broken links, build a whole site page by page and launch it in one undoable step, and lets you undo each change in one step.
 
 == Description ==
 
@@ -52,6 +52,11 @@ Deleting the plugin removes its own data (licence state, batch history, saved co
 
 == Changelog ==
 
+= 0.5.0 =
+* New: `build_site` builds a whole Divi 5 site as one undoable build. `add_page` creates one checked draft page per call: a page that fails the layout checks, or whose slug another page already uses, is refused with the exact reason and the pages already added are kept. `status` lists the build. `launch` publishes the pages, sets the front page and rebuilds the primary menu in one step, after a preview (`dry_run`) and with its single-use token; pages that changed or lost their slug since the preview are skipped and the rest goes live. Nothing is visible on the live site before the launch.
+* One `undo_batch` takes the whole build away again (the menu and the front page come back, the pages go to the trash); a page you edited in the Divi builder afterwards is skipped and reported, never trashed behind your back. An unfinished build can be abandoned the same way.
+* `build_site` uses the last of the 30 operations the ChatGPT importer accepts (16 free + 14 Pro).
+
 = 0.4.1 =
 * Safer previews: the token a preview returns now only works for the person who made it, only once, and only within 30 minutes. A preview of a Theme Builder template change has the same rules (it used to have no time limit).
 * `audit_site` continues by page id, so pages added or deleted between two calls can no longer make it skip or repeat a page; it looks up each distinct link once per call, and it no longer says whether a draft or private post you cannot read is a draft or private.
`````

Run: `php "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator/.superpowers/sdd/extract-plan.php" docs/superpowers/plans/2026-10-03-build-site.md T7-src` → `applied: T7-src`.

- [ ] **Step 4: Run the suites and watch them pass**

Run: `make test-pro 2>&1 | tail -3` → `OK (841 tests, 18711 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -q -F - <<'EOF'
docs(pro): 0.5.0 - version, changelog, README site builds and limits, EXTENDING budget, CLAUDE.md state

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B | tail -2
```

### Task 8: Live proof on the real dev site

**What:** The live gate `make verify-pro-build-site` and the existing gates that touch the batch store and the OpenAPI budget are run through the real registry on the Docker site with Divi 5.14, with the Pro add-on activated only for the runs. They create and delete their own throwaway content and restore every setting. No code changes in this task unless a SCRIPT defect shows.

**Files:** none (the scripts came with Tasks 6 and 7).

**Interfaces:** None.

- [ ] **Step 1: Activate the Pro add-on for the runs**

```bash
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin activate jhmg-ai-editor-for-divi-5-pro
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin list --fields=name,status | grep jhmg
```
Expected: both `jhmg-ai-editor-for-divi-5` and `jhmg-ai-editor-for-divi-5-pro` are `active`.

- [ ] **Step 2: Run the gates**

```bash
make verify-pro-build-site 2>&1 | tail -50
make verify-pro-rest 2>&1 | tail -30
make verify-pro-settings 2>&1 | tail -40
make verify-pro-site-tools 2>&1 | tail -60
```
Expected: no line starts with `FAIL`, and each run ends with its success line: `Success: build_site verified on the real site: build, launch, anonymous menu, undo, abandon, edited page, taken slug.`, `Success: Pro REST and OpenAPI faces verified on the real REST server.`, `Success: The Pro settings tools and batch undo are verified on the real site.`, `Success: Pro site-wide tools verified through the real registry.` The build-site run has 35 `PASS` lines; the REST run includes `the live spec has one /pro/ operation for each of the 14 Pro tools` and `the live spec has 30 operations (limit 30)`. If a gate fails because of a SCRIPT defect, fix the script, say exactly what you changed, and rerun; if it fails because of the PRODUCT code, stop and report.

- [ ] **Step 3: Deactivate the Pro add-on and confirm**

```bash
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin deactivate jhmg-ai-editor-for-divi-5-pro
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin list --fields=name,status | grep jhmg
```
Expected: the free plugin `active`, the Pro add-on `inactive`.

- [ ] **Step 4: Commit only if a script was fixed**

If Step 2 needed a script fix: `git add -A && git commit` with a message `test(pro): <what was fixed in which live script>` and the trailer above. Otherwise there is nothing to commit; `git status --short` prints nothing.

### Task 9: Final checks

- [ ] **Step 1: Both suites**

Run: `make test-pro 2>&1 | tail -3` → `OK (841 tests, 18711 assertions)`; `make test 2>&1 | tail -3` → `OK (727 tests, 5241 assertions)`.

- [ ] **Step 2: Every number written in the docs is the real one**

Run: `grep -n "tests" CLAUDE.md | grep -n "0.5.0\|841\|839\|build_site"` and compare with the real counts of Step 1 and with `make verify-pro-build-site` (35 PASS). The 0.5.0 bullet in `CLAUDE.md` must state the real `make test-pro` and `make test` numbers; if a review fix round changed the count, correct it in a docs commit. Expected: the bullet says `841 tests` and `727`.

- [ ] **Step 3: The zip builds and ships no tests or scripts**

```bash
make build-pro 2>&1 | tail -2
unzip -l dist/jhmg-ai-editor-for-divi-5-pro.zip | grep -E "tests|scripts|\.md|staged" ; echo "exit=$?"
unzip -p dist/jhmg-ai-editor-for-divi-5-pro.zip jhmg-ai-editor-for-divi-5-pro/jhmg-ai-editor-for-divi-5-pro.php | grep -E " Version:"
unzip -l dist/jhmg-ai-editor-for-divi-5-pro.zip | grep -E "BuildSite|BuildLaunch|BuildLock|PageCreatedKind|PageTrashedKind"
```
Expected: `[build-pro] Packaging jhmg-ai-editor-for-divi-5-pro version 0.5.0`, the first `grep` prints nothing and `exit=1`, ` * Version:           0.5.0`, and the last `grep` lists the five new source files.

- [ ] **Step 4: Working tree and branch state**

```bash
git status --short && git log --oneline main..HEAD
```
Expected: no `git status` output; seven commits (Tasks 1 to 7) plus any fix commits on `feat/pro-0-5-0`. Do not merge: the final whole-branch review (opus) comes first, then the owner decides.

## As built (2026-10-03)

Executed by subagent-driven development on `feat/pro-0-5-0` (merged to LOCAL main 749dc86; not released, not pushed). The plan's code was replayed byte for byte; review rounds then changed it (numbers in the tasks above are plan-time, the real ones are Pro 875 tests, main 727, `make verify-pro-build-site` 44 PASS, `make verify-pro-rest` 30 operations).

Changes made by review fix rounds (each was prototyped test-first and applied from exact patches):
- Task 2: `PageCreatedKind::current()` requires a draft page of type `page`; untrash of a vanished page reports it.
- Task 3: `BuildLock` is atomic on the database (core's `add_option` is not); `append` only lands on an open build; slug length and id disclosure rules.
- Task 4: `guard_in_use` (undo never unpublishes a page the live menu or front page still points at), real status on published lines, preview `front_page.from`.
- Task 5: `launch` takes the lock BEFORE it reads the build; a throwing menu step is reported (`menu_error`) instead of a generic 500; a failed record stops the front page and menu; honest notes for partial records.
- Final wave (whole-branch opus review): a real `undo_batch` always closes an open build; `force` never trashes a page that is no longer a draft nor deletes an edited one when `EMPTY_TRASH_DAYS` is 0; `add_page` refuses `in_menu`/`is_front` the user could never launch (default `in_menu` follows `edit_theme_options`); the launch re-reads each page right before publishing; a slug WordPress changed at publish is written into the build record so undo still works; user-facing exception texts; uninstall removes leftover build-lock rows; the live gate also proves the real-database lock, an open build surviving the trim and an all-edited open build being closed.

Accepted limits (README "Known limits"): `undo_batch` takes no build lock (and `BatchLog::close` is a read-modify-write), an abandoned build never expires, a call longer than the 15 s lock expiry can overlap the next, a menu location repointed after the preview is not detected as stale, a throw inside `MenuBuilder` after it started changing the menu is not recorded, a forced undo preview is optimistic about pages the real run refuses, and the slug rehash is saved only when the build closes.
