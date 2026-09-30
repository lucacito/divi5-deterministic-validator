<?php
/**
 * JHMG License Client — CANONICAL COPY.
 * Source of truth: layoutlab repo, lib/license-server/php-client/class-license-client.php
 * Synced into Pro plugins via scripts/sync-license-client.sh — DO NOT edit the plugin copies.
 * WP.ORG VARIANT (AI Editor for Divi 5): the update-check/updater code (inject_update and
 * helpers) is deliberately removed here — WordPress.org bans self-updaters (Plugin Check:
 * plugin_updater_detected). Do not re-sync the updater into this copy.
 * Constructor-parameterized per product; see sync script for consumers.
 * API contract (frozen): /api/license/{activate,validate,deactivate}, /api/plugin/update-check
 * Error codes: invalid_key | product_mismatch | license_not_usable | rate_limited | invalid_request
 * Enforcement policy: SOFT for the converter Pros (frozen — license state gates update
 * delivery + admin notices only; conversion features never lock). Documented exception:
 * the AI Editor for Divi 5 gates its premium tools behind a sticky unlock that only an
 * explicit server verdict of `revoked` or `invalid` re-locks (see its Licensing adapter);
 * lapse (expired/canceled) never locks features anywhere.
 */

namespace AiEditorDivi5\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LicenseClient {
    private const CACHE_TTL          = DAY_IN_SECONDS;
    private const GRACE_TTL          = 3 * DAY_IN_SECONDS;

    private string $opt_key;
    private string $opt_state;

    public function __construct(
        private string $product,
        private string $plugin_version,
        private string $api_base,
        private string $plugin_basename,
        private string $admin_page_slug,   // e.g. 'edcp-kit' — used in status_notice() links
        private string $product_page_url,  // e.g. 'https://divi5lab.com/plugins/elementor-to-divi-5' — update entry `url` + renew links
        private string $option_prefix      // e.g. 'edcp' — scopes option/transient storage so co-installed Pro plugins never collide
    ) {
        $this->opt_key             = "{$this->option_prefix}_license_key";
        $this->opt_state           = "{$this->option_prefix}_license_state";
    }

    public function get_key(): ?string { $k = get_option( $this->opt_key, '' ); return $k !== '' ? $k : null; }
    public function get_state(): ?array { $s = get_option( $this->opt_state, null ); return is_array( $s ) ? $s : null; }

    public function activate( string $key ): array {
        $res = $this->post( '/api/license/activate', [
            'key'            => $key,
            'site_url'       => home_url(),
            'product'        => $this->product,
            'plugin_version' => $this->plugin_version,
            'wp_version'     => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '',
        ] );
        if ( $res['ok'] ) {
            update_option( $this->opt_key, $key, false );
            $this->store_state( $res['body'] );
            return [ 'ok' => true, 'error' => null, 'status' => $res['body']['status'] ?? null ];
        }
        return [ 'ok' => false, 'error' => $res['error'], 'status' => $res['body']['status'] ?? null ];
    }

    public function deactivate(): void {
        $key = $this->get_key();
        if ( $key ) {
            $this->post( '/api/license/deactivate', [ 'key' => $key, 'site_url' => home_url() ] );
        }
        delete_option( $this->opt_key );
        delete_option( $this->opt_state );
        delete_option( $this->option_prefix . '_update_blocked' ); // legacy option from the pre-WP.org updater
    }

    public function refresh( bool $force = false ): void {
        $key = $this->get_key();
        if ( ! $key ) { return; }
        $state = $this->get_state();
        $age   = time() - (int) ( $state['checked_at'] ?? 0 );
        if ( ! $force && $age < self::CACHE_TTL ) { return; }

        $res = $this->post( '/api/license/validate', [ 'key' => $key, 'site_url' => home_url(), 'product' => $this->product ] );
        if ( $res['network_error'] ) {
            // Offline grace: keep last-known state up to GRACE_TTL past the cache window.
            if ( $age < self::CACHE_TTL + self::GRACE_TTL && $state ) { return; }
            return; // Beyond grace we STILL keep last state (soft enforcement) — notices handle messaging.
        }

        $code = $res['code'] ?? null;
        if ( $code === 429 || ( null !== $code && $code >= 500 ) ) {
            // Transient server error (rate limit / 5xx): keep last-known state and do NOT
            // bump checked_at, so the next admin load retries instead of being downgraded
            // by a blip on the license server.
            return;
        }

        if ( $res['ok'] ) {
            $this->store_state( $res['body'] );
        } else {
            // Definitive verdict (403 license_not_usable/product_mismatch, 404 invalid_key, ...).
            $this->store_state( [ 'status' => $res['body']['status'] ?? 'invalid', 'expires' => $state['expires'] ?? null ] );
        }
    }

    /**
     * Soft-enforcement admin notice. Informational only — never disables
     * features. Hooked indirectly via Licensing\LicensePage::maybe_render_notice().
     */
    public function status_notice(): void {
        if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $license_url = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=' . $this->admin_page_slug . '&tab=upgrade' ) : '';

        $key = $this->get_key();
        if ( ! $key ) {
            $this->render_notice(
                'notice-warning',
                __( 'AI Editor for Divi 5: activate your Pro license to unlock site-building tools and support.', 'ai-editor-divi5' ),
                __( 'Activate now', 'ai-editor-divi5' ),
                $license_url
            );
            return;
        }

        $state  = $this->get_state();
        $status = $state['status'] ?? 'unknown';
        if ( in_array( $status, [ 'expired', 'canceled' ], true ) ) {
            $this->render_notice(
                'notice-warning',
                __( 'AI Editor for Divi 5: your license has expired. Renew to keep receiving support.', 'ai-editor-divi5' ),
                __( 'Renew', 'ai-editor-divi5' ),
                $license_url
            );
            return;
        }
    }

    private function render_notice( string $class, string $message, string $cta, string $url ): void {
        printf(
            '<div class="notice %1$s"><p>%2$s%3$s</p></div>',
            esc_attr( $class ),
            esc_html( $message ),
            $url !== '' ? ' <a href="' . esc_url( $url ) . '">' . esc_html( $cta ) . '</a>' : ''
        );
    }

    private function store_state( array $body ): void {
        update_option( $this->opt_state, [
            'status'     => $body['status'] ?? 'unknown',
            'expires'    => $body['expires'] ?? null,
            'checked_at' => time(),
        ], false );
    }

    /** @return array{ok:bool, error:?string, body:array, network_error:bool, code:?int} */
    private function post( string $path, array $payload ): array {
        $raw = wp_remote_post( $this->api_base . $path, [
            'timeout' => 10,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
        ] );
        if ( is_wp_error( $raw ) ) {
            return [ 'ok' => false, 'error' => 'network_error', 'body' => [], 'network_error' => true, 'code' => null ];
        }
        $code = wp_remote_retrieve_response_code( $raw );
        $body = json_decode( wp_remote_retrieve_body( $raw ), true ) ?: [];
        if ( $code === 200 ) {
            return [ 'ok' => true, 'error' => null, 'body' => $body, 'network_error' => false, 'code' => $code ];
        }
        return [ 'ok' => false, 'error' => $body['error'] ?? "http_$code", 'body' => $body, 'network_error' => false, 'code' => $code ];
    }
}
