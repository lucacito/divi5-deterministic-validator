# Pro Add-on 0.2.0: Licence Hardening, Batches & Undo, Settings Tools — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Take the Pro add-on from the 0.1.0 skeleton to 0.2.0: fix the licence blockers found in the final review, add the batch store with honest one-step undo, and ship the first seven Pro tools (`set_front_page`, `set_primary_menu`, `publish_page`, `set_custom_css`, `propose_php_snippet`, `list_batches`, `undo_batch`) plus the admin panels that go with them.

**Architecture:** Every Pro write is recorded as a *batch* of *items* in the add-on's own table `{prefix}aied_pro_batches`. An item stores what the target looked like before, a hash of what the batch wrote, and an undo point. One generic engine (`BatchUndo`) restores items through small per-target adapters (`ItemKind`), skipping and reporting any item that was changed since (the "changed-since rule") and any item with no undo point. Tools are `ProTool` classes registered through the existing licence-gated `ToolRegistry`; the free plugin is touched only through its documented `jhmg_aied_*` hooks and a few pinned classes.

**Tech Stack:** PHP 8.1+, PHPUnit (the Pro suite: `make test-pro`; WordPress shims in `tests/bootstrap.php` and `pro-addon/tests/`), WordPress core functions, the free plugin's `EditTarget`/`HistoryService` classes. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-10-02-pro-addon-design.md` (sections "v1 tools", "Batches and undo", "Licensing and distribution", "Verification", "Risks"). Build-order step 2 of the spec, plus the release blockers listed under "Pro add-on skeleton 0.1.0" in `CLAUDE.md`.

**Baseline:** `main` at `eca9aa7`; `./vendor/bin/phpunit -c pro-addon/phpunit.xml` is green with 121 tests before Task 1. Work on a branch `feat/pro-settings-batches` (use superpowers:using-git-worktrees or `git switch -c`).

**Not in this plan (later plans):** Theme Builder tools (Plan 4), `find_replace_site` and `audit_site` (Plan 5), REST routes and OpenAPI entries for the Pro tools (Plan 5, "REST/OpenAPI parity"). The Pro tools here are MCP + admin only. `PostContentKind` (Task 13) is built now because the undo engine's contract needs it, but no tool in this plan writes page content.

## Global Constraints

Copied from the spec; every task implicitly includes them.

- **The validator stays deterministic and untouched.** The add-on adds no schema knowledge and no AI call. Every write that saves Divi layout content is validated first. (This plan saves no layout content; `PostContentKind` only restores a person's own earlier content through the free `HistoryService::restore`.)
- **No Divi schema from memory.** Anything that depends on a Divi attribute shape or Divi storage must trace to a real export or a live check on the real Divi.
- **Extension contract.** The add-on attaches to the free plugin through the `jhmg_aied_*` hooks only (`docs/EXTENDING.md`). It cannot override, remove or edit a built-in tool (`ExtensionGuard`). It checks capabilities itself.
- **No PHP execution, ever.** `propose_php_snippet` stores a proposal for a human to review; nothing runs it. The add-on never writes users, plugins or Divi presets/colors/variables.
- **Nothing licence-gated, remote-fetching or custom-code-saving may enter `wp-plugin/`.** Nothing in this plan touches `wp-plugin/`.
- Every tool checks its own capability, type-checks arguments (non-strings and non-booleans are rejected, never cast), and returns the built-in response shapes.
- Namespace `AiEditorDivi5\Pro`, constants `AIED_PRO_*`, option and table prefix `aied_pro`, text domain `jhmg-ai-editor-for-divi-5-pro`.
- Remote calls: `divi5lab.com` only, for licence and updates (key, site URL, product); daily cache, 3-day grace; disclosed in the add-on's own readme.
- Unlock: first successful activation unlocks Pro persistently. Only an explicit server verdict of `revoked` or `invalid` re-locks. Lapsed, expired or offline never locks.
- ChatGPT importer budget: free (16 tools) + Pro ≤ 29 operations. This plan brings Pro to 7 tools (23 of 29).
- **Undo is honest.** It restores every item it can and reports the rest (`changed_since`, `no_undo_point`, `forbidden`, `failed`). It claims "fully undone" only when every item restored. A strict boolean `force` overrides only the changed-since rule.
- Last 20 batches kept; oldest trimmed first.

## Review Focus

Failure modes the spec implies but a happy-path test would miss, most likely first. Each has a test in the task named in brackets.

1. **A licence-server fault (an HTML 403/404 from a proxy, an empty or non-JSON body, `invalid_request`) must never lock a paying customer's tools**, and must leave the stored licence state untouched. [Task 1]
2. **CSS that contains `$1`, `\0`, `</style>` or the managed-block marker text must not corrupt or break out of the managed block**, and removing the block must leave the person's own CSS byte-for-byte. (The staged `CustomCss` used `preg_replace` with the CSS as the replacement string, so `$1` and `\0` were read as back-references.) [Task 9]
3. **Undo after the person edited the thing later must skip and report, never overwrite**; an undo with a missing snapshot must say `no_undo_point` and never say "fully undone". [Task 7, Task 8-12 per tool]
4. **A write whose batch cannot be recorded** (table missing, host refused `CREATE TABLE`, DB insert failed) **still reports the write honestly** with `batch.undoable: false`; it must neither hide that the change was made nor claim an undo exists. [Task 6, Task 8]
5. **A user without `manage_options` must not list or undo another user's batch**, and per-item capabilities (`edit_post`, `edit_theme_options`, `edit_css`, `publish_pages`) are re-checked at undo time. [Task 14]

## File Structure

Create (all under `pro-addon/`):

| Path | Responsibility |
|---|---|
| `src/Args.php` | strict argument readers (`int`, `string`, `bool`, `listOf`) |
| `src/Canon.php` | canonical JSON + sha256 for the changed-since hashes |
| `src/Batch/BatchRepository.php` | interface: the five storage operations the batch log needs |
| `src/Batch/BatchTable.php` | table name, schema, create-on-demand with backoff, drop |
| `src/Batch/WpdbBatchRepository.php` | the only code that queries the batch table |
| `src/Batch/BatchLog.php` | record/find/recent/markUndone over a repository; trims to 20; `describe`/`attach` result shapes |
| `src/Batch/Item.php` | builds an item array |
| `src/Batch/ItemKind.php`, `src/Batch/ValueKind.php` | per-target adapter interface + the simple "write a value back" base |
| `src/Batch/BatchUndo.php` | the generic undo engine |
| `src/Batch/Batches.php` | shared factory: the log, the kind registry, the engine |
| `src/Kinds/FrontPageKind.php`, `CssKind.php`, `MenuKind.php`, `PostStatusKind.php`, `PostContentKind.php` | the five item kinds |
| `src/Settings/CustomCss.php`, `MenuBuilder.php`, `PhpProposals.php` | the pure/WordPress-facing helpers behind the tools |
| `src/Tools/SetFrontPage.php`, `SetCustomCss.php`, `SetPrimaryMenu.php`, `PublishPage.php`, `ProposePhpSnippet.php`, `ListBatches.php`, `UndoBatch.php` | the seven tools |
| `src/Admin/AdminActions.php`, `BatchesPanel.php`, `ProposalsPanel.php` | admin-post helpers and the two panels on the Pro tab |
| `readme.txt` | the add-on's own readme (discloses the remote calls) |
| `tests/WpSettingsShims.php`, `tests/InMemoryBatchRepository.php` | test doubles |
| `scripts/verify-pro-settings.php` (repo root `scripts/`) | real-site self-cleaning check |

Modify: `src/Licensing.php`, `src/ProPage.php`, `src/Plugin.php`, `src/Tools.php`, `src/autoload.php`, `uninstall.php`, `jhmg-ai-editor-for-divi-5-pro.php`, `tests/bootstrap.php`, root `tests/bootstrap.php` (two shim lines), `scripts/build-pro-zip.sh`, `Makefile`, `pro-addon/README.md`, `CLAUDE.md`.

---

## Task 1: A server fault never locks the tools

**Why:** The canonical `LicenseClient::refresh()` files any non-200, non-429, non-5xx answer as `status = body.status ?? 'invalid'`. An HTML 403 from a proxy therefore stores `invalid`, and `Licensing::isPremium()` locks. The client is generated (never edit it), so the fix is in the adapter: capture the raw validate response, and if the new state is locking but the answer was not an explicit verdict, put the previous state back.

**Files:**
- Modify: `tests/bootstrap.php` (root; the `__wp_http_next` shim)
- Modify: `pro-addon/tests/bootstrap.php` (add `remove_filter`, `wp_doing_ajax`, `wp_doing_cron`, `wp_get_environment_type`)
- Modify: `pro-addon/src/Licensing.php`
- Test: `pro-addon/tests/LicensingTest.php`

**Interfaces:**
- Consumes: `LicenseClient::get_key(): ?string`, `get_state(): ?array`, `refresh(bool): void` (generated, unchanged).
- Produces: `Licensing::refresh(bool $force = false): void` (same signature; new behaviour). Constant `Licensing::STATE_OPTION = 'aied_pro_license_state'` is private.

- [ ] **Step 1: Teach the shared HTTP shim to send raw bodies and run the `http_response` filter**

In `tests/bootstrap.php`, replace the two lines at the end of `__wp_http_next`:

```php
        if ( $next === null || $next === 'network_error' ) return new WP_Error();
        return [ 'response' => [ 'code' => $next['code'] ], 'body' => json_encode( $next['body'] ) ];
```

with:

```php
        if ( $next === null || $next === 'network_error' ) return new WP_Error();
        // A string body is sent as-is (an HTML error page, an empty body); an array is JSON-encoded.
        $res = [ 'response' => [ 'code' => $next['code'] ], 'body' => is_string( $next['body'] ) ? $next['body'] : json_encode( $next['body'] ) ];
        return apply_filters( 'http_response', $res, [], $url );
```

- [ ] **Step 2: Add the four shims the Pro suite needs**

Append to `pro-addon/tests/bootstrap.php`:

```php
if ( ! function_exists( 'remove_filter' ) ) {
    function remove_filter( $tag, $fn, $priority = 10 ) {
        foreach ( $GLOBALS['__wp_filters'][ $tag ] ?? [] as $i => $f ) {
            if ( $f === $fn ) {
                unset( $GLOBALS['__wp_filters'][ $tag ][ $i ] );

                return true;
            }
        }

        return false;
    }
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
    function wp_doing_ajax(): bool { return (bool) ( $GLOBALS['__wp_doing_ajax'] ?? false ); }
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
    function wp_doing_cron(): bool { return (bool) ( $GLOBALS['__wp_doing_cron'] ?? false ); }
}
if ( ! function_exists( 'wp_get_environment_type' ) ) {
    function wp_get_environment_type(): string { return (string) ( $GLOBALS['__wp_env_type'] ?? 'production' ); }
}
```

Run `make test` (the free suite) and `make test-pro`; both must still pass (121 Pro tests).

- [ ] **Step 3: Write the failing tests**

In `pro-addon/tests/LicensingTest.php`, change the `queue()` helper's signature to accept a raw string body:

```php
    private function queue( int $code, array|string $body ): void
    {
        $GLOBALS['__wp_http_queue'][] = [ 'code' => $code, 'body' => $body ];
    }
```

Add these methods after `testReactivationAfterRevokeUnlocksAgain`:

```php
    /** @return array<string,array{int,array|string}> */
    public static function serverFaults(): array
    {
        return [
            'html 403 from a proxy'                      => [ 403, '<html><body>Forbidden</body></html>' ],
            'html 404 from a broken deploy'              => [ 404, '<h1>Not Found</h1>' ],
            'empty 404'                                  => [ 404, '' ],
            'empty json 404'                             => [ 404, [] ],
            'invalid_request is our fault, not a verdict' => [ 400, [ 'error' => 'invalid_request' ] ],
            '403 with an unknown error code'             => [ 403, [ 'error' => 'teapot' ] ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'serverFaults' )]
    public function testAServerFaultNeverLocksAndLeavesTheStoredStateAlone( int $code, array|string $body ): void
    {
        $this->activatePremium();
        $this->ageState();
        $stored = $GLOBALS['__wp_options']['aied_pro_license_state'];
        $this->queue( $code, $body );
        Licensing::refresh();
        $this->assertTrue( Licensing::isPremium(), 'a fault is not a verdict' );
        $this->assertSame( $stored, $GLOBALS['__wp_options']['aied_pro_license_state'], 'the stored state is exactly what it was' );
    }

    public function testAnExplicit200RevokedVerdictLocks(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 200, [ 'status' => 'revoked', 'expires' => null ] );
        Licensing::refresh();
        $this->assertFalse( Licensing::isPremium() );
    }

    public function testProductMismatchIsAnExplicitVerdictAndLocks(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 403, [ 'error' => 'product_mismatch' ] );
        Licensing::refresh();
        $this->assertFalse( Licensing::isPremium() );
    }

    public function testAFaultDoesNotUndoAnEarlierExplicitRevocation(): void
    {
        $this->testRevokedVerdictLocksPremium();
        $this->ageState();
        $this->queue( 403, '<html>Forbidden</html>' );
        Licensing::refresh();
        $this->assertFalse( Licensing::isPremium(), 'a fault changes nothing, in either direction' );
    }
```

- [ ] **Step 4: Run to verify the new tests fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter LicensingTest`
Expected: the `serverFaults` cases FAIL (`isPremium()` is false after the fault).

- [ ] **Step 5: Implement the explicit-verdict rule**

In `pro-addon/src/Licensing.php`, add constants after `LOCKING`:

```php
    private const STATE_OPTION   = 'aied_pro_license_state';
    /** Answers that are a decision about this licence. Anything else (an HTML error page, an empty or non-JSON body, `invalid_request`) is a fault. */
    private const VERDICT_ERRORS = [ 'invalid_key', 'product_mismatch', 'license_not_usable' ];
```

Replace the `refresh()` method with:

```php
    public static function refresh( bool $force = false ): void
    {
        $client = self::client();
        if ( null === $client->get_key() ) {
            return;
        }
        $before  = $client->get_state();
        $seen    = null;
        $capture = static function ( $response, $args = [], $url = '' ) use ( &$seen ) {
            if ( is_array( $response ) && is_string( $url ) && str_contains( $url, '/api/license/validate' ) ) {
                $seen = [ 'code' => (int) wp_remote_retrieve_response_code( $response ), 'body' => (string) wp_remote_retrieve_body( $response ) ];
            }

            return $response;
        };
        add_filter( 'http_response', $capture, 10, 3 );
        try {
            $client->refresh( $force );
        } finally {
            remove_filter( 'http_response', $capture, 10 );
        }

        // The generated client files any definitive-looking non-200 answer as `invalid`. Only an answer that
        // really is a decision about this licence may keep that; after a fault the previous state comes back.
        $after = $client->get_state();
        if ( in_array( $after['status'] ?? '', self::LOCKING, true ) && ! self::isVerdict( $seen ) ) {
            if ( null === $before ) {
                delete_option( self::STATE_OPTION );
            } else {
                update_option( self::STATE_OPTION, $before, false );
            }
        }
    }

    /** @param array{code:int, body:string}|null $seen the raw validate answer */
    private static function isVerdict( ?array $seen ): bool
    {
        if ( null === $seen ) {
            return false;
        }
        $body = json_decode( $seen['body'], true );
        if ( ! is_array( $body ) ) {
            return false;
        }
        if ( 200 === $seen['code'] ) {
            return is_string( $body['status'] ?? null ) && in_array( $body['status'], self::LOCKING, true );
        }
        if ( ! in_array( $seen['code'], [ 403, 404 ], true ) ) {
            return false;
        }

        return is_string( $body['error'] ?? null ) && in_array( $body['error'], self::VERDICT_ERRORS, true );
    }
```

- [ ] **Step 6: Run the suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: all green (the previous matrix tests `testRevokedVerdictLocksPremium`, `testInvalidKeyVerdictLocksPremium` still lock: their answers are `403 license_not_usable` and `404 invalid_key`, both explicit).

- [ ] **Step 7: Commit**

```bash
git add tests/bootstrap.php pro-addon/tests/bootstrap.php pro-addon/src/Licensing.php pro-addon/tests/LicensingTest.php
git commit -m "fix(pro): only an explicit server verdict can lock the Pro tools (a fault keeps the stored licence state)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 2: The licence refresh stops retrying every request

**Why:** `admin_init` calls `Licensing::refresh()`. The client does not bump `checked_at` after a network error, 429 or 5xx, so during a licence-server outage every admin page load, every `admin-ajax.php` call and every cron run waits up to 10 seconds for a new request.

**Files:**
- Modify: `pro-addon/src/Licensing.php`
- Test: `pro-addon/tests/LicensingTest.php`

**Interfaces:**
- Produces: `Licensing::REFRESH_BACKOFF_TRANSIENT = 'aied_pro_refresh_backoff'`, `Licensing::REFRESH_BACKOFF_SECONDS = 900` (public constants). `Licensing::refresh(bool $force = false)` skips when not forced and: it is an ajax or cron request, or the backoff is active, or the cached state is younger than one day. Having no key never makes a request.

- [ ] **Step 1: Write the failing tests**

Add to `LicensingTest.php`:

```php
    public function testNoKeyMeansNoRemoteCallEvenWhenForced(): void
    {
        Licensing::refresh( true );
        $this->assertSame( [], $GLOBALS['__wp_http_log'] );
    }

    public function testAFreshStateIsNotRefreshedAgain(): void
    {
        $this->activatePremium();
        $GLOBALS['__wp_http_log'] = [];
        Licensing::refresh();
        $this->assertSame( [], $GLOBALS['__wp_http_log'], 'the client cache window is one day' );
    }

    public function testAnAjaxOrCronRequestNeverRefreshes(): void
    {
        $this->activatePremium();
        $this->ageState();
        $GLOBALS['__wp_http_log'] = [];
        $GLOBALS['__wp_doing_ajax'] = true;
        Licensing::refresh();
        $GLOBALS['__wp_doing_ajax'] = false;
        $GLOBALS['__wp_doing_cron'] = true;
        Licensing::refresh();
        $GLOBALS['__wp_doing_cron'] = false;
        $this->assertSame( [], $GLOBALS['__wp_http_log'] );
    }

    public function testAFaultStartsABackoffSoTheNextLoadsDoNotRetry(): void
    {
        $this->activatePremium();
        $this->ageState();
        $GLOBALS['__wp_http_log'] = [];
        $this->queue( 503, [] );
        Licensing::refresh();
        $this->assertCount( 1, $GLOBALS['__wp_http_log'] );
        $this->assertArrayHasKey( Licensing::REFRESH_BACKOFF_TRANSIENT, $GLOBALS['__wp_transients'] );
        Licensing::refresh();
        Licensing::refresh();
        $this->assertCount( 1, $GLOBALS['__wp_http_log'], 'backed off: no further request' );
        $this->assertTrue( Licensing::isPremium() );
    }

    public function testANetworkErrorAlsoBacksOff(): void
    {
        $this->activatePremium();
        $this->ageState();
        $GLOBALS['__wp_http_log']     = [];
        $GLOBALS['__wp_http_queue'][] = 'network_error';
        Licensing::refresh();
        Licensing::refresh();
        $this->assertCount( 1, $GLOBALS['__wp_http_log'] );
    }

    public function testAnExpiredBackoffRetriesAndASuccessClearsIt(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 503, [] );
        Licensing::refresh();
        $GLOBALS['__wp_transients'] = [];   // the backoff has expired
        $GLOBALS['__wp_http_log']   = [];
        $this->queue( 200, [ 'status' => 'active', 'expires' => null ] );
        Licensing::refresh();
        $this->assertCount( 1, $GLOBALS['__wp_http_log'] );
        $this->assertArrayNotHasKey( Licensing::REFRESH_BACKOFF_TRANSIENT, $GLOBALS['__wp_transients'] );
    }

    public function testForceBypassesTheBackoffAndTheAgeCheck(): void
    {
        $this->activatePremium();
        $this->ageState();
        $this->queue( 503, [] );
        Licensing::refresh();
        $GLOBALS['__wp_http_log'] = [];
        $this->queue( 200, [ 'status' => 'active', 'expires' => null ] );
        Licensing::refresh( true );
        $this->assertCount( 1, $GLOBALS['__wp_http_log'] );
    }

    public function testAFaultThatIsRevertedAlsoBacksOff(): void
    {
        $this->activatePremium();
        $this->ageState();
        $GLOBALS['__wp_http_log'] = [];
        $this->queue( 403, '<html>Forbidden</html>' );
        Licensing::refresh();
        Licensing::refresh();
        $this->assertCount( 1, $GLOBALS['__wp_http_log'] );
    }

    public function testClearAlsoRemovesTheBackoff(): void
    {
        set_transient( Licensing::REFRESH_BACKOFF_TRANSIENT, 1, 900 );
        Licensing::clear();
        $this->assertArrayNotHasKey( Licensing::REFRESH_BACKOFF_TRANSIENT, $GLOBALS['__wp_transients'] );
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter LicensingTest`
Expected: FAIL (`REFRESH_BACKOFF_TRANSIENT` undefined; ajax/cron/backoff cases make extra requests).

- [ ] **Step 3: Implement the guard**

In `Licensing.php` add the public constants after `UPGRADE_URL`:

```php
    public const REFRESH_BACKOFF_TRANSIENT = 'aied_pro_refresh_backoff';
    public const REFRESH_BACKOFF_SECONDS   = 900;
```

Replace `refresh()` with the final version (Task 1's body plus the guard and the backoff bookkeeping):

```php
    public static function refresh( bool $force = false ): void
    {
        $client = self::client();
        if ( null === $client->get_key() ) {
            return;
        }
        $before = $client->get_state();
        if ( ! $force && ! self::refreshDue( $before ) ) {
            return;
        }
        $seen    = null;
        $capture = static function ( $response, $args = [], $url = '' ) use ( &$seen ) {
            if ( is_array( $response ) && is_string( $url ) && str_contains( $url, '/api/license/validate' ) ) {
                $seen = [ 'code' => (int) wp_remote_retrieve_response_code( $response ), 'body' => (string) wp_remote_retrieve_body( $response ) ];
            }

            return $response;
        };
        add_filter( 'http_response', $capture, 10, 3 );
        try {
            $client->refresh( $force );
        } finally {
            remove_filter( 'http_response', $capture, 10 );
        }

        // The generated client files any definitive-looking non-200 answer as `invalid`. Only an answer that
        // really is a decision about this licence may keep that; after a fault the previous state comes back.
        $after = $client->get_state();
        if ( in_array( $after['status'] ?? '', self::LOCKING, true ) && ! self::isVerdict( $seen ) ) {
            if ( null === $before ) {
                delete_option( self::STATE_OPTION );
            } else {
                update_option( self::STATE_OPTION, $before, false );
            }
        }

        // A refresh that stored nothing new (network error, 429, 5xx, or a reverted fault) must not be retried on
        // every request while the licence server is down.
        $now = $client->get_state();
        if ( (int) ( $now['checked_at'] ?? 0 ) !== (int) ( $before['checked_at'] ?? 0 ) ) {
            delete_transient( self::REFRESH_BACKOFF_TRANSIENT );
        } else {
            set_transient( self::REFRESH_BACKOFF_TRANSIENT, 1, self::REFRESH_BACKOFF_SECONDS );
        }
    }

    /** Not on ajax or cron requests, not during a backoff, and only once the one-day cache window has passed. */
    private static function refreshDue( ?array $state ): bool
    {
        if ( wp_doing_ajax() || wp_doing_cron() ) {
            return false;
        }
        if ( false !== get_transient( self::REFRESH_BACKOFF_TRANSIENT ) ) {
            return false;
        }

        return time() - (int) ( $state['checked_at'] ?? 0 ) >= DAY_IN_SECONDS;
    }
```

In `clear()`, add `delete_transient( self::REFRESH_BACKOFF_TRANSIENT );` after the `foreach`.

- [ ] **Step 4: Run the whole Pro suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src/Licensing.php pro-addon/tests/LicensingTest.php
git commit -m "fix(pro): licence refresh skips ajax/cron and backs off 15 minutes after a fault

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 3: Guard the API base override; say why an activation failed

**Why:** `AIED_PRO_API_BASE` redirects the licence key to any host if somebody defines it; and every failed activation shows the same "could not be activated" notice.

**Files:**
- Modify: `pro-addon/src/Licensing.php`, `pro-addon/src/ProPage.php`
- Test: `pro-addon/tests/LicensingTest.php`, `pro-addon/tests/ProPageTest.php`

**Interfaces:**
- Produces: `Licensing::apiBaseFor(?string $override, string $envType): string` (public static, pure); `ProPage::failureNotice(?string $error): string` (public static, pure) returning one of `key_invalid | key_wrong_product | key_not_usable | rate_limited | offline | activate_failed`.

- [ ] **Step 1: Write the failing tests**

Add to `LicensingTest.php`:

```php
    public function testTheApiBaseOverrideIsHonouredOnlyOnALocalOrDevelopmentSite(): void
    {
        $this->assertSame( 'http://localhost:3000', Licensing::apiBaseFor( 'http://localhost:3000/', 'local' ) );
        $this->assertSame( 'http://localhost:3000', Licensing::apiBaseFor( 'http://localhost:3000', 'development' ) );
        foreach ( [ 'production', 'staging', '' ] as $env ) {
            $this->assertSame( 'https://divi5lab.com', Licensing::apiBaseFor( 'https://evil.example', $env ), "env: {$env}" );
        }
        $this->assertSame( 'https://divi5lab.com', Licensing::apiBaseFor( null, 'local' ) );
        $this->assertSame( 'https://divi5lab.com', Licensing::apiBaseFor( '', 'local' ) );
    }
```

In `ProPageTest.php`, change the expectation in `testAFailedActivationStaysLocked` from `aied_pro_notice=activate_failed` to `aied_pro_notice=key_invalid`, and add:

```php
    /** @return array<string,array{?string,string}> */
    public static function activationErrors(): array
    {
        return [
            'invalid key'      => [ 'invalid_key', 'key_invalid' ],
            'wrong product'    => [ 'product_mismatch', 'key_wrong_product' ],
            'not usable'       => [ 'license_not_usable', 'key_not_usable' ],
            'rate limited'     => [ 'rate_limited', 'rate_limited' ],
            'offline'          => [ 'network_error', 'offline' ],
            'something else'   => [ 'http_500', 'activate_failed' ],
            'no error at all'  => [ null, 'activate_failed' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'activationErrors' )]
    public function testEachActivationFailureHasItsOwnNotice( ?string $error, string $notice ): void
    {
        $this->assertSame( $notice, ProPage::failureNotice( $error ) );
    }

    public function testTheOfflineAndRateLimitedFailuresReachTheRedirect(): void
    {
        $GLOBALS['__wp_http_queue'][] = 'network_error';
        $this->asPost( 'aied_pro_activate', [ 'license_key' => self::KEY ] );
        $this->page()->handleActivate();
        $this->assertStringContainsString( 'aied_pro_notice=offline', $GLOBALS['__wp_redirects'][0] );

        $GLOBALS['__wp_http_queue'][] = [ 'code' => 429, 'body' => [ 'error' => 'rate_limited' ] ];
        $this->asPost( 'aied_pro_activate', [ 'license_key' => self::KEY ] );
        $this->page()->handleActivate();
        $this->assertStringContainsString( 'aied_pro_notice=rate_limited', $GLOBALS['__wp_redirects'][1] );
    }

    public function testEveryFailureNoticeHasAnExplanatoryMessage(): void
    {
        foreach ( [ 'key_invalid' => 'not recognised', 'key_wrong_product' => 'different product', 'key_not_usable' => 'not active', 'rate_limited' => 'Too many', 'offline' => 'divi5lab.com' ] as $notice => $text ) {
            $_GET['aied_pro_notice'] = $notice;
            $html                    = $this->render( $this->page() );
            $this->assertStringContainsString( 'notice-error', $html, $notice );
            $this->assertStringContainsString( $text, $html, $notice );
        }
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter "LicensingTest|ProPageTest"`
Expected: FAIL (`apiBaseFor` and `failureNotice` do not exist).

- [ ] **Step 3: Implement**

In `Licensing.php` replace the inline `defined( 'AIED_PRO_API_BASE' ) ? ... : 'https://divi5lab.com'` argument in `client()` with `self::apiBaseFor( defined( 'AIED_PRO_API_BASE' ) ? (string) AIED_PRO_API_BASE : null, wp_get_environment_type() )` and add:

```php
    private const DEFAULT_API_BASE = 'https://divi5lab.com';

    /**
     * The licence key is sent to this host, so an override is honoured only on a local or development site
     * (`wp_get_environment_type()`); on any other site the constant is ignored and divi5lab.com is used.
     */
    public static function apiBaseFor( ?string $override, string $envType ): string
    {
        if ( null !== $override && '' !== $override && in_array( $envType, [ 'local', 'development' ], true ) ) {
            return rtrim( $override, '/' );
        }

        return self::DEFAULT_API_BASE;
    }
```

(Keep the existing comment "Dev/test override only…" next to the call.)

In `ProPage.php`:

1. Add to the `$notices` array in `render()`:

```php
            'key_invalid'       => [ 'error', __( 'That licence key was not recognised. Check it for typos and try again.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'key_wrong_product' => [ 'error', __( 'That key belongs to a different product. Use the key for JHMG AI Editor for Divi 5 Pro.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'key_not_usable'    => [ 'error', __( 'That licence is not active (expired, cancelled, or every site activation is in use). Check your divi5lab.com account.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'rate_limited'      => [ 'error', __( 'Too many attempts. Wait a few minutes and try again.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'offline'           => [ 'error', __( 'Could not reach divi5lab.com. Check that this site can make outgoing requests, then try again.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
```

2. In `handleActivate()` replace `$this->redirect( $res['ok'] ? 'activated' : 'activate_failed' );` with `$this->redirect( $res['ok'] ? 'activated' : self::failureNotice( $res['error'] ) );`

3. Add the method:

```php
    /** Maps the licence server's error code to the notice that tells the person what to do. */
    public static function failureNotice( ?string $error ): string
    {
        return match ( $error ) {
            'invalid_key'        => 'key_invalid',
            'product_mismatch'   => 'key_wrong_product',
            'license_not_usable' => 'key_not_usable',
            'rate_limited'       => 'rate_limited',
            'network_error'      => 'offline',
            default              => 'activate_failed',
        };
    }
```

- [ ] **Step 4: Run the whole Pro suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src/Licensing.php pro-addon/src/ProPage.php pro-addon/tests/LicensingTest.php pro-addon/tests/ProPageTest.php
git commit -m "fix(pro): API base override only on local/dev sites; activation failures say why

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 4: The add-on's readme, honest Description, version 0.2.0

**Why (blockers 4-6):** the add-on makes remote calls and must disclose them in its own readme; the plugin Description must match what ships; the product id and URL must be confirmed with the divi5lab server.

**Verified 2026-10-02 (read-only, in the sibling `layoutlab` repo):** product id `ai-editor-divi5-pro` is the id used by `app/api/checkout/route.ts`, `app/(catalog)/pricing/page.tsx` and `tests/license-core.test.ts`; the product page `/plugins/divi-5-ai-editor` exists (`app/(marketing)/plugins/divi-5-ai-editor/page.tsx`). `AIED_PRO_PRODUCT` and `Licensing::UPGRADE_URL` already match. No code change is needed for that; Step 6 re-confirms and records it.

**Files:**
- Create: `pro-addon/readme.txt`, `pro-addon/tests/ReadmeTest.php`
- Modify: `pro-addon/jhmg-ai-editor-for-divi-5-pro.php` (version `0.2.0`, Description, activation hook), `scripts/build-pro-zip.sh`

**Interfaces:**
- Produces: the readme's tool list is the contract `ReadmeTest` pins against `Tools::all()` (every shipped tool name must appear in the readme; the Description must not claim unshipped features).

- [ ] **Step 1: Write the failing test**

Create `pro-addon/tests/ReadmeTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Tools;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

/** The add-on is not on WordPress.org, but it makes remote calls, so its own readme must say so, and say only true things. */
class ReadmeTest extends TestCase
{
    private function readme(): string
    {
        $path = dirname( AIED_PRO_FILE ) . '/readme.txt';
        $this->assertFileExists( $path );

        return (string) file_get_contents( $path );
    }

    public function testTheStableTagIsTheCurrentVersion(): void
    {
        $this->assertSame( 1, preg_match( '/^Stable tag:\s*(\S+)\s*$/m', $this->readme(), $m ) );
        $this->assertSame( AIED_PRO_VERSION, $m[1] );
    }

    public function testItDisclosesTheLicenceServerAndWhatIsSent(): void
    {
        $r = $this->readme();
        $this->assertStringContainsString( '== External services ==', $r );
        $this->assertStringContainsString( 'divi5lab.com', $r );
        foreach ( [ 'licence key', 'site URL', 'plugin version', 'update' ] as $what ) {
            $this->assertStringContainsString( $what, $r, "the disclosure must say it sends/uses: {$what}" );
        }
    }

    public function testItNamesEveryShippedTool(): void
    {
        $r = $this->readme();
        foreach ( Tools::all() as $tool ) {
            $this->assertStringContainsString( '`' . $tool->name() . '`', $r, $tool->name() );
        }
    }

    public function testTheHeaderDescriptionClaimsNothingThatIsNotShipped(): void
    {
        $this->assertSame( 1, preg_match( '/^\s*\*\s*Description:\s*(.+)$/m', (string) file_get_contents( AIED_PRO_FILE ), $m ) );
        foreach ( [ 'Theme Builder', 'site-wide', 'audit', 'find and replace', 'find/replace' ] as $unshipped ) {
            $this->assertStringNotContainsStringIgnoringCase( $unshipped, $m[1], "not shipped yet: {$unshipped}" );
        }
        $this->assertStringContainsString( 'free plugin', $m[1] );
    }

    public function testTheZipScriptShipsTheReadme(): void
    {
        $this->assertStringContainsString( 'readme.txt', (string) file_get_contents( dirname( AIED_PRO_FILE ) . '/../scripts/build-pro-zip.sh' ) );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter ReadmeTest`
Expected: FAIL (no readme.txt; zip script does not mention it).

- [ ] **Step 3: Write `pro-addon/readme.txt`**

```
=== JHMG AI Editor for Divi 5 Pro ===
Contributors:      lucaslopvet
Requires at least: 6.0
Requires PHP:      8.1
Stable tag:        0.2.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Pro add-on for JHMG AI Editor for Divi 5. Lets your AI assistant set the front page and menu, publish pages and add CSS, and lets you undo each change in one step.

== Description ==

This is a separate plugin. It needs the free plugin "JHMG AI Editor for Divi 5" (version 4.4.0 or newer) and a licence from divi5lab.com. Without both, the Pro tools stay off.

Every change a Pro tool makes is recorded as a batch with an undo point. `undo_batch` restores everything it safely can and tells you what it could not (because the page or setting was changed afterwards, or the page history no longer holds the earlier version). It only says "fully undone" when every item was restored.

= Tools =

* `set_front_page` - set a published page as the site's front page.
* `set_primary_menu` - build the primary menu from pages and links, and assign it to the theme's menu location.
* `publish_page` - publish or schedule a draft; preview first with `dry_run`.
* `set_custom_css` - add CSS inside one clearly marked block of WordPress Additional CSS. Your own CSS is never touched.
* `propose_php_snippet` - store PHP for you to review. Nothing is ever run, saved as a file or loaded by the plugin.
* `list_batches` - list recent Pro changes.
* `undo_batch` - undo one batch.

Each tool checks the permission it really needs (for example `manage_options` to set the front page), not only that the request is authenticated.

== External services ==

This plugin contacts one external service, **divi5lab.com**, which is operated by the plugin's author. It is used only to check your licence and to look for plugin updates.

* **When:** when you activate or deactivate a licence, about once a day while a licence key is saved, and when WordPress checks for plugin updates. Never on page views of your site's visitors.
* **What is sent:** your licence key, your site URL, the product id (`ai-editor-divi5-pro`), this plugin's version and your WordPress version. Nothing else, and no content from your pages.
* **Why:** to confirm the licence is valid for this site and to deliver updates.
* Terms and privacy policy: https://divi5lab.com

If the service cannot be reached, the Pro tools you already unlocked keep working. Only a licence that divi5lab.com explicitly reports as revoked or invalid locks them again; an expired or lapsed licence stops updates and support, not features.

== Uninstall ==

Deleting the plugin removes its own data (licence state, batch history, saved code proposals). It does not undo your site's front page, menu, CSS or page statuses: undo those first if you want them back.

== Changelog ==

= 0.2.0 =
* Licence checks: a server error page can no longer lock your Pro tools; refreshes back off during an outage.
* New: batches and one-step undo (`list_batches`, `undo_batch`).
* New: `set_front_page`, `set_primary_menu`, `publish_page`, `set_custom_css`, `propose_php_snippet`.

= 0.1.0 =
* Skeleton: licensing, compatibility guard and the Pro admin tab.
```

- [ ] **Step 4: Update the main file**

In `jhmg-ai-editor-for-divi-5-pro.php`:

- header `Description:` becomes: `Pro add-on for JHMG AI Editor for Divi 5: set the front page and menu, publish or schedule pages, add managed custom CSS, file PHP snippets for human review, and undo each Pro change in one step. Requires the free plugin.`
- header `Version:` and `define( 'AIED_PRO_VERSION', ... )` become `0.2.0`; `pro-addon/tests/bootstrap.php` also defines `AIED_PRO_VERSION` as `'0.1.0'` — change that to `'0.2.0'` too.
- after `require_once __DIR__ . '/src/autoload.php';` add:

```php
register_activation_hook( __FILE__, static function (): void {
    AiEditorDivi5\Pro\Batch\BatchTable::ensureReady( true );
} );
```

(The class arrives in Task 6; the hook only runs on activation, so the file stays loadable. Add the line now so the main file is edited once.)

- [ ] **Step 5: Ship the readme**

In `scripts/build-pro-zip.sh`, change the `cp` line to `cp "$SRC/$MAIN" "$SRC/uninstall.php" "$SRC/readme.txt" "$STAGE/$SLUG/"` and update the header comment to say "the main file, uninstall.php, readme.txt and src/ ship".

- [ ] **Step 6: Re-confirm the product id and URL, then run everything**

Run: `grep -rn "ai-editor-divi5-pro" ../layoutlab/app/api/checkout/route.ts ../layoutlab/tests/license-core.test.ts | head -3` (read-only; from the repo root). Expected: matches. If the sibling repo is not there, ask the owner to confirm `ai-editor-divi5-pro` and `/plugins/divi-5-ai-editor` against the divi5lab server and stop here until answered.

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: all green (`testItNamesEveryShippedTool` passes trivially while `Tools::all()` is empty and becomes the contract as tools land).

- [ ] **Step 7: Commit**

```bash
git add pro-addon/readme.txt pro-addon/tests/ReadmeTest.php pro-addon/tests/bootstrap.php pro-addon/jhmg-ai-editor-for-divi-5-pro.php scripts/build-pro-zip.sh
git commit -m "feat(pro): readme that discloses the licence calls, honest Description, version 0.2.0

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---
## Task 5: Strict argument readers and canonical hashes

**Files:**
- Create: `pro-addon/src/Args.php`, `pro-addon/src/Canon.php`
- Modify: `pro-addon/src/autoload.php`
- Test: `pro-addon/tests/ArgsTest.php`, `pro-addon/tests/CanonTest.php`

**Interfaces:**
- Produces (used by every later task):
  - `Args::int(array $a, string $key, bool $required = true, int $min = 1): ?int`
  - `Args::string(array $a, string $key, bool $required = true, int $maxBytes = 10000): ?string` (a required string must not be blank)
  - `Args::bool(array $a, string $key): bool` (absent or null = false; a non-boolean throws)
  - `Args::listOf(array $a, string $key, int $maxItems): array` (required, non-empty, a real list)
  - each throws `\InvalidArgumentException` with a message safe to show the client, e.g. `"page_id" must be an integer.`
  - `Canon::hash(mixed $value): string` (sha256 of canonical JSON: object keys sorted, list order kept)

- [ ] **Step 1: Write the failing tests**

`pro-addon/tests/ArgsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Args;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class ArgsTest extends TestCase
{
    private function fails( callable $fn, string $message ): void
    {
        try {
            $fn();
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertSame( $message, $e->getMessage() );
        }
    }

    public function testIntAcceptsOnlyRealIntegers(): void
    {
        $this->assertSame( 5, Args::int( [ 'id' => 5 ], 'id' ) );
        foreach ( [ '5', 5.0, 5.5, true, [], 'abc' ] as $bad ) {
            $this->fails( static fn () => Args::int( [ 'id' => $bad ], 'id' ), '"id" must be an integer.' );
        }
    }

    public function testIntRequiredOptionalAndMinimum(): void
    {
        $this->fails( static fn () => Args::int( [], 'id' ), '"id" is required.' );
        $this->fails( static fn () => Args::int( [ 'id' => null ], 'id' ), '"id" is required.' );
        $this->assertNull( Args::int( [], 'id', false ) );
        $this->assertNull( Args::int( [ 'id' => null ], 'id', false ) );
        $this->fails( static fn () => Args::int( [ 'id' => 0 ], 'id' ), '"id" must be at least 1.' );
        $this->assertSame( 0, Args::int( [ 'id' => 0 ], 'id', true, 0 ) );
    }

    public function testStringAcceptsOnlyStrings(): void
    {
        $this->assertSame( 'hi', Args::string( [ 's' => 'hi' ], 's' ) );
        foreach ( [ 5, true, [], 1.5 ] as $bad ) {
            $this->fails( static fn () => Args::string( [ 's' => $bad ], 's' ), '"s" must be a string.' );
        }
    }

    public function testStringRequiredBlankOptionalAndLength(): void
    {
        $this->fails( static fn () => Args::string( [], 's' ), '"s" is required.' );
        $this->fails( static fn () => Args::string( [ 's' => "  \n" ], 's' ), '"s" must not be empty.' );
        $this->assertNull( Args::string( [], 's', false ) );
        $this->assertSame( '', Args::string( [ 's' => '' ], 's', false ), 'an optional string may be empty' );
        $this->fails( static fn () => Args::string( [ 's' => 'abcdef' ], 's', true, 5 ), '"s" must be at most 5 bytes.' );
        $this->assertSame( 'abcde', Args::string( [ 's' => 'abcde' ], 's', true, 5 ) );
    }

    public function testBoolIsStrict(): void
    {
        $this->assertFalse( Args::bool( [], 'b' ) );
        $this->assertFalse( Args::bool( [ 'b' => null ], 'b' ) );
        $this->assertTrue( Args::bool( [ 'b' => true ], 'b' ) );
        $this->assertFalse( Args::bool( [ 'b' => false ], 'b' ) );
        foreach ( [ 'true', 1, 0, 'false', [] ] as $bad ) {
            $this->fails( static fn () => Args::bool( [ 'b' => $bad ], 'b' ), '"b" must be true or false.' );
        }
    }

    public function testListOf(): void
    {
        $this->assertSame( [ 1, 2 ], Args::listOf( [ 'l' => [ 1, 2 ] ], 'l', 5 ) );
        $this->fails( static fn () => Args::listOf( [], 'l', 5 ), '"l" is required.' );
        $this->fails( static fn () => Args::listOf( [ 'l' => 'x' ], 'l', 5 ), '"l" must be a list.' );
        $this->fails( static fn () => Args::listOf( [ 'l' => [ 'a' => 1 ] ], 'l', 5 ), '"l" must be a list.' );
        $this->fails( static fn () => Args::listOf( [ 'l' => [] ], 'l', 5 ), '"l" must not be empty.' );
        $this->fails( static fn () => Args::listOf( [ 'l' => [ 1, 2, 3 ] ], 'l', 2 ), '"l" must have at most 2 items.' );
    }
}
```

`pro-addon/tests/CanonTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Canon;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class CanonTest extends TestCase
{
    public function testObjectKeyOrderDoesNotMatterButListOrderDoes(): void
    {
        $this->assertSame( Canon::hash( [ 'a' => 1, 'b' => [ 'x' => 1, 'y' => 2 ] ] ), Canon::hash( [ 'b' => [ 'y' => 2, 'x' => 1 ], 'a' => 1 ] ) );
        $this->assertNotSame( Canon::hash( [ 1, 2 ] ), Canon::hash( [ 2, 1 ] ) );
    }

    public function testDifferentValuesAndTypesDiffer(): void
    {
        $hashes = array_map( [ Canon::class, 'hash' ], [ null, '', '0', 0, false, [], 'a', 'A', [ 'a' ] ] );
        $this->assertSame( count( $hashes ), count( array_unique( $hashes ) ) );
    }

    public function testItIsStableForUnicodeAndSlashes(): void
    {
        $this->assertSame( hash( 'sha256', '"a/b — ü"' ), Canon::hash( 'a/b — ü' ) );
    }

    public function testAStringHashIsTheHashOfItsJson(): void
    {
        $this->assertSame( hash( 'sha256', '"abc"' ), Canon::hash( 'abc' ) );
        $this->assertSame( hash( 'sha256', 'null' ), Canon::hash( null ) );
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter "ArgsTest|CanonTest"`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement**

`pro-addon/src/Args.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Strict argument readers for Pro tools. A value of the wrong type is rejected with a message that is safe to
 * show the client; nothing is ever cast.
 */
final class Args
{
    /** @param array<string,mixed> $a */
    public static function int( array $a, string $key, bool $required = true, int $min = 1 ): ?int
    {
        if ( ! array_key_exists( $key, $a ) || null === $a[ $key ] ) {
            return self::missing( $key, $required );
        }
        if ( ! is_int( $a[ $key ] ) ) {
            throw new \InvalidArgumentException( "\"{$key}\" must be an integer." );
        }
        if ( $a[ $key ] < $min ) {
            throw new \InvalidArgumentException( "\"{$key}\" must be at least {$min}." );
        }

        return $a[ $key ];
    }

    /** @param array<string,mixed> $a */
    public static function string( array $a, string $key, bool $required = true, int $maxBytes = 10000 ): ?string
    {
        if ( ! array_key_exists( $key, $a ) || null === $a[ $key ] ) {
            return self::missing( $key, $required );
        }
        if ( ! is_string( $a[ $key ] ) ) {
            throw new \InvalidArgumentException( "\"{$key}\" must be a string." );
        }
        if ( $required && '' === trim( $a[ $key ] ) ) {
            throw new \InvalidArgumentException( "\"{$key}\" must not be empty." );
        }
        if ( strlen( $a[ $key ] ) > $maxBytes ) {
            throw new \InvalidArgumentException( "\"{$key}\" must be at most {$maxBytes} bytes." );
        }

        return $a[ $key ];
    }

    /** @param array<string,mixed> $a */
    public static function bool( array $a, string $key ): bool
    {
        if ( ! array_key_exists( $key, $a ) || null === $a[ $key ] ) {
            return false;
        }
        if ( ! is_bool( $a[ $key ] ) ) {
            throw new \InvalidArgumentException( "\"{$key}\" must be true or false." );
        }

        return $a[ $key ];
    }

    /**
     * @param array<string,mixed> $a
     * @return list<mixed>
     */
    public static function listOf( array $a, string $key, int $maxItems ): array
    {
        if ( ! array_key_exists( $key, $a ) || null === $a[ $key ] ) {
            self::missing( $key, true );
        }
        if ( ! is_array( $a[ $key ] ) || ! array_is_list( $a[ $key ] ) ) {
            throw new \InvalidArgumentException( "\"{$key}\" must be a list." );
        }
        if ( [] === $a[ $key ] ) {
            throw new \InvalidArgumentException( "\"{$key}\" must not be empty." );
        }
        if ( count( $a[ $key ] ) > $maxItems ) {
            throw new \InvalidArgumentException( "\"{$key}\" must have at most {$maxItems} items." );
        }

        return $a[ $key ];
    }

    /** The add-on supports PHP 8.1, so no standalone `null` return type (8.2+). */
    private static function missing( string $key, bool $required ): mixed
    {
        if ( $required ) {
            throw new \InvalidArgumentException( "\"{$key}\" is required." );
        }

        return null;
    }
}
```

`pro-addon/src/Canon.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Canonical JSON and sha256, so "has this changed since the batch wrote it?" is a stable string comparison. */
final class Canon
{
    public static function hash( mixed $value ): string
    {
        return hash( 'sha256', (string) json_encode( self::normalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) );
    }

    private static function normalize( mixed $value ): mixed
    {
        if ( ! is_array( $value ) ) {
            return $value;
        }
        if ( ! array_is_list( $value ) ) {
            ksort( $value );
        }

        return array_map( [ self::class, 'normalize' ], $value );
    }
}
```

In `pro-addon/src/autoload.php` insert before the `Plugin.php` line:

```php
require_once __DIR__ . '/Args.php';
require_once __DIR__ . '/Canon.php';
```

- [ ] **Step 4: Run the whole Pro suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src/Args.php pro-addon/src/Canon.php pro-addon/src/autoload.php pro-addon/tests/ArgsTest.php pro-addon/tests/CanonTest.php
git commit -m "feat(pro): strict argument readers and canonical hashes

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 6: The batch store

**Files:**
- Create: `pro-addon/src/Batch/BatchRepository.php`, `BatchTable.php`, `WpdbBatchRepository.php`, `BatchLog.php`
- Create (test doubles): `pro-addon/tests/InMemoryBatchRepository.php`, `pro-addon/tests/BatchStubWpdb.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Plugin.php`, `pro-addon/uninstall.php`
- Test: `pro-addon/tests/BatchLogTest.php`, `BatchTableTest.php`, `WpdbBatchRepositoryTest.php`, `UninstallTest.php` (modify), `PluginTest.php` (modify)

**Interfaces:**
- Produces:
  - `interface BatchRepository { insert(array $row): ?int; find(int $id): ?array; recent(int $limit): array; update(int $id, string $status, array $items): bool; trim(int $keep): void; }` A row: `['id'=>int, 'user_id'=>int, 'tool'=>string, 'created_at'=>'Y-m-d H:i:s' (UTC), 'summary'=>string, 'status'=>'applied'|'undone'|'partly_undone', 'items'=>list<array>]`. `insert()` takes a row without `id` and returns the new id or `null`.
  - `BatchLog::__construct(BatchRepository $repo)`; `record(string $tool, string $summary, array $items): ?array` (returns `describe()` or `null`); `find(int $id): ?array`; `recent(int $limit = 20): array`; `markUndone(int $id, string $status, array $items): bool`; `const MAX = 20`; `static describe(array $batch): array` = `['id','tool','summary','created_at','status','items'=>int,'undo_points'=>int,'undoable'=>bool]`; `static attach(array $payload, ?array $batch): array` adds `$payload['batch']` (the described batch, or `['id'=>null,'undoable'=>false,'note'=>...]`).
  - `BatchTable::name()`, `schemaSql()`, `createTable()`, `exists()`, `ensureReady(bool $force = false)`, `drop()`, `dropTablesFilter(mixed $tables): array`; constants `DB_VERSION_OPTION = 'aied_pro_batches_db_version'`, `BACKOFF_TRANSIENT = 'aied_pro_batches_backoff'`.
  - `WpdbBatchRepository implements BatchRepository`.
- Item shape (documented here, built in Task 7): `['kind','ref','label','before','after_hash','undo'=>['available'=>bool,'reason'=>?string,'version_id'=>?int],'state'=>'applied'|'undone']`.

- [ ] **Step 1: Write the two test doubles**

`pro-addon/tests/InMemoryBatchRepository.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchRepository;

require_once __DIR__ . '/../src/Batch/BatchRepository.php';

/** A repository that lives in an array, with switches to simulate a failing store. */
final class InMemoryBatchRepository implements BatchRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    public bool $failInsert = false;
    private int $next = 1;

    public function insert( array $row ): ?int
    {
        if ( $this->failInsert ) {
            return null;
        }
        $id                = $this->next++;
        $this->rows[ $id ] = [ 'id' => $id ] + $row;

        return $id;
    }

    public function find( int $id ): ?array
    {
        return $this->rows[ $id ] ?? null;
    }

    public function recent( int $limit ): array
    {
        return array_slice( array_reverse( array_values( $this->rows ) ), 0, $limit );
    }

    public function update( int $id, string $status, array $items ): bool
    {
        if ( ! isset( $this->rows[ $id ] ) ) {
            return false;
        }
        $this->rows[ $id ]['status'] = $status;
        $this->rows[ $id ]['items']  = $items;

        return true;
    }

    public function trim( int $keep ): void
    {
        while ( count( $this->rows ) > $keep ) {
            unset( $this->rows[ array_key_first( $this->rows ) ] );
        }
    }
}
```

`pro-addon/tests/BatchStubWpdb.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

/**
 * A recording stand-in for the few $wpdb methods the batch code uses. It proves the calls the code makes;
 * the real SQL is proven on a real database by scripts/verify-pro-settings.php.
 */
final class BatchStubWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var list<array<int,mixed>> */
    public array $calls = [];
    /** @var array<int,array<string,string>> */
    public array $rows = [];
    public bool $failInsert = false;
    /** What `SELECT COUNT(*)` returns: null = the table does not exist. */
    public ?string $count = '0';
    /** @var list<string> what get_col returns */
    public array $col = [];

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }

    public function prepare( string $query, mixed ...$args ): string
    {
        return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
    }

    public function suppress_errors( bool $on = true ): bool
    {
        return false;
    }

    public function get_var( string $query ): ?string
    {
        $this->calls[] = [ 'get_var', $query ];

        return $this->count;
    }

    public function insert( string $table, array $data, array $formats = [] ): int|false
    {
        $this->calls[] = [ 'insert', $table, $data ];
        if ( $this->failInsert ) {
            return false;
        }
        $this->insert_id          = count( $this->rows ) + 1;
        $this->rows[ $this->insert_id ] = [ 'id' => (string) $this->insert_id ] + array_map( 'strval', $data );

        return 1;
    }

    public function get_row( string $query, mixed $output = null ): ?array
    {
        $this->calls[] = [ 'get_row', $query ];
        preg_match( '/id = (\d+)/', $query, $m );

        return $this->rows[ (int) ( $m[1] ?? 0 ) ] ?? null;
    }

    /** @return list<array<string,string>> */
    public function get_results( string $query, mixed $output = null ): array
    {
        $this->calls[] = [ 'get_results', $query ];

        return array_reverse( array_values( $this->rows ) );
    }

    public function update( string $table, array $data, array $where, array $formats = [], array $whereFormats = [] ): int|false
    {
        $this->calls[] = [ 'update', $table, $data, $where ];
        $id            = (int) $where['id'];
        if ( ! isset( $this->rows[ $id ] ) ) {
            return false;
        }
        foreach ( $data as $k => $v ) {
            $this->rows[ $id ][ $k ] = (string) $v;
        }

        return 1;
    }

    /** @return list<string> */
    public function get_col( string $query ): array
    {
        $this->calls[] = [ 'get_col', $query ];

        return $this->col;
    }

    public function query( string $query ): int
    {
        $this->calls[] = [ 'query', $query ];

        return 1;
    }
}
```

- [ ] **Step 2: Write the failing tests**

`pro-addon/tests/BatchLogTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

class BatchLogTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
    }

    /** @return array<string,mixed> */
    private function item( bool $available = true, string $state = 'applied' ): array
    {
        return [ 'kind' => 'fake', 'ref' => 'a', 'label' => 'A', 'before' => 1, 'after_hash' => 'h', 'undo' => [ 'available' => $available ], 'state' => $state ];
    }

    public function testRecordStoresTheBatchAndReturnsItsDescription(): void
    {
        $d = $this->log->record( 'set_front_page', 'Set front page', [ $this->item(), $this->item( false ) ] );
        $this->assertSame( 1, $d['id'] );
        $this->assertSame( 'set_front_page', $d['tool'] );
        $this->assertSame( 'applied', $d['status'] );
        $this->assertSame( 2, $d['items'] );
        $this->assertSame( 1, $d['undo_points'], 'only an item with an undo point counts' );
        $this->assertTrue( $d['undoable'] );

        $row = $this->repo->rows[1];
        $this->assertSame( 7, $row['user_id'] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['created_at'] );
        $this->assertSame( 'applied', $row['status'] );
    }

    public function testABatchWithNoUndoPointIsNotUndoable(): void
    {
        $d = $this->log->record( 't', 's', [ $this->item( false ) ] );
        $this->assertSame( 0, $d['undo_points'] );
        $this->assertFalse( $d['undoable'] );
    }

    public function testAnEmptyBatchIsNeverStored(): void
    {
        $this->assertNull( $this->log->record( 't', 's', [] ) );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testAFailedStoreReturnsNull(): void
    {
        $this->repo->failInsert = true;
        $this->assertNull( $this->log->record( 't', 's', [ $this->item() ] ) );
    }

    public function testOnlyTheLast20BatchesAreKept(): void
    {
        for ( $i = 1; $i <= 25; $i++ ) {
            $this->log->record( 't', "batch {$i}", [ $this->item() ] );
        }
        $this->assertCount( 20, $this->repo->rows );
        $this->assertSame( 6, array_key_first( $this->repo->rows ) );
        $this->assertSame( 25, array_key_last( $this->repo->rows ) );
    }

    public function testALongSummaryIsCutTo255Characters(): void
    {
        $this->log->record( 't', str_repeat( 'x', 400 ), [ $this->item() ] );
        $this->assertSame( 255, mb_strlen( $this->repo->rows[1]['summary'] ) );
    }

    public function testFindRecentAndMarkUndone(): void
    {
        $this->log->record( 't', 'one', [ $this->item() ] );
        $this->log->record( 't', 'two', [ $this->item() ] );
        $this->assertSame( 'one', $this->log->find( 1 )['summary'] );
        $this->assertNull( $this->log->find( 99 ) );
        $this->assertSame( [ 2, 1 ], array_column( $this->log->recent(), 'id' ), 'newest first' );
        $this->assertSame( [ 2 ], array_column( $this->log->recent( 1 ), 'id' ) );

        $this->assertTrue( $this->log->markUndone( 1, 'undone', [ $this->item( true, 'undone' ) ] ) );
        $this->assertSame( 'undone', $this->log->find( 1 )['status'] );
        $this->assertSame( 'undone', $this->log->find( 1 )['items'][0]['state'] );
        $this->assertFalse( $this->log->markUndone( 99, 'undone', [] ) );
    }

    public function testAnUndoneItemIsNotAnUndoPointAnyMore(): void
    {
        $d = BatchLog::describe( [ 'id' => 1, 'tool' => 't', 'summary' => 's', 'created_at' => 'x', 'status' => 'undone', 'items' => [ $this->item( true, 'undone' ) ] ] );
        $this->assertSame( 0, $d['undo_points'] );
    }

    public function testAttachAddsTheDescribedBatchOrAnHonestNote(): void
    {
        $d = $this->log->record( 't', 's', [ $this->item() ] );
        $this->assertSame( $d, BatchLog::attach( [ 'changed' => true ], $d )['batch'] );
        $out = BatchLog::attach( [ 'changed' => true ], null );
        $this->assertTrue( $out['changed'] );
        $this->assertNull( $out['batch']['id'] );
        $this->assertFalse( $out['batch']['undoable'] );
        $this->assertStringContainsString( 'could not be recorded', $out['batch']['note'] );
    }
}
```

`pro-addon/tests/BatchTableTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchTable;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/BatchStubWpdb.php';

class BatchTableTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb']            = new BatchStubWpdb();
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        $GLOBALS['__wp_dbdelta_sql'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
    }

    public function testTheTableIsPrefixedAndTheSchemaHasEveryColumn(): void
    {
        $this->assertSame( 'wp_aied_pro_batches', BatchTable::name() );
        $sql = BatchTable::schemaSql();
        $this->assertStringContainsString( 'CREATE TABLE wp_aied_pro_batches', $sql );
        foreach ( [ 'id ', 'user_id', 'tool', 'created_at', 'summary', 'status', 'items', 'PRIMARY KEY', 'DEFAULT CHARSET=utf8mb4' ] as $part ) {
            $this->assertStringContainsString( $part, $sql, $part );
        }
    }

    public function testEnsureReadyCreatesTheTableOnceAndRemembersIt(): void
    {
        BatchTable::ensureReady();
        $this->assertCount( 1, $GLOBALS['__wp_dbdelta_sql'] );
        $this->assertSame( 1, $GLOBALS['__wp_options'][ BatchTable::DB_VERSION_OPTION ] );
        BatchTable::ensureReady();
        $this->assertCount( 1, $GLOBALS['__wp_dbdelta_sql'], 'already created: no second CREATE' );
    }

    public function testAHostThatRefusesCreateTableBacksOffInsteadOfRetryingEveryRequest(): void
    {
        $GLOBALS['wpdb']->count = null;   // the table still does not exist after dbDelta
        BatchTable::ensureReady();
        $this->assertArrayNotHasKey( BatchTable::DB_VERSION_OPTION, $GLOBALS['__wp_options'] );
        $this->assertArrayHasKey( BatchTable::BACKOFF_TRANSIENT, $GLOBALS['__wp_transients'] );
        BatchTable::ensureReady();
        $this->assertCount( 1, $GLOBALS['__wp_dbdelta_sql'], 'backed off' );
        BatchTable::ensureReady( true );
        $this->assertCount( 2, $GLOBALS['__wp_dbdelta_sql'], 'activation forces a retry' );
    }

    public function testDropRemovesTheTableAndItsState(): void
    {
        $GLOBALS['__wp_options'][ BatchTable::DB_VERSION_OPTION ] = 1;
        $GLOBALS['__wp_transients'][ BatchTable::BACKOFF_TRANSIENT ] = 1;
        BatchTable::drop();
        $this->assertContains( [ 'query', 'DROP TABLE IF EXISTS wp_aied_pro_batches' ], $GLOBALS['wpdb']->calls );
        $this->assertSame( [], $GLOBALS['__wp_options'] );
        $this->assertSame( [], $GLOBALS['__wp_transients'] );
    }

    public function testTheMultisiteDropFilterAddsOurTable(): void
    {
        $this->assertSame( [ 'wp_2_posts', 'wp_aied_pro_batches' ], BatchTable::dropTablesFilter( [ 'wp_2_posts' ] ) );
        $this->assertSame( [ 'wp_aied_pro_batches' ], BatchTable::dropTablesFilter( 'junk' ) );
    }
}
```

`pro-addon/tests/WpdbBatchRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\WpdbBatchRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/BatchStubWpdb.php';

class WpdbBatchRepositoryTest extends TestCase
{
    private WpdbBatchRepository $repo;

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new BatchStubWpdb();
        $this->repo      = new WpdbBatchRepository();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
    }

    /** @return array<string,mixed> */
    private function row( string $summary = 's' ): array
    {
        return [ 'user_id' => 7, 'tool' => 't', 'created_at' => '2026-10-02 10:00:00', 'summary' => $summary, 'status' => 'applied', 'items' => [ [ 'kind' => 'k', 'before' => 'é "q" \\ </p>' ] ] ];
    }

    public function testInsertEncodesTheItemsAndReturnsTheId(): void
    {
        $id = $this->repo->insert( $this->row() );
        $this->assertSame( 1, $id );
        $stored = $GLOBALS['wpdb']->rows[1];
        $this->assertSame( [ [ 'kind' => 'k', 'before' => 'é "q" \\ </p>' ] ], json_decode( $stored['items'], true ) );
        $this->assertSame( 'wp_aied_pro_batches', $GLOBALS['wpdb']->calls[0][1] );
    }

    public function testInsertReturnsNullWhenTheDatabaseRefuses(): void
    {
        $GLOBALS['wpdb']->failInsert = true;
        $this->assertNull( $this->repo->insert( $this->row() ) );
    }

    public function testInsertReturnsNullWhenTheItemsCannotBeEncoded(): void
    {
        $row                      = $this->row();
        $row['items'][0]['before'] = "\xB1\x31";   // invalid UTF-8
        $this->assertNull( $this->repo->insert( $row ) );
        $this->assertSame( [], $GLOBALS['wpdb']->rows, 'nothing half-written' );
    }

    public function testFindDecodesTheRowIntoTypedValues(): void
    {
        $this->repo->insert( $this->row( 'hello' ) );
        $b = $this->repo->find( 1 );
        $this->assertSame( 1, $b['id'] );
        $this->assertSame( 7, $b['user_id'] );
        $this->assertSame( 'hello', $b['summary'] );
        $this->assertSame( 'k', $b['items'][0]['kind'] );
        $this->assertNull( $this->repo->find( 99 ) );
    }

    public function testRecentReturnsNewestFirst(): void
    {
        $this->repo->insert( $this->row( 'one' ) );
        $this->repo->insert( $this->row( 'two' ) );
        $this->assertSame( [ 'two', 'one' ], array_column( $this->repo->recent( 10 ), 'summary' ) );
    }

    public function testUpdateWritesStatusAndItems(): void
    {
        $this->repo->insert( $this->row() );
        $this->assertTrue( $this->repo->update( 1, 'undone', [ [ 'state' => 'undone' ] ] ) );
        $b = $this->repo->find( 1 );
        $this->assertSame( 'undone', $b['status'] );
        $this->assertSame( 'undone', $b['items'][0]['state'] );
        $this->assertFalse( $this->repo->update( 99, 'undone', [] ) );
    }

    public function testTrimDeletesOnlyTheRowsBeyondTheNewestN(): void
    {
        $GLOBALS['wpdb']->col = [ '5', '3', '1' ];
        $this->repo->trim( 20 );
        $queries = array_values( array_filter( $GLOBALS['wpdb']->calls, static fn ( array $c ): bool => 'query' === $c[0] ) );
        $this->assertSame( 'DELETE FROM wp_aied_pro_batches WHERE id IN (5,3,1)', $queries[0][1] );
        $this->assertStringContainsString( 'OFFSET 20', $GLOBALS['wpdb']->calls[0][1] );
    }

    public function testTrimWithNothingToTrimRunsNoDelete(): void
    {
        $GLOBALS['wpdb']->col = [];
        $this->repo->trim( 20 );
        $this->assertSame( [], array_filter( $GLOBALS['wpdb']->calls, static fn ( array $c ): bool => 'query' === $c[0] ) );
    }
}
```

In `UninstallTest.php`, replace `testItWipesTheLocalLicenceStateAndNeverCallsTheServer` with:

```php
    public function testItWipesTheLocalStateDropsTheBatchTableAndNeverCallsTheServer(): void
    {
        require_once __DIR__ . '/BatchStubWpdb.php';
        $GLOBALS['wpdb']         = new BatchStubWpdb();
        $GLOBALS['__wp_options'] = [
            'aied_pro_license_key'      => 'KEY',
            'aied_pro_license_state'    => [ 'status' => 'active' ],
            'aied_pro_premium_unlocked' => 1,
            'aied_pro_update_blocked'   => '1',
            'aied_pro_batches_db_version' => 1,
            'unrelated_option'          => 'keep',
        ];
        $GLOBALS['__wp_transients'] = [ 'aied_pro_refresh_backoff' => 1, 'aied_pro_batches_backoff' => 1 ];
        $GLOBALS['__wp_http_log']   = [];
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', 'jhmg-ai-editor-for-divi-5-pro/jhmg-ai-editor-for-divi-5-pro.php' );
        }
        require dirname( AIED_PRO_FILE ) . '/uninstall.php';

        $this->assertSame( [ 'unrelated_option' => 'keep' ], $GLOBALS['__wp_options'] );
        $this->assertSame( [], $GLOBALS['__wp_transients'] );
        $this->assertSame( [], $GLOBALS['__wp_http_log'] );
        $this->assertContains( [ 'query', 'DROP TABLE IF EXISTS wp_aied_pro_batches' ], $GLOBALS['wpdb']->calls );
        $GLOBALS['wpdb'] = null;
    }
