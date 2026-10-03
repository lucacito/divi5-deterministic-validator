<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Site\LinkScanner;
use AiEditorDivi5\Pro\Site\SiteItems;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\StyleReferences;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A read-only, deterministic audit of the site's Divi 5 pages and live header/footer: layout checker violations
 * (including custom code the plugin will not save), preset and global-color ids the site does not have, and internal
 * links that point at no page or at an unpublished one. 25 items per call with a cursor. No AI, no remote request,
 * nothing is ever written.
 */
final class AuditSite implements ProTool
{
    public const NAME         = 'audit_site';
    public const PAGE_SIZE    = 25;
    public const MAX_FINDINGS = 200;

    public const LINK_NO_POST       = 'LINK_NO_POST';
    public const LINK_NOT_PUBLISHED = 'LINK_NOT_PUBLISHED';
    public const UNKNOWN_PRESET     = 'UNKNOWN_PRESET_ID';
    public const UNKNOWN_COLOR      = 'UNKNOWN_COLOR_ID';

    private \Closure $styleAdvisor;

    /**
     * @param (\Closure(string):?array<string,mixed>)|null $styleAdvisor fn(content): the free plugin's style-reference advisory or null
     */
    public function __construct( ?\Closure $styleAdvisor = null, private int $maxFindings = self::MAX_FINDINGS )
    {
        $this->styleAdvisor = $styleAdvisor ?? static fn ( string $content ): ?array => StyleReferences::attach( [], $content )['advisories']['style_references'] ?? null;
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
            'description' => 'Read-only audit of Divi 5 pages and the live header/footer: layout check violations, unknown preset or color ids, and internal links to missing or unpublished pages. 25 items per call; pass the returned next_cursor to continue. Makes no remote request.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'scope'  => [ 'type' => 'string', 'description' => 'pages, layouts or all (default all)', 'enum' => SiteItems::SCOPES ],
                    'cursor' => [ 'type' => 'integer', 'description' => 'Where to continue: the next_cursor of the previous call (default 0)' ],
                ],
                'required'   => [],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $scope = array_key_exists( 'scope', $arguments ) && null !== $arguments['scope'] ? (string) Args::string( $arguments, 'scope', true, 20 ) : 'all';
        if ( ! in_array( $scope, SiteItems::SCOPES, true ) ) {
            throw new \InvalidArgumentException( '"scope" must be one of: ' . implode( ', ', SiteItems::SCOPES ) . '.' );
        }
        $cursor = (int) Args::int( $arguments, 'cursor', false, 0 );
        if ( 'layouts' === $scope && ! current_user_can( 'edit_theme_options' ) ) {
            throw new \InvalidArgumentException( 'You do not have permission to read header and footer layouts (edit_theme_options and Divi\'s Theme Builder permission).' );
        }

        $set   = SiteItems::collect( $scope, SiteItems::AUDIT_STATUSES, null );
        $total = count( $set['items'] );
        $slice = array_slice( $set['items'], $cursor, self::PAGE_SIZE );
        $host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );

        $findings = [];
        $totals   = [];
        $problems = 0;
        foreach ( $slice as $item ) {
            $found = $this->auditItem( SiteItems::content( $item['id'] ), $host );
            if ( [] !== $found ) {
                $problems++;
            }
            foreach ( $found as $f ) {
                $totals[ $f['code'] ] = ( $totals[ $f['code'] ] ?? 0 ) + 1;
                $findings[]           = [ 'kind' => $item['kind'], 'id' => $item['id'], 'title' => $item['title'] ] + $f;
            }
        }
        $truncated = count( $findings ) > $this->maxFindings;
        ksort( $totals );

        $out = [
            'scope'              => $scope,
            'total_items'        => $total,
            'scanned'            => count( $slice ),
            'items_with_findings' => $problems,
            'findings'           => array_slice( $findings, 0, $this->maxFindings ),
            'findings_truncated' => $truncated,
            'totals_by_code'     => $totals,
            'next_cursor'        => $cursor + self::PAGE_SIZE < $total ? $cursor + self::PAGE_SIZE : null,
        ];
        if ( $set['truncated'] ) {
            $out['note'] = 'This site has more than ' . SiteItems::PAGE_LIMIT . ' Divi 5 pages; only the first ' . SiteItems::PAGE_LIMIT . ' (by id) are audited.';
        }
        if ( null !== $set['layouts_note'] ) {
            $out['layouts_note'] = $set['layouts_note'];
        }

        return $out;
    }

    /**
     * @return list<array{code:string, count:int, detail:string}>
     */
    private function auditItem( string $content, string $host ): array
    {
        $findings = [];

        // 1. Layout checker (structure, schema, and the no-custom-code policy), on the stored content as it is.
        $byCode = [];
        foreach ( LayoutGate::check( $content )->violations() as $v ) {
            $a = $v->toArray();
            $byCode[ $a['code'] ]['count']  = ( $byCode[ $a['code'] ]['count'] ?? 0 ) + 1;
            $byCode[ $a['code'] ]['detail'] ??= $a['path'] . ': ' . $a['message'];
        }
        foreach ( $byCode as $code => $row ) {
            $findings[] = [ 'code' => (string) $code, 'count' => $row['count'], 'detail' => $row['detail'] ];
        }

        // 2. Preset and global-color ids this site does not have (the free plugin's own advisory).
        $advice = ( $this->styleAdvisor )( $content );
        if ( is_array( $advice ) ) {
            foreach ( [ 'unknown_presets' => self::UNKNOWN_PRESET, 'unknown_colors' => self::UNKNOWN_COLOR ] as $key => $code ) {
                $rows = is_array( $advice[ $key ] ?? null ) ? $advice[ $key ] : [];
                if ( [] !== $rows ) {
                    $findings[] = [ 'code' => $code, 'count' => count( $rows ), 'detail' => implode( ', ', array_slice( array_map( static fn ( $r ): string => (string) ( $r['id'] ?? '' ), $rows ), 0, 5 ) ) ];
                }
            }
        }

        // 3. Internal links to nothing, or to something that is not published. Read from the content; nothing is requested.
        $missing = [];
        $draft   = [];
        // Root-relative links are relative to the host root, not to the WordPress home path (a subdirectory install).
        $home    = (array) wp_parse_url( home_url() );
        $base    = ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? $host ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
        foreach ( LinkScanner::internalPaths( $content, $host ) as $path ) {
            $id = (int) url_to_postid( $base . $path );
            if ( 0 === $id ) {
                $missing[] = $path;
                continue;
            }
            $status = (string) get_post_status( $id );
            if ( 'publish' !== $status ) {
                $draft[] = $path . ' (' . ( '' === $status ? 'unknown' : $status ) . ')';
            }
        }
        if ( [] !== $missing ) {
            $findings[] = [ 'code' => self::LINK_NO_POST, 'count' => count( $missing ), 'detail' => implode( ', ', array_slice( $missing, 0, 5 ) ) . ' (no page or post has this address; it may be an archive or a custom route)' ];
        }
        if ( [] !== $draft ) {
            $findings[] = [ 'code' => self::LINK_NOT_PUBLISHED, 'count' => count( $draft ), 'detail' => implode( ', ', array_slice( $draft, 0, 5 ) ) ];
        }

        return $findings;
    }
}
