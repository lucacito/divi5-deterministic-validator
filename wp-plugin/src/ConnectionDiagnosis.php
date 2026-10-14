<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns what the Settings screen's "Test connection" button observed in the browser, plus a few facts the
 * server knows, into plain-English findings. Pure: no WordPress calls except the translation functions.
 *
 * The browser sends only small facts per probe (status, content type, the `code` of a WordPress error body,
 * whether the reply was a JSON-RPC envelope, a tool count, whether it was an HTML page, whether it was
 * redirected). Never the API key, never a response body. Nothing the browser sends is echoed back.
 *
 * "Connected" needs positive evidence from every probe. A missing or surprising probe is reported, never
 * read as success.
 */
final class ConnectionDiagnosis
{
    /** The probes every test runs. */
    public const PROBES = [ 'unauth', 'auth', 'tools' ];

    /** Extra probes, sent only while the OAuth sign-in is switched on. They never turn a working API-key connection into an error. */
    public const OAUTH_PROBES = [ 'oauth_as', 'oauth_prm' ];

    /** Error checks in precedence order: the first that matches is the only error shown. */
    private const ERROR_CHECKS = [
        'checkNetwork',
        'checkPermalinksPlain',
        'checkNotFound',
        'checkRestBlockedJson',
        'checkRestBlockedHtml',
        'checkServerError',
        'checkRedirected',
        'checkKeyOwnerCannotEdit',
        'checkKeyRejected',
        'checkBadReply',
        'checkNoTools',
        'checkUnexpected',
    ];

    /** Codes of WordPress or a security plugin refusing REST for visitors (never this plugin's own codes). */
    private const FOREIGN_REST_CODES = [ 'rest_login_required', 'rest_cannot_access', 'rest_forbidden', 'rest_disabled', 'rest_not_logged_in' ];

    /**
     * @param array<string,mixed> $probes output of sanitizeProbes()
     * @param array<string,mixed> $site   permalinks_plain, is_local, scheme, key_owner_ok, divi
     * @return list<array{level:string, code:string, title:string, fix:string}>
     */
    public static function explain( array $probes, array $site ): array
    {
        foreach ( self::ERROR_CHECKS as $check ) {
            $finding = self::$check( $probes, $site );
            if ( null !== $finding ) {
                return [ $finding ];
            }
        }

        $findings = [];
        foreach ( [ 'warnDiviMissing', 'warnDiviNewer', 'warnLocalSite', 'warnHttpOnly' ] as $warn ) {
            $finding = self::$warn( $site );
            if ( null !== $finding ) {
                $findings[] = $finding;
            }
        }
        if ( true === ( $site['oauth'] ?? false ) ) {
            foreach ( self::oauthFindings( $probes, $site ) as $finding ) {
                $findings[] = $finding;
            }
        }
        $findings[] = self::allOk( $probes );

        return $findings;
    }

    /**
     * Strict validation of what the browser posted: only the known probes, each with the known fields of the
     * right type. A value of the wrong type becomes its safe default; nothing is cast.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function sanitizeProbes( mixed $input ): array
    {
        $input  = is_array( $input ) ? $input : [];
        $probes = [];
        foreach ( self::PROBES as $name ) {
            $probes[ $name ] = self::sanitizeProbe( $input[ $name ] ?? [] );
        }
        foreach ( self::OAUTH_PROBES as $name ) {
            if ( isset( $input[ $name ] ) ) {
                $probes[ $name ] = self::sanitizeProbe( $input[ $name ] );
            }
        }

        return $probes;
    }

    /** @return array{status:int,contentType:string,jsonCode:string,jsonRpc:bool,toolCount:?int,headerHint:bool,redirected:bool,hasChallenge:bool,docIssuer:string,docResource:string} */
    private static function sanitizeProbe( mixed $p ): array
    {
        $p = is_array( $p ) ? $p : [];

        return [
            'status'       => self::intIn( $p['status'] ?? null, 0, 599 ) ?? 0,
            'contentType'  => self::text( $p['contentType'] ?? null, 100 ),
            'jsonCode'     => self::text( $p['jsonCode'] ?? null, 100 ),
            'jsonRpc'      => true === ( $p['jsonRpc'] ?? false ),
            'toolCount'    => self::intIn( $p['toolCount'] ?? null, 0, 1000 ),
            'headerHint'   => true === ( $p['headerHint'] ?? false ),
            'redirected'   => true === ( $p['redirected'] ?? false ),
            'hasChallenge' => true === ( $p['hasChallenge'] ?? false ),
            'docIssuer'    => self::text( $p['docIssuer'] ?? null, 300 ),
            'docResource'  => self::text( $p['docResource'] ?? null, 300 ),
        ];
    }