```

In `PluginTest.php`, add:

```php
    public function testACompatibleBootCreatesTheBatchTableOnInitAndDropsItWithAMultisiteSite(): void
    {
        $this->assertTrue( Plugin::bootWith( '4.4.0', static fn (): bool => true ) );
        $this->assertArrayHasKey( 'init', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'wpmu_drop_tables', $GLOBALS['__wp_filters'] );
    }
```

- [ ] **Step 3: Run to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: FAIL (batch classes do not exist; uninstall/plugin assertions fail).

- [ ] **Step 4: Implement**

`pro-addon/src/Batch/BatchRepository.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The five storage operations the batch log needs. A row is
 * ['id'=>int, 'user_id'=>int, 'tool'=>string, 'created_at'=>'Y-m-d H:i:s' (UTC), 'summary'=>string,
 *  'status'=>'applied'|'undone'|'partly_undone', 'items'=>list<array>].
 */
interface BatchRepository
{
    /** @param array<string,mixed> $row a row without `id` @return ?int the new id, null when it could not be stored */
    public function insert( array $row ): ?int;

    /** @return array<string,mixed>|null */
    public function find( int $id ): ?array;

    /** @return list<array<string,mixed>> newest first */
    public function recent( int $limit ): array;

    /** @param list<array<string,mixed>> $items */
    public function update( int $id, string $status, array $items ): bool;

    /** Delete everything but the newest $keep rows. */
    public function trim( int $keep ): void;
}
```

`pro-addon/src/Batch/BatchTable.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The batch table: name, schema, create-on-demand (with a backoff when the host refuses) and cleanup. */
final class BatchTable
{
    public const DB_VERSION_OPTION = 'aied_pro_batches_db_version';
    public const BACKOFF_TRANSIENT = 'aied_pro_batches_backoff';
    public const BACKOFF_SECONDS   = 300;
    private const SCHEMA_VERSION   = 1;

    public static function name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'aied_pro_batches';
    }

    public static function schemaSql(): string
    {
        global $wpdb;

        $table   = self::name();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     bigint(20) UNSIGNED NOT NULL,
            tool        varchar(50)         NOT NULL,
            created_at  datetime            NOT NULL,
            summary     varchar(255)        NOT NULL,
            status      varchar(20)         NOT NULL,
            items       longtext            NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset};";
    }

    public static function createTable(): void
    {
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta( self::schemaSql() );
    }

    /** True when the table can be queried (a missing table yields null even for COUNT(*)). */
    public static function exists(): bool
    {
        global $wpdb;

        $table = self::name();
        $prev  = $wpdb->suppress_errors( true );
        $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $wpdb->suppress_errors( $prev );

        return null !== $count;
    }

    /**
     * Creates the table when it is missing. Runs on `init` (an auto-update does not re-run the activation hook)
     * and on activation (with $force, which ignores the backoff).
     */
    public static function ensureReady( bool $force = false ): void
    {
        if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
            return;
        }
        if ( ! $force && false !== get_transient( self::BACKOFF_TRANSIENT ) ) {
            return;
        }
        self::createTable();
        if ( ! self::exists() ) {
            // The host refused CREATE TABLE (or it failed): wait instead of retrying every request.
            set_transient( self::BACKOFF_TRANSIENT, 1, self::BACKOFF_SECONDS );

            return;
        }
        update_option( self::DB_VERSION_OPTION, self::SCHEMA_VERSION, true );
    }

    public static function drop(): void
    {
        global $wpdb;

        $table = self::name();
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        delete_option( self::DB_VERSION_OPTION );
        delete_transient( self::BACKOFF_TRANSIENT );
    }

    /**
     * `wpmu_drop_tables` filter: when a multisite site is deleted, drop this table too.
     *
     * @param mixed $tables the site's table names
     * @return list<string>
     */
    public static function dropTablesFilter( mixed $tables ): array
    {
        $tables   = is_array( $tables ) ? array_values( $tables ) : [];
        $tables[] = self::name();

        return array_values( array_unique( $tables ) );
    }
}
```

`pro-addon/src/Batch/WpdbBatchRepository.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The only code that queries the batch table (see BatchTable for the schema). */
final class WpdbBatchRepository implements BatchRepository
{
    public function insert( array $row ): ?int
    {
        global $wpdb;

        $items = wp_json_encode( $row['items'] ?? [] );
        if ( false === $items ) {
            return null;
        }
        $ok = $wpdb->insert(
            BatchTable::name(),
            [
                'user_id'    => (int) $row['user_id'],
                'tool'       => (string) $row['tool'],
                'created_at' => (string) $row['created_at'],
                'summary'    => (string) $row['summary'],
                'status'     => (string) $row['status'],
                'items'      => $items,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        return false === $ok ? null : (int) $wpdb->insert_id;
    }

    public function find( int $id ): ?array
    {
        global $wpdb;

        $table = BatchTable::name();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

        return is_array( $row ) ? self::hydrate( $row ) : null;
    }

    public function recent( int $limit ): array
    {
        global $wpdb;

        $table = BatchTable::name();
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 0, $limit ) ), ARRAY_A );

