<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The sign-in page (admin-post.php?action=aied_oauth_authorize). Claude opens it in the person's browser:
 * not signed in -> the normal WordPress login, then back here; signed in -> "Allow this app?"; Approve -> a
 * one-time code is sent to the address the app registered. The checks are in OAuthAuthorizeRequest.
 */
final class OAuthAuthorizeHandler
{
    public static function handle(): void
    {
        self::headers();
        if ( ! OAuthSettings::enabled() ) {
            self::page( 404, OAuthConsentPage::error( __( 'Signing in with an app is switched off on this site.', 'jhmg-ai-editor-for-divi-5' ) ) );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $isPost = 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
        // phpcs:disable WordPress.Security.NonceVerification
        $params = wp_unslash( $isPost ? $_POST : $_GET );
        // phpcs:enable WordPress.Security.NonceVerification

        $req = OAuthAuthorizeRequest::parse( is_array( $params ) ? $params : [], OAuthRuntime::clients(), OAuthRuntime::config() );
        if ( true !== $req['ok'] ) {
            if ( true === $req['redirectable'] ) {
                self::redirect( OAuthAuthorizeRequest::redirectUrl( $req['redirect_uri'], [ 'error' => $req['error'], 'error_description' => $req['description'], 'state' => $req['state'] ] ) );
            }
            self::page( 400, OAuthConsentPage::error( (string) $req['message'] ) );
        }

        if ( ! is_user_logged_in() ) {
            if ( $isPost ) {
                self::page( 403, OAuthConsentPage::error( __( 'Your session ended. Start the connection again from your app.', 'jhmg-ai-editor-for-divi-5' ) ) );
            }
            // phpcs:ignore WordPress.Security.NonceVerification
            wp_safe_redirect( wp_login_url( admin_url( 'admin-post.php?' . http_build_query( array_map( 'strval', array_filter( (array) wp_unslash( $_GET ), 'is_string' ) ), '', '&' ) ) ) );
            exit;
        }
        if ( ! current_user_can( PageAccess::CAP ) ) {
            self::page( 403, OAuthConsentPage::error( __( 'Your WordPress account is not allowed to edit pages, so it cannot connect an app.', 'jhmg-ai-editor-for-divi-5' ) ) );
        }

        $action = 'aied_oauth_approve_' . OAuthAuthorizeRequest::fingerprint( $req['client']['id'], $req['redirect_uri'], $req['code_challenge'], $req['state'] );

        if ( ! $isPost ) {
            $user = wp_get_current_user();
            self::page( 200, OAuthConsentPage::render(
                $req,
                (string) $user->display_name,
                (string) get_bloginfo( 'name' ),
                admin_url( 'admin-post.php?action=aied_oauth_authorize' ),
                wp_create_nonce( $action )
            ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.
        $nonce = is_string( $_POST['_wpnonce'] ?? null ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( false === wp_verify_nonce( $nonce, $action ) ) {
            self::page( 403, OAuthConsentPage::error( __( 'This page expired. Start the connection again from your app.', 'jhmg-ai-editor-for-divi-5' ) ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $decision = is_string( $_POST['decision'] ?? null ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';

        if ( 'approve' !== $decision ) {
            self::redirect( OAuthAuthorizeRequest::redirectUrl( $req['redirect_uri'], [ 'error' => 'access_denied', 'state' => $req['state'] ] ) );
        }

        $code = OAuthRuntime::tokens()->issueCode( $req['client']['id'], get_current_user_id(), $req['redirect_uri'], $req['code_challenge'], $req['resource'] );
        self::redirect( OAuthAuthorizeRequest::redirectUrl( $req['redirect_uri'], [ 'code' => $code, 'state' => $req['state'] ] ) );
    }

    private static function headers(): void
    {
        nocache_headers();
        header( 'X-Frame-Options: DENY' );
        header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'" );
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Content-Type-Options: nosniff' );
    }

    private static function page( int $status, string $html ): never
    {
        status_header( $status );
        header( 'Content-Type: text/html; charset=utf-8' );
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- OAuthConsentPage escapes every value.
        exit;
    }

    /** The target was registered by the client and re-checked against that registration, so an external redirect is intended. */
    private static function redirect( string $url ): never
    {
        wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit;
    }
}