    private static function intIn( mixed $v, int $min, int $max ): ?int
    {
        return is_int( $v ) && $v >= $min && $v <= $max ? $v : null;
    }

    private static function text( mixed $v, int $max ): string
    {
        return is_string( $v ) ? substr( $v, 0, $max ) : '';
    }

    // ── Errors ──────────────────────────────────────────────────────────

    private static function checkNetwork( array $probes ): ?array
    {
        foreach ( self::PROBES as $name ) {
            if ( 0 === (int) ( $probes[ $name ]['status'] ?? 0 ) ) {
                return self::finding(
                    'error',
                    'NETWORK',
                    __( 'The test could not reach your site’s address.', 'jhmg-ai-editor-for-divi-5' ),
                    __( 'Check that the site address under Settings → General matches the address in your browser (www or not, http or https), and that the site is online.', 'jhmg-ai-editor-for-divi-5' )
                );
            }
        }

        return null;
    }

    private static function checkPermalinksPlain( array $probes, array $site ): ?array
    {
        if ( 404 === (int) $probes['unauth']['status'] && true === ( $site['permalinks_plain'] ?? false ) ) {
            return self::finding(
                'error',
                'PERMALINKS_PLAIN',
                __( 'The plugin’s address does not exist because your permalinks are set to “Plain”.', 'jhmg-ai-editor-for-divi-5' ),
                __( 'Go to Settings → Permalinks, choose any option except Plain, and save.', 'jhmg-ai-editor-for-divi-5' )
            );
        }

        return null;
    }

    private static function checkNotFound( array $probes ): ?array
    {
        foreach ( self::PROBES as $name ) {
            if ( 404 === (int) $probes[ $name ]['status'] ) {
                return self::finding(
                    'error',
                    'NOT_FOUND',
                    __( 'The plugin’s address answered “not found”.', 'jhmg-ai-editor-for-divi-5' ),
                    __( 'Go to Settings → Permalinks and press Save without changing anything. If it still fails, look for a redirect or rewrite plugin that is catching /wp-json/.', 'jhmg-ai-editor-for-divi-5' )
                );
            }
        }

        return null;
    }

    private static function checkRestBlockedJson( array $probes ): ?array
    {
        foreach ( self::PROBES as $name ) {
            $p = $probes[ $name ];
            if ( in_array( $p['status'], [ 401, 403 ], true ) && in_array( $p['jsonCode'], self::FOREIGN_REST_CODES, true ) ) {
                return self::finding(
                    'error',
                    'REST_BLOCKED_JSON',
                    __( 'A plugin or setting blocks the WordPress REST API for requests that are not logged in.', 'jhmg-ai-editor-for-divi-5' ),
                    __( 'In your security or “disable REST API” plugin, allow the path /wp-json/ai-editor-divi5/ . Your assistant cannot work while the REST API is closed.', 'jhmg-ai-editor-for-divi-5' )
                );
            }
        }

        return null;
    }