        return array_map( [ self::class, 'hydrate' ], is_array( $rows ) ? $rows : [] );
    }

    public function update( int $id, string $status, array $items ): bool
    {
        global $wpdb;

        $json = wp_json_encode( $items );
        if ( false === $json ) {
            return false;
        }

        return false !== $wpdb->update( BatchTable::name(), [ 'status' => $status, 'items' => $json ], [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );
    }

    public function trim( int $keep ): void
    {
        global $wpdb;

        $table = BatchTable::name();
        $ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1000 OFFSET %d", max( 0, $keep ) ) );
        if ( ! is_array( $ids ) || [] === $ids ) {
            return;
        }
        $wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate( array $row ): array
    {
        $items = json_decode( (string) ( $row['items'] ?? '' ), true );

        return [
            'id'         => (int) $row['id'],
            'user_id'    => (int) $row['user_id'],
            'tool'       => (string) $row['tool'],
            'created_at' => (string) $row['created_at'],
            'summary'    => (string) $row['summary'],
            'status'     => (string) $row['status'],
            'items'      => is_array( $items ) ? array_values( $items ) : [],
        ];
    }
}
```

`pro-addon/src/Batch/BatchLog.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Records Pro changes as batches (the last 20 are kept) and hands them back for undo. */
final class BatchLog
{
    public const MAX = 20;

    public function __construct( private BatchRepository $repo )
    {
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>|null the described batch, or null when nothing was recorded
     */
    public function record( string $tool, string $summary, array $items ): ?array
    {
        if ( [] === $items ) {
            return null;
        }
        $row = [
            'user_id'    => (int) get_current_user_id(),
            'tool'       => $tool,
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
            'summary'    => mb_substr( $summary, 0, 255 ),
            'status'     => 'applied',
            'items'      => array_values( $items ),
        ];
        $id = $this->repo->insert( $row );
        if ( null === $id ) {
            return null;
        }
        $this->repo->trim( self::MAX );

        return self::describe( [ 'id' => $id ] + $row );
    }

    /** @return array<string,mixed>|null */
    public function find( int $id ): ?array
    {
        return $this->repo->find( $id );
    }

    /** @return list<array<string,mixed>> newest first */
    public function recent( int $limit = self::MAX ): array
    {
        return $this->repo->recent( $limit );
    }

    /** @param list<array<string,mixed>> $items */
    public function markUndone( int $id, string $status, array $items ): bool
    {
        return $this->repo->update( $id, $status, $items );
    }

    /**
     * @param array<string,mixed> $batch
     * @return array<string,mixed>
     */
    public static function describe( array $batch ): array
    {
        $items  = is_array( $batch['items'] ?? null ) ? $batch['items'] : [];
        $points = 0;
        foreach ( $items as $item ) {
            if ( ! empty( $item['undo']['available'] ) && 'undone' !== ( $item['state'] ?? 'applied' ) ) {
                $points++;
            }
        }

        return [
            'id'          => (int) $batch['id'],
            'tool'        => (string) $batch['tool'],
            'summary'     => (string) $batch['summary'],
            'created_at'  => (string) $batch['created_at'],
            'status'      => (string) $batch['status'],
            'items'       => count( $items ),
            'undo_points' => $points,
            'undoable'    => $points > 0,
        ];
    }

    /**
     * Adds the batch to a tool's result. A write whose batch could not be recorded is still reported as made,
     * and says plainly that it cannot be undone.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $batch
     * @return array<string,mixed>
     */
    public static function attach( array $payload, ?array $batch ): array
    {
        $payload['batch'] = $batch ?? [
            'id'       => null,
            'undoable' => false,
            'note'     => 'The change was saved, but it could not be recorded, so undo_batch cannot undo it.',
        ];

        return $payload;
    }
}
```

`pro-addon/src/autoload.php`: insert before `Plugin.php`:

```php
require_once __DIR__ . '/Batch/BatchRepository.php';
require_once __DIR__ . '/Batch/BatchTable.php';
require_once __DIR__ . '/Batch/WpdbBatchRepository.php';
require_once __DIR__ . '/Batch/BatchLog.php';
```

`pro-addon/src/Plugin.php`: add `use AiEditorDivi5\Pro\Batch\BatchTable;` under the namespace line, and at the end of `wire()`:

```php
        add_action( 'init', [ BatchTable::class, 'ensureReady' ] );
        add_filter( 'wpmu_drop_tables', [ BatchTable::class, 'dropTablesFilter' ] );
```

`pro-addon/uninstall.php` becomes:

```php
<?php

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/src/Licensing.php';
require_once __DIR__ . '/src/Batch/BatchTable.php';

// Local state only: uninstalling never calls the licence server. It removes the add-on's own data and leaves
// the site's front page, menu, CSS and page statuses exactly as they are (undo those first if you want them back).
AiEditorDivi5\Pro\Licensing::clear();
AiEditorDivi5\Pro\Batch\BatchTable::drop();
```

- [ ] **Step 5: Run the whole Pro suite and the free suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml && make test`
Expected: both green.

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src pro-addon/uninstall.php pro-addon/tests
git commit -m "feat(pro): the batch store (table, repository, log trimmed to 20) with honest 'could not be recorded' results

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 7: The undo engine

**Files:**
- Create: `pro-addon/src/Batch/Item.php`, `ItemKind.php`, `ValueKind.php`, `BatchUndo.php`
- Modify: `pro-addon/src/autoload.php`
- Test: `pro-addon/tests/BatchUndoTest.php`, `pro-addon/tests/FakeKind.php` (test double)

**Interfaces:**
- Produces:
  - `Item::make(string $kind, string $ref, string $label, mixed $before, mixed $afterValue, ?array $undoPoint = null): array` (`after_hash = Canon::hash($afterValue)`; default undo point `['available'=>true]`; `state = 'applied'`); constants `Item::APPLIED = 'applied'`, `Item::UNDONE = 'undone'`.
  - `interface ItemKind { name(): string; current(array $item): mixed; allowed(array $item): bool; restore(array $item): array; }` where `current()` is the canonical value of the target right now (the value whose `Canon::hash` is compared with `after_hash`), `allowed()` re-checks the user's capability, and `restore()` returns `['ok'=>true, 'inverse'=>array]` (the item that would undo this undo) or `['ok'=>false, 'reason'=>string]`.
  - `abstract class ValueKind implements ItemKind`: implements `restore()` as "write `$item['before']` back, build the inverse from the value that was there"; a subclass implements `name()`, `current()`, `allowed()` and `protected function write(array $item, mixed $value): bool`.
  - `BatchUndo::__construct(array $kinds /* array<string,ItemKind> keyed by name */, BatchLog $log)`; `undo(int $batchId, bool $force = false, bool $dryRun = false): array`. Result: `['ok'=>false,'error'=>'not_found','message'=>...]` or `['ok'=>true,'batch_id'=>int,'dry_run'=>bool,'restored'=>int,'would_restore'=>int,'fully_undone'=>bool,'items'=>list<['kind','label','result','reason'?]>,'undo_batch'=>?array]`. Item `result` is one of `restored | would_restore | already_undone | changed_since | no_undo_point | forbidden | unknown_kind | failed`.

- [ ] **Step 1: Write the test double and the failing tests**

`pro-addon/tests/FakeKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\ValueKind;

require_once __DIR__ . '/../src/autoload.php';

/** A kind whose "site" is an array in $GLOBALS, with switches for permission and write failure. */
final class FakeKind extends ValueKind
{
    public function name(): string
    {
        return 'fake';
    }

    public function current( array $item ): mixed
    {
        return $GLOBALS['__fake_store'][ $item['ref'] ] ?? null;
    }

    public function allowed( array $item ): bool
    {
        return (bool) ( $GLOBALS['__fake_allowed'] ?? true );
    }

    protected function write( array $item, mixed $value ): bool
    {
        if ( $GLOBALS['__fake_fail'] ?? false ) {
            return false;
        }
        $GLOBALS['__fake_store'][ $item['ref'] ] = $value;
        $GLOBALS['__fake_writes'][]               = $item['ref'];

        return true;
    }
}
```

`pro-addon/tests/BatchUndoTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\Item;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/FakeKind.php';

class BatchUndoTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private BatchUndo $undo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->undo = new BatchUndo( [ 'fake' => new FakeKind() ], $this->log );
        $GLOBALS['__fake_store']   = [];
        $GLOBALS['__fake_writes']  = [];
        $GLOBALS['__fake_allowed'] = true;
        $GLOBALS['__fake_fail']    = false;
    }

    /** Changes the fake site and records the change as a batch of one item. */
    private function change( string $ref, mixed $new, ?array $undoPoint = null ): int
    {
        $old                               = $GLOBALS['__fake_store'][ $ref ] ?? null;
        $GLOBALS['__fake_store'][ $ref ]   = $new;
        $batch                             = $this->log->record( 'fake_tool', "change {$ref}", [ Item::make( 'fake', $ref, "Thing {$ref}", $old, $new, $undoPoint ) ] );

        return $batch['id'];
    }

    public function testItRestoresAnItemThatIsStillAsTheBatchLeftIt(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $r                            = $this->undo->undo( $id );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'one', $GLOBALS['__fake_store']['a'] );
        $this->assertSame( 1, $r['restored'] );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( 'restored', $r['items'][0]['result'] );
        $this->assertSame( 'undone', $this->repo->rows[ $id ]['status'] );
        $this->assertSame( 'undone', $this->repo->rows[ $id ]['items'][0]['state'] );
    }

    public function testAnUndoIsItselfABatchSoItCanBeUndone(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $first                        = $this->undo->undo( $id );
        $this->assertSame( 'undo_batch', $this->repo->rows[ $first['undo_batch']['id'] ]['tool'] );
        $this->undo->undo( $first['undo_batch']['id'] );
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'], 'undoing the undo brings the change back' );
    }

    public function testAnItemChangedSinceIsSkippedAndReportedNeverOverwritten(): void
    {
        $id                           = $this->change( 'a', 'two' );
        $GLOBALS['__fake_store']['a'] = 'the person edited this afterwards';
        $r                            = $this->undo->undo( $id );
        $this->assertSame( 'changed_since', $r['items'][0]['result'] );
        $this->assertSame( 0, $r['restored'] );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'the person edited this afterwards', $GLOBALS['__fake_store']['a'] );
        $this->assertSame( 'applied', $this->repo->rows[ $id ]['status'], 'nothing was undone' );
        $this->assertNull( $r['undo_batch'], 'no undo batch for an undo that restored nothing' );
    }

    public function testForceOverridesOnlyTheChangedSinceRule(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $GLOBALS['__fake_store']['a'] = 'edited';
        $r                            = $this->undo->undo( $id, true );
        $this->assertSame( 'restored', $r['items'][0]['result'] );
        $this->assertSame( 'one', $GLOBALS['__fake_store']['a'] );
    }

    public function testForceNeverInventsAnUndoPoint(): void
    {
        $id = $this->change( 'a', 'two', [ 'available' => false, 'reason' => 'too_large' ] );
        $r  = $this->undo->undo( $id, true );
        $this->assertSame( 'no_undo_point', $r['items'][0]['result'] );
        $this->assertSame( 'too_large', $r['items'][0]['reason'] );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( [], $GLOBALS['__fake_writes'] );
    }

    public function testPartialUndoRestoresWhatItCanAndSaysSo(): void
    {
        $GLOBALS['__fake_store'] = [ 'a' => 'a1', 'b' => 'b1' ];
        $batch                   = $this->log->record( 'multi', 'two things', [
            Item::make( 'fake', 'a', 'A', 'a1', 'a2' ),
            Item::make( 'fake', 'b', 'B', 'b1', 'b2', [ 'available' => false, 'reason' => 'missing' ] ),
        ] );
        $GLOBALS['__fake_store'] = [ 'a' => 'a2', 'b' => 'b2' ];
        $r                       = $this->undo->undo( $batch['id'] );
        $this->assertSame( 1, $r['restored'] );
        $this->assertFalse( $r['fully_undone'], 'only claims fully undone when every item restored' );
        $this->assertSame( [ 'restored', 'no_undo_point' ], array_column( $r['items'], 'result' ), 'reported in the batch\'s own order' );
        $this->assertSame( 'partly_undone', $this->repo->rows[ $batch['id'] ]['status'] );
    }

    public function testItemsAreRestoredLastFirst(): void
    {
        $GLOBALS['__fake_store'] = [ 'a' => 'a1', 'b' => 'b1' ];
        $batch                   = $this->log->record( 'multi', 'two things', [ Item::make( 'fake', 'a', 'A', 'a1', 'a2' ), Item::make( 'fake', 'b', 'B', 'b1', 'b2' ) ] );
        $GLOBALS['__fake_store'] = [ 'a' => 'a2', 'b' => 'b2' ];
        $this->undo->undo( $batch['id'] );
        $this->assertSame( [ 'b', 'a' ], $GLOBALS['__fake_writes'] );
    }

    public function testAnAlreadyUndoneBatchIsReportedAndNothingIsWrittenOrRecordedAgain(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $this->undo->undo( $id );
        $rowsBefore = count( $this->repo->rows );
        $r          = $this->undo->undo( $id );
        $this->assertSame( 'already_undone', $r['items'][0]['result'] );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( 0, $r['restored'] );
        $this->assertCount( $rowsBefore, $this->repo->rows );
    }

    public function testAnItemTheUserMayNotTouchIsSkipped(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $GLOBALS['__fake_allowed']    = false;
        $r                            = $this->undo->undo( $id );
        $this->assertSame( 'forbidden', $r['items'][0]['result'] );
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'] );
    }

    public function testAnUnknownKindAndAFailedWriteAreReportedNotSwallowed(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $this->repo->rows[ $id ]['items'][0]['kind'] = 'alien';
        $this->assertSame( 'unknown_kind', $this->undo->undo( $id )['items'][0]['result'] );

        $this->repo->rows[ $id ]['items'][0]['kind'] = 'fake';
        $GLOBALS['__fake_fail']                      = true;
        $r                                           = $this->undo->undo( $id );
        $this->assertSame( 'failed', $r['items'][0]['result'] );
        $this->assertSame( 'applied', $this->repo->rows[ $id ]['items'][0]['state'], 'a failed restore leaves the item undoable' );
    }

    public function testAKindThatThrowsIsReportedAsFailed(): void
    {
        $thrower = new class() extends \AiEditorDivi5\Pro\Batch\ValueKind {
            public function name(): string { return 'fake'; }
            public function current( array $item ): mixed { return 'two'; }
            public function allowed( array $item ): bool { return true; }
            protected function write( array $item, mixed $value ): bool { throw new \RuntimeException( 'secret path /var/www' ); }
        };
        $undo  = new BatchUndo( [ 'fake' => $thrower ], $this->log );
        $batch = $this->log->record( 't', 's', [ Item::make( 'fake', 'a', 'A', 'one', 'two' ) ] );
        $r     = $undo->undo( $batch['id'] );
        $this->assertSame( 'failed', $r['items'][0]['result'] );
        $this->assertStringNotContainsString( 'secret', (string) json_encode( $r ) );
    }

    public function testDryRunWritesAndRecordsNothing(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $rows                         = count( $this->repo->rows );
        $r                            = $this->undo->undo( $id, false, true );
        $this->assertTrue( $r['dry_run'] );
        $this->assertSame( 'would_restore', $r['items'][0]['result'] );
        $this->assertSame( 1, $r['would_restore'] );
        $this->assertSame( 0, $r['restored'] );
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'] );
        $this->assertCount( $rows, $this->repo->rows );
        $this->assertSame( 'applied', $this->repo->rows[ $id ]['status'] );
    }

    public function testAnUnknownBatchIsNotFound(): void
    {
        $r = $this->undo->undo( 99 );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'not_found', $r['error'] );
    }

    public function testUndoingTheOldestBatchStillSucceedsWhenRecordingTheUndoTrimsIt(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $first                        = $this->change( 'a', 'two' );
        for ( $i = 0; $i < 19; $i++ ) {
            $this->log->record( 't', 'filler', [ Item::make( 'fake', 'z', 'Z', 1, 2 ) ] );
        }
        $r = $this->undo->undo( $first );
        $this->assertSame( 'one', $GLOBALS['__fake_store']['a'] );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertNotNull( $r['undo_batch'] );
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter BatchUndoTest`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement**

`pro-addon/src/Batch/Item.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

use AiEditorDivi5\Pro\Canon;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Builds the stored record of one thing a batch changed. */
final class Item
{
    public const APPLIED = 'applied';
    public const UNDONE  = 'undone';

    /**
     * @param mixed $before what the target looked like before (kind-specific, JSON-safe; may be richer than the canonical value)
     * @param mixed $afterValue the canonical value the target has now: what the batch wrote (hashed, never stored)
     * @param array{available:bool, reason?:?string, version_id?:?int}|null $undoPoint null = an ordinary, available undo point
     * @return array<string,mixed>
     */
    public static function make( string $kind, string $ref, string $label, mixed $before, mixed $afterValue, ?array $undoPoint = null ): array
    {
        return [
            'kind'       => $kind,
            'ref'        => $ref,
            'label'      => $label,
            'before'     => $before,
            'after_hash' => Canon::hash( $afterValue ),
            'undo'       => $undoPoint ?? [ 'available' => true ],
            'state'      => self::APPLIED,
        ];
    }
}
```

`pro-addon/src/Batch/ItemKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** How one kind of target (an option, a CSS block, a menu, a page's status or content) is read and put back. */
interface ItemKind
{
    /** The `kind` string stored in items. */
    public function name(): string;

    /** The canonical value of the target right now: its `Canon::hash` is compared with the item's `after_hash`. */
    public function current( array $item ): mixed;

    /** Whether the current user may restore this item (re-checked at undo time). */
    public function allowed( array $item ): bool;

    /**
     * Put the previous value back.
     *
     * @return array{ok:true, inverse:array<string,mixed>}|array{ok:false, reason:string} the inverse is the item that undoes this undo
     */
    public function restore( array $item ): array;
}
```

`pro-addon/src/Batch/ValueKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The common case: the item's `before` is the whole previous value and putting it back is one write. */
abstract class ValueKind implements ItemKind
{
    /** Write $value to the target. Return false when it could not be written. */
    abstract protected function write( array $item, mixed $value ): bool;

    public function restore( array $item ): array
    {
        $now = $this->current( $item );
        if ( ! $this->write( $item, $item['before'] ?? null ) ) {
            return [ 'ok' => false, 'reason' => 'write_failed' ];
        }

        return [ 'ok' => true, 'inverse' => Item::make( $this->name(), (string) $item['ref'], (string) $item['label'], $now, $item['before'] ?? null ) ];
    }
}
```

`pro-addon/src/Batch/BatchUndo.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

use AiEditorDivi5\Pro\Canon;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Undoes a batch honestly: every item that can be restored is, the rest are reported with the reason, and
 * "fully undone" is claimed only when every item is back. An item whose target changed since the batch wrote
 * it is never overwritten (unless `force`), and an item without an undo point is never invented one.
 */
final class BatchUndo
{
    /** @param array<string,ItemKind> $kinds keyed by kind name */
    public function __construct( private array $kinds, private BatchLog $log )
    {
    }

    /** @return array<string,mixed> */
    public function undo( int $batchId, bool $force = false, bool $dryRun = false ): array
    {
        $batch = $this->log->find( $batchId );
        if ( null === $batch ) {
            return [ 'ok' => false, 'error' => 'not_found', 'message' => "Batch {$batchId} not found." ];
        }

        $items    = $batch['items'];
        $report   = [];
        $inverse  = [];
        $restored = 0;
        $would    = 0;
        for ( $i = count( $items ) - 1; $i >= 0; $i-- ) {
            $item  = $items[ $i ];
            $row   = [ 'kind' => (string) ( $item['kind'] ?? '' ), 'label' => (string) ( $item['label'] ?? '' ) ];
            $kind  = $this->kinds[ $row['kind'] ] ?? null;
            $check = $this->check( $item, $kind, $force );
            if ( null !== $check ) {
                $report[ $i ] = $row + $check;
                continue;
            }
            if ( $dryRun ) {
                $would++;
                $report[ $i ] = $row + [ 'result' => 'would_restore' ];
                continue;
            }
            try {
                $r = $kind->restore( $item );
            } catch ( \Throwable $e ) {
                $r = [ 'ok' => false, 'reason' => 'exception' ];
            }
            if ( empty( $r['ok'] ) ) {
                $report[ $i ] = $row + [ 'result' => 'failed', 'reason' => (string) ( $r['reason'] ?? 'failed' ) ];
                continue;
            }
            $restored++;
            $items[ $i ]['state'] = Item::UNDONE;
            $inverse[]            = $r['inverse'];
            $report[ $i ]         = $row + [ 'result' => 'restored' ];
        }
        ksort( $report );

        $allUndone = true;
        foreach ( $items as $item ) {
            if ( Item::UNDONE !== ( $item['state'] ?? Item::APPLIED ) ) {
                $allUndone = false;
            }
        }

        $undoBatch = null;
        if ( $restored > 0 ) {
            // Mark the original first: recording the undo trims to the newest 20 and may remove the oldest batch.
            $this->log->markUndone( $batchId, $allUndone ? 'undone' : 'partly_undone', $items );
            $undoBatch = $this->log->record( 'undo_batch', 'Undo of batch #' . $batchId . ': ' . $batch['summary'], $inverse );
        }

        return [
            'ok'            => true,
            'batch_id'      => $batchId,
            'dry_run'       => $dryRun,
            'restored'      => $restored,
            'would_restore' => $would,
            'fully_undone'  => ! $dryRun && $allUndone,
            'items'         => array_values( $report ),
            'undo_batch'    => $undoBatch,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null null = this item may be restored; otherwise its report fields
     */
    private function check( array $item, ?ItemKind $kind, bool $force ): ?array
    {
        if ( Item::UNDONE === ( $item['state'] ?? Item::APPLIED ) ) {
            return [ 'result' => 'already_undone' ];
        }
        if ( null === $kind ) {
            return [ 'result' => 'unknown_kind' ];
        }
        if ( empty( $item['undo']['available'] ) ) {
            return [ 'result' => 'no_undo_point', 'reason' => $item['undo']['reason'] ?? null ];
        }
        if ( ! $kind->allowed( $item ) ) {
            return [ 'result' => 'forbidden' ];
        }
        if ( ! $force && Canon::hash( $kind->current( $item ) ) !== (string) ( $item['after_hash'] ?? '' ) ) {
            return [ 'result' => 'changed_since' ];
        }

        return null;
    }
}
```

In `autoload.php` insert before `Plugin.php`:

```php
require_once __DIR__ . '/Batch/Item.php';
require_once __DIR__ . '/Batch/ItemKind.php';
require_once __DIR__ . '/Batch/ValueKind.php';
require_once __DIR__ . '/Batch/BatchUndo.php';
```

- [ ] **Step 4: Run the whole Pro suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: all green. (If `testAKindThatThrowsIsReportedAsFailed` fails because `failOnWarning` flags nothing, no change is needed; the anonymous class implements the abstract method `write` that throws, and the engine catches it.)

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src pro-addon/tests
git commit -m "feat(pro): the undo engine (changed-since rule, honest partial undo, undo is itself a batch)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---
## Task 8: `set_front_page` (and the shared `Batches` factory)

**Files:**
- Create: `pro-addon/src/Batch/Batches.php`, `pro-addon/src/Kinds/FrontPageKind.php`, `pro-addon/src/Tools/SetFrontPage.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Tools.php`
- Test: `pro-addon/tests/SetFrontPageTest.php`

**Interfaces:**
- Consumes: `ProTool` (existing), `Args::int/bool`, `BatchLog::record/attach`, `Item::make`, `ValueKind`, `BatchUndo`.
- Produces:
  - `Batches::log(): BatchLog` (a shared `BatchLog` over `WpdbBatchRepository`), `Batches::kinds(): array<string,ItemKind>` (each later tool task adds its kind here), `Batches::undo(): BatchUndo`, `Batches::resetForTests(): void`.
  - `FrontPageKind` (kind name `front_page`; canonical value `['show_on_front'=>string,'page_on_front'=>int]`; needs `manage_options`); `FrontPageKind::read(): array`, `FrontPageKind::set(string $show, int $page): void`.
  - `SetFrontPage::NAME = 'set_front_page'`; `new SetFrontPage(BatchLog $log)`.
  - Tool result: `['changed'=>bool,'dry_run'=>bool,'front_page'=>['id'=>int,'title'=>string],'batch'=>[...]]`; a preview returns `['changed'=>false,'dry_run'=>true,'would_change'=>['from'=>array,'to'=>array]]`; setting what is already set returns `['changed'=>false, ..., 'message'=>...]` with no batch.

- [ ] **Step 1: Write the failing tests**

`pro-addon/tests/SetFrontPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\FrontPageKind;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\SetFrontPage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

class SetFrontPageTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private SetFrontPage $tool;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->tool = new SetFrontPage( $this->log );
        $GLOBALS['__wp_options'] = [ 'show_on_front' => 'posts' ];
        $GLOBALS['__wp_posts']   = [
            5 => (object) [ 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_content' => '' ],
            6 => (object) [ 'ID' => 6, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Soon', 'post_content' => '' ],
            7 => (object) [ 'ID' => 7, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'A post', 'post_content' => '' ],
        ];
        $GLOBALS['__wp_can'] = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    private function fails( array $args, string $message ): void
    {
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( 'posts', $GLOBALS['__wp_options']['show_on_front'], 'nothing was changed' );
    }

    public function testItSetsTheFrontPageAndRecordsAnUndoableBatch(): void
    {
        $r = $this->tool->run( [ 'page_id' => 5 ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ 'id' => 5, 'title' => 'Home' ], $r['front_page'] );
        $this->assertSame( 'page', $GLOBALS['__wp_options']['show_on_front'] );
        $this->assertSame( 5, $GLOBALS['__wp_options']['page_on_front'] );
        $this->assertSame( 1, $r['batch']['id'] );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertSame( 'set_front_page', $this->repo->rows[1]['tool'] );
    }

    public function testTheArgumentsAreTypeCheckedNeverCast(): void
    {
        $this->fails( [], '"page_id" is required.' );
        $this->fails( [ 'page_id' => '5' ], '"page_id" must be an integer.' );
        $this->fails( [ 'page_id' => 5.0 ], '"page_id" must be an integer.' );
        $this->fails( [ 'page_id' => 5, 'dry_run' => 'yes' ], '"dry_run" must be true or false.' );
        $this->fails( [ 'page_id' => 5, 'dry_run' => 1 ], '"dry_run" must be true or false.' );
    }

    public function testOnlyAnExistingPublishedPageIsAccepted(): void
    {
        $this->fails( [ 'page_id' => 99 ], 'Page 99 not found.' );
        $this->fails( [ 'page_id' => 7 ], 'Page 7 not found.' );
        $this->fails( [ 'page_id' => 6 ], 'not published' );
    }

    public function testADryRunChangesNothingAndRecordsNothing(): void
    {
        $r = $this->tool->run( [ 'page_id' => 5, 'dry_run' => true ] );
        $this->assertFalse( $r['changed'] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertSame( [ 'show_on_front' => 'posts', 'page_on_front' => 0 ], $r['would_change']['from'] );
        $this->assertSame( [ 'show_on_front' => 'page', 'page_on_front' => 5 ], $r['would_change']['to'] );
        $this->assertSame( 'posts', $GLOBALS['__wp_options']['show_on_front'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testSettingWhatIsAlreadySetChangesNothingAndRecordsNothing(): void
    {
        $this->tool->run( [ 'page_id' => 5 ] );
        $r = $this->tool->run( [ 'page_id' => 5 ] );
        $this->assertFalse( $r['changed'] );
        $this->assertStringContainsString( 'already', $r['message'] );
        $this->assertArrayNotHasKey( 'batch', $r );
        $this->assertCount( 1, $this->repo->rows );
    }

    public function testAWriteWhoseBatchCannotBeRecordedStillReportsTheWriteHonestly(): void
    {
        $this->repo->failInsert = true;
        $r                      = $this->tool->run( [ 'page_id' => 5 ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 'page', $GLOBALS['__wp_options']['show_on_front'] );
        $this->assertNull( $r['batch']['id'] );
        $this->assertFalse( $r['batch']['undoable'] );
    }

    public function testUndoPutsTheOldFrontPageSettingsBack(): void
    {
        $GLOBALS['__wp_options'] = [ 'show_on_front' => 'page', 'page_on_front' => 3 ];
        $r                       = $this->tool->run( [ 'page_id' => 5 ] );
        $undo                    = ( new BatchUndo( [ 'front_page' => new FrontPageKind() ], $this->log ) )->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'] );
        $this->assertSame( 'page', $GLOBALS['__wp_options']['show_on_front'] );
        $this->assertSame( 3, $GLOBALS['__wp_options']['page_on_front'] );
    }

    public function testUndoRestoresTheBlogPostsHomepage(): void
    {
        $r = $this->tool->run( [ 'page_id' => 5 ] );
        ( new BatchUndo( [ 'front_page' => new FrontPageKind() ], $this->log ) )->undo( $r['batch']['id'] );
        $this->assertSame( 'posts', $GLOBALS['__wp_options']['show_on_front'] );
        $this->assertSame( 0, $GLOBALS['__wp_options']['page_on_front'] );
    }

    public function testUndoSkipsAFrontPageTheOwnerChangedSince(): void
    {
        $r = $this->tool->run( [ 'page_id' => 5 ] );
        update_option( 'page_on_front', 9 );
        $undo = ( new BatchUndo( [ 'front_page' => new FrontPageKind() ], $this->log ) )->undo( $r['batch']['id'] );
        $this->assertSame( 'changed_since', $undo['items'][0]['result'] );
        $this->assertSame( 9, $GLOBALS['__wp_options']['page_on_front'] );
    }

    public function testItIsRegisteredAndNeedsManageOptions(): void
    {
        $this->assertContains( 'set_front_page', array_map( static fn ( $t ) => $t->name(), Tools::all() ) );
        $this->assertSame( 'manage_options', $this->tool->capability() );
        $d = $this->tool->definition();
        $this->assertSame( 'set_front_page', $d['name'] );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'page_id' ], $d['inputSchema']['required'] );
    }

    public function testAnEditorWithoutManageOptionsIsRefusedBeforeAnythingRuns(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $registry             = new ToolRegistry( [ $this->tool ], static fn (): bool => true );
        $d                    = (array) $registry->claim( null, 'set_front_page', [ 'page_id' => 5 ], 1 )->get_data();
        $this->assertSame( -32602, $d['error']['code'] );
        $this->assertSame( 'posts', $GLOBALS['__wp_options']['show_on_front'] );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter SetFrontPageTest`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement**

`pro-addon/src/Kinds/FrontPageKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\ValueKind;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The static front page: the `show_on_front` and `page_on_front` options, read and written as one value. */
final class FrontPageKind extends ValueKind
{
    public function name(): string
    {
        return 'front_page';
    }

    /** @return array{show_on_front:string, page_on_front:int} */
    public static function read(): array
    {
        return [ 'show_on_front' => (string) get_option( 'show_on_front', 'posts' ), 'page_on_front' => (int) get_option( 'page_on_front', 0 ) ];
    }

    public static function set( string $show, int $page ): void
    {
        update_option( 'show_on_front', $show );
        update_option( 'page_on_front', $page );
    }

    public function current( array $item = [] ): mixed
    {
        return self::read();
    }

    public function allowed( array $item ): bool
    {
        return current_user_can( 'manage_options' );
    }

    protected function write( array $item, mixed $value ): bool
    {
        if ( ! is_array( $value ) || ! isset( $value['show_on_front'], $value['page_on_front'] ) ) {
            return false;
        }
        self::set( (string) $value['show_on_front'], (int) $value['page_on_front'] );

        return true;
    }
}
```

`pro-addon/src/Tools/SetFrontPage.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Kinds\FrontPageKind;
use AiEditorDivi5\Pro\ProTool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Set a published page as the static front page. One undoable batch. */
final class SetFrontPage implements ProTool
{
    public const NAME = 'set_front_page';

    public function __construct( private BatchLog $log )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'manage_options';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Set a published page as the site\'s static front page (homepage). Recorded as an undoable batch. Pass dry_run=true to preview without changing anything.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'page_id' => [ 'type' => 'integer', 'description' => 'ID of a published page' ],
                    'dry_run' => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'page_id' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $pageId = (int) Args::int( $arguments, 'page_id' );
        $dry    = Args::bool( $arguments, 'dry_run' );

        $post = get_post( $pageId );
        if ( ! $post || 'page' !== $post->post_type ) {
            throw new \InvalidArgumentException( "Page {$pageId} not found." );
        }
        if ( 'publish' !== $post->post_status ) {
            throw new \InvalidArgumentException( "Page {$pageId} is not published. Publish it first (publish_page), then set it as the front page." );
        }

        $title  = (string) get_the_title( $post );
        $page   = [ 'id' => $pageId, 'title' => $title ];
        $before = FrontPageKind::read();
        $after  = [ 'show_on_front' => 'page', 'page_on_front' => $pageId ];

        if ( $before === $after ) {
            return [ 'changed' => false, 'dry_run' => $dry, 'front_page' => $page, 'message' => 'That page is already the front page; nothing changed.' ];
        }
        if ( $dry ) {
            return [ 'changed' => false, 'dry_run' => true, 'front_page' => $page, 'would_change' => [ 'from' => $before, 'to' => $after ] ];
        }

        FrontPageKind::set( 'page', $pageId );
        $batch = $this->log->record(
            self::NAME,
            "Set front page to \"{$title}\" (#{$pageId})",
            [ Item::make( 'front_page', 'front_page', 'Front page', $before, FrontPageKind::read() ) ]
        );

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'front_page' => $page ], $batch );
    }
}
```

`pro-addon/src/Batch/Batches.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Batch;

use AiEditorDivi5\Pro\Kinds\FrontPageKind;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The shared batch log, the registry of item kinds and the undo engine built on them. */
final class Batches
{
    private static ?BatchLog $log   = null;
    private static ?BatchUndo $undo = null;

    public static function log(): BatchLog
    {
        return self::$log ??= new BatchLog( new WpdbBatchRepository() );
    }

    /** @return array<string,ItemKind> keyed by kind name */
    public static function kinds(): array
    {
        $kinds = [
            new FrontPageKind(),
        ];
        $out = [];
        foreach ( $kinds as $kind ) {
            $out[ $kind->name() ] = $kind;
        }

        return $out;
    }

    public static function undo(): BatchUndo
    {
        return self::$undo ??= new BatchUndo( self::kinds(), self::log() );
    }

    /** @internal test isolation only */
    public static function resetForTests(): void
    {
        self::$log  = null;
        self::$undo = null;
    }
}
```

`autoload.php`, before `Plugin.php`:

```php
require_once __DIR__ . '/Kinds/FrontPageKind.php';
require_once __DIR__ . '/Batch/Batches.php';
require_once __DIR__ . '/Tools/SetFrontPage.php';
```

`pro-addon/src/Tools.php`: replace the class body with

```php
/** The list of Pro tools. */
final class Tools
{
    /** @return list<ProTool> */
    public static function all(): array
    {
        $log = Batches::log();

        return [
            new SetFrontPage( $log ),
        ];
    }
}
```

and add `use AiEditorDivi5\Pro\Batch\Batches;` and `use AiEditorDivi5\Pro\Tools\SetFrontPage;` under the namespace line (update the class docblock to "The list of Pro tools.").

- [ ] **Step 4: Run the Pro suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: all green. (`ProPageTest`/`PluginTest` still pass: they build their own registries or boot while locked.)

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src pro-addon/tests
git commit -m "feat(pro): set_front_page with preview and one-step undo

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 9: `set_custom_css`

**Files:**
- Create: `pro-addon/src/Settings/CustomCss.php`, `pro-addon/src/Kinds/CssKind.php`, `pro-addon/src/Tools/SetCustomCss.php`, `pro-addon/tests/WpSettingsShims.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Batch/Batches.php` (add the kind), `pro-addon/src/Tools.php`, `pro-addon/tests/bootstrap.php` (require the shims)
- Test: `pro-addon/tests/CustomCssTest.php`, `pro-addon/tests/SetCustomCssTest.php`

**Interfaces:**
- Produces:
  - `Settings\CustomCss`: `BEGIN`, `END` (the same markers the old 3.x premium tool wrote, so an old managed block is replaced in place), `MAX_BYTES = 100000`; `problem(string $css): ?string`; `extract(string $all): ?string` (the CSS inside the managed block, `null` when there is none); `apply(string $all, ?string $css): string` (put `$css` in the block, creating or replacing it; `null` removes the block; everything outside the block is kept byte-for-byte; `$` and `\` in the CSS are literal).
  - `CssKind` (kind name `custom_css`; canonical value = `CustomCss::extract(wp_get_custom_css())`; needs `edit_css`).
  - `SetCustomCss::NAME = 'set_custom_css'`; `new SetCustomCss(BatchLog $log)`; capability `edit_css`.
  - Tool result: `['changed'=>bool,'dry_run'=>bool,'bytes'=>int,'batch'=>[...]]`; preview: `['changed'=>false,'dry_run'=>true,'would_change'=>['from_bytes'=>?int,'to_bytes'=>int]]`.

- [ ] **Step 1: Write the settings shims**

`pro-addon/tests/WpSettingsShims.php` (later tasks append to it):

```php
<?php

declare(strict_types=1);

/**
 * In-memory WordPress settings shims for the Pro tools: Additional CSS now, menus and post status/permalink
 * helpers in later tasks. Every definition is guarded so it never clashes with another shim.
 */

$GLOBALS['__wp_custom_css']      = '';
$GLOBALS['__wp_custom_css_fail'] = false;

if ( ! function_exists( 'wp_get_custom_css' ) ) {
    function wp_get_custom_css( $stylesheet = '' ) { return (string) $GLOBALS['__wp_custom_css']; }
}
if ( ! function_exists( 'wp_update_custom_css_post' ) ) {
    function wp_update_custom_css_post( $css, $args = [] ) {
        if ( $GLOBALS['__wp_custom_css_fail'] ) {
            return new WP_Error( 'css_failed', 'simulated css failure' );
        }
        $GLOBALS['__wp_custom_css'] = (string) $css;

        return (object) [ 'ID' => 900, 'post_content' => (string) $css ];
    }
}
```

Add to the end of `pro-addon/tests/bootstrap.php`: `require_once __DIR__ . '/WpSettingsShims.php';`

- [ ] **Step 2: Write the failing tests**

`pro-addon/tests/CustomCssTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\CustomCss;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class CustomCssTest extends TestCase
{
    public function testApplyAddsABlockAfterThePersonsOwnCssAndExtractReadsItBack(): void
    {
        $out = CustomCss::apply( "body { color: red; }\n", '.a { color: blue; }' );
        $this->assertStringStartsWith( "body { color: red; }\n\n" . CustomCss::BEGIN, $out );
        $this->assertSame( '.a { color: blue; }', CustomCss::extract( $out ) );
    }

    public function testApplyOnEmptyCssWritesJustTheBlock(): void
    {
        $out = CustomCss::apply( '', '.a{}' );
        $this->assertSame( CustomCss::BEGIN . "\n.a{}\n" . CustomCss::END, $out );
    }

    public function testApplyReplacesTheBlockInPlaceAndNeverDuplicatesIt(): void
    {
        $one = CustomCss::apply( "top{}\n", '.a{}' ) . "\n\nbottom{}";
        $two = CustomCss::apply( $one, '.b{}' );
        $this->assertSame( 1, substr_count( $two, CustomCss::BEGIN ) );
        $this->assertSame( '.b{}', CustomCss::extract( $two ) );
        $this->assertStringStartsWith( 'top{}', $two );
        $this->assertStringEndsWith( "\n\nbottom{}", $two );
    }

    public function testDollarSignsAndBackslashesInTheCssAreLiteral(): void
    {
        foreach ( [ '.a::before { content: "$1 \\0 $0 \\\\ ${x}"; }', 'a{b:c}$2', '\\1' ] as $css ) {
            $with = CustomCss::apply( CustomCss::apply( 'x{}', '.old{}' ), $css );
            $this->assertSame( $css, CustomCss::extract( $with ), $css );
        }
    }

    public function testRemovingTheBlockLeavesThePersonsCssIntact(): void
    {
        $own  = "body { color: red; }\n\n.footer { margin: 0; }";
        $with = CustomCss::apply( $own, '.a{}' );
        $this->assertSame( $own, CustomCss::apply( $with, null ) );
        $this->assertSame( $own, CustomCss::apply( $own, null ), 'no block: nothing to remove' );
        $this->assertSame( '', CustomCss::apply( CustomCss::apply( '', '.a{}' ), null ) );
    }

    public function testRemovingABlockInTheMiddleJoinsTheTwoSides(): void
    {
        $all = "top{}\n\n" . CustomCss::BEGIN . "\n.a{}\n" . CustomCss::END . "\n\nbottom{}";
        $this->assertSame( "top{}\n\nbottom{}", CustomCss::apply( $all, null ) );
    }

    public function testExtractWithoutACompleteBlockIsNull(): void
    {
        $this->assertNull( CustomCss::extract( 'body{}' ) );
        $this->assertNull( CustomCss::extract( CustomCss::BEGIN . "\n.a{}" ) );
        $this->assertSame( '', CustomCss::extract( CustomCss::BEGIN . "\n" . CustomCss::END ) );
    }

    /** @return array<string,array{string}> */
    public static function badCss(): array
    {
        return [
            'empty'                 => [ '   ' ],
            'a closing style tag'   => [ '.a{} </style><script>alert(1)</script>' ],
            'a style tag'           => [ '<style>.a{}</style>' ],
            'a script tag'          => [ '.a{} <  script src=x>' ],
            'an html comment'       => [ '.a{} <!-- x' ],
            'the begin marker'      => [ '.a{} ' . CustomCss::BEGIN ],
            'the end marker'        => [ CustomCss::END . ' .a{}' ],
            'a control character'   => [ ".a{}\x00" ],
            'too large'             => [ str_repeat( 'a', CustomCss::MAX_BYTES + 1 ) ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'badCss' )]
    public function testProblemRejectsCssThatCouldBreakOutOfTheBlockOrThePage( string $css ): void
    {
        $this->assertNotNull( CustomCss::problem( $css ) );
    }

    public function testProblemAcceptsOrdinaryCss(): void
    {
        $css = "@media (max-width: 600px) { .a > .b:hover::after { content: \"a < b\"; transform: translate(0, 5px); } }\n/* ok */";
        $this->assertNull( CustomCss::problem( $css ) );
    }
}
```

`pro-addon/tests/SetCustomCssTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\CssKind;
use AiEditorDivi5\Pro\Settings\CustomCss;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\SetCustomCss;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

class SetCustomCssTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private SetCustomCss $tool;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->tool = new SetCustomCss( $this->log );
        $GLOBALS['__wp_custom_css']      = "body { color: red; }\n";
        $GLOBALS['__wp_custom_css_fail'] = false;
        $GLOBALS['__wp_can']             = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ 'custom_css' => new CssKind() ], $this->log );
    }

    private function fails( array $args, string $message ): void
    {
        $before = $GLOBALS['__wp_custom_css'];
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( $before, $GLOBALS['__wp_custom_css'], 'nothing was changed' );
    }

    public function testItAddsTheManagedBlockAndKeepsThePersonsCss(): void
    {
        $r = $this->tool->run( [ 'css' => '.hero { color: blue; }' ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( strlen( '.hero { color: blue; }' ), $r['bytes'] );
        $this->assertStringStartsWith( "body { color: red; }\n", wp_get_custom_css() );
        $this->assertSame( '.hero { color: blue; }', CustomCss::extract( wp_get_custom_css() ) );
        $this->assertTrue( $r['batch']['undoable'] );
    }

    public function testItReplacesTheBlockOnEachCall(): void
    {
        $this->tool->run( [ 'css' => '.a{}' ] );
        $this->tool->run( [ 'css' => '.b{}' ] );
        $this->assertSame( 1, substr_count( wp_get_custom_css(), CustomCss::BEGIN ) );
        $this->assertSame( '.b{}', CustomCss::extract( wp_get_custom_css() ) );
    }

    public function testDollarAndBackslashCssIsStoredLiterally(): void
    {
        $css = '.a::before { content: "$1 \\0 $0"; }';
        $this->tool->run( [ 'css' => '.old{}' ] );
        $this->tool->run( [ 'css' => $css ] );
        $this->assertSame( $css, CustomCss::extract( wp_get_custom_css() ) );
    }

    public function testTheSameCssAgainChangesNothingAndRecordsNothing(): void
    {
        $this->tool->run( [ 'css' => '.a{}' ] );
        $r = $this->tool->run( [ 'css' => "  .a{}\n" ] );
        $this->assertFalse( $r['changed'] );
        $this->assertArrayNotHasKey( 'batch', $r );
        $this->assertCount( 1, $this->repo->rows );
    }

    public function testADryRunChangesAndRecordsNothing(): void
    {
        $r = $this->tool->run( [ 'css' => '.a{}', 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ 'from_bytes' => null, 'to_bytes' => 4 ], $r['would_change'] );
        $this->assertSame( "body { color: red; }\n", wp_get_custom_css() );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testBadArgumentsAndDangerousCssAreRejected(): void
    {
        $this->fails( [], '"css" is required.' );
        $this->fails( [ 'css' => 5 ], '"css" must be a string.' );
        $this->fails( [ 'css' => '   ' ], '"css" must not be empty.' );
        $this->fails( [ 'css' => '.a{}', 'dry_run' => 'yes' ], '"dry_run" must be true or false.' );
        $this->fails( [ 'css' => '.a{} </style><script>x</script>' ], 'HTML' );
        $this->fails( [ 'css' => CustomCss::END ], 'markers' );
        $this->fails( [ 'css' => str_repeat( 'a', CustomCss::MAX_BYTES + 1 ) ], '"css" must be at most 100000 bytes.' );
    }

    public function testAFailedSaveIsAnErrorNotASilentSuccess(): void
    {
        $GLOBALS['__wp_custom_css_fail'] = true;
        $this->expectException( \RuntimeException::class );
        $this->tool->run( [ 'css' => '.a{}' ] );
    }

    public function testUndoRemovesABlockThatDidNotExistAndLeavesThePersonsCssByteForByte(): void
    {
        $original = $GLOBALS['__wp_custom_css'];
        $r        = $this->tool->run( [ 'css' => '.a{}' ] );
        $undo     = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'] );
        $this->assertSame( rtrim( $original ), rtrim( wp_get_custom_css() ) );
        $this->assertStringNotContainsString( CustomCss::BEGIN, wp_get_custom_css() );
    }

    public function testUndoPutsAPreviousBlockBack(): void
    {
        $this->tool->run( [ 'css' => '.first{}' ] );
        $second = $this->tool->run( [ 'css' => '.second{}' ] );
        $this->undo()->undo( $second['batch']['id'] );
        $this->assertSame( '.first{}', CustomCss::extract( wp_get_custom_css() ) );
    }

    public function testEditsOutsideTheBlockDoNotBlockUndoButEditsInsideDo(): void
    {
        $r = $this->tool->run( [ 'css' => '.a{}' ] );
        $GLOBALS['__wp_custom_css'] = "/* owner added this */\n" . $GLOBALS['__wp_custom_css'];
        $undo                       = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'], 'the owner\'s own CSS outside the block is not "changed since"' );
        $this->assertStringContainsString( '/* owner added this */', wp_get_custom_css() );

        $r2                         = $this->tool->run( [ 'css' => '.b{}' ] );
        $GLOBALS['__wp_custom_css'] = str_replace( '.b{}', '.b{ color: green }', $GLOBALS['__wp_custom_css'] );
        $undo2                      = $this->undo()->undo( $r2['batch']['id'] );
        $this->assertSame( 'changed_since', $undo2['items'][0]['result'] );
        $this->assertSame( '.b{ color: green }', CustomCss::extract( wp_get_custom_css() ) );
    }

    public function testItIsRegisteredAndNeedsEditCss(): void
    {
        $this->assertContains( 'set_custom_css', array_map( static fn ( $t ) => $t->name(), Tools::all() ) );
        $this->assertSame( 'edit_css', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $this->tool->definition()['description'] ) );
        $this->assertSame( [ 'css' ], $this->tool->definition()['inputSchema']['required'] );
    }
}
```

- [ ] **Step 3: Run to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter "CustomCssTest|SetCustomCssTest"`
Expected: FAIL (classes not found).

- [ ] **Step 4: Implement**

`pro-addon/src/Settings/CustomCss.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The AI's CSS lives inside one clearly marked block of WordPress "Additional CSS"; the owner's own CSS outside it
 * is never touched. Pure string functions: the block is spliced with substr, never with a regex replacement
 * string, so `$1` and `\0` in the CSS stay literal.
 */
final class CustomCss
{
    // The same markers the 3.x premium tool wrote, so an old managed block is replaced in place.
    public const BEGIN     = '/* BEGIN AI Editor for Divi 5 — managed block (safe to edit/remove) */';
    public const END       = '/* END AI Editor for Divi 5 */';
    public const MAX_BYTES = 100000;

    /** @return string|null why this CSS is refused, or null when it is acceptable */
    public static function problem( string $css ): ?string
    {
        $css = trim( $css );
        if ( '' === $css ) {
            return 'The CSS is empty.';
        }
        if ( strlen( $css ) > self::MAX_BYTES ) {
            return 'The CSS is larger than ' . self::MAX_BYTES . ' bytes.';
        }
        if ( str_contains( $css, self::BEGIN ) || str_contains( $css, self::END ) ) {
            return 'The CSS contains the managed-block markers; send only the CSS itself (the markers are added for you).';
        }
        if ( 1 === preg_match( '/<\s*\/?\s*(?:style|script)\b|<!--/i', $css ) ) {
            return 'The CSS contains HTML (a style or script tag, or an HTML comment). Send plain CSS only.';
        }
        if ( 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $css ) ) {
            return 'The CSS contains control characters.';
        }

        return null;
    }

    /** The CSS inside the managed block, or null when there is no complete block. */
    public static function extract( string $all ): ?string
    {
        $b = strpos( $all, self::BEGIN );
        if ( false === $b ) {
            return null;
        }
        $start = $b + strlen( self::BEGIN );
        $e     = strpos( $all, self::END, $start );

        return false === $e ? null : trim( substr( $all, $start, $e - $start ) );
    }

    /** Put $css in the managed block (created or replaced in place); null removes the block. Everything else is kept as it was. */
    public static function apply( string $all, ?string $css ): string
    {
        $b = strpos( $all, self::BEGIN );
        $e = false === $b ? false : strpos( $all, self::END, $b + strlen( self::BEGIN ) );

        if ( null === $css ) {
            if ( false === $e ) {
                return $all;
            }
            $head = rtrim( substr( $all, 0, $b ) );
            $tail = ltrim( substr( $all, $e + strlen( self::END ) ) );

            return $head . ( '' !== $head && '' !== $tail ? "\n\n" : '' ) . $tail;
        }

        $block = self::BEGIN . "\n" . trim( $css ) . "\n" . self::END;
        if ( false !== $e ) {
            return substr( $all, 0, $b ) . $block . substr( $all, $e + strlen( self::END ) );
        }

        return ( '' !== trim( $all ) ? rtrim( $all ) . "\n\n" : '' ) . $block;
    }
}
```

`pro-addon/src/Kinds/CssKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\ValueKind;
use AiEditorDivi5\Pro\Settings\CustomCss;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The managed block of Additional CSS. Only the block counts as "changed since"; the owner's own CSS outside it does not. */
final class CssKind extends ValueKind
{
    public function name(): string
    {
        return 'custom_css';
    }

    public function current( array $item = [] ): mixed
    {
        return CustomCss::extract( (string) wp_get_custom_css() );
    }

    public function allowed( array $item ): bool
    {
        return current_user_can( 'edit_css' );
    }

    protected function write( array $item, mixed $value ): bool
    {
        $res = wp_update_custom_css_post( CustomCss::apply( (string) wp_get_custom_css(), is_string( $value ) ? $value : null ) );

        return ! is_wp_error( $res );
    }
}
```

`pro-addon/src/Tools/SetCustomCss.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Settings\CustomCss;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Site-wide CSS in one managed block of Additional CSS. CSS cannot execute code; the block is validated and undoable. */
final class SetCustomCss implements ProTool
{
    public const NAME = 'set_custom_css';

    public function __construct( private BatchLog $log )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_css';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Add site-wide CSS inside one marked block of WordPress Additional CSS. The owner\'s own CSS outside the block is never touched. Replaces the block on each call. Undoable. dry_run=true previews.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'css'     => [ 'type' => 'string', 'description' => 'Plain CSS to apply site-wide (no HTML tags)' ],
                    'dry_run' => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'css' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $css = trim( (string) Args::string( $arguments, 'css', true, CustomCss::MAX_BYTES ) );
        $dry = Args::bool( $arguments, 'dry_run' );
        $why = CustomCss::problem( $css );
        if ( null !== $why ) {
            throw new \InvalidArgumentException( $why );
        }

        $all    = (string) wp_get_custom_css();
        $before = CustomCss::extract( $all );
        $bytes  = strlen( $css );
        if ( $before === $css ) {
            return [ 'changed' => false, 'dry_run' => $dry, 'bytes' => $bytes, 'message' => 'The managed CSS block already holds exactly this CSS; nothing changed.' ];
        }
        if ( $dry ) {
            return [ 'changed' => false, 'dry_run' => true, 'bytes' => $bytes, 'would_change' => [ 'from_bytes' => null === $before ? null : strlen( $before ), 'to_bytes' => $bytes ] ];
        }

        $saved = wp_update_custom_css_post( CustomCss::apply( $all, $css ) );
        if ( is_wp_error( $saved ) ) {
            throw new \RuntimeException( $saved->get_error_message() );
        }
        // Hash what is stored now, not what was sent: WordPress may normalise the CSS on save.
        $batch = $this->log->record(
            self::NAME,
            "Set the managed custom CSS block ({$bytes} bytes)",
            [ Item::make( 'custom_css', 'custom_css', 'Managed Additional CSS block', $before, CustomCss::extract( (string) wp_get_custom_css() ) ) ]
        );

        return BatchLog::attach(
            [ 'changed' => true, 'dry_run' => false, 'bytes' => $bytes, 'message' => 'Custom CSS applied site-wide (Appearance > Customize > Additional CSS, inside the marked block).' ],
            $batch
        );
    }
}
```

`autoload.php` (before `Plugin.php`): `Settings/CustomCss.php`, `Kinds/CssKind.php`, `Tools/SetCustomCss.php`.

`Batches::kinds()`: add `new CssKind(),` (and the `use` line). `Tools::all()`: add `new SetCustomCss( $log ),` (and the `use` line).

- [ ] **Step 5: Run the Pro suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src pro-addon/tests
git commit -m "feat(pro): set_custom_css (managed block, \$ and backslash safe, undoable)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 10: `set_primary_menu`

**Risk (spec "Risks" #1):** menu creation is unproven on a real site. Task 16's live check decides whether this tool ships in 0.2.0; if it fails there, remove `SetPrimaryMenu` from `Tools::all()` and say so in the readme rather than shipping a guess.

**Files:**
- Create: `pro-addon/src/Settings/MenuBuilder.php`, `pro-addon/src/Kinds/MenuKind.php`, `pro-addon/src/Tools/SetPrimaryMenu.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Batch/Batches.php`, `pro-addon/src/Tools.php`, `pro-addon/tests/WpSettingsShims.php` (append menu shims)
- Test: `pro-addon/tests/MenuBuilderTest.php`, `pro-addon/tests/SetPrimaryMenuTest.php`

**Interfaces:**
- Produces:
  - `MenuBuilder::MENU_NAME = 'Main Menu'`, `MAX_ITEMS = 30`; `targetLocation(): string` (the first registered theme location containing `primary` or `main`, else the first one, else `''`); `snapshot(string $location): array` (full snapshot: `['exists'=>bool,'menu_id'=>int,'items'=>list<item>,'location'=>string,'location_menu_id'=>int]`; an item is `['title','type','object','object_id','url','parent','classes','target','attr_title','xfn','description']` where `parent` is the position of the parent item in the list or -1 and `url` is only kept for `custom` items); `canonical(array $snapshot): array` = `['exists','items','at_location']` (no database ids, so it survives a delete-and-recreate); `build(array $items, string $location): int` (items added); `restore(array $snapshot): void` (throws `\RuntimeException` when the menu cannot be created).
  - `MenuKind` (kind name `menu`; `ref` = theme location; `before` = the full snapshot; canonical value = `MenuBuilder::canonical(snapshot)`; needs `edit_theme_options`).
  - `SetPrimaryMenu::NAME = 'set_primary_menu'`; `new SetPrimaryMenu(BatchLog $log)`; capability `edit_theme_options`; input `items` (list of 1-30 objects: `title` string, and `page_id` integer of a published page OR `url` string; `page_id` wins when both are given), `dry_run` boolean.
  - Tool result: `['changed'=>true,'dry_run'=>false,'menu_id'=>int,'items'=>int,'location'=>?string,'message'=>string,'batch'=>[...]]`; preview: `['changed'=>false,'dry_run'=>true,'would_change'=>['menu'=>'Main Menu','location'=>?string,'replaces_existing_items'=>int,'new_items'=>int]]`.
- What undo restores: titles, links, nesting, CSS classes, link target, title attribute, XFN and description of every item, and which menu the location points at. It does not capture custom meta other plugins add to menu items (documented in the tool description).

- [ ] **Step 1: Append the menu shims**

Append to `pro-addon/tests/WpSettingsShims.php`:

```php
// ---- Navigation menus, theme mods --------------------------------------------------------
$GLOBALS['__wp_menus']            = [];                                   // term_id => name
$GLOBALS['__wp_menu_items']       = [];                                   // menu_id => [ item_id => object ]
$GLOBALS['__wp_menu_seq']         = 100;
$GLOBALS['__wp_theme_mods']       = [];
$GLOBALS['__wp_registered_menus'] = [ 'primary-menu' => 'Primary Menu' ];

if ( ! function_exists( 'wp_get_nav_menu_object' ) ) {
    function wp_get_nav_menu_object( $name ) {
        foreach ( $GLOBALS['__wp_menus'] as $id => $n ) {
            if ( $n === $name ) {
                return (object) [ 'term_id' => $id, 'name' => $n ];
            }
        }

        return false;
    }
}
if ( ! function_exists( 'wp_create_nav_menu' ) ) {
    function wp_create_nav_menu( $name ) {
        if ( false !== wp_get_nav_menu_object( $name ) ) {
            return new WP_Error( 'menu_exists', 'The menu name already exists.' );
        }
        $id                                = ++$GLOBALS['__wp_menu_seq'];
        $GLOBALS['__wp_menus'][ $id ]      = $name;
        $GLOBALS['__wp_menu_items'][ $id ] = [];

        return $id;
    }
}
if ( ! function_exists( 'wp_delete_nav_menu' ) ) {
    function wp_delete_nav_menu( $id ) {
        unset( $GLOBALS['__wp_menus'][ (int) $id ], $GLOBALS['__wp_menu_items'][ (int) $id ] );
        $mods = $GLOBALS['__wp_theme_mods']['nav_menu_locations'] ?? [];
        foreach ( $mods as $loc => $menu ) {
            if ( (int) $menu === (int) $id ) {
                unset( $mods[ $loc ] );
            }
        }
        $GLOBALS['__wp_theme_mods']['nav_menu_locations'] = $mods;

        return true;
    }
}
if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
    function wp_get_nav_menu_items( $menuId ) {
        $items = array_values( $GLOBALS['__wp_menu_items'][ (int) $menuId ] ?? [] );
        usort( $items, static fn ( $a, $b ) => $a->menu_order <=> $b->menu_order );

        return $items;
    }
}
if ( ! function_exists( 'wp_update_nav_menu_item' ) ) {
    function wp_update_nav_menu_item( $menuId, $itemId = 0, $args = [] ) {
        $id   = ++$GLOBALS['__wp_menu_seq'];
        $type = $args['menu-item-type'] ?? 'custom';
        $oid  = (int) ( $args['menu-item-object-id'] ?? 0 );
        $GLOBALS['__wp_menu_items'][ (int) $menuId ][ $id ] = (object) [
            'ID'               => $id,
            'title'            => $args['menu-item-title'] ?? '',
            'type'             => $type,
            'object'           => $args['menu-item-object'] ?? 'custom',
            'object_id'        => $oid,
            'url'              => 'custom' === $type ? (string) ( $args['menu-item-url'] ?? '' ) : 'https://example.com/?p=' . $oid,
            'menu_item_parent' => (int) ( $args['menu-item-parent-id'] ?? 0 ),
            'menu_order'       => $id,
            'classes'          => array_values( array_filter( explode( ' ', (string) ( $args['menu-item-classes'] ?? '' ) ) ) ),
            'target'           => (string) ( $args['menu-item-target'] ?? '' ),
            'attr_title'       => (string) ( $args['menu-item-attr-title'] ?? '' ),
            'xfn'              => (string) ( $args['menu-item-xfn'] ?? '' ),
            'description'      => (string) ( $args['menu-item-description'] ?? '' ),
        ];

        return $id;
    }
}
if ( ! function_exists( 'wp_delete_post' ) ) {
    function wp_delete_post( $id, $force = false ) {
        foreach ( array_keys( $GLOBALS['__wp_menu_items'] ) as $menu ) {
            unset( $GLOBALS['__wp_menu_items'][ $menu ][ (int) $id ] );
        }
        unset( $GLOBALS['__wp_posts'][ (int) $id ] );

        return true;
    }
}
if ( ! function_exists( 'get_registered_nav_menus' ) ) {
    function get_registered_nav_menus() { return $GLOBALS['__wp_registered_menus']; }
}
if ( ! function_exists( 'get_theme_mod' ) ) {
    function get_theme_mod( $name, $default = false ) { return $GLOBALS['__wp_theme_mods'][ $name ] ?? $default; }
}
if ( ! function_exists( 'set_theme_mod' ) ) {
    function set_theme_mod( $name, $value ) { $GLOBALS['__wp_theme_mods'][ $name ] = $value; }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ) {
        $url = trim( (string) $url );

        return 1 === preg_match( '#^(https?://|/|\#)#i', $url ) ? $url : '';
    }
}
```

- [ ] **Step 2: Write the failing tests**

`pro-addon/tests/MenuBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\MenuBuilder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class MenuBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_menus']            = [];
        $GLOBALS['__wp_menu_items']       = [];
        $GLOBALS['__wp_theme_mods']       = [];
        $GLOBALS['__wp_registered_menus'] = [ 'primary-menu' => 'Primary Menu' ];
    }

    /** @return array<string,mixed> */
    private function custom( string $title, string $url, int $parent = -1 ): array
    {
        return [ 'title' => $title, 'type' => 'custom', 'object' => 'custom', 'object_id' => 0, 'url' => $url, 'parent' => $parent, 'classes' => '', 'target' => '', 'attr_title' => '', 'xfn' => '', 'description' => '' ];
    }

    /** @return array<string,mixed> */
    private function page( string $title, int $id ): array
    {
        return [ 'title' => $title, 'type' => 'post_type', 'object' => 'page', 'object_id' => $id, 'url' => '', 'parent' => -1, 'classes' => '', 'target' => '', 'attr_title' => '', 'xfn' => '', 'description' => '' ];
    }

    /** Seeds a hand-made "Main Menu" with nesting, classes and a link target, assigned to the primary location. */
    private function seedOldMenu(): int
    {
        $id  = wp_create_nav_menu( MenuBuilder::MENU_NAME );
        $top = wp_update_nav_menu_item( $id, 0, [ 'menu-item-title' => 'Old Top', 'menu-item-type' => 'custom', 'menu-item-url' => 'https://old.example/top', 'menu-item-classes' => 'btn big', 'menu-item-target' => '_blank', 'menu-item-xfn' => 'nofollow', 'menu-item-attr-title' => 'Tip', 'menu-item-description' => 'Desc' ] );
        wp_update_nav_menu_item( $id, 0, [ 'menu-item-title' => 'Old Child', 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => 9, 'menu-item-parent-id' => $top ] );
        set_theme_mod( 'nav_menu_locations', [ 'primary-menu' => $id ] );

        return $id;
    }

    public function testTheTargetLocationPrefersPrimaryOrMainThenTheFirstThenNone(): void
    {
        $GLOBALS['__wp_registered_menus'] = [ 'footer-menu' => 'Footer', 'primary-menu' => 'Primary' ];
        $this->assertSame( 'primary-menu', MenuBuilder::targetLocation() );
        $GLOBALS['__wp_registered_menus'] = [ 'x' => 'X', 'main_nav' => 'Main' ];
        $this->assertSame( 'main_nav', MenuBuilder::targetLocation() );
        $GLOBALS['__wp_registered_menus'] = [ 'alpha' => 'A', 'beta' => 'B' ];
        $this->assertSame( 'alpha', MenuBuilder::targetLocation() );
        $GLOBALS['__wp_registered_menus'] = [];
        $this->assertSame( '', MenuBuilder::targetLocation() );
    }

    public function testBuildCreatesTheMenuInOrderAndPointsTheLocationAtIt(): void
    {
        $added = MenuBuilder::build( [ $this->page( 'Home', 5 ), $this->custom( 'Blog', 'https://example.com/blog' ) ], 'primary-menu' );
        $this->assertSame( 2, $added );
        $s = MenuBuilder::snapshot( 'primary-menu' );
        $this->assertTrue( $s['exists'] );
        $this->assertSame( [ 'Home', 'Blog' ], array_column( $s['items'], 'title' ) );
        $this->assertSame( [ 'post_type', 'custom' ], array_column( $s['items'], 'type' ) );
        $this->assertSame( 5, $s['items'][0]['object_id'] );
        $this->assertSame( '', $s['items'][0]['url'], 'a page link keeps no url of its own' );
        $this->assertSame( 'https://example.com/blog', $s['items'][1]['url'] );
        $this->assertSame( $s['menu_id'], $s['location_menu_id'] );
        $this->assertTrue( MenuBuilder::canonical( $s )['at_location'] );
    }

    public function testRebuildingReplacesTheItemsWithoutDuplicates(): void
    {
        MenuBuilder::build( [ $this->page( 'Home', 5 ), $this->page( 'About', 6 ) ], 'primary-menu' );
        MenuBuilder::build( [ $this->page( 'Contact', 8 ) ], 'primary-menu' );
        $this->assertSame( [ 'Contact' ], array_column( MenuBuilder::snapshot( 'primary-menu' )['items'], 'title' ) );
        $this->assertCount( 1, $GLOBALS['__wp_menus'] );
    }

    public function testAMenuWithNoThemeLocationIsStillBuiltButNotAssigned(): void
    {
        MenuBuilder::build( [ $this->page( 'Home', 5 ) ], '' );
        $s = MenuBuilder::snapshot( '' );
        $this->assertTrue( $s['exists'] );
        $this->assertFalse( MenuBuilder::canonical( $s )['at_location'] );
        $this->assertSame( [], $GLOBALS['__wp_theme_mods'] );
    }

    public function testSnapshotThenRestoreRoundTripsTitlesLinksNestingClassesAndTargets(): void
    {
        $this->seedOldMenu();
        $snap = MenuBuilder::snapshot( 'primary-menu' );
        $this->assertSame( -1, $snap['items'][0]['parent'] );
        $this->assertSame( 0, $snap['items'][1]['parent'], 'the child points at its parent\'s position' );
        $this->assertSame( 'btn big', $snap['items'][0]['classes'] );
        $this->assertSame( '_blank', $snap['items'][0]['target'] );

        MenuBuilder::build( [ $this->page( 'New', 5 ) ], 'primary-menu' );
        MenuBuilder::restore( $snap );

        $after = MenuBuilder::snapshot( 'primary-menu' );
        $this->assertSame( MenuBuilder::canonical( $snap ), MenuBuilder::canonical( $after ) );
        $this->assertSame( 'Tip', $after['items'][0]['attr_title'] );
        $this->assertSame( 'nofollow', $after['items'][0]['xfn'] );
        $this->assertSame( 'Desc', $after['items'][0]['description'] );
        $this->assertSame( 0, $after['items'][1]['parent'] );
    }

    public function testRestoreOfAMenuThatDidNotExistDeletesItAndClearsTheLocation(): void
    {
        $snap = MenuBuilder::snapshot( 'primary-menu' );
        $this->assertFalse( $snap['exists'] );
        MenuBuilder::build( [ $this->page( 'Home', 5 ) ], 'primary-menu' );
        MenuBuilder::restore( $snap );
        $this->assertSame( [], $GLOBALS['__wp_menus'] );
        $this->assertArrayNotHasKey( 'primary-menu', $GLOBALS['__wp_theme_mods']['nav_menu_locations'] ?? [] );
    }

    public function testRestoreGivesTheLocationBackToTheOtherMenuItPointedAt(): void
    {
        $footer = wp_create_nav_menu( 'Footer Links' );
        set_theme_mod( 'nav_menu_locations', [ 'primary-menu' => $footer ] );
        $snap = MenuBuilder::snapshot( 'primary-menu' );
        MenuBuilder::build( [ $this->page( 'Home', 5 ) ], 'primary-menu' );
        MenuBuilder::restore( $snap );
        $this->assertSame( $footer, $GLOBALS['__wp_theme_mods']['nav_menu_locations']['primary-menu'] );
        $this->assertFalse( wp_get_nav_menu_object( MenuBuilder::MENU_NAME ) );
    }

    public function testRestoreRecreatesAMenuThatWasDeletedInTheMeantime(): void
    {
        $id   = $this->seedOldMenu();
        $snap = MenuBuilder::snapshot( 'primary-menu' );
        wp_delete_nav_menu( $id );
        MenuBuilder::restore( $snap );
        $after = MenuBuilder::snapshot( 'primary-menu' );
        $this->assertSame( MenuBuilder::canonical( $snap ), MenuBuilder::canonical( $after ) );
        $this->assertNotSame( $id, $after['menu_id'], 'a recreated menu has a new id; the canonical value must not depend on it' );
        $this->assertSame( $after['menu_id'], $GLOBALS['__wp_theme_mods']['nav_menu_locations']['primary-menu'] );
    }
}
```

`pro-addon/tests/SetPrimaryMenuTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\MenuKind;
use AiEditorDivi5\Pro\Settings\MenuBuilder;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\SetPrimaryMenu;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

class SetPrimaryMenuTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private SetPrimaryMenu $tool;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->tool = new SetPrimaryMenu( $this->log );
        $GLOBALS['__wp_menus']            = [];
        $GLOBALS['__wp_menu_items']       = [];
        $GLOBALS['__wp_theme_mods']       = [];
        $GLOBALS['__wp_registered_menus'] = [ 'primary-menu' => 'Primary Menu' ];
        $GLOBALS['__wp_posts']            = [
            5 => (object) [ 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_content' => '' ],
            6 => (object) [ 'ID' => 6, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About', 'post_content' => '' ],
            7 => (object) [ 'ID' => 7, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Soon', 'post_content' => '' ],
            8 => (object) [ 'ID' => 8, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Post', 'post_content' => '' ],
        ];
        $GLOBALS['__wp_can'] = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ 'menu' => new MenuKind() ], $this->log );
    }

    private function fails( array $args, string $message ): void
    {
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( [], $GLOBALS['__wp_menus'], 'no menu was created' );
        $this->assertSame( [], $this->repo->rows );
    }

    private function items(): array
    {
        return [ [ 'title' => 'Home', 'page_id' => 5 ], [ 'title' => 'About', 'page_id' => 6 ], [ 'title' => 'Shop', 'url' => 'https://example.com/shop' ] ];
    }

    public function testItBuildsTheMenuAssignsTheLocationAndRecordsABatch(): void
    {
        $r = $this->tool->run( [ 'items' => $this->items() ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 3, $r['items'] );
        $this->assertSame( 'primary-menu', $r['location'] );
        $this->assertSame( [ 'Home', 'About', 'Shop' ], array_column( MenuBuilder::snapshot( 'primary-menu' )['items'], 'title' ) );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertSame( 'primary-menu', $this->repo->rows[1]['items'][0]['ref'] );
    }

    public function testTitlesAreSanitisedAndAPageIdWinsOverAUrl(): void
    {
        $this->tool->run( [ 'items' => [ [ 'title' => '<b>Home</b><script>x()</script>', 'page_id' => 5, 'url' => 'https://ignored.example' ] ] ] );
        $item = MenuBuilder::snapshot( 'primary-menu' )['items'][0];
        $this->assertSame( 'Home', $item['title'] );
        $this->assertSame( 'post_type', $item['type'] );
    }

    public function testBadItemsAreRejectedWithTheItemNumberAndNothingIsCreated(): void
    {
        $this->fails( [], '"items" is required.' );
        $this->fails( [ 'items' => 'Home' ], '"items" must be a list.' );
        $this->fails( [ 'items' => [] ], '"items" must not be empty.' );
        $this->fails( [ 'items' => array_fill( 0, 31, [ 'title' => 'x', 'url' => '/x' ] ) ], '"items" must have at most 30 items.' );
        $this->fails( [ 'items' => [ 'Home' ] ], 'Item 1 must be an object.' );
        $this->fails( [ 'items' => [ [ 'url' => '/x' ] ] ], 'Item 1: "title"' );
        $this->fails( [ 'items' => [ [ 'title' => 5, 'url' => '/x' ] ] ], 'Item 1: "title"' );
        $this->fails( [ 'items' => [ [ 'title' => str_repeat( 'a', 101 ), 'url' => '/x' ] ] ], '"title" must be at most 100 bytes' );
        $this->fails( [ 'items' => [ [ 'title' => 'Ok', 'url' => '/ok' ], [ 'title' => 'No link' ] ] ], 'Item 2: give a "page_id" or a "url"' );
        $this->fails( [ 'items' => [ [ 'title' => 'x', 'page_id' => '5' ] ] ], '"page_id" must be an integer' );
        $this->fails( [ 'items' => [ [ 'title' => 'x', 'page_id' => 99 ] ] ], 'page 99 was not found or is not published' );
        $this->fails( [ 'items' => [ [ 'title' => 'x', 'page_id' => 7 ] ] ], 'page 7 was not found or is not published' );
        $this->fails( [ 'items' => [ [ 'title' => 'x', 'page_id' => 8 ] ] ], 'page 8 was not found or is not published' );
        $this->fails( [ 'items' => [ [ 'title' => 'x', 'url' => 5 ] ] ], '"url" must be a string' );
        $this->fails( [ 'items' => [ [ 'title' => 'x', 'url' => 'javascript:alert(1)' ] ] ], 'not a valid link' );
        $this->fails( [ 'items' => [ [ 'title' => 'x', 'url' => '/x' ] ], 'dry_run' => 'yes' ], '"dry_run" must be true or false.' );
    }

    public function testADryRunBuildsNothingAndRecordsNothing(): void
    {
        $r = $this->tool->run( [ 'items' => $this->items(), 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ 'menu' => 'Main Menu', 'location' => 'primary-menu', 'replaces_existing_items' => 0, 'new_items' => 3 ], $r['would_change'] );
        $this->assertSame( [], $GLOBALS['__wp_menus'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testANewMenuBuiltOnATrulyEmptySiteIsDeletedByUndo(): void
    {
        $r    = $this->tool->run( [ 'items' => $this->items() ] );
        $undo = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'] );
        $this->assertSame( [], $GLOBALS['__wp_menus'] );
    }

    public function testUndoRestoresThePreviousMenuAndUndoingTheUndoBringsTheNewOneBack(): void
    {
        $id = wp_create_nav_menu( MenuBuilder::MENU_NAME );
        wp_update_nav_menu_item( $id, 0, [ 'menu-item-title' => 'Old', 'menu-item-type' => 'custom', 'menu-item-url' => 'https://old.example' ] );
        set_theme_mod( 'nav_menu_locations', [ 'primary-menu' => $id ] );
        $old = MenuBuilder::canonical( MenuBuilder::snapshot( 'primary-menu' ) );

        $r     = $this->tool->run( [ 'items' => $this->items() ] );
        $built = MenuBuilder::canonical( MenuBuilder::snapshot( 'primary-menu' ) );
        $this->assertNotSame( $old, $built );

        $first = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $first['fully_undone'] );
        $this->assertSame( $old, MenuBuilder::canonical( MenuBuilder::snapshot( 'primary-menu' ) ) );

        $second = $this->undo()->undo( $first['undo_batch']['id'] );
        $this->assertTrue( $second['fully_undone'], 'an undo is itself undoable' );
        $this->assertSame( $built, MenuBuilder::canonical( MenuBuilder::snapshot( 'primary-menu' ) ) );
    }

    public function testUndoSkipsAMenuTheOwnerEditedSince(): void
    {
        $r  = $this->tool->run( [ 'items' => $this->items() ] );
        $id = MenuBuilder::snapshot( 'primary-menu' )['menu_id'];
        wp_update_nav_menu_item( $id, 0, [ 'menu-item-title' => 'Added by the owner', 'menu-item-type' => 'custom', 'menu-item-url' => '/x' ] );
        $undo = $this->undo()->undo( $r['batch']['id'] );
        $this->assertSame( 'changed_since', $undo['items'][0]['result'] );
        $this->assertCount( 4, MenuBuilder::snapshot( 'primary-menu' )['items'] );
    }

    public function testAThemeWithNoMenuLocationSaysSo(): void
    {
        $GLOBALS['__wp_registered_menus'] = [];
        $r                                = $this->tool->run( [ 'items' => $this->items() ] );
        $this->assertNull( $r['location'] );
        $this->assertStringContainsString( 'Appearance', $r['message'] );
    }

    public function testItIsRegisteredAndNeedsEditThemeOptions(): void
    {
        $this->assertContains( 'set_primary_menu', array_map( static fn ( $t ) => $t->name(), Tools::all() ) );
        $this->assertSame( 'edit_theme_options', $this->tool->capability() );
        $d = $this->tool->definition();
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'items' ], $d['inputSchema']['required'] );
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $registry             = new ToolRegistry( [ $this->tool ], static fn (): bool => true );
        $this->assertSame( -32602, ( (array) $registry->claim( null, 'set_primary_menu', [ 'items' => $this->items() ], 1 )->get_data() )['error']['code'] );
        $this->assertSame( [], $GLOBALS['__wp_menus'] );
    }
}
```

- [ ] **Step 3: Run to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter "MenuBuilderTest|SetPrimaryMenuTest"`
Expected: FAIL (classes not found).

- [ ] **Step 4: Implement `MenuBuilder`**

`pro-addon/src/Settings/MenuBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds the "Main Menu" navigation menu and points a theme location at it, and can put a snapshot of the
 * previous state back. Everything goes through WordPress's own menu functions.
 */
final class MenuBuilder
{
    public const MENU_NAME = 'Main Menu';
    public const MAX_ITEMS = 30;

    /** The theme's primary menu location: the first one named like "primary" or "main", else the first, else ''. */
    public static function targetLocation(): string
    {
        $locations = array_keys( (array) get_registered_nav_menus() );
        foreach ( $locations as $loc ) {
            if ( false !== stripos( (string) $loc, 'primary' ) || false !== stripos( (string) $loc, 'main' ) ) {
                return (string) $loc;
            }
        }

        return [] === $locations ? '' : (string) $locations[0];
    }

    /**
     * Everything needed to put the menu and its location back.
     *
     * @return array{exists:bool, menu_id:int, items:list<array<string,mixed>>, location:string, location_menu_id:int}
     */
    public static function snapshot( string $location ): array
    {
        $mods     = get_theme_mod( 'nav_menu_locations', [] );
        $mods     = is_array( $mods ) ? $mods : [];
        $pointing = '' !== $location ? (int) ( $mods[ $location ] ?? 0 ) : 0;
        $menu     = wp_get_nav_menu_object( self::MENU_NAME );
        if ( ! $menu ) {
            return [ 'exists' => false, 'menu_id' => 0, 'items' => [], 'location' => $location, 'location_menu_id' => $pointing ];
        }

        $raw   = wp_get_nav_menu_items( (int) $menu->term_id );
        $raw   = is_array( $raw ) ? $raw : [];
        $index = [];
        foreach ( $raw as $pos => $o ) {
            $index[ (int) $o->ID ] = $pos;
        }
        $items = [];
        foreach ( $raw as $o ) {
            $type    = (string) $o->type;
            $items[] = [
                'title'       => (string) $o->title,
                'type'        => $type,
                'object'      => (string) $o->object,
                'object_id'   => (int) $o->object_id,
                'url'         => 'custom' === $type ? (string) $o->url : '',
                'parent'      => $index[ (int) $o->menu_item_parent ] ?? -1,
                'classes'     => implode( ' ', array_filter( (array) $o->classes ) ),
                'target'      => (string) $o->target,
                'attr_title'  => (string) $o->attr_title,
                'xfn'         => (string) $o->xfn,
                'description' => (string) $o->description,
            ];
        }

        return [ 'exists' => true, 'menu_id' => (int) $menu->term_id, 'items' => $items, 'location' => $location, 'location_menu_id' => $pointing ];
    }

    /**
     * The part of a snapshot that is compared for "changed since": no database ids, so a menu that was deleted
     * and recreated by an undo still compares equal.
     *
     * @param array<string,mixed> $s
     * @return array{exists:bool, items:list<array<string,mixed>>, at_location:bool}
     */
    public static function canonical( array $s ): array
    {
        return [
            'exists'      => (bool) $s['exists'],
            'items'       => $s['items'],
            'at_location' => '' !== (string) $s['location'] && (bool) $s['exists'] && (int) $s['menu_id'] > 0 && (int) $s['location_menu_id'] === (int) $s['menu_id'],
        ];
    }

    /**
     * Rebuild the menu from $items (the snapshot item shape) and point $location at it.
     *
     * @param list<array<string,mixed>> $items
     * @return int the number of items added
     */
    public static function build( array $items, string $location ): int
    {
        $menu   = wp_get_nav_menu_object( self::MENU_NAME );
        $menuId = $menu ? (int) $menu->term_id : wp_create_nav_menu( self::MENU_NAME );
        if ( is_wp_error( $menuId ) ) {
            throw new \RuntimeException( $menuId->get_error_message() );
        }
        self::clearItems( (int) $menuId );
        $added = self::addItems( (int) $menuId, $items );
        self::pointLocation( $location, (int) $menuId );

        return $added;
    }

    /**
     * Put a snapshot back: recreate or empty-and-refill the menu (or delete it when it did not exist) and give the
     * location back to whatever menu it pointed at.
     *
     * @param array<string,mixed> $snap
     */
    public static function restore( array $snap ): void
    {
        $now = wp_get_nav_menu_object( self::MENU_NAME );
        if ( ! $snap['exists'] ) {
            if ( $now ) {
                wp_delete_nav_menu( (int) $now->term_id );
            }
            self::pointLocation( (string) $snap['location'], (int) $snap['location_menu_id'] );

            return;
        }

        $menuId = $now ? (int) $now->term_id : wp_create_nav_menu( self::MENU_NAME );
        if ( is_wp_error( $menuId ) ) {
            throw new \RuntimeException( $menuId->get_error_message() );
        }
        self::clearItems( (int) $menuId );
        self::addItems( (int) $menuId, $snap['items'] );
        $pointed = (int) $snap['location_menu_id'];
        self::pointLocation( (string) $snap['location'], $pointed === (int) $snap['menu_id'] ? (int) $menuId : $pointed );
    }

    private static function clearItems( int $menuId ): void
    {
        foreach ( (array) wp_get_nav_menu_items( $menuId ) as $old ) {
            wp_delete_post( (int) $old->ID, true );
        }
    }

    /** @param list<array<string,mixed>> $items */
    private static function addItems( int $menuId, array $items ): int
    {
        $ids   = [];
        $added = 0;
        foreach ( $items as $i => $it ) {
            $id = wp_update_nav_menu_item( $menuId, 0, [
                'menu-item-title'       => (string) $it['title'],
                'menu-item-status'      => 'publish',
                'menu-item-type'        => (string) $it['type'],
                'menu-item-object'      => (string) $it['object'],
                'menu-item-object-id'   => (int) $it['object_id'],
                'menu-item-url'         => 'custom' === $it['type'] ? (string) $it['url'] : '',
                'menu-item-classes'     => (string) $it['classes'],
                'menu-item-target'      => (string) $it['target'],
                'menu-item-attr-title'  => (string) $it['attr_title'],
                'menu-item-xfn'         => (string) $it['xfn'],
                'menu-item-description' => (string) $it['description'],
                'menu-item-parent-id'   => (int) $it['parent'] >= 0 ? (int) ( $ids[ (int) $it['parent'] ] ?? 0 ) : 0,
            ] );
            if ( is_wp_error( $id ) ) {
                continue;
            }
            $ids[ $i ] = (int) $id;
            $added++;
        }

        return $added;
    }

    /** Point $location at $menuId; 0 clears it. An empty location (the theme has none) does nothing. */
    private static function pointLocation( string $location, int $menuId ): void
    {
        if ( '' === $location ) {
            return;
        }
        $mods = get_theme_mod( 'nav_menu_locations', [] );
        $mods = is_array( $mods ) ? $mods : [];
        if ( $menuId > 0 ) {
            $mods[ $location ] = $menuId;
        } else {
            unset( $mods[ $location ] );
        }
        set_theme_mod( 'nav_menu_locations', $mods );
    }
}
```

`pro-addon/src/Kinds/MenuKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\Pro\Settings\MenuBuilder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The "Main Menu" and the theme location pointing at it. `ref` is the location; `before` is the full snapshot. */
final class MenuKind implements ItemKind
{
    public function name(): string
    {
        return 'menu';
    }

    public function current( array $item ): mixed
    {
        return MenuBuilder::canonical( MenuBuilder::snapshot( (string) ( $item['ref'] ?? '' ) ) );
    }

    public function allowed( array $item ): bool
    {
        return current_user_can( 'edit_theme_options' );
    }

    public function restore( array $item ): array
    {
        $location = (string) $item['ref'];
        $now      = MenuBuilder::snapshot( $location );
        try {
            MenuBuilder::restore( (array) $item['before'] );
        } catch ( \RuntimeException $e ) {
            return [ 'ok' => false, 'reason' => 'menu_failed' ];
        }

        return [ 'ok' => true, 'inverse' => Item::make( 'menu', $location, (string) $item['label'], $now, MenuBuilder::canonical( (array) $item['before'] ) ) ];
    }
}
```

`pro-addon/src/Tools/SetPrimaryMenu.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Settings\MenuBuilder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Build the primary navigation menu from pages and links and assign it to the theme's menu location. One undoable batch. */
final class SetPrimaryMenu implements ProTool
{
    public const NAME = 'set_primary_menu';

    public function __construct( private BatchLog $log )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_theme_options';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Build the primary menu ("Main Menu") from up to 30 items (a published page_id or a url each) and assign it to the theme\'s menu location. Replaces that menu\'s items. Undoable. dry_run=true previews.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'items'   => [
                        'type'        => 'array',
                        'description' => 'Ordered menu items; page_id wins when both page_id and url are given',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'title'   => [ 'type' => 'string' ],
                                'page_id' => [ 'type' => 'integer', 'description' => 'A published page (preferred)' ],
                                'url'     => [ 'type' => 'string', 'description' => 'Or a custom link' ],
                            ],
                            'required'   => [ 'title' ],
                        ],
                    ],
                    'dry_run' => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'items' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $items    = self::normalize( Args::listOf( $arguments, 'items', MenuBuilder::MAX_ITEMS ) );
        $dry      = Args::bool( $arguments, 'dry_run' );
        $location = MenuBuilder::targetLocation();
        $before   = MenuBuilder::snapshot( $location );
        $named    = '' !== $location ? $location : null;

        if ( $dry ) {
            return [
                'changed'      => false,
                'dry_run'      => true,
                'would_change' => [ 'menu' => MenuBuilder::MENU_NAME, 'location' => $named, 'replaces_existing_items' => count( $before['items'] ), 'new_items' => count( $items ) ],
            ];
        }

        $added = MenuBuilder::build( $items, $location );
        $after = MenuBuilder::snapshot( $location );
        $batch = $this->log->record(
            self::NAME,
            'Rebuilt the primary menu (' . $added . ' items)',
            [ Item::make( 'menu', $location, 'Main Menu (primary navigation)', $before, MenuBuilder::canonical( $after ) ) ]
        );

        $message = null === $named
            ? 'The menu "' . MenuBuilder::MENU_NAME . '" was built, but this theme has no menu location, so it was not assigned. Assign it under Appearance > Menus.'
            : 'The menu "' . MenuBuilder::MENU_NAME . '" was built and assigned to the "' . $location . '" location.';
        if ( $added < count( $items ) ) {
            $message .= ' ' . ( count( $items ) - $added ) . ' of ' . count( $items ) . ' items could not be added.';
        }

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'menu_id' => $after['menu_id'], 'items' => $added, 'location' => $named, 'message' => $message ], $batch );
    }

    /**
     * Validate the items and turn them into the snapshot item shape. A bad item rejects the whole call.
     *
     * @param list<mixed> $raw
     * @return list<array<string,mixed>>
     */
    private static function normalize( array $raw ): array
    {
        $out = [];
        foreach ( $raw as $i => $it ) {
            $n = $i + 1;
            if ( ! is_array( $it ) || array_is_list( $it ) ) {
                throw new \InvalidArgumentException( "Item {$n} must be an object." );
            }
            $title = isset( $it['title'] ) && is_string( $it['title'] ) ? sanitize_text_field( $it['title'] ) : '';
            if ( '' === $title ) {
                throw new \InvalidArgumentException( "Item {$n}: \"title\" is required and must be text." );
            }
            if ( strlen( $title ) > 100 ) {
                throw new \InvalidArgumentException( "Item {$n}: \"title\" must be at most 100 bytes." );
            }
            $base = [ 'title' => $title, 'parent' => -1, 'classes' => '', 'target' => '', 'attr_title' => '', 'xfn' => '', 'description' => '' ];

            $pageId = $it['page_id'] ?? null;
            $url    = $it['url'] ?? null;
            if ( null !== $pageId ) {
                if ( ! is_int( $pageId ) ) {
                    throw new \InvalidArgumentException( "Item {$n}: \"page_id\" must be an integer." );
                }
                $post = get_post( $pageId );
                if ( ! $post || 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
                    throw new \InvalidArgumentException( "Item {$n}: page {$pageId} was not found or is not published." );
                }
                $out[] = $base + [ 'type' => 'post_type', 'object' => 'page', 'object_id' => $pageId, 'url' => '' ];
            } elseif ( null !== $url ) {
                if ( ! is_string( $url ) ) {
                    throw new \InvalidArgumentException( "Item {$n}: \"url\" must be a string." );
                }
                $clean = esc_url_raw( $url );
                if ( '' === $clean ) {
                    throw new \InvalidArgumentException( "Item {$n}: \"url\" is not a valid link." );
                }
                $out[] = $base + [ 'type' => 'custom', 'object' => 'custom', 'object_id' => 0, 'url' => $clean ];
            } else {
                throw new \InvalidArgumentException( "Item {$n}: give a \"page_id\" or a \"url\"." );
            }
        }

        return $out;
    }
}
```

`autoload.php` (before `Plugin.php`): `Settings/MenuBuilder.php`, `Kinds/MenuKind.php`, `Tools/SetPrimaryMenu.php`. `Batches::kinds()`: add `new MenuKind(),`. `Tools::all()`: add `new SetPrimaryMenu( $log ),`.

- [ ] **Step 5: Run the Pro suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: all green. If `testSnapshotThenRestore...` fails on `classes`, check that the shim splits `menu-item-classes` on spaces and `snapshot()` joins with a single space (`'btn big'`).

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src pro-addon/tests
git commit -m "feat(pro): set_primary_menu (validated items, full-fidelity snapshot, undo restores or deletes the menu)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---
## Task 11: `publish_page`

**Files:**
- Create: `pro-addon/src/Kinds/PostStatusKind.php`, `pro-addon/src/Tools/PublishPage.php`
- Modify: `tests/bootstrap.php` (root: make the `wp_update_post` shim write any field), `pro-addon/tests/WpSettingsShims.php` (append `get_permalink`, `get_date_from_gmt`), `pro-addon/src/autoload.php`, `pro-addon/src/Batch/Batches.php`, `pro-addon/src/Tools.php`
- Test: `pro-addon/tests/PublishPageTest.php`

**Interfaces:**
- Consumes: the free plugin's `AiEditorDivi5\WP\EditTarget::resolve(int $id): array{post:?\WP_Post, kind:?string, error:?string}` with constants `EditTarget::NOT_FOUND`, `EditTarget::FORBIDDEN` (pinned by `FreeApiCompatTest`).
- Produces:
  - `PostStatusKind` (kind name `post_status`; `ref` = the page id as a string; canonical value = the post's `post_status`; needs `publish_pages` and `edit_post` on that page).
  - `PublishPage::NAME = 'publish_page'`; `new PublishPage(BatchLog $log)`; capability `publish_pages`; input `page_id` (int), `publish_at` (optional string: ISO 8601 with a timezone, at least one minute in the future), `dry_run` (bool).
  - Result: `['changed'=>true,'dry_run'=>false,'page'=>['id','title','status','link'],'batch'=>[...]]`; preview: `['changed'=>false,'dry_run'=>true,'page'=>[...],'would_change'=>['status'=>['from','to'],'publish_at'=>?string]]`.
- Undo returns the page to its previous *status* (`draft`/`pending`). It does not rewind the publish date. Only `draft` and `pending` pages can be published or scheduled.

- [ ] **Step 1: Teach the shims**

In root `tests/bootstrap.php`, in the `wp_update_post` shim, replace

```php
        $GLOBALS['__wp_posts'][ $id ]->post_content = (string) $arr['post_content'];
