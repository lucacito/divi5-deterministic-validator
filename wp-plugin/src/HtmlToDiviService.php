<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one implementation of the html_to_divi tool, called by both the MCP handler and the REST controller so the two
 * can never drift: argument checks, the conversion, the same gate as every write, and the optional draft page.
 *
 * It never fetches anything and never saves anything but a new DRAFT page, only when asked to and only when the
 * converted layout passes the validator.
 */
final class HtmlToDiviService
{
    public const ENDPOINT = 'html_to_divi';

    /** Keys the tool accepts. Anything else is refused by name rather than ignored. */
    private const KNOWN = [ 'html', 'title', 'slug', 'create', 'image_mode', 'styles' ];

    /**
     * @param array<string,mixed> $args
     * @return array{status:int, body:array<string,mixed>} status: 200 ok, 400 bad input, 403 not allowed, 422 layout refused, 500 save failed
     */
    public static function run( array $args ): array
    {
        if ( ! current_user_can( PageAccess::CAP ) ) {
            UsageTracker::log( self::ENDPOINT, null, 'error' );

            return self::fail( 403, 'You do not have permission to convert HTML to pages.' );
        }

        $unknown = array_diff( array_keys( $args ), self::KNOWN );
        if ( array() !== $unknown ) {
            return self::fail( 400, 'Unknown argument(s): ' . implode( ', ', array_map( 'strval', $unknown ) ) . '. Use: ' . implode( ', ', self::KNOWN ) . '.' );
        }
        $html  = PageAccess::stringArg( $args, 'html' );
        $title = PageAccess::stringArg( $args, 'title' );
        $slug  = PageAccess::stringArg( $args, 'slug' );
        $mode  = PageAccess::stringArg( $args, 'image_mode' );
        $style = PageAccess::stringArg( $args, 'styles' );
        $create = PageAccess::boolArg( $args, 'create' );
        if ( null === $html || null === $title || null === $slug || null === $mode || null === $style ) {
            return self::fail( 400, 'html, title, slug, image_mode and styles must be strings.' );
        }
        if ( null === $create ) {
            return self::fail( 400, 'create must be a boolean (true or false).' );
        }
        if ( '' === $html ) {
            return self::fail( 400, 'html is required.' );
        }
        if ( '' !== $mode && ! in_array( $mode, [ HtmlImages::MODE_PLACEHOLDER, HtmlImages::MODE_KEEP ], true ) ) {
            return self::fail( 400, 'image_mode must be "placeholder" or "keep".' );
        }
        if ( '' !== $style && ! in_array( $style, [ HtmlToDivi::STYLES_NONE, HtmlToDivi::STYLES_BASIC ], true ) ) {
            return self::fail( 400, 'styles must be "basic" or "none".' );
        }
        $title = trim( sanitize_text_field( $title ) ); // a title is plain text
        $slug  = sanitize_title( $slug );
        if ( $create && ! current_user_can( 'publish_pages' ) ) {
            UsageTracker::log( self::ENDPOINT, null, 'error' );

            return self::fail( 403, 'You do not have permission to create pages.' );
        }

        $result = HtmlToDivi::convert(
            $html,
            [ 'image_mode' => '' === $mode ? HtmlImages::MODE_PLACEHOLDER : $mode, 'styles' => '' === $style ? HtmlToDivi::STYLES_BASIC : $style ],
            ImagePack::manifest(),
            static fn( string $url ): bool => function_exists( 'attachment_url_to_postid' ) && attachment_url_to_postid( $url ) > 0,
            static fn( string $layout ): string => ImageTokens::resolve(
                $layout,
                defined( 'AI_EDITOR_DIVI5_FILE' ) ? plugins_url( 'assets/images/', AI_EDITOR_DIVI5_FILE ) : '',
                null,
                static fn( ?string $u, string $t ): ?string => apply_filters( 'jhmg_aied_image_token', $u, $t )
            )
        );
        if ( true !== $result['ok'] ) {
            UsageTracker::log( self::ENDPOINT, null, 'error' );

            return [ 'status' => 400, 'body' => [ 'converted' => false, 'error' => (string) $result['error'], 'report' => $result['report'] ] ];
        }

        $layout = (string) $result['layout'];
        $gate   = LayoutGate::check( $layout );
        if ( ! $gate->isValid() ) {
            UsageTracker::log( self::ENDPOINT, null, 'invalid', count( $gate->violations() ) );

            return [ 'status' => 422, 'body' => [
                'converted'  => false,
                'valid'      => false,
                'created'    => false,
                'violations' => array_map( static fn( $v ): array => $v->toArray(), $gate->violations() ),
                'report'     => $result['report'],
                'message'    => 'The converted layout failed validation, so nothing was saved. Report these violations; they mean the converter produced something Divi 5 does not accept.',
            ] ];
        }

        if ( ! $create ) {
            UsageTracker::log( self::ENDPOINT, null, 'read' );

            return [ 'status' => 200, 'body' => StyleReferences::attach( [
                'converted' => true,
                'valid'     => true,
                'created'   => false,
                'layout'    => $layout,
                'report'    => $result['report'],
                'next'      => 'Review the report, then call create_page with this layout (or call html_to_divi again with create=true and a title). Replace placeholder images with real ones from list_media_images, and style the page with get_style_guide.',
            ], $layout ) ];
        }

        if ( '' === $title ) {
            return self::fail( 400, 'title is required when create is true.' . ( '' !== $result['report']['suggested_title'] ? ' The HTML suggests: "' . $result['report']['suggested_title'] . '".' : '' ) );
        }
        $pageId = DraftPage::insert( $title, $layout, $slug );
        if ( is_wp_error( $pageId ) ) {
            UsageTracker::log( self::ENDPOINT, null, 'error' );

            return self::fail( 500, $pageId->get_error_message() );
        }
        UsageTracker::log( self::ENDPOINT, (int) $pageId, 'valid' );

        return [ 'status' => 200, 'body' => StyleReferences::attach( [
            'converted'      => true,
            'valid'          => true,
            'created'        => true,
            'layout_omitted' => true,
            'page'           => [
                'id'     => (int) $pageId,
                'title'  => $title,
                'status' => 'draft',
                'link'   => get_permalink( (int) $pageId ),
            ],
            'report'         => $result['report'],
            'next'           => 'The page is a draft. Replace placeholder images (list_media_images, then update_page_layout or edit_page_content) and style it with get_style_guide; the owner publishes it.',
        ], $layout ) ];
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function fail( int $status, string $message ): array
    {
        return [ 'status' => $status, 'body' => [ 'converted' => false, 'error' => $message ] ];
    }
}