    private static function checkRestBlockedHtml( array $probes ): ?array
    {
        foreach ( self::PROBES as $name ) {
            $p = $probes[ $name ];
            if ( in_array( $p['status'], [ 403, 406, 429, 503 ], true ) && $p['headerHint'] ) {
                return self::finding(
                    'error',
                    'REST_BLOCKED_HTML',
                    __( 'A firewall, security plugin or maintenance page answered instead of WordPress.', 'jhmg-ai-editor-for-divi-5' ),
                    __( 'Allow the path /wp-json/ai-editor-divi5/ , including POST requests that carry an Authorization header, in your firewall (for example Wordfence, Cloudflare, Sucuri or your host’s firewall), and make sure maintenance mode is off.', 'jhmg-ai-editor-for-divi-5' )
                );
            }
        }

        return null;
    }

    private static function checkServerError( array $probes ): ?array
    {
        foreach ( self::PROBES as $name ) {
            if ( (int) $probes[ $name ]['status'] >= 500 ) {
                return self::finding(
                    'error',
                    'SERVER_ERROR',
                    __( 'Your site answered with a server error.', 'jhmg-ai-editor-for-divi-5' ),
                    __( 'Check your PHP error log (your host’s control panel shows it) and try again. If the error names this plugin, please report it in the support forum.', 'jhmg-ai-editor-for-divi-5' )
                );
            }
        }

        return null;
    }

    private static function checkRedirected( array $probes ): ?array
    {
        foreach ( self::PROBES as $name ) {
            if ( $probes[ $name ]['redirected'] ) {
                return self::finding(
                    'error',
                    'REDIRECTED',
                    __( 'Your site redirects the plugin’s address (for example http to https, or www to non-www). Assistants often drop the key or the request body on a redirect.', 'jhmg-ai-editor-for-divi-5' ),
                    __( 'Use the final address, the one the redirect leads to, in your assistant’s setup, or fix the site address under Settings → General.', 'jhmg-ai-editor-for-divi-5' )
                );
            }
        }

        return null;
    }

    private static function checkKeyOwnerCannotEdit( array $probes, array $site ): ?array
    {
        if ( false === ( $site['key_owner_ok'] ?? true ) ) {
            return self::finding(
                'error',
                'KEY_OWNER_CANNOT_EDIT',
                __( 'The user your API key belongs to cannot edit pages, so your assistant would be refused.', 'jhmg-ai-editor-for-divi-5' ),
                __( 'While logged in as an administrator, press Regenerate next to the API key, then update your assistant with the new key.', 'jhmg-ai-editor-for-divi-5' )
            );
        }

        return null;
    }

    private static function checkKeyRejected( array $probes ): ?array
    {
        $auth = $probes['auth'];
        if ( in_array( $auth['status'], [ 401, 403 ], true ) ) {
            return self::finding(
                'error',
                'KEY_REJECTED',
                __( 'The site received the request but did not accept the API key. Either the key is wrong, or your host removed the Authorization header before WordPress saw it.', 'jhmg-ai-editor-for-divi-5' ),
                __( 'Press Regenerate and paste the new key into your assistant. If it still fails, add these two lines at the top of your .htaccess file (Apache hosts):', 'jhmg-ai-editor-for-divi-5' )
                    . "\nRewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]"
            );
        }

        return null;
    }

    private static function checkBadReply( array $probes ): ?array
    {
        foreach ( [ 'unauth', 'auth' ] as $name ) {
            $p = $probes[ $name ];
            if ( 200 === $p['status'] && ! $p['jsonRpc'] ) {
                return self::finding(
                    'error',
                    'BAD_REPLY',
                    __( 'The address answered, but not with the plugin’s reply. A page cache, a maintenance page or another plugin is answering instead.', 'jhmg-ai-editor-for-divi-5' ),
                    __( 'Exclude /wp-json/ from page caching and CDN caching, and turn maintenance mode off.', 'jhmg-ai-editor-for-divi-5' )
                );
            }
        }

        return null;
    }