```

with

```php
        foreach ( $arr as $field => $value ) {
            if ( 'ID' !== $field ) {
                $GLOBALS['__wp_posts'][ $id ]->$field = 'post_content' === $field ? (string) $value : $value;
            }
        }
```

Append to `pro-addon/tests/WpSettingsShims.php`:

```php
// ---- Posts ---------------------------------------------------------------------------------
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $post = 0 ) { return 'https://example.com/?p=' . ( is_object( $post ) ? $post->ID : (int) $post ); }
}
if ( ! function_exists( 'get_date_from_gmt' ) ) {
    function get_date_from_gmt( $gmt, $format = 'Y-m-d H:i:s' ) { return (string) $gmt; }
}
```

Run `make test` and `./vendor/bin/phpunit -c pro-addon/phpunit.xml`; both must still pass.

- [ ] **Step 2: Write the failing tests**

`pro-addon/tests/PublishPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\PostStatusKind;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\PublishPage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

class PublishPageTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private PublishPage $tool;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->tool = new PublishPage( $this->log );
        $page       = static fn ( int $id, string $status, string $type = 'page' ) => (object) [ 'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => "Page {$id}", 'post_content' => 'C' ];
        $GLOBALS['__wp_posts'] = [
            5  => $page( 5, 'draft' ),
            6  => $page( 6, 'pending' ),
            7  => $page( 7, 'publish' ),
            8  => $page( 8, 'draft', 'post' ),
            9  => $page( 9, 'private' ),
            10 => $page( 10, 'trash' ),
        ];
        $GLOBALS['__wp_update_calls'] = 0;
        $GLOBALS['__wp_update_fail']  = false;
        $GLOBALS['__wp_can']          = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ 'post_status' => new PostStatusKind() ], $this->log );
    }

    private function fails( array $args, string $message ): void
    {
        $status = $GLOBALS['__wp_posts'][5]->post_status;
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], 'nothing was saved' );
        $this->assertSame( $status, $GLOBALS['__wp_posts'][5]->post_status );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testItPublishesADraftAndRecordsAnUndoableBatch(): void
    {
        $r = $this->tool->run( [ 'page_id' => 5 ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 'publish', $r['page']['status'] );
        $this->assertSame( 'publish', $GLOBALS['__wp_posts'][5]->post_status );
        $this->assertSame( 'Page 5', $r['page']['title'] );
        $this->assertTrue( $r['batch']['undoable'] );
    }

    public function testItPublishesAPendingPageToo(): void
    {
        $this->assertSame( 'publish', $this->tool->run( [ 'page_id' => 6 ] )['page']['status'] );
    }

    public function testItSchedulesWhenPublishAtIsGivenInAnyTimezone(): void
    {
        $r = $this->tool->run( [ 'page_id' => 5, 'publish_at' => '2099-01-01T12:00:00+02:00' ] );
        $this->assertSame( 'future', $r['page']['status'] );
        $this->assertSame( '2099-01-01 10:00:00', $GLOBALS['__wp_posts'][5]->post_date_gmt );
        $this->assertTrue( $GLOBALS['__wp_posts'][5]->edit_date );

        $r2 = $this->tool->run( [ 'page_id' => 6, 'publish_at' => '2099-01-01T12:00Z' ] );
        $this->assertSame( '2099-01-01 12:00:00', $GLOBALS['__wp_posts'][6]->post_date_gmt, 'seconds are optional, Z means UTC' );
        $this->assertSame( 'future', $r2['page']['status'] );
    }

    public function testBadArgumentsAreRejectedBeforeAnythingIsSaved(): void
    {
        $this->fails( [], '"page_id" is required.' );
        $this->fails( [ 'page_id' => '5' ], '"page_id" must be an integer.' );
        $this->fails( [ 'page_id' => 5, 'dry_run' => 'no' ], '"dry_run" must be true or false.' );
        $this->fails( [ 'page_id' => 5, 'publish_at' => 20990101 ], '"publish_at" must be a string.' );
        foreach ( [ '', 'tomorrow', '2099-01-01', '2099-01-01 12:00:00', '2099-01-01T12:00:00', '2099-02-31T10:00:00Z', '2099-13-01T10:00:00Z' ] as $bad ) {
            $this->fails( [ 'page_id' => 5, 'publish_at' => $bad ], 'publish_at' );
        }
        $this->fails( [ 'page_id' => 5, 'publish_at' => '2020-01-01T00:00:00Z' ], 'in the future' );
        $this->fails( [ 'page_id' => 5, 'publish_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 10 ) ], 'in the future' );
    }

    public function testOnlyAnEditableDraftOrPendingPageIsAccepted(): void
    {
        $this->fails( [ 'page_id' => 99 ], 'Page 99 not found.' );
        $this->fails( [ 'page_id' => 8 ], 'Page 8 not found.' );
        foreach ( [ 7 => 'publish', 9 => 'private', 10 => 'trash' ] as $id => $status ) {
            $this->fails( [ 'page_id' => $id ], "Page {$id} is {$status}" );
        }
    }

    public function testAUserWhoCannotEditThatPageIsRefused(): void
    {
        $GLOBALS['__wp_caps'] = [ 'publish_pages' => true, 'edit_post:5' => false ];
        $this->fails( [ 'page_id' => 5 ], 'You do not have permission to edit page 5.' );
    }

    public function testADryRunSavesAndRecordsNothing(): void
    {
        $r = $this->tool->run( [ 'page_id' => 5, 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ 'from' => 'draft', 'to' => 'publish' ], $r['would_change']['status'] );
        $this->assertNull( $r['would_change']['publish_at'] );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $this->repo->rows );

        $s = $this->tool->run( [ 'page_id' => 5, 'publish_at' => '2099-01-01T12:00:00Z', 'dry_run' => true ] );
        $this->assertSame( 'future', $s['would_change']['status']['to'] );
        $this->assertSame( '2099-01-01T12:00:00Z', $s['would_change']['publish_at'] );
    }

    public function testAFailedSaveIsAnErrorAndRecordsNothing(): void
    {
        $GLOBALS['__wp_update_fail'] = true;
        try {
            $this->tool->run( [ 'page_id' => 5 ] );
            $this->fail( 'expected RuntimeException' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( [], $this->repo->rows );
        }
    }

    public function testUndoReturnsThePageToItsPreviousStatus(): void
    {
        $r    = $this->tool->run( [ 'page_id' => 6 ] );
        $undo = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'] );
        $this->assertSame( 'pending', $GLOBALS['__wp_posts'][6]->post_status );
    }

    public function testUndoOfAScheduledPageAndUndoingTheUndo(): void
    {
        $r     = $this->tool->run( [ 'page_id' => 5, 'publish_at' => '2099-01-01T12:00:00Z' ] );
        $first = $this->undo()->undo( $r['batch']['id'] );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][5]->post_status );
        $this->undo()->undo( $first['undo_batch']['id'] );
        $this->assertSame( 'future', $GLOBALS['__wp_posts'][5]->post_status );
    }

    public function testUndoSkipsAPageThatWasChangedSince(): void
    {
        $r                                      = $this->tool->run( [ 'page_id' => 5 ] );
        $GLOBALS['__wp_posts'][5]->post_status = 'trash';
        $undo                                   = $this->undo()->undo( $r['batch']['id'] );
        $this->assertSame( 'changed_since', $undo['items'][0]['result'] );
        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][5]->post_status );
    }

    public function testUndoIsRefusedForAUserWhoCannotEditThePageAnyMore(): void
    {
        $r                    = $this->tool->run( [ 'page_id' => 5 ] );
        $GLOBALS['__wp_caps'] = [ 'publish_pages' => true, 'edit_post:5' => false ];
        $undo                 = $this->undo()->undo( $r['batch']['id'] );
        $this->assertSame( 'forbidden', $undo['items'][0]['result'] );
        $this->assertSame( 'publish', $GLOBALS['__wp_posts'][5]->post_status );
    }

    public function testItIsRegisteredAndNeedsPublishPages(): void
    {
        $this->assertContains( 'publish_page', array_map( static fn ( $t ) => $t->name(), Tools::all() ) );
        $this->assertSame( 'publish_pages', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $this->tool->definition()['description'] ) );
        $this->assertSame( [ 'page_id' ], $this->tool->definition()['inputSchema']['required'] );
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $registry             = new ToolRegistry( [ $this->tool ], static fn (): bool => true );
        $this->assertSame( -32602, ( (array) $registry->claim( null, 'publish_page', [ 'page_id' => 5 ], 1 )->get_data() )['error']['code'] );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][5]->post_status );
    }
}
```

- [ ] **Step 3: Run to verify it fails**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter PublishPageTest`
Expected: FAIL (classes not found).

