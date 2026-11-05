<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Companion;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns what the checks found on a set of pages into one prioritised fix list that any agent can work through with any tool.
 * Pure: the findings come in, an ordered list goes out. It adds nothing of its own and never changes a page.
 *
 * Priority: high = a visitor is hurt now (text that cannot be read, a link to nowhere, a layout the checker refuses),
 * medium = Divi's stock look or an id the site does not have, low = taste (repetition, spacing, a hard-coded colour).
 */
final class DesignReview
{
    public const MAX_FIXES = 200;

    private const RANK = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];

    /** The audit codes that mean "an id this site does not have" (AuditSite). */
    private const STYLE_ID_CODES = [ 'UNKNOWN_PRESET_ID', 'UNKNOWN_COLOR_ID', 'UNKNOWN_VARIABLE_ID', 'UNKNOWN_CUSTOM_FIELD' ];

    /**
     * @param list<array{kind:string, id:int, title:string, content:string}> $items the pages reviewed
     * @param list<array<string,mixed>> $audit findings of the site audit for the same pages (kind, id, title, code, count, detail)
     * @param callable(string):array<string,mixed> $designCheck the free plugin's DesignCheck::run for one layout
     * @param array<string,string> $globals global color id => value
     * @return array{fixes:list<array<string,mixed>>, counts:array<string,int>, truncated:bool, pages_reviewed:int}
     */
    public static function build( array $items, array $audit, callable $designCheck, array $globals = [] ): array
    {
        $fixes = [];
        $add   = static function ( string $priority, string $code, array $item, string $message, array $extra = [] ) use ( &$fixes ): void {
            $fixes[] = [ 'priority' => $priority, 'code' => $code, 'kind' => $item['kind'], 'id' => $item['id'], 'title' => $item['title'], 'message' => $message ] + $extra;
        };
        $byId = [];
        foreach ( $items as $item ) {
            $byId[ $item['id'] ] = $item;
        }

        foreach ( $audit as $f ) {
            $item = $byId[ $f['id'] ?? 0 ] ?? [ 'kind' => (string) ( $f['kind'] ?? 'page' ), 'id' => (int) ( $f['id'] ?? 0 ), 'title' => (string) ( $f['title'] ?? '' ) ];
            $code = (string) ( $f['code'] ?? '' );
            if ( 'LINKS_NOT_ALL_CHECKED' === $code ) {
                continue;
            }
            $detail = [ 'detail' => (string) ( $f['detail'] ?? '' ), 'count' => (int) ( $f['count'] ?? 1 ) ];
            if ( in_array( $code, [ 'LINK_NO_POST', 'LINK_NOT_PUBLISHED' ], true ) ) {
                $add( 'high', 'BROKEN_INTERNAL_LINK', $item, 'A link on this page goes to a page that does not exist or is not published. Fix or remove the link.', $detail + [ 'audit_code' => $code ] );
            } elseif ( in_array( $code, self::STYLE_ID_CODES, true ) ) {
                $add( 'medium', $code, $item, 'The layout uses an id this site does not have (Divi falls back to a default look). Use an id from list_global_styles or remove it.', $detail );
            } else {
                $add( 'high', $code, $item, 'The layout checker reports this on the stored layout. Fix it with validate-layout as a guide.', $detail );
            }
        }

        foreach ( $items as $item ) {
            $check = $designCheck( $item['content'] );
            foreach ( (array) ( $check['checks']['contrast']['problems'] ?? [] ) as $p ) {
                $add( 'high', 'LOW_CONTRAST', $item, sprintf( 'Text %s on %s has a contrast of %s:1 (needs %s:1). Change one of the two colors.', $p['text_color'] ?? '?', $p['background'] ?? '?', $p['ratio'] ?? '?', $p['needs'] ?? '?' ), [ 'block' => $p['block'] ?? '' ] );
            }
            foreach ( (array) ( $check['checks']['default_fallbacks']['findings'] ?? [] ) as $d ) {
                $add( 'certain' === ( $d['confidence'] ?? '' ) ? 'medium' : 'low', (string) $d['code'], $item, (string) $d['message'], [ 'block' => $d['block'] ?? '', 'confidence' => $d['confidence'] ?? '' ] );
            }
            foreach ( (array) ( $check['checks']['variety']['details']['findings'] ?? [] ) as $v ) {
                $add( 'low', (string) $v['code'], $item, (string) $v['message'], [ 'try' => $v['try'] ?? [] ] );
            }
            foreach ( SiteConsistency::literalGlobalColors( $item['content'], $globals ) as $g ) {
                $add( 'low', 'USE_GLOBAL_COLOR', $item, sprintf( 'The color %s is written %d time(s) as a plain value but is the site\'s global color %s. Use the global color so a later change reaches this page.', $g['color'], $g['count'], $g['use'] ), [ 'use' => $g['use'] ] );
            }
        }

        $contents = [];
        foreach ( $items as $item ) {
            $contents[ $item['id'] ] = $item['content'];
        }
        foreach ( SiteConsistency::spacingFindings( $contents ) as $s ) {
            $fixes[] = [ 'priority' => 'low', 'code' => $s['code'], 'kind' => 'site', 'id' => 0, 'title' => 'These pages', 'message' => $s['message'], 'detail' => $s['detail'] ];
        }

        usort( $fixes, static fn ( array $a, array $b ): int => [ self::RANK[ $a['priority'] ], $a['id'], $a['code'] ] <=> [ self::RANK[ $b['priority'] ], $b['id'], $b['code'] ] );
        $counts = [ 'high' => 0, 'medium' => 0, 'low' => 0 ];
        foreach ( $fixes as $f ) {
            ++$counts[ $f['priority'] ];
        }

        return [
            'fixes'          => array_slice( $fixes, 0, self::MAX_FIXES ),
            'counts'         => $counts,
            'truncated'      => count( $fixes ) > self::MAX_FIXES,
            'pages_reviewed' => count( $items ),
        ];
    }
}