    private static function checkNoTools( array $probes ): ?array
    {
        $tools = $probes['tools'];
        if ( 200 === $tools['status'] && 0 === $tools['toolCount'] ) {
            return self::finding(
                'error',
                'NO_TOOLS',
                __( 'Connected, but the plugin listed no tools.', 'jhmg-ai-editor-for-divi-5' ),
                __( 'Deactivate and reactivate the plugin, then test again.', 'jhmg-ai-editor-for-divi-5' )
            );
        }

        return null;
    }

    /**
     * Last error check: "connected" needs the exact healthy shape on all three probes. Anything else is
     * reported as unexpected, with the statuses so the owner can describe it in a support request.
     */
    private static function checkUnexpected( array $probes ): ?array
    {
        $u = $probes['unauth'];
        $a = $probes['auth'];
        $t = $probes['tools'];

        $healthy = 401 === $u['status']
            && 200 === $a['status'] && $a['jsonRpc']
            && 200 === $t['status'] && null !== $t['toolCount'] && $t['toolCount'] > 0;
        if ( $healthy ) {
            return null;
        }

        return self::finding(
            'error',
            'UNEXPECTED',
            sprintf(
                /* translators: 1: HTTP status of the request without a key, 2: with the key, 3: tool list request */
                __( 'The site answered in a way the plugin did not expect (without the key: %1$d, with the key: %2$d, tool list: %3$d).', 'jhmg-ai-editor-for-divi-5' ),
                $u['status'],
                $a['status'],
                $t['status']
            ),
            __( 'Run the test again. If the numbers stay the same, send them to the support forum together with the name of your security or caching plugin.', 'jhmg-ai-editor-for-divi-5' )
        );
    }

    // ── Warnings (shown next to the success line) ───────────────────────

    private static function warnDiviMissing( array $site ): ?array
    {
        if ( null === ( $site['divi'] ?? null ) ) {
            return self::finding(
                'warning',
                'DIVI_MISSING',
                __( 'Connected, but Divi was not found, so there are no Divi pages to edit yet.', 'jhmg-ai-editor-for-divi-5' ),
                __( 'Install and activate the Divi 5 theme.', 'jhmg-ai-editor-for-divi-5' )
            );
        }

        return null;
    }

    private static function warnDiviNewer( array $site ): ?array
    {
        $divi = $site['divi'] ?? null;
        if ( is_string( $divi ) && '' !== $divi ) {
            $status = DiviCompat::status( $divi );
            if ( 'newer' === $status['level'] ) {
                return self::finding(
                    'warning',
                    'DIVI_NEWER',
                    $status['message'],
                    __( 'No action needed now. Check for a plugin update from time to time.', 'jhmg-ai-editor-for-divi-5' )
                );
            }
        }

        return null;
    }

    private static function warnLocalSite( array $site ): ?array
    {
        if ( true === ( $site['is_local'] ?? false ) ) {
            return self::finding(
                'warning',
                'LOCAL_SITE',
                __( 'This is a local site: only assistants that run on your own computer can reach it.', 'jhmg-ai-editor-for-divi-5' ),
                __( 'Claude Code, Cursor, VS Code and Gemini CLI can connect. ChatGPT and the Claude website cannot: they need a public https address.', 'jhmg-ai-editor-for-divi-5' )
            );
        }

        return null;
    }

    private static function warnHttpOnly( array $site ): ?array
    {
        if ( 'http' === ( $site['scheme'] ?? 'https' ) && true !== ( $site['is_local'] ?? false ) ) {
            return self::finding(
                'warning',
                'HTTP_ONLY',
                __( 'Your site address uses http, not https. ChatGPT and the Claude website need https.', 'jhmg-ai-editor-for-divi-5' ),
                __( 'Ask your host for an SSL certificate, then set the https address under Settings → General.', 'jhmg-ai-editor-for-divi-5' )
            );
        }

        return null;
    }