- [ ] **Step 4: Implement**

`pro-addon/src/Kinds/PostStatusKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\ValueKind;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** A page's status (draft, pending, future, publish). `ref` is the page id. */
final class PostStatusKind extends ValueKind
{
    public function name(): string
    {
        return 'post_status';
    }

    public function current( array $item ): mixed
    {
        $post = get_post( (int) $item['ref'] );

        return $post ? (string) $post->post_status : null;
    }

    public function allowed( array $item ): bool
    {
        return current_user_can( 'publish_pages' ) && current_user_can( 'edit_post', (int) $item['ref'] );
    }

    protected function write( array $item, mixed $value ): bool
    {
        if ( ! is_string( $value ) || '' === $value ) {
            return false;
        }

        return ! is_wp_error( wp_update_post( [ 'ID' => (int) $item['ref'], 'post_status' => $value ], true ) );
    }
}
```

`pro-addon/src/Tools/PublishPage.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\WP\EditTarget;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Publish a draft or pending page now, or schedule it. One undoable batch; the page content is never touched. */
final class PublishPage implements ProTool
{
    public const NAME = 'publish_page';

    public function __construct( private BatchLog $log )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'publish_pages';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Publish a draft or pending page now, or schedule it with publish_at (ISO 8601 with a timezone, in the future). Does not change the page content. Undoable. dry_run=true previews.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'page_id'    => [ 'type' => 'integer', 'description' => 'ID of a draft or pending page' ],
                    'publish_at' => [ 'type' => 'string', 'description' => 'Optional. Schedule instead of publishing now, e.g. 2026-12-01T09:00:00+01:00' ],
                    'dry_run'    => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'page_id' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $pageId = (int) Args::int( $arguments, 'page_id' );
        $at     = Args::string( $arguments, 'publish_at', false, 40 );
        $dry    = Args::bool( $arguments, 'dry_run' );

        $target = EditTarget::resolve( $pageId );
        if ( EditTarget::NOT_FOUND === $target['error'] ) {
            throw new \InvalidArgumentException( "Page {$pageId} not found." );
        }
        if ( EditTarget::FORBIDDEN === $target['error'] || null === $target['post'] ) {
            throw new \InvalidArgumentException( "You do not have permission to edit page {$pageId}." );
        }
        $post   = $target['post'];
        $status = (string) $post->post_status;
        if ( ! in_array( $status, [ 'draft', 'pending' ], true ) ) {
            throw new \InvalidArgumentException( "Page {$pageId} is {$status}; only a draft or pending page can be published or scheduled." );
        }

        $gmt = null === $at ? null : self::parseFuture( $at );
        $to  = null === $gmt ? 'publish' : 'future';
        $title = (string) $post->post_title;

        if ( $dry ) {
            return [
                'changed'      => false,
                'dry_run'      => true,
                'page'         => [ 'id' => $pageId, 'title' => $title, 'status' => $status ],
                'would_change' => [ 'status' => [ 'from' => $status, 'to' => $to ], 'publish_at' => $at ],
            ];
        }

        $update = [ 'ID' => $pageId, 'post_status' => $to ];
        if ( null !== $gmt ) {
            $update['post_date_gmt'] = $gmt;
            $update['post_date']     = get_date_from_gmt( $gmt );
            $update['edit_date']     = true;
        }
        $saved = wp_update_post( $update, true );
        if ( is_wp_error( $saved ) ) {
            throw new \RuntimeException( $saved->get_error_message() );
        }

        // Record what the page really has now: WordPress decides the final status.
        $now   = get_post( $pageId );
        $final = $now ? (string) $now->post_status : $to;
        $batch = $this->log->record(
            self::NAME,
            ( 'future' === $final ? 'Scheduled' : 'Published' ) . " \"{$title}\" (#{$pageId})",
            [ Item::make( 'post_status', (string) $pageId, "Status of \"{$title}\" (#{$pageId})", $status, $final ) ]
        );

        return BatchLog::attach(
            [ 'changed' => true, 'dry_run' => false, 'page' => [ 'id' => $pageId, 'title' => $title, 'status' => $final, 'link' => get_permalink( $pageId ) ] ],
            $batch
        );
    }

    /** ISO 8601 with a timezone, strictly parsed, at least a minute from now. Returns UTC `Y-m-d H:i:s`. */
    private static function parseFuture( string $at ): string
    {
        $invalid = 'publish_at must be an ISO 8601 date and time with a timezone, for example 2026-12-01T09:00:00+01:00 or 2026-12-01T09:00:00Z.';
        if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/', $at ) ) {
            throw new \InvalidArgumentException( $invalid );
        }
        $norm = preg_replace( '/Z$/', '+00:00', $at );
        $norm = (string) preg_replace( '/T(\d{2}:\d{2})(?=[+-])/', 'T$1:00', $norm );
        $dt   = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:sP', $norm );
        $err  = \DateTimeImmutable::getLastErrors();
        if ( false === $dt || ( is_array( $err ) && ( $err['warning_count'] > 0 || $err['error_count'] > 0 ) ) ) {
            throw new \InvalidArgumentException( $invalid );
        }
        if ( $dt->getTimestamp() < time() + 60 ) {
            throw new \InvalidArgumentException( 'publish_at must be in the future (at least one minute from now).' );
        }

        return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
    }
}
```

`autoload.php` (before `Plugin.php`): `Kinds/PostStatusKind.php`, `Tools/PublishPage.php`. `Batches::kinds()`: add `new PostStatusKind(),`. `Tools::all()`: add `new PublishPage( $log ),`.

- [ ] **Step 5: Run both suites**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml && make test`
Expected: all green. If a date case in `testBadArgumentsAreRejected...` is not rejected, print `DateTimeImmutable::getLastErrors()` for it; `2099-02-31T10:00:00Z` must produce a "The parsed date was invalid" warning and `2099-13-01…` an error.

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src pro-addon/tests tests/bootstrap.php
git commit -m "feat(pro): publish_page (publish or schedule a draft, strict dates, undo returns the previous status)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 12: `propose_php_snippet` and the Code proposals panel

**Files:**
- Create: `pro-addon/src/Settings/PhpProposals.php`, `pro-addon/src/Tools/ProposePhpSnippet.php`, `pro-addon/src/Admin/AdminActions.php`, `pro-addon/src/Admin/ProposalsPanel.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Tools.php`, `pro-addon/src/Plugin.php`, `pro-addon/uninstall.php`, `pro-addon/tests/UninstallTest.php`
- Test: `pro-addon/tests/PhpProposalsTest.php`, `pro-addon/tests/ProposePhpSnippetTest.php`, `pro-addon/tests/ProposalsPanelTest.php`
- Delete (after the move): the staged `pro-addon/staged/PhpProposals.php` and `pro-addon/staged/PhpProposalsTest.php` (superseded). Leave `staged/CustomCss.php` and `staged/MenuBuilder.php` deleted too once Tasks 9-10 are merged (they are replaced by `src/Settings/`); `git rm pro-addon/staged/*.php` and remove the `staged/` row from `pro-addon/README.md` in Task 16.

**Interfaces:**
- Produces:
  - `Settings\PhpProposals`: `OPTION = 'aied_pro_php_proposals'`, `MAX = 50`; `all(): list<array{id,title,description,code,created,user_id}>` (newest first); `add(string $title, string $description, string $code, int $userId): string` (returns the id; keeps the newest 50); `delete(string $id): void`; `clear(): void`; `count(): int`. It stores strings. Nothing in the add-on includes, evaluates or writes them as a file.
  - `ProposePhpSnippet::NAME = 'propose_php_snippet'`; `new ProposePhpSnippet()`; capability `edit_pages`; input `title` (optional string ≤120), `description` (optional ≤2000), `code` (required ≤100000 bytes). Result: `['proposed'=>true,'executed'=>false,'id'=>string,'message'=>string,'review_url'=>string]`. It does not create a batch (it changes nothing on the site).
  - `trait Admin\AdminActions`: `initTerminate(?\Closure $terminate): void`, `guardPost(string $nonceAction, string $capability): void` (capability, POST-only and nonce, in that order), `redirectToProTab(string $notice): void`.
  - `Admin\ProposalsPanel::__construct(\Closure $unlocked, ?\Closure $terminate = null)`; `register()`; `render(string $tab)`; `handleDelete()`; `ACTION_DELETE = 'aied_pro_delete_proposal'`. Renders only on the `pro` tab, only for `manage_options`, only while unlocked; every value is escaped.

- [ ] **Step 1: Write the failing tests**

`pro-addon/tests/PhpProposalsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\PhpProposals;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class PhpProposalsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_options'] = [];
    }

    public function testAddStoresTheCodeVerbatimAndReturnsAnId(): void
    {
        $code = "<?php\n// \$x = '\\n'; </code></pre><script>alert(1)</script>";
        $id   = PhpProposals::add( 'My CPT', 'Registers a post type', $code, 7 );
        $this->assertMatchesRegularExpression( '/^php_[a-f0-9]{12}$/', $id );
        $all = PhpProposals::all();
        $this->assertSame( 1, PhpProposals::count() );
        $this->assertSame( [ 'id' => $id, 'title' => 'My CPT', 'description' => 'Registers a post type', 'code' => $code, 'user_id' => 7 ], array_diff_key( $all[0], [ 'created' => 1 ] ) );
        $this->assertIsInt( $all[0]['created'] );
    }

    public function testNewestFirstAndAnEmptyTitleFallsBack(): void
    {
        PhpProposals::add( 'First', '', 'a', 1 );
        PhpProposals::add( '', '', 'b', 1 );
        $this->assertSame( [ 'Untitled snippet', 'First' ], array_column( PhpProposals::all(), 'title' ) );
    }

    public function testDeleteRemovesExactlyOne(): void
    {
        $a = PhpProposals::add( 'A', '', 'a', 1 );
        PhpProposals::add( 'B', '', 'b', 1 );
        PhpProposals::delete( $a );
        $this->assertSame( [ 'B' ], array_column( PhpProposals::all(), 'title' ) );
        PhpProposals::delete( 'php_unknown' );
        $this->assertSame( 1, PhpProposals::count() );
    }

    public function testOnlyTheNewest50AreKept(): void
    {
        for ( $i = 1; $i <= 52; $i++ ) {
            PhpProposals::add( "P{$i}", '', 'c', 1 );
        }
        $this->assertSame( 50, PhpProposals::count() );
        $this->assertSame( 'P52', PhpProposals::all()[0]['title'] );
        $this->assertSame( 'P3', PhpProposals::all()[49]['title'] );
    }

    public function testClearAndACorruptOptionAreSafe(): void
    {
        PhpProposals::add( 'A', '', 'a', 1 );
        PhpProposals::clear();
        $this->assertSame( 0, PhpProposals::count() );
        $GLOBALS['__wp_options'][ PhpProposals::OPTION ] = 'garbage';
        $this->assertSame( [], PhpProposals::all() );
    }
}
```

`pro-addon/tests/ProposePhpSnippetTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\PhpProposals;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\ProposePhpSnippet;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class ProposePhpSnippetTest extends TestCase
{
    private ProposePhpSnippet $tool;

    protected function setUp(): void
    {
        $this->tool              = new ProposePhpSnippet();
        $GLOBALS['__wp_options'] = [];
    }

    private function fails( array $args, string $message ): void
    {
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( 0, PhpProposals::count() );
    }

    public function testItStoresAnInertProposalAndSaysItWasNotExecuted(): void
    {
        $r = $this->tool->run( [ 'title' => 'CPT', 'description' => 'Adds <b>a</b> type', 'code' => "<?php\nregister_post_type( 'x' );" ] );
        $this->assertTrue( $r['proposed'] );
        $this->assertFalse( $r['executed'] );
        $this->assertStringContainsString( 'NOT executed', $r['message'] );
        $this->assertStringContainsString( 'tab=pro', $r['review_url'] );
        $stored = PhpProposals::all()[0];
        $this->assertSame( $r['id'], $stored['id'] );
        $this->assertSame( "<?php\nregister_post_type( 'x' );", $stored['code'], 'the code is kept exactly as sent' );
        $this->assertSame( 'Adds a type', $stored['description'], 'tags are stripped from the description' );
        $this->assertArrayNotHasKey( 'batch', $r, 'it changes nothing on the site, so there is nothing to undo' );
    }

    public function testTheArgumentsAreTypeChecked(): void
    {
        $this->fails( [], '"code" is required.' );
        $this->fails( [ 'code' => '   ' ], '"code" must not be empty.' );
        $this->fails( [ 'code' => 5 ], '"code" must be a string.' );
        $this->fails( [ 'code' => 'x', 'title' => [ 'a' ] ], '"title" must be a string.' );
        $this->fails( [ 'code' => 'x', 'description' => 5 ], '"description" must be a string.' );
        $this->fails( [ 'code' => str_repeat( 'a', 100001 ) ], '"code" must be at most 100000 bytes.' );
        $this->fails( [ 'code' => 'x', 'title' => str_repeat( 'a', 121 ) ], '"title" must be at most 120 bytes.' );
        $this->fails( [ 'code' => 'x', 'description' => str_repeat( 'a', 2001 ) ], '"description" must be at most 2000 bytes.' );
    }

    public function testItIsRegisteredAndNeedsEditPages(): void
    {
        $this->assertContains( 'propose_php_snippet', array_map( static fn ( $t ) => $t->name(), Tools::all() ) );
        $this->assertSame( 'edit_pages', $this->tool->capability() );
        $d = $this->tool->definition();
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'code' ], $d['inputSchema']['required'] );
    }
}
```

`pro-addon/tests/ProposalsPanelTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Admin\ProposalsPanel;
use AiEditorDivi5\Pro\Settings\PhpProposals;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class ProposalsPanelTest extends TestCase
{
    private bool $unlocked = true;

    protected function setUp(): void
    {
        $GLOBALS['__wp_options']   = [];
        $GLOBALS['__wp_redirects'] = [];
        $GLOBALS['__wp_filters']   = [];
        $GLOBALS['__wp_can']       = true;
        unset( $GLOBALS['__wp_caps'] );
        $_GET = $_POST = $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->unlocked            = true;
    }

    protected function tearDown(): void
    {
        $_GET = $_POST = $_REQUEST = [];
        $GLOBALS['__wp_can'] = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    private function panel(): ProposalsPanel
    {
        return new ProposalsPanel( fn (): bool => $this->unlocked, static function (): void {} );
    }

    private function render( string $tab = 'pro' ): string
    {
        ob_start();
        $this->panel()->render( $tab );

        return (string) ob_get_clean();
    }

    public function testRegisterAttachesTheTabRenderAndTheDeleteAction(): void
    {
        $this->panel()->register();
        $this->assertArrayHasKey( 'jhmg_aied_render_admin_tab', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'admin_post_aied_pro_delete_proposal', $GLOBALS['__wp_filters'] );
    }

    public function testItRendersOnlyOnTheProTabForAdministratorsWhileUnlocked(): void
    {
        PhpProposals::add( 'T', '', 'code', 1 );
        $this->assertSame( '', $this->render( 'features' ) );
        $this->unlocked = false;
        $this->assertSame( '', $this->render() );
        $this->unlocked       = true;
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $this->assertSame( '', $this->render(), 'manage_options is required to see code proposals' );
    }

    public function testItSaysNothingHasBeenRunAndShowsAnEmptyState(): void
    {
        $html = $this->render();
        $this->assertStringContainsString( 'Code proposals', $html );
        $this->assertStringContainsString( 'No code proposals', $html );
        $this->assertStringContainsString( 'never run', $html );
    }

    public function testEveryProposedValueIsEscaped(): void
    {
        PhpProposals::add( '<img src=x onerror=alert(1)>', '"><script>alert(2)</script>', "<?php\n</code></pre><script>alert(3)</script>", 1 );
        $html = $this->render();
        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringNotContainsString( '<img src=x', $html );
        $this->assertStringContainsString( '&lt;/code&gt;&lt;/pre&gt;&lt;script&gt;alert(3)', $html );
        $this->assertStringContainsString( '&lt;?php', $html );
    }

    public function testEachProposalHasADeleteFormWithItsOwnNonce(): void
    {
        $id   = PhpProposals::add( 'T', '', 'code', 1 );
        $html = $this->render();
        $this->assertStringContainsString( 'name="proposal_id" value="' . $id . '"', $html );
        $this->assertStringContainsString( 'nonce-for-aied_pro_delete_proposal', $html );
    }

    public function testDeleteRemovesTheProposalAndRedirects(): void
    {
        $id                = PhpProposals::add( 'T', '', 'code', 1 );
        $keep              = PhpProposals::add( 'K', '', 'code', 1 );
        $_POST             = [ 'proposal_id' => $id ];
        $_REQUEST          = $_POST + [ '_wpnonce' => 'nonce-for-aied_pro_delete_proposal' ];
        $this->panel()->handleDelete();
        $this->assertSame( [ $keep ], array_column( PhpProposals::all(), 'id' ) );
        $this->assertStringContainsString( 'aied_pro_notice=proposal_deleted', $GLOBALS['__wp_redirects'][0] );
    }

    public function testDeleteRefusesABadNonceAMissingCapabilityAndANonPost(): void
    {
        $id       = PhpProposals::add( 'T', '', 'code', 1 );
        $_POST    = [ 'proposal_id' => $id ];
        $_REQUEST = $_POST + [ '_wpnonce' => 'forged' ];
        try {
            $this->panel()->handleDelete();
            $this->fail( 'a forged nonce must be refused' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'check_admin_referer failed', $e->getMessage() );
        }

        $_REQUEST             = $_POST + [ '_wpnonce' => 'nonce-for-aied_pro_delete_proposal' ];
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        try {
            $this->panel()->handleDelete();
            $this->fail( 'manage_options is required' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'wp_die', $e->getMessage() );
        }

        unset( $GLOBALS['__wp_caps'] );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try {
            $this->panel()->handleDelete();
            $this->fail( 'POST only' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'wp_die', $e->getMessage() );
        }
        $this->assertSame( 1, PhpProposals::count(), 'nothing was deleted' );
    }

    public function testTheDeletedNoticeIsShown(): void
    {
        $_GET['aied_pro_notice'] = 'proposal_deleted';
        $this->assertStringContainsString( 'Proposal deleted', $this->render() );
    }
}
```

In `UninstallTest.php`, add `'aied_pro_php_proposals' => [ [ 'id' => 'php_x' ] ],` to the initial `$GLOBALS['__wp_options']` of the uninstall test (it must be gone afterwards: the final `assertSame( [ 'unrelated_option' => 'keep' ], ... )` already checks that).

In `PluginTest.php` add:

```php
    public function testACompatibleBootRegistersTheCodeProposalsPanel(): void
    {
        $this->assertTrue( Plugin::bootWith( '4.4.0', static fn (): bool => true ) );
        $this->assertArrayHasKey( 'admin_post_aied_pro_delete_proposal', $GLOBALS['__wp_filters'] );
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: FAIL (new classes missing; uninstall still leaves the proposals option).

- [ ] **Step 3: Implement**

`pro-addon/src/Settings/PhpProposals.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stores AI-proposed PHP snippets for a HUMAN to review. They are strings in an option: nothing in this add-on
 * includes, evaluates or writes them to a file, so an API key never becomes a code-execution credential.
 */
final class PhpProposals
{
    public const OPTION = 'aied_pro_php_proposals';
    public const MAX    = 50;

    /** @return list<array{id:string,title:string,description:string,code:string,created:int,user_id:int}> newest first */
    public static function all(): array
    {
        $data = get_option( self::OPTION, [] );

        return is_array( $data ) ? array_values( $data ) : [];
    }

    /** Store a proposal (inert) and return its id. Only the newest MAX are kept. */
    public static function add( string $title, string $description, string $code, int $userId ): string
    {
        $items = self::all();
        $id    = 'php_' . bin2hex( random_bytes( 6 ) );
        array_unshift( $items, [
            'id'          => $id,
            'title'       => '' !== $title ? $title : 'Untitled snippet',
            'description' => $description,
            'code'        => $code,
            'created'     => time(),
            'user_id'     => $userId,
        ] );
        update_option( self::OPTION, array_slice( $items, 0, self::MAX ), false );

        return $id;
    }

    public static function delete( string $id ): void
    {
        $items = array_filter( self::all(), static fn ( array $p ): bool => $p['id'] !== $id );
        update_option( self::OPTION, array_values( $items ), false );
    }

    public static function clear(): void
    {
        delete_option( self::OPTION );
    }

    public static function count(): int
    {
        return count( self::all() );
    }
}
```

`pro-addon/src/Tools/ProposePhpSnippet.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Settings\PhpProposals;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** File PHP for a human to review on the Pro tab. Nothing is run, saved as a file or loaded. */
final class ProposePhpSnippet implements ProTool
{
    public const NAME = 'propose_php_snippet';

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_pages';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Propose a PHP snippet (post type, hook, shortcode...) for the site owner to REVIEW. It is stored inert on the Pro tab and is never run or saved to the site. Say what it does and where to put it.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'title'       => [ 'type' => 'string', 'description' => 'Short name for the snippet' ],
                    'description' => [ 'type' => 'string', 'description' => 'What it does and where or how to apply it' ],
                    'code'        => [ 'type' => 'string', 'description' => 'The PHP code' ],
                ],
                'required'   => [ 'code' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $code  = (string) Args::string( $arguments, 'code', true, 100000 );
        $title = (string) Args::string( $arguments, 'title', false, 120 );
        $desc  = (string) Args::string( $arguments, 'description', false, 2000 );

        $id = PhpProposals::add( sanitize_text_field( $title ), wp_strip_all_tags( $desc ), $code, (int) get_current_user_id() );

        return [
            'proposed'   => true,
            'executed'   => false,
            'id'         => $id,
            'message'    => 'Snippet stored for review. It was NOT executed or saved to the site. The owner can review it on the Pro tab (AI Editor > Pro > Code proposals).',
            'review_url' => admin_url( 'admin.php?page=ai-editor-divi5&tab=pro#proposals' ),
        ];
    }
}
```

`pro-addon/src/Admin/AdminActions.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The checks every admin-post handler on the Pro tab makes, and the redirect back to the tab. */
trait AdminActions
{
    private \Closure $terminate;

    private function initTerminate( ?\Closure $terminate ): void
    {
        $this->terminate = $terminate ?? static function (): void {
            exit;
        };
    }

    /** Capability, then POST-only, then the nonce. */
    private function guardPost( string $nonceAction, string $capability ): void
    {
        if ( ! current_user_can( $capability ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'jhmg-ai-editor-for-divi-5-pro' ) );
        }
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'jhmg-ai-editor-for-divi-5-pro' ) );
        }
        check_admin_referer( $nonceAction );
    }

    private function redirectToProTab( string $notice ): void
    {
        wp_safe_redirect( add_query_arg( [ 'page' => 'ai-editor-divi5', 'tab' => 'pro', 'aied_pro_notice' => $notice ], admin_url( 'admin.php' ) ) );
        ( $this->terminate )();
    }
}
```

`pro-addon/src/Admin/ProposalsPanel.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Admin;

use AiEditorDivi5\Pro\ProPage;
use AiEditorDivi5\Pro\Settings\PhpProposals;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The "Code proposals" card on the Pro tab: PHP the AI proposed, shown escaped, for a human to review and copy. */
final class ProposalsPanel
{
    use AdminActions;

