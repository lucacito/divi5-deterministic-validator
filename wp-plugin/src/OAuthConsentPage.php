<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The two small HTML pages of the sign-in: "Allow this app?" and an error. A self-contained document (no theme,
 * no scripts, no external files), every value escaped. Pure: the handler sends the headers and the page.
 */
final class OAuthConsentPage
{
    /**
     * @param array<string,mixed> $req   a successful OAuthAuthorizeRequest::parse() result
     * @param string              $nonce the approval nonce for this exact request
     */
    public static function render( array $req, string $userName, string $siteName, string $postUrl, string $nonce ): string
    {
        $host     = OAuthRedirectPolicy::displayHost( (string) $req['redirect_uri'] );
        $loopback = OAuthRedirectPolicy::isLoopback( (string) $req['redirect_uri'] );
        $name     = (string) $req['client']['name'];

        $hidden = '';
        foreach ( [
            'client_id'             => $req['client']['id'],
            'redirect_uri'          => $req['redirect_uri'],
            'state'                 => $req['state'],
            'code_challenge'        => $req['code_challenge'],
            'code_challenge_method' => 'S256',
            'response_type'         => 'code',
            'resource'              => $req['resource'],
            '_wpnonce'              => $nonce,
        ] as $field => $value ) {
            $hidden .= '<input type="hidden" name="' . self::e( $field ) . '" value="' . self::e( (string) $value ) . '">';
        }

        $warn = $loopback
            ? '<p class="warn">' . self::e( __( 'This app is running on your own computer. Only continue if you started it just now.', 'jhmg-ai-editor-for-divi-5' ) ) . '</p>'
            : '';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . self::e( __( 'Allow access', 'jhmg-ai-editor-for-divi-5' ) ) . '</title>' . self::style() . '</head><body><main>'
            . '<h1>' . self::e( sprintf(
                /* translators: %s: the name of the app asking for access */
                __( 'Allow “%s” to use this site?', 'jhmg-ai-editor-for-divi-5' ),
                $name
            ) ) . '</h1>'
            . '<p>' . self::e( sprintf(
                /* translators: 1: the site name, 2: the WordPress user's name */
                __( 'You are signed in to %1$s as %2$s.', 'jhmg-ai-editor-for-divi-5' ),
                $siteName,
                $userName
            ) ) . '</p>'
            . '<p>' . self::e( __( 'If you allow it, this app can:', 'jhmg-ai-editor-for-divi-5' ) ) . '</p><ul>'
            . '<li>' . self::e( __( 'read your Divi 5 pages and Projects', 'jhmg-ai-editor-for-divi-5' ) ) . '</li>'
            . '<li>' . self::e( __( 'edit them and create new pages as drafts, only as you and only where your account is allowed to', 'jhmg-ai-editor-for-divi-5' ) ) . '</li></ul>'
            . '<p class="muted">' . self::e( __( 'Every change is checked before it is saved and can be undone. It cannot change your settings, users, plugins or code. You can disconnect it any time under AI Editor → Settings.', 'jhmg-ai-editor-for-divi-5' ) ) . '</p>'
            . '<p class="to">' . self::e( __( 'After you answer, your browser returns to:', 'jhmg-ai-editor-for-divi-5' ) ) . ' <strong>' . self::e( $host ) . '</strong></p>' . $warn
            . '<form method="post" action="' . self::e( $postUrl ) . '">' . $hidden
            . '<button type="submit" name="decision" value="approve" class="allow">' . self::e( __( 'Allow', 'jhmg-ai-editor-for-divi-5' ) ) . '</button> '
            . '<button type="submit" name="decision" value="deny">' . self::e( __( 'Deny', 'jhmg-ai-editor-for-divi-5' ) ) . '</button>'
            . '</form></main></body></html>';
    }

    public static function error( string $message ): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . self::e( __( 'Sign-in stopped', 'jhmg-ai-editor-for-divi-5' ) ) . '</title>' . self::style() . '</head><body><main>'
            . '<h1>' . self::e( __( 'Sign-in stopped', 'jhmg-ai-editor-for-divi-5' ) ) . '</h1><p>' . self::e( $message ) . '</p>'
            . '<p class="muted">' . self::e( __( 'Nothing was shared and nothing was changed. You can close this window.', 'jhmg-ai-editor-for-divi-5' ) ) . '</p></main></body></html>';
    }

    private static function e( string $s ): string
    {
        return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }

    private static function style(): string
    {
        return '<style>body{margin:0;background:#f0f0f1;color:#1d2327;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
            . 'main{max-width:30rem;margin:8vh auto;padding:1.5rem 1.75rem;background:#fff;border:1px solid #c3c4c7;border-radius:8px}'
            . 'h1{font-size:1.3rem;margin:0 0 .75rem}.muted{color:#50575e;font-size:.9rem}.to strong{word-break:break-all}'
            . '.warn{background:#fcf9e8;border:1px solid #dba617;padding:.5rem .75rem;border-radius:4px}'
            . 'button{font:inherit;padding:.5rem 1.25rem;border:1px solid #2271b1;border-radius:4px;background:#fff;color:#2271b1;cursor:pointer}'
            . 'button.allow{background:#2271b1;color:#fff}button:focus{outline:2px solid #1d2327;outline-offset:2px}'
            . '@media (prefers-color-scheme:dark){body{background:#101517;color:#e8eaeb}main{background:#1d2327;border-color:#3c434a}.muted{color:#a7aaad}'
            . '.warn{background:#2e2a12;border-color:#b78a10}button{background:#1d2327;color:#72aee6;border-color:#72aee6}button.allow{background:#72aee6;color:#101517}}</style>';
    }
}