    /**
     * What stops Claude on the web from signing in. These are warnings: the API-key connection above works either way.
     * Nothing from the browser is echoed; only the verdicts are.
     *
     * @return list<array{level:string, code:string, title:string, fix:string}>
     */
    private static function oauthFindings( array $probes, array $site ): array
    {
        $out = [];
        $as  = $probes['oauth_as'] ?? null;
        $prm = $probes['oauth_prm'] ?? null;

        if ( null === $as || null === $prm ) {
            return $out;
        }

        $docsReachable = 200 === $as['status'] && '' !== $as['docIssuer'] && 200 === $prm['status'] && '' !== $prm['docResource'];
        if ( ! $docsReachable ) {
            $out[] = self::finding(
                'warning',
                'OAUTH_WELLKNOWN_BLOCKED',
                __( 'Claude on the web cannot find this site’s sign-in details.', 'jhmg-ai-editor-for-divi-5' ),
                __( 'The address /.well-known/oauth-authorization-server on your site did not return the plugin’s document. A security plugin, a server rule that blocks paths starting with a dot (common on nginx), or a site in a subfolder can cause this. Allow requests to /.well-known/oauth-authorization-server, /.well-known/openid-configuration and /.well-known/oauth-protected-resource.', 'jhmg-ai-editor-for-divi-5' )
            );

            return $out;
        }
        if ( ( $site['issuer'] ?? '' ) !== $as['docIssuer'] || ( $site['resource'] ?? '' ) !== $prm['docResource'] ) {
            $out[] = self::finding(
                'warning',
                'OAUTH_METADATA_MISMATCH',
                __( 'The sign-in details name a different site address than the one you are using.', 'jhmg-ai-editor-for-divi-5' ),
                __( 'Check Settings → General: the WordPress Address and Site Address must be the same address people actually visit (www or not, http or https). A redirect or a proxy in front of the site can also change it.', 'jhmg-ai-editor-for-divi-5' )
            );

            return $out;
        }
        if ( 401 === $probes['unauth']['status'] && ! $probes['unauth']['hasChallenge'] ) {
            $out[] = self::finding(
                'warning',
                'OAUTH_NO_CHALLENGE',
                __( 'Claude on the web would not be told where to sign in.', 'jhmg-ai-editor-for-divi-5' ),
                __( 'The “not signed in” answer reached the browser without its WWW-Authenticate header. A proxy, a CDN rule or a security plugin is removing it. Let that header through for /wp-json/ai-editor-divi5/.', 'jhmg-ai-editor-for-divi-5' )
            );

            return $out;
        }
        $out[] = self::finding(
            'ok',
            'OAUTH_OK',
            __( 'Claude on the web can find the sign-in details.', 'jhmg-ai-editor-for-divi-5' ),
            __( 'Add the address from the card below in claude.ai (Customize → Connectors → Add custom connector).', 'jhmg-ai-editor-for-divi-5' )
        );

        return $out;
    }

    private static function allOk( array $probes ): array
    {
        $count = (int) ( $probes['tools']['toolCount'] ?? 0 );

        return self::finding(
            'ok',
            'ALL_OK',
            sprintf(
                /* translators: %d: number of tools the plugin offers the assistant */
                _n(
                    'Connected: the address, the key and the permissions all work (%d tool available).',
                    'Connected: the address, the key and the permissions all work (%d tools available).',
                    $count,
                    'jhmg-ai-editor-for-divi-5'
                ),
                $count
            ),
            __( 'This test ran from your browser. If your assistant still cannot connect, a firewall may be blocking your assistant’s own address.', 'jhmg-ai-editor-for-divi-5' )
        );
    }

    /** @return array{level:string, code:string, title:string, fix:string} */
    private static function finding( string $level, string $code, string $title, string $fix ): array
    {
        return [ 'level' => $level, 'code' => $code, 'title' => $title, 'fix' => $fix ];
    }
}