    public const ACTION_DELETE = 'aied_pro_delete_proposal';

    public function __construct( private \Closure $unlocked, ?\Closure $terminate = null )
    {
        $this->initTerminate( $terminate );
    }

    public function register(): void
    {
        add_action( 'jhmg_aied_render_admin_tab', [ $this, 'render' ] );
        add_action( 'admin_post_' . self::ACTION_DELETE, [ $this, 'handleDelete' ] );
    }

    public function render( string $tab ): void
    {
        if ( ProPage::TAB !== $tab || ! current_user_can( 'manage_options' ) || ! ( $this->unlocked )() ) {
            return;
        }
        $proposals = PhpProposals::all();
        $notice    = isset( $_GET['aied_pro_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['aied_pro_notice'] ) ) : '';
        ?>
        <div class="aied-card aied-pro" id="proposals">
            <h2><?php esc_html_e( 'Code proposals', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h2>
            <?php if ( 'proposal_deleted' === $notice ) : ?>
                <div class="notice notice-success inline"><p><?php esc_html_e( 'Proposal deleted.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p></div>
            <?php endif; ?>
            <p><?php esc_html_e( 'PHP your AI assistant proposed. It is shown here only: it is never run, saved as a file or loaded by this plugin. Read it, and if you want it, copy it into a snippets plugin yourself.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
            <?php if ( [] === $proposals ) : ?>
                <p><?php esc_html_e( 'No code proposals yet.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
            <?php endif; ?>
            <?php foreach ( $proposals as $p ) : ?>
                <div class="aied-proposal">
                    <h3><?php echo esc_html( $p['title'] ); ?></h3>
                    <p><small><?php echo esc_html( sprintf( /* translators: 1: date, 2: user id */ __( 'Proposed %1$s by user #%2$d', 'jhmg-ai-editor-for-divi-5-pro' ), gmdate( 'Y-m-d H:i', (int) $p['created'] ) . ' UTC', (int) ( $p['user_id'] ?? 0 ) ) ); ?></small></p>
                    <?php if ( '' !== $p['description'] ) : ?>
                        <p style="white-space:pre-wrap"><?php echo esc_html( $p['description'] ); ?></p>
                    <?php endif; ?>
                    <pre><code><?php echo esc_html( $p['code'] ); ?></code></pre>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE ); ?>">
                        <input type="hidden" name="proposal_id" value="<?php echo esc_attr( $p['id'] ); ?>">
                        <?php wp_nonce_field( self::ACTION_DELETE ); ?>
                        <button type="submit" class="button"><?php esc_html_e( 'Delete proposal', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public function handleDelete(): void
    {
        $this->guardPost( self::ACTION_DELETE, 'manage_options' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guardPost().
        $id = isset( $_POST['proposal_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['proposal_id'] ) ) : '';
        if ( '' !== $id ) {
            PhpProposals::delete( $id );
        }
        $this->redirectToProTab( 'proposal_deleted' );
    }
}
```

`autoload.php` (before `Plugin.php`): `Settings/PhpProposals.php`, `Tools/ProposePhpSnippet.php`, `Admin/AdminActions.php`, `Admin/ProposalsPanel.php`. `Tools::all()`: add `new ProposePhpSnippet(),`.

`Plugin::wire()`: add `use AiEditorDivi5\Pro\Admin\ProposalsPanel;` and, after `( new ProPage( $registry ) )->register();`:

```php
        $unlocked = static fn (): bool => Licensing::isPremium();
        ( new ProposalsPanel( $unlocked ) )->register();
```

`uninstall.php`: add `require_once __DIR__ . '/src/Settings/PhpProposals.php';` and `AiEditorDivi5\Pro\Settings\PhpProposals::clear();` after the licence line.

Then `git rm pro-addon/staged/PhpProposals.php pro-addon/staged/PhpProposalsTest.php`.

- [ ] **Step 4: Run the Pro suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: all green. (`ProSafetyTest` scans the new files for banned constructs; none are used. The word `eval` appears only in comments, which the scanner ignores.)

- [ ] **Step 5: Commit**

```bash
git add -A pro-addon
git commit -m "feat(pro): propose_php_snippet and the Code proposals panel (stored inert, shown escaped, never run)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 13: `PostContentKind` (undo for page-content writes)

**Why now:** the undo engine's contract must cover the item kind that Plans 4 and 5 (theme layouts, find/replace) will record. No tool in this plan writes page content; this task adds and tests the kind so those plans only build tools.

**Files:**
- Create: `pro-addon/src/Kinds/PostContentKind.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Batch/Batches.php`
- Test: `pro-addon/tests/PostContentKindTest.php`

**Interfaces:**
- Consumes: the free `HistoryService::restore(int $pageId, int $versionId): array` (shape on success: `['ok'=>true,'restored_version'=>int,'snapshot'=>['stored'=>bool,'version_id'=>?int,'reason'=>?string],'validator'=>[...]]`; on failure `['ok'=>false,'error'=>string,...]`); pinned by `FreeApiCompatTest`.
- Produces:
  - `new PostContentKind(?\Closure $restorer = null)` where `$restorer` is `fn(int $pageId, int $versionId): array` (default: `HistoryService::restore`); kind name `post_content`; `ref` = page id; canonical value = the post's `post_content` string; needs `edit_post` on that page; `before` is `null` (the previous content lives in the free plugin's history, not in the batch).
  - `PostContentKind::itemFor(int $pageId, string $title, string $newContent, array $snapshot): array` builds the batch item for a write made through `HistoryService::write()`: `$snapshot` is that call's `snapshot` (`stored`, `version_id`, `reason`); `undo.available = stored`.
- Restore bypasses the validator gate by design (it brings back the person's own earlier content).

- [ ] **Step 1: Write the failing tests**

`pro-addon/tests/PostContentKindTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\PostContentKind;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

class PostContentKindTest extends TestCase
{
    /** @var list<array{int,int}> */
    private array $restored = [];

    protected function setUp(): void
    {
        $GLOBALS['__wp_posts'] = [ 5 => (object) [ 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_content' => 'NEW' ] ];
        $GLOBALS['__wp_can']   = true;
        unset( $GLOBALS['__wp_caps'] );
        $this->restored = [];
    }

    /** A stand-in for HistoryService::restore: puts OLD back and reports the snapshot it took of NEW. */
    private function kind( bool $ok = true, ?array $snapshot = null ): PostContentKind
    {
        return new PostContentKind( function ( int $page, int $version ) use ( $ok, $snapshot ): array {
            $this->restored[] = [ $page, $version ];
            if ( ! $ok ) {
                return [ 'ok' => false, 'error' => 'version_not_found' ];
            }
            $GLOBALS['__wp_posts'][ $page ]->post_content = 'OLD';

            return [ 'ok' => true, 'restored_version' => $version, 'snapshot' => $snapshot ?? [ 'stored' => true, 'version_id' => 9, 'reason' => null ] ];
        } );
    }

    public function testItemForRecordsTheFreeHistorySnapshotAsTheUndoPoint(): void
    {
        $item = PostContentKind::itemFor( 5, 'Home', 'NEW', [ 'stored' => true, 'version_id' => 3, 'reason' => null ] );
        $this->assertSame( 'post_content', $item['kind'] );
        $this->assertSame( '5', $item['ref'] );
        $this->assertNull( $item['before'] );
        $this->assertSame( Canon::hash( 'NEW' ), $item['after_hash'] );
        $this->assertSame( [ 'available' => true, 'reason' => null, 'version_id' => 3 ], $item['undo'] );
    }

    public function testAWriteWhoseSnapshotWasNotStoredHasNoUndoPoint(): void
    {
        $item = PostContentKind::itemFor( 5, 'Home', 'NEW', [ 'stored' => false, 'version_id' => null, 'reason' => 'too_large' ] );
        $this->assertFalse( $item['undo']['available'] );
        $this->assertSame( 'too_large', $item['undo']['reason'] );
    }

    public function testCurrentIsThePostContentAndAMissingPostIsNull(): void
    {
        $k = $this->kind();
        $this->assertSame( 'NEW', $k->current( [ 'ref' => '5' ] ) );
        $this->assertNull( $k->current( [ 'ref' => '99' ] ) );
    }

    public function testItNeedsEditPostOnThatPage(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post:5' => false ];
        $this->assertFalse( $this->kind()->allowed( [ 'ref' => '5' ] ) );
        $GLOBALS['__wp_caps'] = [ 'edit_post:5' => true ];
        $this->assertTrue( $this->kind()->allowed( [ 'ref' => '5' ] ) );
    }

    public function testRestoreAsksTheFreeHistoryForTheRecordedVersionAndBuildsTheInverse(): void
    {
        $item = PostContentKind::itemFor( 5, 'Home', 'NEW', [ 'stored' => true, 'version_id' => 3, 'reason' => null ] );
        $r    = $this->kind()->restore( $item );
        $this->assertSame( [ [ 5, 3 ] ], $this->restored );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'OLD', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( Canon::hash( 'OLD' ), $r['inverse']['after_hash'] );
        $this->assertSame( [ 'available' => true, 'reason' => null, 'version_id' => 9 ], $r['inverse']['undo'], 'the inverse points at the snapshot the restore took' );
    }

    public function testARestoreThatTookNoSnapshotYieldsAnInverseWithoutAnUndoPoint(): void
    {
        $item = PostContentKind::itemFor( 5, 'Home', 'NEW', [ 'stored' => true, 'version_id' => 3, 'reason' => null ] );
        $r    = $this->kind( true, [ 'stored' => false, 'version_id' => null, 'reason' => 'duplicate' ] )->restore( $item );
        $this->assertFalse( $r['inverse']['undo']['available'] );
        $this->assertSame( 'duplicate', $r['inverse']['undo']['reason'] );
    }

    public function testARefusedRestoreIsReportedWithItsReason(): void
    {
        $item = PostContentKind::itemFor( 5, 'Home', 'NEW', [ 'stored' => true, 'version_id' => 3, 'reason' => null ] );
        $this->assertSame( [ 'ok' => false, 'reason' => 'version_not_found' ], $this->kind( false )->restore( $item ) );
        $this->assertSame( 'NEW', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testAnItemWithoutAVersionCannotBeRestored(): void
    {
        $item                       = PostContentKind::itemFor( 5, 'Home', 'NEW', [ 'stored' => true, 'version_id' => 3, 'reason' => null ] );
        $item['undo']['version_id'] = null;
        $this->assertSame( [ 'ok' => false, 'reason' => 'no_version' ], $this->kind()->restore( $item ) );
        $this->assertSame( [], $this->restored );
    }

    public function testThroughTheEngineAContentChangedSinceIsNeverOverwritten(): void
    {
        $repo = new InMemoryBatchRepository();
        $log  = new BatchLog( $repo );
        $undo = new BatchUndo( [ 'post_content' => $this->kind() ], $log );
        $b    = $log->record( 'edit_page_content', 'edit', [ PostContentKind::itemFor( 5, 'Home', 'NEW', [ 'stored' => true, 'version_id' => 3, 'reason' => null ] ) ] );

        $GLOBALS['__wp_posts'][5]->post_content = 'EDITED IN THE BUILDER';
        $this->assertSame( 'changed_since', $undo->undo( $b['id'] )['items'][0]['result'] );
        $this->assertSame( [], $this->restored );

        $GLOBALS['__wp_posts'][5]->post_content = 'NEW';
        $r                                      = $undo->undo( $b['id'] );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( 'OLD', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertTrue( $r['undo_batch']['undoable'], 'the undo is itself undoable through the new snapshot' );
    }

    public function testTheDefaultRestorerIsTheFreePluginsHistoryService(): void
    {
        $this->assertTrue( method_exists( \AiEditorDivi5\WP\HistoryService::class, 'restore' ) );
        $this->assertNotNull( ( new \ReflectionClass( PostContentKind::class ) )->getConstructor() );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter PostContentKindTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

`pro-addon/src/Kinds/PostContentKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\WP\HistoryService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A page's content. The previous content is NOT stored in the batch: the free plugin's history holds it, and the
 * item records the history version to restore. Restore goes through the free plugin's own `HistoryService::restore`
 * (one shared undo history), which snapshots the current content first, so the undo is itself undoable.
 */
final class PostContentKind implements ItemKind
{
    /** @param (\Closure(int,int):array<string,mixed>)|null $restorer fn(pageId, versionId): HistoryService::restore()'s result */
    public function __construct( private ?\Closure $restorer = null )
    {
    }

    public function name(): string
    {
        return 'post_content';
    }

    /**
     * The batch item for a write made through `HistoryService::write()`.
     *
     * @param array{stored?:bool, version_id?:?int, reason?:?string} $snapshot that call's `snapshot`
     * @return array<string,mixed>
     */
    public static function itemFor( int $pageId, string $title, string $newContent, array $snapshot ): array
    {
        return Item::make(
            'post_content',
            (string) $pageId,
            "Content of \"{$title}\" (#{$pageId})",
            null,
            $newContent,
            self::undoPoint( $snapshot )
        );
    }

    public function current( array $item ): mixed
    {
        $post = get_post( (int) $item['ref'] );

        return $post ? (string) $post->post_content : null;
    }

    public function allowed( array $item ): bool
    {
        return current_user_can( 'edit_post', (int) $item['ref'] );
    }

    public function restore( array $item ): array
    {
        $pageId    = (int) $item['ref'];
        $versionId = (int) ( $item['undo']['version_id'] ?? 0 );
        if ( $versionId < 1 ) {
            return [ 'ok' => false, 'reason' => 'no_version' ];
        }
        $restorer = $this->restorer ?? static fn ( int $p, int $v ): array => HistoryService::restore( $p, $v );
        $r        = $restorer( $pageId, $versionId );
        if ( empty( $r['ok'] ) ) {
            return [ 'ok' => false, 'reason' => (string) ( $r['error'] ?? 'restore_failed' ) ];
        }

        return [
            'ok'      => true,
            'inverse' => Item::make(
                'post_content',
                (string) $pageId,
                (string) $item['label'],
                null,
                $this->current( $item ),
                self::undoPoint( is_array( $r['snapshot'] ?? null ) ? $r['snapshot'] : [] )
            ),
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array{available:bool, reason:?string, version_id:?int}
     */
    private static function undoPoint( array $snapshot ): array
    {
        return [
            'available'  => (bool) ( $snapshot['stored'] ?? false ),
            'reason'     => isset( $snapshot['reason'] ) ? (string) $snapshot['reason'] : null,
            'version_id' => isset( $snapshot['version_id'] ) ? (int) $snapshot['version_id'] : null,
        ];
    }
}
```

`autoload.php` (before `Plugin.php`): `Kinds/PostContentKind.php`. `Batches::kinds()`: add `new PostContentKind(),` (+ `use`).

- [ ] **Step 4: Run the Pro suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src pro-addon/tests
git commit -m "feat(pro): PostContentKind, undo for page-content writes through the free history

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 14: `list_batches` and `undo_batch`

**Files:**
- Create: `pro-addon/src/Tools/ListBatches.php`, `pro-addon/src/Tools/UndoBatch.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Tools.php`
- Test: `pro-addon/tests/BatchToolsTest.php`

**Interfaces:**
- Consumes: `BatchLog::recent/find/describe`, `BatchUndo::undo`, `Batches::kinds()`.
- Produces:
  - `ListBatches::NAME = 'list_batches'`; `new ListBatches(BatchLog $log)`; capability `edit_pages`; input `limit` (optional int 1-20, default 10). Result `['batches'=>list<describe() + ['user_id'=>int,'changes'=>list<['kind','label','state','undo_available','undo_reason'?]>]>]`, newest first. A user without `manage_options` sees only their own batches.
  - `UndoBatch::NAME = 'undo_batch'`; `new UndoBatch(BatchLog $log, BatchUndo $undo)`; capability `edit_pages`; input `batch_id` (int), `force` (bool: overrides only the changed-since rule), `dry_run` (bool). Result is the engine result plus `message`. A batch the user may not see is reported as not found.

- [ ] **Step 1: Write the failing tests**

`pro-addon/tests/BatchToolsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\ListBatches;
use AiEditorDivi5\Pro\Tools\UndoBatch;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/FakeKind.php';

class BatchToolsTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private ListBatches $list;
    private UndoBatch $undo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->list = new ListBatches( $this->log );
        $this->undo = new UndoBatch( $this->log, new BatchUndo( [ 'fake' => new FakeKind() ] + Batches::kinds(), $this->log ) );
        $GLOBALS['__fake_store']   = [];
        $GLOBALS['__fake_allowed'] = true;
        $GLOBALS['__fake_fail']    = false;
        $GLOBALS['__fake_writes']  = [];
        $GLOBALS['__wp_can']       = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    /** Records a change of the fake site as user $uid (the shim's current user is 7). */
    private function change( string $ref, mixed $new, int $uid = 7 ): int
    {
        $old                             = $GLOBALS['__fake_store'][ $ref ] ?? null;
        $GLOBALS['__fake_store'][ $ref ] = $new;
        $b                               = $this->log->record( 'fake_tool', "change {$ref}", [ Item::make( 'fake', $ref, "Thing {$ref}", $old, $new ) ] );
        $this->repo->rows[ $b['id'] ]['user_id'] = $uid;

        return $b['id'];
    }

    // ---- list_batches ----------------------------------------------------------------------

    public function testItListsRecentBatchesNewestFirstWithWhatEachChanged(): void
    {
        $this->change( 'a', 1 );
        $this->change( 'b', 2 );
        $r = $this->list->run( [] );
        $this->assertSame( [ 2, 1 ], array_column( $r['batches'], 'id' ) );
        $first = $r['batches'][0];
        $this->assertSame( 'fake_tool', $first['tool'] );
        $this->assertSame( 'applied', $first['status'] );
        $this->assertTrue( $first['undoable'] );
        $this->assertSame( [ [ 'kind' => 'fake', 'label' => 'Thing b', 'state' => 'applied', 'undo_available' => true ] ], $first['changes'] );
        $this->assertArrayNotHasKey( 'before', $first['changes'][0], 'stored previous values are never listed' );
    }

    public function testTheLimitIsStrictAndBounded(): void
    {
        for ( $i = 0; $i < 5; $i++ ) {
            $this->change( "k{$i}", $i );
        }
        $this->assertCount( 2, $this->list->run( [ 'limit' => 2 ] )['batches'] );
        foreach ( [ '2', 0, 21, 2.5 ] as $bad ) {
            try {
                $this->list->run( [ 'limit' => $bad ] );
                $this->fail( 'accepted limit ' . json_encode( $bad ) );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( '"limit"', $e->getMessage() );
            }
        }
    }

    public function testAnItemWithNoUndoPointSaysWhy(): void
    {
        $b = $this->log->record( 't', 's', [ Item::make( 'fake', 'a', 'A', 1, 2, [ 'available' => false, 'reason' => 'too_large' ] ) ] );
        $c = $this->list->run( [] )['batches'][0]['changes'][0];
        $this->assertFalse( $c['undo_available'] );
        $this->assertSame( 'too_large', $c['undo_reason'] );
        $this->assertFalse( $this->list->run( [] )['batches'][0]['undoable'] );
        $this->assertSame( 1, $b['id'] );
    }

    public function testANonAdministratorSeesOnlyTheirOwnBatches(): void
    {
        $mine   = $this->change( 'a', 1, 7 );
        $theirs = $this->change( 'b', 2, 99 );
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $this->assertSame( [ $mine ], array_column( $this->list->run( [] )['batches'], 'id' ) );
        unset( $GLOBALS['__wp_caps'] );
        $this->assertSame( [ $theirs, $mine ], array_column( $this->list->run( [] )['batches'], 'id' ), 'an administrator sees all' );
    }

    // ---- undo_batch ------------------------------------------------------------------------

    public function testItUndoesABatchAndSaysSo(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $r                            = $this->undo->run( [ 'batch_id' => $id ] );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( 'one', $GLOBALS['__fake_store']['a'] );
        $this->assertStringContainsString( 'undone', $r['message'] );
        $this->assertNotNull( $r['undo_batch'] );
    }

    public function testItNeverCallsAPartialUndoFullyUndone(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $GLOBALS['__fake_store']['a'] = 'edited since';
        $r                            = $this->undo->run( [ 'batch_id' => $id ] );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'changed_since', $r['items'][0]['result'] );
        $this->assertStringContainsString( 'NOT', $r['message'] );
        $this->assertStringContainsString( 'changed_since', $r['message'] );
        $this->assertSame( 'edited since', $GLOBALS['__fake_store']['a'] );
    }

    public function testForceOverridesTheChangedSinceRule(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $GLOBALS['__fake_store']['a'] = 'edited since';
        $r                            = $this->undo->run( [ 'batch_id' => $id, 'force' => true ] );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( 'one', $GLOBALS['__fake_store']['a'] );
    }

    public function testADryRunWritesNothing(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $r                            = $this->undo->run( [ 'batch_id' => $id, 'dry_run' => true ] );
        $this->assertSame( 'would_restore', $r['items'][0]['result'] );
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'] );
    }

    public function testTheArgumentsAreTypeChecked(): void
    {
        foreach ( [ [ [], '"batch_id" is required.' ], [ [ 'batch_id' => '1' ], '"batch_id" must be an integer.' ], [ [ 'batch_id' => 1, 'force' => 'yes' ], '"force" must be true or false.' ], [ [ 'batch_id' => 1, 'dry_run' => 1 ], '"dry_run" must be true or false.' ] ] as [ $args, $message ] ) {
            try {
                $this->undo->run( $args );
                $this->fail( 'expected: ' . $message );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertSame( $message, $e->getMessage() );
            }
        }
    }

    public function testAnUnknownBatchIsNotFound(): void
    {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Batch 99 not found.' );
        $this->undo->run( [ 'batch_id' => 99 ] );
    }

    public function testAnotherUsersBatchLooksNotFoundToANonAdministrator(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two', 99 );
        $GLOBALS['__wp_caps']         = [ 'edit_pages' => true ];
        try {
            $this->undo->run( [ 'batch_id' => $id ] );
            $this->fail( 'must not reveal or undo another user\'s batch' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertSame( "Batch {$id} not found.", $e->getMessage() );
        }
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'] );

        unset( $GLOBALS['__wp_caps'] );
        $this->assertTrue( $this->undo->run( [ 'batch_id' => $id ] )['fully_undone'], 'an administrator may undo it' );
    }

    public function testThePerItemPermissionIsRecheckedAtUndoTime(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $GLOBALS['__fake_allowed']    = false;
        $r                            = $this->undo->run( [ 'batch_id' => $id ] );
        $this->assertSame( 'forbidden', $r['items'][0]['result'] );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'] );
    }

    public function testBothToolsAreRegisteredAndNeedEditPages(): void
    {
        $names = array_map( static fn ( $t ) => $t->name(), Tools::all() );
        $this->assertContains( 'list_batches', $names );
        $this->assertContains( 'undo_batch', $names );
        foreach ( [ $this->list, $this->undo ] as $tool ) {
            $this->assertSame( 'edit_pages', $tool->capability() );
            $this->assertLessThanOrEqual( 300, strlen( $tool->definition()['description'] ) );
        }
        $this->assertSame( [ 'batch_id' ], $this->undo->definition()['inputSchema']['required'] );
        $GLOBALS['__wp_caps'] = [ 'read' => true ];
        $registry             = new ToolRegistry( [ $this->list, $this->undo ], static fn (): bool => true );
        $this->assertSame( -32602, ( (array) $registry->claim( null, 'list_batches', [], 1 )->get_data() )['error']['code'] );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter BatchToolsTest`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement**

`pro-addon/src/Tools/ListBatches.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\ProTool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Recent Pro changes and whether each can still be undone. Read-only. */
final class ListBatches implements ProTool
{
    public const NAME = 'list_batches';

    public function __construct( private BatchLog $log )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_pages';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'List recent Pro changes (batches), newest first: what each changed and whether it can still be undone with undo_batch. Administrators see all; other users see their own.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [ 'limit' => [ 'type' => 'integer', 'description' => 'How many batches (1-20, default 10)' ] ],
                'required'   => [],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $limit = Args::int( $arguments, 'limit', false, 1 ) ?? 10;
        if ( $limit > BatchLog::MAX ) {
            throw new \InvalidArgumentException( '"limit" must be at most ' . BatchLog::MAX . '.' );
        }
        $all     = current_user_can( 'manage_options' );
        $me      = (int) get_current_user_id();
        $batches = [];
        foreach ( $this->log->recent( BatchLog::MAX ) as $batch ) {
            if ( ! $all && (int) $batch['user_id'] !== $me ) {
                continue;
            }
            $changes = [];
            foreach ( $batch['items'] as $item ) {
                $change = [
                    'kind'           => (string) ( $item['kind'] ?? '' ),
                    'label'          => (string) ( $item['label'] ?? '' ),
                    'state'          => (string) ( $item['state'] ?? 'applied' ),
                    'undo_available' => ! empty( $item['undo']['available'] ),
                ];
                if ( ! $change['undo_available'] && ! empty( $item['undo']['reason'] ) ) {
                    $change['undo_reason'] = (string) $item['undo']['reason'];
                }
                $changes[] = $change;
            }
            $batches[] = BatchLog::describe( $batch ) + [ 'user_id' => (int) $batch['user_id'], 'changes' => $changes ];
            if ( count( $batches ) >= $limit ) {
                break;
            }
        }

        return [ 'batches' => $batches ];
    }
}
```

`pro-addon/src/Tools/UndoBatch.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\ProTool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Undo one batch, honestly: restore what can be restored, report the rest, never overwrite a later change. */
final class UndoBatch implements ProTool
{
    public const NAME = 'undo_batch';

    public function __construct( private BatchLog $log, private BatchUndo $undo )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_pages';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Undo one batch from list_batches. Restores every item it safely can and reports the rest; an item changed since is skipped unless force=true. Claims "fully undone" only if all items restored. dry_run=true previews.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'batch_id' => [ 'type' => 'integer', 'description' => 'Batch id from list_batches' ],
                    'force'    => [ 'type' => 'boolean', 'description' => 'Restore items even if they changed since (never invents a missing undo point)' ],
                    'dry_run'  => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'batch_id' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $id    = (int) Args::int( $arguments, 'batch_id' );
        $force = Args::bool( $arguments, 'force' );
        $dry   = Args::bool( $arguments, 'dry_run' );

        // A batch the user may not see looks exactly like a batch that does not exist.
        $batch = $this->log->find( $id );
        if ( null === $batch || ( ! current_user_can( 'manage_options' ) && (int) $batch['user_id'] !== (int) get_current_user_id() ) ) {
            throw new \InvalidArgumentException( "Batch {$id} not found." );
        }

        $result = $this->undo->undo( $id, $force, $dry );
        if ( empty( $result['ok'] ) ) {
            throw new \InvalidArgumentException( (string) ( $result['message'] ?? "Batch {$id} not found." ) );
        }
        $result['message'] = self::message( $id, $result );

        return $result;
    }

    /** @param array<string,mixed> $r */
    private static function message( int $id, array $r ): string
    {
        if ( $r['dry_run'] ) {
            return "Preview: {$r['would_restore']} item(s) of batch #{$id} would be restored; nothing was changed.";
        }
        if ( $r['fully_undone'] ) {
            return "Everything in batch #{$id} was undone.";
        }
        $skipped = [];
        foreach ( $r['items'] as $item ) {
            if ( ! in_array( $item['result'], [ 'restored', 'already_undone' ], true ) ) {
                $skipped[] = $item['label'] . ' (' . $item['result'] . ')';
            }
        }

        return "Batch #{$id} was NOT fully undone. Restored {$r['restored']} item(s); skipped: " . implode( '; ', $skipped ) . '.';
    }
}
```

`autoload.php` (before `Plugin.php`): `Tools/ListBatches.php`, `Tools/UndoBatch.php`. `Tools::all()`: add `new ListBatches( $log ),` and `new UndoBatch( $log, Batches::undo() ),`.

- [ ] **Step 4: Run the Pro suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src pro-addon/tests
git commit -m "feat(pro): list_batches and undo_batch (own-batches rule, force overrides only changed-since)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---
## Task 15: The "Recent Pro changes" panel with an Undo button

**Files:**
- Create: `pro-addon/src/Admin/BatchesPanel.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Plugin.php`
- Test: `pro-addon/tests/BatchesPanelTest.php`, `pro-addon/tests/PluginTest.php` (add one test)

**Interfaces:**
- Consumes: `AdminActions` trait, `BatchLog::recent/find/describe`, `BatchUndo::undo`, `ProPage::TAB`.
- Produces: `BatchesPanel::__construct(\Closure $unlocked, BatchLog $log, BatchUndo $undo, ?\Closure $terminate = null)`; `register()`; `render(string $tab)`; `handleUndo()`; `ACTION_UNDO = 'aied_pro_undo_batch'`. Renders only on the `pro` tab, for users with `edit_pages`, while unlocked; users without `manage_options` see only their own batches. The Undo button (a nonce-protected POST form) appears only for a batch that is not fully undone and has at least one undo point. The handler uses the same changed-since rule as `undo_batch` (never `force`) and redirects with one of the notices `batch_undone | batch_partly_undone | batch_nothing_restored | batch_not_found`.

- [ ] **Step 1: Write the failing tests**

`pro-addon/tests/BatchesPanelTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Admin\BatchesPanel;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\Item;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/FakeKind.php';

class BatchesPanelTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private bool $unlocked = true;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $GLOBALS['__fake_store']   = [];
        $GLOBALS['__fake_allowed'] = true;
        $GLOBALS['__fake_fail']    = false;
        $GLOBALS['__fake_writes']  = [];
        $GLOBALS['__wp_redirects'] = [];
        $GLOBALS['__wp_filters']   = [];
        $GLOBALS['__wp_can']       = true;
        unset( $GLOBALS['__wp_caps'] );
        $_GET = $_POST = $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->unlocked            = true;
    }

    protected function tearDown(): void
    {
        $_GET = $_POST = $_REQUEST = [];
        $GLOBALS['__wp_can'] = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    private function panel(): BatchesPanel
    {
        return new BatchesPanel( fn (): bool => $this->unlocked, $this->log, new BatchUndo( [ 'fake' => new FakeKind() ], $this->log ), static function (): void {} );
    }

    private function render( string $tab = 'pro' ): string
    {
        ob_start();
        $this->panel()->render( $tab );

        return (string) ob_get_clean();
    }

    private function change( string $ref, mixed $new, string $summary = 'a change', int $uid = 7 ): int
    {
        $old                             = $GLOBALS['__fake_store'][ $ref ] ?? null;
        $GLOBALS['__fake_store'][ $ref ] = $new;
        $b                               = $this->log->record( 'fake_tool', $summary, [ Item::make( 'fake', $ref, "Thing {$ref}", $old, $new ) ] );
        $this->repo->rows[ $b['id'] ]['user_id'] = $uid;

        return $b['id'];
    }

    private function post( int|string $id ): void
    {
        $_POST    = [ 'batch_id' => (string) $id ];
        $_REQUEST = $_POST + [ '_wpnonce' => 'nonce-for-aied_pro_undo_batch' ];
    }

    public function testRegisterAttachesTheTabRenderAndTheUndoAction(): void
    {
        $this->panel()->register();
        $this->assertArrayHasKey( 'jhmg_aied_render_admin_tab', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'admin_post_aied_pro_undo_batch', $GLOBALS['__wp_filters'] );
    }

    public function testItRendersOnlyOnTheProTabForEditorsWhileUnlocked(): void
    {
        $this->change( 'a', 1 );
        $this->assertSame( '', $this->render( 'features' ) );
        $this->unlocked = false;
        $this->assertSame( '', $this->render() );
        $this->unlocked       = true;
        $GLOBALS['__wp_caps'] = [ 'read' => true ];
        $this->assertSame( '', $this->render() );
    }

    public function testItShowsAnEmptyState(): void
    {
        $html = $this->render();
        $this->assertStringContainsString( 'Recent Pro changes', $html );
        $this->assertStringContainsString( 'No Pro changes yet', $html );
    }

    public function testItListsBatchesEscapedWithAnUndoFormOnlyWhereUndoIsPossible(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $undoable                     = $this->change( 'a', 'two', '<script>alert(1)</script> set a' );
        $none                         = $this->log->record( 'fake_tool', 'no undo point', [ Item::make( 'fake', 'z', 'Z', 1, 2, [ 'available' => false, 'reason' => 'missing' ] ) ] )['id'];
        $html                         = $this->render();
        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; set a', $html );
        $this->assertStringContainsString( 'name="batch_id" value="' . $undoable . '"', $html );
        $this->assertStringNotContainsString( 'name="batch_id" value="' . $none . '"', $html );
        $this->assertStringContainsString( 'nonce-for-aied_pro_undo_batch', $html );
    }

    public function testAnUndoneBatchHasNoUndoButton(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $this->post( $id );
        $this->panel()->handleUndo();
        $html = $this->render();
        $this->assertStringContainsString( 'undone', $html );
        $this->assertStringNotContainsString( 'name="batch_id" value="' . $id . '"', $html );
    }

    public function testANonAdministratorSeesOnlyTheirOwnBatches(): void
    {
        $this->change( 'a', 1, 'mine', 7 );
        $this->change( 'b', 2, 'theirs', 99 );
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $html                 = $this->render();
        $this->assertStringContainsString( 'mine', $html );
        $this->assertStringNotContainsString( 'theirs', $html );
        unset( $GLOBALS['__wp_caps'] );
        $this->assertStringContainsString( 'theirs', $this->render() );
    }

    public function testUndoRestoresAndRedirectsWithTheRightNotice(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $this->post( $id );
        $this->panel()->handleUndo();
        $this->assertSame( 'one', $GLOBALS['__fake_store']['a'] );
        $this->assertStringContainsString( 'aied_pro_notice=batch_undone', $GLOBALS['__wp_redirects'][0] );
        $this->assertStringContainsString( 'tab=pro', $GLOBALS['__wp_redirects'][0] );
    }

    public function testAChangedSinceBatchIsNotOverwrittenAndTheNoticeSaysNothingWasRestored(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $GLOBALS['__fake_store']['a'] = 'edited since';
        $this->post( $id );
        $this->panel()->handleUndo();
        $this->assertSame( 'edited since', $GLOBALS['__fake_store']['a'], 'the panel never forces' );
        $this->assertStringContainsString( 'aied_pro_notice=batch_nothing_restored', $GLOBALS['__wp_redirects'][0] );
    }

    public function testAPartialUndoSaysSo(): void
    {
        $GLOBALS['__fake_store'] = [ 'a' => 'a1', 'b' => 'b1' ];
        $b                       = $this->log->record( 'multi', 'two things', [
            Item::make( 'fake', 'a', 'A', 'a1', 'a2' ),
            Item::make( 'fake', 'b', 'B', 'b1', 'b2', [ 'available' => false, 'reason' => 'missing' ] ),
        ] );
        $GLOBALS['__fake_store'] = [ 'a' => 'a2', 'b' => 'b2' ];
        $this->post( $b['id'] );
        $this->panel()->handleUndo();
        $this->assertStringContainsString( 'aied_pro_notice=batch_partly_undone', $GLOBALS['__wp_redirects'][0] );
        $this->assertSame( 'a1', $GLOBALS['__fake_store']['a'] );
        $this->assertSame( 'b2', $GLOBALS['__fake_store']['b'] );
    }

    public function testAnUnknownOrNonNumericBatchIsNotFound(): void
    {
        foreach ( [ 99, 'abc', '1; DROP TABLE', '-1', '' ] as $bad ) {
            $GLOBALS['__wp_redirects'] = [];
            $this->post( $bad );
            $this->panel()->handleUndo();
            $this->assertStringContainsString( 'aied_pro_notice=batch_not_found', $GLOBALS['__wp_redirects'][0], (string) $bad );
        }
    }

    public function testAnotherUsersBatchIsNotFoundToANonAdministratorAndIsNotUndone(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two', 'theirs', 99 );
        $GLOBALS['__wp_caps']         = [ 'edit_pages' => true ];
        $this->post( $id );
        $this->panel()->handleUndo();
        $this->assertStringContainsString( 'aied_pro_notice=batch_not_found', $GLOBALS['__wp_redirects'][0] );
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'] );
    }

    public function testTheHandlerRefusesABadNonceAMissingCapabilityAndANonPost(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $_POST                        = [ 'batch_id' => (string) $id ];

        $_REQUEST = $_POST + [ '_wpnonce' => 'forged' ];
        try {
            $this->panel()->handleUndo();
            $this->fail( 'a forged nonce must be refused' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'check_admin_referer failed', $e->getMessage() );
        }

        $this->post( $id );
        $GLOBALS['__wp_caps'] = [ 'read' => true ];
        try {
            $this->panel()->handleUndo();
            $this->fail( 'edit_pages is required' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'wp_die', $e->getMessage() );
        }

        unset( $GLOBALS['__wp_caps'] );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try {
            $this->panel()->handleUndo();
            $this->fail( 'POST only' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'wp_die', $e->getMessage() );
        }
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'], 'nothing was undone' );
    }

    public function testTheResultNoticesAreShown(): void
    {
        foreach ( [ 'batch_undone' => 'notice-success', 'batch_partly_undone' => 'notice-warning', 'batch_nothing_restored' => 'notice-error', 'batch_not_found' => 'notice-error' ] as $notice => $class ) {
            $_GET['aied_pro_notice'] = $notice;
            $this->assertStringContainsString( $class, $this->render(), $notice );
        }
        $_GET['aied_pro_notice'] = '<script>x</script>';
        $this->assertStringNotContainsString( '<script>', $this->render() );
    }
}
```

In `PluginTest.php` add:

```php
    public function testACompatibleBootRegistersTheRecentChangesPanel(): void
    {
        $this->assertTrue( Plugin::bootWith( '4.4.0', static fn (): bool => true ) );
        $this->assertArrayHasKey( 'admin_post_aied_pro_undo_batch', $GLOBALS['__wp_filters'] );
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: FAIL (`BatchesPanel` not found).

- [ ] **Step 3: Implement**

`pro-addon/src/Admin/BatchesPanel.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Admin;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\ProPage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The "Recent Pro changes" card on the Pro tab, with an Undo button that follows the same changed-since rule as `undo_batch`. */
final class BatchesPanel
{
    use AdminActions;

    public const ACTION_UNDO = 'aied_pro_undo_batch';

    public function __construct( private \Closure $unlocked, private BatchLog $log, private BatchUndo $undo, ?\Closure $terminate = null )
    {
        $this->initTerminate( $terminate );
    }

    public function register(): void
    {
        add_action( 'jhmg_aied_render_admin_tab', [ $this, 'render' ] );
        add_action( 'admin_post_' . self::ACTION_UNDO, [ $this, 'handleUndo' ] );
    }

    public function render( string $tab ): void
    {
        if ( ProPage::TAB !== $tab || ! current_user_can( 'edit_pages' ) || ! ( $this->unlocked )() ) {
            return;
        }
        $all     = current_user_can( 'manage_options' );
        $me      = (int) get_current_user_id();
        $batches = [];
        foreach ( $this->log->recent( 10 ) as $batch ) {
            if ( $all || (int) $batch['user_id'] === $me ) {
                $batches[] = $batch;
            }
        }
        $notices = [
            'batch_undone'           => [ 'success', __( 'The batch was undone.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'batch_partly_undone'    => [ 'warning', __( 'Only part of that batch could be undone: a page or setting was changed afterwards, or its earlier version is no longer stored. Nothing that changed since was overwritten.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'batch_nothing_restored' => [ 'error', __( 'Nothing could be restored: what that batch changed has been changed since, or its earlier version is no longer stored. Nothing was overwritten.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'batch_not_found'        => [ 'error', __( 'That batch was not found.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
        ];
        $notice = isset( $_GET['aied_pro_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['aied_pro_notice'] ) ) : '';
        ?>
        <div class="aied-card aied-pro" id="batches">
            <h2><?php esc_html_e( 'Recent Pro changes', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h2>
            <?php if ( isset( $notices[ $notice ] ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> inline"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
            <?php endif; ?>
            <?php if ( [] === $batches ) : ?>
                <p><?php esc_html_e( 'No Pro changes yet.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr>
                        <th>#</th>
                        <th><?php esc_html_e( 'When (UTC)', 'jhmg-ai-editor-for-divi-5-pro' ); ?></th>
                        <th><?php esc_html_e( 'Change', 'jhmg-ai-editor-for-divi-5-pro' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'jhmg-ai-editor-for-divi-5-pro' ); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $batches as $batch ) : ?>
                        <?php $d = BatchLog::describe( $batch ); ?>
                        <tr>
                            <td><?php echo esc_html( (string) $d['id'] ); ?></td>
                            <td><?php echo esc_html( $d['created_at'] ); ?></td>
                            <td><code><?php echo esc_html( $d['tool'] ); ?></code> <?php echo esc_html( $d['summary'] ); ?></td>
                            <td><?php echo esc_html( self::statusLabel( $d['status'] ) ); ?></td>
                            <td>
                                <?php if ( $d['undoable'] ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_UNDO ); ?>">
                                        <input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $d['id'] ); ?>">
                                        <?php wp_nonce_field( self::ACTION_UNDO ); ?>
                                        <button type="submit" class="button"><?php esc_html_e( 'Undo', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handleUndo(): void
    {
        $this->guardPost( self::ACTION_UNDO, 'edit_pages' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guardPost().
        $raw = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['batch_id'] ) ) : '';
        $id  = ctype_digit( $raw ) ? (int) $raw : 0;

        $batch = $id > 0 ? $this->log->find( $id ) : null;
        if ( null === $batch || ( ! current_user_can( 'manage_options' ) && (int) $batch['user_id'] !== (int) get_current_user_id() ) ) {
            $this->redirectToProTab( 'batch_not_found' );

            return;
        }
        $result = $this->undo->undo( $id );
        $this->redirectToProTab( ! empty( $result['fully_undone'] ) ? 'batch_undone' : ( (int) ( $result['restored'] ?? 0 ) > 0 ? 'batch_partly_undone' : 'batch_nothing_restored' ) );
    }

    private static function statusLabel( string $status ): string
    {
        return match ( $status ) {
            'undone'        => __( 'undone', 'jhmg-ai-editor-for-divi-5-pro' ),
            'partly_undone' => __( 'partly undone', 'jhmg-ai-editor-for-divi-5-pro' ),
            default         => __( 'applied', 'jhmg-ai-editor-for-divi-5-pro' ),
        };
    }
}
```

`autoload.php` (before `Plugin.php`): `Admin/BatchesPanel.php`.

`Plugin::wire()`: add `use AiEditorDivi5\Pro\Admin\BatchesPanel;` and `use AiEditorDivi5\Pro\Batch\Batches;`, and after the `ProposalsPanel` line:

```php
        ( new BatchesPanel( $unlocked, Batches::log(), Batches::undo() ) )->register();
```

- [ ] **Step 4: Run the Pro suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src pro-addon/tests
git commit -m "feat(pro): Recent Pro changes panel with a nonce-protected Undo that never forces

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 16: Wire it together, prove it on a real site, document, build

**Files:**
- Modify: `pro-addon/tests/ToolsTest.php`, `pro-addon/tests/PluginTest.php`, `pro-addon/README.md`, `CLAUDE.md`, `Makefile`
- Create: `scripts/verify-pro-settings.php`
- Delete: `pro-addon/staged/CustomCss.php`, `pro-addon/staged/MenuBuilder.php` (superseded by `src/Settings/`; `staged/PhpProposals*.php` went in Task 12). Remove the `staged/` row from the README table.

- [ ] **Step 1: Pin the finished tool list**

In `pro-addon/tests/ToolsTest.php` replace `testEveryToolIsAProToolWithAMatchingDefinition` (drop its "may be empty" assertion) and add:

```php
    public function testEveryToolIsAProToolWithAMatchingDefinition(): void
    {
        $this->assertNotSame( [], Tools::all() );
        foreach ( Tools::all() as $tool ) {
            $this->assertInstanceOf( ProTool::class, $tool );
            $d = $tool->definition();
            $this->assertSame( $tool->name(), $d['name'] );
            $this->assertNotSame( '', $tool->capability() );
            $this->assertMatchesRegularExpression( '/^[a-z_]+$/', $tool->name() );
            $this->assertLessThanOrEqual( 300, strlen( $d['description'] ), $tool->name() . ': the ChatGPT importer caps descriptions at 300 characters' );
            $this->assertSame( 'object', $d['inputSchema']['type'] );
            foreach ( $d['inputSchema']['required'] ?? [] as $field ) {
                $this->assertArrayHasKey( $field, $d['inputSchema']['properties'], $tool->name() . ": required field {$field}" );
            }
        }
    }

    public function testThisReleaseShipsExactlyTheSevenSettingsAndBatchTools(): void
    {
        $this->assertSame(
            [ 'set_front_page', 'set_custom_css', 'set_primary_menu', 'publish_page', 'propose_php_snippet', 'list_batches', 'undo_batch' ],
            array_map( static fn ( ProTool $t ): string => $t->name(), Tools::all() )
        );
    }

    public function testEveryKindARecordedItemUsesIsRegisteredForUndo(): void
    {
        $this->assertEqualsCanonicalizing(
            [ 'front_page', 'custom_css', 'menu', 'post_status', 'post_content' ],
            array_keys( \AiEditorDivi5\Pro\Batch\Batches::kinds() )
        );
    }
```

(The existing `testNoToolShadowsABuiltInOrAnotherProTool` and `testTheChatGptOperationBudgetIsRespected` stay: 16 free + 7 Pro = 23 ≤ 29.)

In `pro-addon/tests/PluginTest.php` rename `testACompatibleBootRegistersTheToolHooksWithNoToolsYet` to `testWhileLockedNoProToolIsAdvertised` (assertions unchanged) and add:

```php
    public function testWhileUnlockedTheSevenProToolsAreAdvertised(): void
    {
        $GLOBALS['__wp_options'] = [ 'aied_pro_premium_unlocked' => 1 ];
        \AiEditorDivi5\Pro\Licensing::resetForTests();
        $this->assertTrue( Plugin::bootWith( '4.4.0', static fn (): bool => true ) );
        $names = array_column( apply_filters( 'jhmg_aied_mcp_tools', [ [ 'name' => 'get_page_layout' ] ] ), 'name' );
        $this->assertSame( 'get_page_layout', $names[0], 'built-ins stay first' );
        $this->assertCount( 8, $names );
        $this->assertContains( 'undo_batch', $names );
        $GLOBALS['__wp_options'] = [];
        \AiEditorDivi5\Pro\Licensing::resetForTests();
    }
```

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`. Expected: all green.

- [ ] **Step 2: Remove the superseded staged files and update the docs**

```bash
git rm pro-addon/staged/CustomCss.php pro-addon/staged/MenuBuilder.php
```

In `pro-addon/README.md`: change the heading to `## What exists (0.2.0)`, delete the `staged/` row of the table, and add these rows (keep the existing ones):

```
| `src/Args.php`, `src/Canon.php` | strict argument readers; canonical hashes for the changed-since rule |
| `src/Batch/` | the batch store (`{prefix}aied_pro_batches`, last 20), `BatchLog`, and the undo engine (`BatchUndo`, `ItemKind`/`ValueKind`) |
| `src/Kinds/` | how each target is read and put back on undo: front page, Additional CSS block, menu, page status, page content |
| `src/Settings/` | `CustomCss` (managed block), `MenuBuilder`, `PhpProposals` |
| `src/Tools/` | the seven tools: `set_front_page`, `set_primary_menu`, `publish_page`, `set_custom_css`, `propose_php_snippet`, `list_batches`, `undo_batch` |
| `src/Admin/` | the "Recent Pro changes" and "Code proposals" panels on the Pro tab |
| `readme.txt` | the add-on's own readme; discloses the divi5lab.com calls (shipped in the zip) |
```

and add a short section:

```
## Dev override of the licence server

`AIED_PRO_API_BASE` redirects the licence key to another host, so it is honoured only when `wp_get_environment_type()` is `local` or `development`; anywhere else it is ignored and `https://divi5lab.com` is used.

## Undo, in one paragraph

Every Pro write is a batch of items. `undo_batch` restores each item whose target still looks the way the batch left it (the changed-since rule; `force` overrides only that), reports the rest (`changed_since`, `no_undo_point`, `forbidden`, `failed`) and says "fully undone" only when every item restored. Page-content items rely on the free plugin's history (10 snapshots per page), so a very old batch may be only partly undoable. Menu undo restores titles, links, nesting, classes, target, title attribute, XFN and description, not custom meta added by other plugins.
```

- [ ] **Step 3: Write the live check**

Create `scripts/verify-pro-settings.php`:

```php
<?php
/**
 * Real-site check of the Pro settings tools and one-step undo: the real handlers, the real batch table and
 * WordPress's own functions. Self-cleaning: it restores the front page, Additional CSS, the Main Menu and its
 * location and the code proposals to exactly what they were, and deletes every page, batch row and proposal it created.
 *
 * Run: make verify-pro-settings   (needs `make up`, the free plugin and the Pro add-on active; no licence is needed:
 * the tools are called directly, not through the licence-gated registry)
 */

use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Kinds\FrontPageKind;
use AiEditorDivi5\Pro\Settings\CustomCss;
use AiEditorDivi5\Pro\Settings\MenuBuilder;
use AiEditorDivi5\Pro\Settings\PhpProposals;
use AiEditorDivi5\Pro\Tools\ListBatches;
use AiEditorDivi5\Pro\Tools\ProposePhpSnippet;
use AiEditorDivi5\Pro\Tools\PublishPage;
use AiEditorDivi5\Pro\Tools\SetCustomCss;
use AiEditorDivi5\Pro\Tools\SetFrontPage;
use AiEditorDivi5\Pro\Tools\SetPrimaryMenu;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro on this site first.' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};

// ---- remember exactly what this script will touch --------------------------------------------
$location    = MenuBuilder::targetLocation();
$origCssPost = wp_get_custom_css_post();
$orig        = [
    'show'      => get_option( 'show_on_front', null ),
    'front'     => get_option( 'page_on_front', null ),
    'front_val' => FrontPageKind::read(),
    'css'       => (string) wp_get_custom_css(),
    'mods'      => get_theme_mod( 'nav_menu_locations', null ),
    'menu'      => MenuBuilder::snapshot( $location ),
    'proposals' => get_option( PhpProposals::OPTION, null ),
];
$pages    = [];
$batchIds = [];

BatchTable::ensureReady( true );
$check( BatchTable::exists(), 'the batch table exists on the real database (' . BatchTable::name() . ')' );

$log  = Batches::log();
$undo = Batches::undo();

$track = static function ( array $r ) use ( &$batchIds ): array {
    if ( isset( $r['batch']['id'] ) ) {
        $batchIds[] = (int) $r['batch']['id'];
    }

    return $r;
};
$trackUndo = static function ( array $r ) use ( &$batchIds ): array {
    if ( isset( $r['undo_batch']['id'] ) ) {
        $batchIds[] = (int) $r['undo_batch']['id'];
    }

    return $r;
};
$makePage = static function ( string $title, string $status ) use ( &$pages ): int {
    $id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => $status, 'post_title' => $title, 'post_content' => '' ], true );
    if ( is_wp_error( $id ) ) {
        WP_CLI::error( 'Could not create a throwaway page: ' . $id->get_error_message() );
    }
    $pages[] = (int) $id;

    return (int) $id;
};

try {
    $draft = $makePage( 'AIED Pro verify draft', 'draft' );
    $live1 = $makePage( 'AIED Pro verify live 1', 'publish' );
    $live2 = $makePage( 'AIED Pro verify live 2', 'publish' );

    // ---- publish_page -------------------------------------------------------------------------
    $pub = new PublishPage( $log );
    $pub->run( [ 'page_id' => $draft, 'dry_run' => true ] );
    $check( 'draft' === get_post_status( $draft ), 'publish_page: a dry run leaves the page a draft' );

    $r = $track( $pub->run( [ 'page_id' => $draft ] ) );
    $check( 'publish' === get_post_status( $draft ) && true === ( $r['batch']['undoable'] ?? false ), 'publish_page: publishes the draft and records an undoable batch' );
    $row = $log->find( (int) $r['batch']['id'] );
    $check( null !== $row && 'post_status' === ( $row['items'][0]['kind'] ?? '' ) && 'draft' === ( $row['items'][0]['before'] ?? '' ), 'the batch round-trips through the real table' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( true === $u['fully_undone'] && 'draft' === get_post_status( $draft ), 'undo returns the page to draft' );

    $r = $track( $pub->run( [ 'page_id' => $draft, 'publish_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 90 * DAY_IN_SECONDS ) ] ) );
    $check( 'future' === get_post_status( $draft ), 'publish_page: schedules a future page' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( true === $u['fully_undone'] && 'draft' === get_post_status( $draft ), 'undo of a scheduled page returns it to draft' );

    // ---- set_front_page -----------------------------------------------------------------------
    $fp = new SetFrontPage( $log );
    $r  = $track( $fp->run( [ 'page_id' => $live1 ] ) );
    $check( 'page' === get_option( 'show_on_front' ) && $live1 === (int) get_option( 'page_on_front' ), 'set_front_page: the real options are set' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( true === $u['fully_undone'] && FrontPageKind::read() === $orig['front_val'], 'undo restores the front page settings' );

    $r = $track( $fp->run( [ 'page_id' => $live2 ] ) );
    update_option( 'page_on_front', $live1 );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( 'changed_since' === ( $u['items'][0]['result'] ?? '' ) && $live1 === (int) get_option( 'page_on_front' ), 'a front page changed since is not overwritten' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'], true ) );
    $check( true === $u['fully_undone'] && FrontPageKind::read() === $orig['front_val'], 'force restores it' );

    // ---- set_custom_css -----------------------------------------------------------------------
    $css    = new SetCustomCss( $log );
    $sample = '.aied-verify::before { content: "é $1 \\0"; }';
    $r      = $track( $css->run( [ 'css' => $sample ] ) );
    $now    = (string) wp_get_custom_css();
    $check( CustomCss::extract( $now ) === $sample, 'set_custom_css: accents, $1 and a backslash are stored literally in the real Additional CSS' );
    $check( str_starts_with( $now, rtrim( $orig['css'] ) ), 'the owner\'s own CSS is untouched' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( true === $u['fully_undone'] && rtrim( (string) wp_get_custom_css() ) === rtrim( $orig['css'] ), 'undo restores the Additional CSS exactly' );

    $r = $track( $css->run( [ 'css' => '.aied-a{}' ] ) );
    wp_update_custom_css_post( str_replace( '.aied-a{}', '.aied-a{ color: red }', (string) wp_get_custom_css() ) );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( 'changed_since' === ( $u['items'][0]['result'] ?? '' ), 'an edit inside the managed block is not overwritten by undo' );
    $u = $trackUndo( $undo->undo( (int) $r['batch']['id'], true ) );
    $check( true === $u['fully_undone'] && null === CustomCss::extract( (string) wp_get_custom_css() ), 'force removes the block' );

    // ---- set_primary_menu (the spec's riskiest tool: menu creation is unproven until this passes) --
    $check( '' !== $location, 'the theme has a menu location (' . $location . ')' );
    $menu   = new SetPrimaryMenu( $log );
    $before = MenuBuilder::canonical( MenuBuilder::snapshot( $location ) );
    $r      = $track( $menu->run( [ 'items' => [
        [ 'title' => 'AIED Live 1', 'page_id' => $live1 ],
        [ 'title' => 'AIED Live 2', 'page_id' => $live2 ],
        [ 'title' => 'AIED Link', 'url' => 'https://example.com/aied-verify' ],
    ] ] ) );
    $built = MenuBuilder::snapshot( $location );
    $check( $built['exists'] && [ 'AIED Live 1', 'AIED Live 2', 'AIED Link' ] === array_column( $built['items'], 'title' ), 'set_primary_menu: Main Menu exists with the three items in order' );
    $check( '' === $location || $built['location_menu_id'] === $built['menu_id'], 'the theme location points at the new menu' );
    $builtCanon = MenuBuilder::canonical( $built );
    $u          = $trackUndo( $undo->undo( (int) $r['batch']['id'] ) );
    $check( true === $u['fully_undone'] && $before === MenuBuilder::canonical( MenuBuilder::snapshot( $location ) ), 'undo restores the previous menu state exactly' );
    $u2 = $trackUndo( $undo->undo( (int) $u['undo_batch']['id'] ) );
    $check( true === $u2['fully_undone'] && $builtCanon === MenuBuilder::canonical( MenuBuilder::snapshot( $location ) ), 'undoing the undo brings the new menu back' );

    // ---- propose_php_snippet ------------------------------------------------------------------
    $p = ( new ProposePhpSnippet() )->run( [ 'title' => 'AIED verify', 'code' => "<?php\n// verify only, never run", 'description' => 'x' ] );
    $check( true === $p['proposed'] && false === $p['executed'], 'propose_php_snippet: stored, not executed' );
    $check( 1 === count( array_filter( PhpProposals::all(), static fn ( array $x ): bool => $x['id'] === $p['id'] ) ), 'the proposal is in the real option' );

    // ---- list_batches -------------------------------------------------------------------------
    $listed = ( new ListBatches( $log ) )->run( [ 'limit' => 20 ] )['batches'];
    $check( [] !== $listed && isset( $listed[0]['changes'] ), 'list_batches: lists the batches just made' );
} catch ( \Throwable $e ) {
    $check( false, 'unexpected exception: ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
    // ---- put everything back exactly -----------------------------------------------------------
    null === $orig['show'] ? delete_option( 'show_on_front' ) : update_option( 'show_on_front', $orig['show'] );
    null === $orig['front'] ? delete_option( 'page_on_front' ) : update_option( 'page_on_front', $orig['front'] );
    $cssPost = wp_get_custom_css_post();
    if ( null === $origCssPost ) {
        if ( $cssPost ) {
            wp_delete_post( $cssPost->ID, true );
        }
    } else {
        wp_update_custom_css_post( $orig['css'] );
    }
    MenuBuilder::restore( $orig['menu'] );
    null === $orig['mods'] ? remove_theme_mod( 'nav_menu_locations' ) : set_theme_mod( 'nav_menu_locations', $orig['mods'] );
    null === $orig['proposals'] ? delete_option( PhpProposals::OPTION ) : update_option( PhpProposals::OPTION, $orig['proposals'], false );
    foreach ( $pages as $id ) {
        wp_delete_post( $id, true );
    }
    global $wpdb;
    if ( [] !== $batchIds ) {
        $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' );
    }
}

$check( $orig['show'] === get_option( 'show_on_front', null ) && $orig['front'] === get_option( 'page_on_front', null ), 'cleanup: the front page options are exactly as before' );
$check( rtrim( (string) wp_get_custom_css() ) === rtrim( $orig['css'] ), 'cleanup: Additional CSS is exactly as before' );
$check( MenuBuilder::canonical( $orig['menu'] ) === MenuBuilder::canonical( MenuBuilder::snapshot( $location ) ), 'cleanup: the Main Menu and its location are as before' );
$check( $orig['proposals'] === get_option( PhpProposals::OPTION, null ), 'cleanup: code proposals are exactly as before' );
$gone = true;
foreach ( $pages as $id ) {
    $gone = $gone && null === get_post( $id );
}
$check( $gone, 'cleanup: every throwaway page is deleted' );
$left = [] === $batchIds ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' );
$check( 0 === $left, 'cleanup: every batch row this script created is deleted' );

if ( $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'The Pro settings tools and batch undo are verified on the real site.' );
```

In the root `Makefile`: add `verify-pro-settings` to the `.PHONY` line, and after the `verify-pro-skeleton` target add:

```make
# ---------------------------------------------------------------
# verify-pro-settings — real-site check of the Pro settings tools and batch undo (self-cleaning; free + Pro plugins must be active)
# ---------------------------------------------------------------
verify-pro-settings:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-settings.php --user=1
```

- [ ] **Step 4: Run the real-site check**

Run: `make up` (idempotent), make sure both plugins are active (`docker compose exec -T wpcli wp plugin list`), then `make verify-pro-settings`.
Expected: every line `PASS`, ending `The Pro settings tools and batch undo are verified on the real site.`

If a check fails, fix the cause in `src/` (add a unit test that reproduces it first), and rerun. Specific outcomes:
- **A menu check fails** and cannot be fixed within the tool's contract: per spec Risk #1, remove `new SetPrimaryMenu( $log )` from `Tools::all()` (and `MenuKind` stays registered), drop `set_primary_menu` from `readme.txt`, `ToolsTest::testThisReleaseShipsExactlyTheSevenSettingsAndBatchTools` and this script, say so in the commit message, and ship six tools. Do not ship a guess.
- **The CSS `$1` check fails** (WordPress rewrote the stored CSS): the tool already hashes what is stored; if the stored text differs from the sent text, keep the tool but relax the check to compare `CustomCss::extract(wp_get_custom_css())` with what `wp_update_custom_css_post` returned, and note the normalisation in the tool's result message.
- If the script dies midway, rerun it: it records state first and restores in `finally`. If it was killed (not an exception), check the dev site by hand for pages titled `AIED Pro verify …`, a menu named `Main Menu`, the managed CSS block and rows in `wp_aied_pro_batches`, and remove them.

- [ ] **Step 5: Update `CLAUDE.md`**

1. In "Current state", replace the whole first bullet (from `- **Pro add-on skeleton 0.1.0** (merged to LOCAL main, 2d62447; NOT released, NOT pushed): …` through `… These are the first task of Plan 3.`) with:

```
- **Pro add-on 0.2.0** (branch `feat/pro-settings-batches`; NOT merged, NOT released, NOT pushed): licence hardening
  (only an explicit server verdict can lock; refresh skips ajax/cron and backs off 15 minutes after a fault; the API-base
  override only on local/dev sites; activation failures say why; `readme.txt` discloses the divi5lab.com calls), the batch
  store (`{prefix}aied_pro_batches`, last 20) with honest undo (`BatchUndo`: changed-since rule, per-item capability,
  "fully undone" only when every item restored) and 7 MCP tools: `set_front_page`, `set_primary_menu`, `publish_page`,
  `set_custom_css`, `propose_php_snippet`, `list_batches`, `undo_batch`, plus the "Recent Pro changes" and "Code proposals"
  panels on the Pro tab. MCP + admin only: REST/OpenAPI for the Pro tools is Plan 5. Plan:
  `docs/superpowers/plans/2026-10-02-pro-addon-settings-batches.md`. Verified: `make test-pro`, `make test`,
  `make verify-pro-settings`. Next: Plan 4 (Theme Builder layout tools + templates), Plan 5 (find/replace, audit, REST/OpenAPI
  parity, live verification). Before the first Pro sale: an end-to-end activation and update check against the real
  divi5lab.com (product id `ai-editor-divi5-pro` and `/plugins/divi-5-ai-editor` were confirmed against the layoutlab repo on
  2026-10-02), and confirm the licence server's `validate` answers have the shapes `Licensing::isVerdict` expects.
```

2. In the "Entry points" table add after the `verify-pro-skeleton` row:

```
| `make verify-pro-settings` | Real-site check of the Pro settings tools and batch undo on the Docker site; restores every setting and deletes everything it created (the free and Pro plugins must be active) |
```

3. In "What this project is", in the paragraph about the Pro add-on's skeleton, change "Skeleton 0.1.0: compat guard, licensing with the updater, a licence-gated tool registry (no tools yet), the Pro admin tab" to "0.2.0: compat guard, licensing with the updater, a licence-gated tool registry with 7 tools, batches and one-step undo, the Pro admin tab".

- [ ] **Step 6: Full verification before committing**

Run, and read the output of each:

```bash
make test
make test-pro
for f in $(git ls-files 'pro-addon/*.php' 'scripts/verify-pro-settings.php'); do php -l "$f" | grep -v "No syntax errors" ; done
make build-pro && unzip -l dist/jhmg-ai-editor-for-divi-5-pro.zip
```

Expected: `make test` OK (the free suite, unchanged count plus nothing removed), `make test-pro` OK, no `php -l` complaints, the zip lists `readme.txt`, `uninstall.php`, the main file and every `src/` file, and no `tests/`, `staged/` or `staged-4.3.0/` entries.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(pro): 0.2.0 wiring, live check (make verify-pro-settings), docs

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 8: Whole-branch review, then finish**

Use superpowers:requesting-code-review on `main..feat/pro-settings-batches` (focus: the five Review Focus items, `Licensing` hardening, the `CustomCss` splice, `MenuBuilder::restore`, capability checks at undo time), fix what it finds, re-run Step 6, then use superpowers:finishing-a-development-branch. Per `CLAUDE.md`: merge to LOCAL main only; nothing is pushed or released without the owner.

---

## Spec coverage

| Spec requirement | Task |
|---|---|
| Batch store: own table, last 20, user/tool/time/summary/items, item = target + previous value + hash of what was written + free snapshot result | 6, 7, 13 |
| `undo_batch` honest (`missing`/`no_undo_point`/`changed_since`), "fully undone" only when every item restored | 7, 14 |
| Changed-since rule; strict boolean `force` | 7, 14 |
| Undo is itself a batch | 7 |
| Settings tools 1-4 store the previous value; `publish_page` undo returns the previous status | 8-11 |
| `propose_php_snippet` stores for human review, never executed | 12 |
| `list_batches`, `undo_batch` tools | 14 |
| Admin: Recent batches with an Undo button (nonce + capability, same changed-since rule) | 15 |
| Capability per tool, arguments type-checked never cast, built-in response shapes | 5, 8-14 |
| `dry_run` where the spec lists it (`publish_page`) and on the other write tools | 8-11, 14 |
| Licensing: only an explicit verdict re-locks; lapsed/offline never locks; readme discloses remote calls | 1-4 |
| Skeleton release blockers 1-6 from `CLAUDE.md` | 1, 2, 3, 4 |
| Verification: settings tools restored afterwards (menu creation unproven today); batches undo incl. changed-since | 16 |
| `ProSafetyTest` keeps scanning every shipped file | 12, 16 (runs on the new files) |
| Not here (later plans): Theme Builder tools, `find_replace_site`, `audit_site`, REST/OpenAPI | Plans 4-5 |
