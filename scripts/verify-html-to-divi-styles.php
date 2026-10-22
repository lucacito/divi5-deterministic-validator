<?php
/**
 * Helper of the browser gate scripts/verify-html-to-divi-styles.cjs (Task 11 of the html_to_divi styles plan). Run through WP-CLI in the
 * Docker container by that script, never by hand:
 *
 *   wp eval-file /scripts/verify-html-to-divi-styles.php create <runToken> --user=1   saves every /fixtures/html/14-styled-*.html as a throwaway
 *                                                                                    DRAFT through the REAL HtmlToDiviService::run (create=true,
 *                                                                                    styles=basic), reads the stored content back and prints one
 *                                                                                    line `AIED_JSON:` + the JSON below
 *   wp eval-file /scripts/verify-html-to-divi-styles.php count                       how many pages already carry the title prefix
 *   wp eval-file /scripts/verify-html-to-divi-styles.php delete <id>[,<id>...]       deletes those pages (only when the title starts with the prefix)
 *   wp eval-file /scripts/verify-html-to-divi-styles.php clean [<runToken>]          deletes every page titled `aied html styles ` (or only this run's)
 *
 * `create` prints, per page: { name, id, title, source_html, report_styles, plan_ok, plan_problem, blocks: [...] }. blocks is the stored layout
 * flattened in document order (the placeholder wrapper is skipped): { i, block ("divi/heading" ...), parent (index into blocks, -1 for a section),
 * snippet, written, src }.
 *   snippet  the first 24 visible characters of a module (heading title, text with its tags removed and entities decoded, button label; whitespace
 *            collapsed), or of the first module that has one inside a section, row or column; '' for an image or a divider
 *   written  the block's decoded attributes EXACTLY as stored (the gate reads the style paths out of them; it is not an interpretation)
 *   src      the XPath (DOMNode::getNodePath()) of the SOURCE element of the block, from HtmlMapper::map($body, true) on the same HTML in plan
 *            order (the plan's `from`): a module's first source piece; a section's own element (null for a run of loose nodes); a cards chunk's
 *            row = the parent of the cards; a card's column = the card element; null where the plan records nothing
 *   pieces   how many source pieces a text module merged (src is the first one; the snippet may run on into the next piece)
 * plan_ok is false when the stored layout and the plan disagree (module types, number of sections/rows/columns): the gate fails those pages
 * (hs-mapping) instead of guessing.
 *
 * Dev site only (dev-guard.php). Every page it creates is titled `aied html styles <runToken> <fixture name>`.
 */

use AiEditorDivi5\WP\HtmlMapper;
use AiEditorDivi5\WP\HtmlSource;
use AiEditorDivi5\WP\HtmlToDiviService;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

const AIED_HS_PREFIX = 'aied html styles ';

/** Ids of the pages whose title starts with $prefix (a full scan, never a content search). @return list<int> */
function aied_hs_pages( string $prefix ): array {
    $out = [];
    foreach ( get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $id ) {
        $title = (string) get_the_title( (int) $id );
        // get_the_title() may decorate (private/protected prefixes); the raw title decides.
        $raw = (string) get_post_field( 'post_title', (int) $id );
        if ( 0 === strpos( $raw, $prefix ) || 0 === strpos( $title, $prefix ) ) {
            $out[] = (int) $id;
        }
    }

    return $out;
}

/** Deletes pages by id, but only pages whose raw title starts with the prefix. @param list<int> $ids */
function aied_hs_delete( array $ids ): int {
    $n = 0;
    foreach ( $ids as $id ) {
        if ( $id <= 0 ) {
            continue;
        }
        if ( 0 !== strpos( (string) get_post_field( 'post_title', $id ), AIED_HS_PREFIX ) ) {
            WP_CLI::warning( 'page ' . $id . ' is not a styles-gate page; left alone' );
            continue;
        }
        if ( false !== wp_delete_post( $id, true ) ) {
            ++$n;
        }
    }

    return $n;
}

/** First 24 visible characters of a text: tags removed (not replaced by a space), entities decoded, whitespace collapsed. */
function aied_hs_snippet( string $html ): string {
    $text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

    return mb_substr( $text, 0, 24 );
}

/** The snippet of one module block, '' for an image, a divider or anything without text. */
function aied_hs_module_snippet( string $name, array $attrs ): string {
    switch ( $name ) {
        case 'divi/heading':
            return aied_hs_snippet( (string) ( $attrs['title']['innerContent']['desktop']['value'] ?? '' ) );
        case 'divi/text':
            return aied_hs_snippet( (string) ( $attrs['content']['innerContent']['desktop']['value'] ?? '' ) );
        case 'divi/button':
            return aied_hs_snippet( (string) ( $attrs['button']['innerContent']['desktop']['value']['text'] ?? '' ) );
    }

    return '';
}

/**
 * The plan's expectations in layout order: sections => [ [ 'from' => ?string, 'rows' => [ [ 'from' => ?string, 'cols' => [ ?string, ... ] ], ... ] ] ],
 * and the modules flattened (type + first source path) in the order the layout holds them. Mirrors HtmlToDivi::convert's loops.
 *
 * @param array<string,mixed> $plan HtmlMapper::map($body, true)
 * @return array{sections:list<array<string,mixed>>, modules:list<array{type:string,src:?string}>}
 */
function aied_hs_expect( array $plan ): array {
    $sections = [];
    $modules  = [];
    foreach ( $plan['sections'] as $section ) {
        $rows = [];
        foreach ( $section['chunks'] as $chunk ) {
            if ( 'cards' === ( $chunk['kind'] ?? '' ) ) {
                $keep   = array_keys( array_filter( $chunk['columns'], static fn( array $c ): bool => array() !== $c ) );
                $widths = HtmlMapper::rowWidths( count( $keep ) );
                $at     = 0;
                foreach ( $widths as $width ) {
                    $slice = array_slice( $keep, $at, $width );
                    $at   += $width;
                    $cols  = [];
                    foreach ( $slice as $k ) {
                        $cols[] = $chunk['column_src'][ $k ] ?? null;
                        foreach ( $chunk['columns'][ $k ] as $m ) {
                            $modules[] = [ 'type' => (string) $m['type'], 'src' => $m['from'][0] ?? null, 'pieces' => count( $m['from'] ?? [] ) ];
                        }
                    }
                    $rows[] = [ 'from' => $chunk['from'] ?? null, 'cols' => $cols ];
                }
                continue;
            }
            foreach ( $chunk['modules'] as $m ) {
                $modules[] = [ 'type' => (string) $m['type'], 'src' => $m['from'][0] ?? null, 'pieces' => count( $m['from'] ?? [] ) ];
            }
            $rows[] = [ 'from' => null, 'cols' => [ null ] ];
        }
        $sections[] = [ 'from' => $section['from'] ?? null, 'rows' => $rows ];
    }

    return [ 'sections' => $sections, 'modules' => $modules ];
}

/**
 * Flattens the stored layout (parse_blocks) into the list the gate reads. Returns [ blocks, problem ]; $problem is '' when the layout and the
 * plan agree.
 *
 * @param list<array<string,mixed>> $parsed
 * @param array{sections:list<array<string,mixed>>, modules:list<array{type:string,src:?string}>} $expect
 * @return array{0:list<array<string,mixed>>, 1:string}
 */
function aied_hs_flatten( array $parsed, array $expect ): array {
    $blocks  = [];
    $problem = '';
    $mi      = 0; // next plan module
    $si      = -1; // current section
    $ri      = -1; // current row in the section
    $ci      = -1; // current column in the row

    $walk = static function ( array $nodes, int $parent ) use ( &$walk, &$blocks, &$problem, &$mi, &$si, &$ri, &$ci, $expect ): void {
        foreach ( $nodes as $node ) {
            $name = (string) ( $node['blockName'] ?? '' );
            if ( '' === $name ) {
                continue; // freeform whitespace between blocks
            }
            if ( 'divi/placeholder' === $name ) {
                $walk( $node['innerBlocks'] ?? [], $parent );
                continue;
            }
            $attrs = is_array( $node['attrs'] ?? null ) ? $node['attrs'] : [];
            $src   = null;
            $pieces = 1;
            if ( 'divi/section' === $name ) {
                ++$si;
                $ri = -1;
                $ci = -1;
                if ( ! isset( $expect['sections'][ $si ] ) ) {
                    $problem = $problem ?: 'the layout has more sections than the plan';
                }
                $src = $expect['sections'][ $si ]['from'] ?? null;
            } elseif ( 'divi/row' === $name ) {
                ++$ri;
                $ci = -1;
                if ( ! isset( $expect['sections'][ $si ]['rows'][ $ri ] ) ) {
                    $problem = $problem ?: 'the layout has more rows than the plan (section ' . $si . ')';
                }
                $src = $expect['sections'][ $si ]['rows'][ $ri ]['from'] ?? null;
            } elseif ( 'divi/column' === $name ) {
                ++$ci;
                if ( ! isset( $expect['sections'][ $si ]['rows'][ $ri ]['cols'] ) || ! array_key_exists( $ci, $expect['sections'][ $si ]['rows'][ $ri ]['cols'] ) ) {
                    $problem = $problem ?: 'the layout has more columns than the plan (section ' . $si . ', row ' . $ri . ')';
                }
                $src = $expect['sections'][ $si ]['rows'][ $ri ]['cols'][ $ci ] ?? null;
            } else {
                $want = $expect['modules'][ $mi ] ?? null;
                if ( null === $want || 'divi/' . $want['type'] !== $name ) {
                    $problem = $problem ?: 'module ' . $mi . ' is ' . $name . ' in the layout but ' . ( null === $want ? 'missing' : $want['type'] ) . ' in the plan';
                } else {
                    $src    = $want['src'];
                    $pieces = max( 1, (int) $want['pieces'] );
                }
                ++$mi;
            }
            $idx      = count( $blocks );
            $blocks[] = [
                'i'       => $idx,
                'block'   => $name,
                'parent'  => $parent,
                'snippet' => aied_hs_module_snippet( $name, $attrs ),
                'written' => $attrs,
                'src'     => is_string( $src ) && '' !== $src ? $src : null,
                'pieces'  => $pieces,
            ];
            $walk( $node['innerBlocks'] ?? [], $idx );
        }
    };
    $walk( $parsed, -1 );

    if ( '' === $problem && $mi !== count( $expect['modules'] ) ) {
        $problem = 'the plan has ' . count( $expect['modules'] ) . ' modules, the layout ' . $mi;
    }

    // A section, row or column takes the snippet of the first module inside it that has one.
    foreach ( $blocks as $i => $b ) {
        if ( in_array( $b['block'], [ 'divi/section', 'divi/row', 'divi/column' ], true ) ) {
            continue;
        }
        if ( '' === $b['snippet'] ) {
            continue;
        }
        for ( $p = $b['parent']; $p >= 0; $p = $blocks[ $p ]['parent'] ) {
            if ( '' === $blocks[ $p ]['snippet'] ) {
                $blocks[ $p ]['snippet'] = $b['snippet']; // document order: the first module with a snippet wins
            }
        }
    }

    return [ $blocks, $problem ];
}

$sub = (string) ( $args[0] ?? '' );

if ( 'count' === $sub ) {
    echo 'AIED_JSON:' . wp_json_encode( [ 'count' => count( aied_hs_pages( AIED_HS_PREFIX ) ) ] ) . "\n";

    return;
}

if ( 'delete' === $sub ) {
    $ids = [];
    foreach ( array_slice( $args, 1 ) as $a ) {
        foreach ( explode( ',', (string) $a ) as $part ) {
            if ( 1 === preg_match( '/^\d+$/', trim( $part ) ) ) {
                $ids[] = (int) trim( $part );
            }
        }
    }
    echo 'AIED_JSON:' . wp_json_encode( [ 'deleted' => aied_hs_delete( $ids ) ] ) . "\n";

    return;
}

if ( 'clean' === $sub ) {
    $token  = (string) ( $args[1] ?? '' );
    $prefix = '' === $token ? AIED_HS_PREFIX : AIED_HS_PREFIX . $token . ' ';
    echo 'AIED_JSON:' . wp_json_encode( [ 'deleted' => aied_hs_delete( aied_hs_pages( $prefix ) ) ] ) . "\n";

    return;
}

if ( 'create' !== $sub ) {
    WP_CLI::error( 'Use: create <runToken> | count | delete <ids> | clean [<runToken>]' );
}

$token = (string) ( $args[1] ?? '' );
if ( 1 !== preg_match( '/^[0-9a-z-]{4,40}$/', $token ) ) {
    WP_CLI::error( 'create needs a run token (lower-case letters, digits and dashes).' );
}
if ( ! class_exists( HtmlToDiviService::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (this branch) on this site first.' );
}
$files = glob( '/fixtures/html/14-styled-*.html' );
if ( ! $files ) {
    WP_CLI::error( 'The fixtures are not mounted at /fixtures/html in the wpcli container (see docker-compose.yml).' );
}
sort( $files );

$pages = [];
foreach ( $files as $file ) {
    $name  = basename( $file, '.html' );
    $html  = (string) file_get_contents( $file );
    $title = AIED_HS_PREFIX . $token . ' ' . $name;
    $res   = HtmlToDiviService::run( [ 'html' => $html, 'create' => true, 'title' => $title, 'styles' => 'basic' ] );
    $body  = is_array( $res['body'] ?? null ) ? $res['body'] : [];
    $id    = (int) ( $body['page']['id'] ?? 0 );
    if ( 200 !== ( $res['status'] ?? 0 ) || $id <= 0 ) {
        // Whatever was created before the failure must not stay: remove this run's pages, then stop with the reason.
        aied_hs_delete( aied_hs_pages( AIED_HS_PREFIX . $token . ' ' ) );
        WP_CLI::error( $name . ': the converter did not save a page (status ' . ( $res['status'] ?? '?' ) . '): ' . wp_json_encode( $body['error'] ?? $body['message'] ?? $body ) );
    }

    $post   = get_post( $id );
    $source = HtmlSource::parse( $html );
    $plan   = [ 'sections' => [] ];
    if ( null === $source['error'] && $source['body'] instanceof DOMElement ) {
        $plan = HtmlMapper::map( $source['body'], true );
    }
    $expect                   = aied_hs_expect( $plan );
    [ $blocks, $planProblem ] = aied_hs_flatten( parse_blocks( (string) $post->post_content ), $expect );

    $pages[] = [
        'name'          => $name,
        'id'            => $id,
        'title'         => $title,
        'source_html'   => $html,
        'report_styles' => $body['report']['styles'] ?? null,
        'plan_ok'       => '' === $planProblem,
        'plan_problem'  => $planProblem,
        'blocks'        => $blocks,
    ];
}

echo 'AIED_JSON:' . wp_json_encode( [ 'pages' => $pages ] ) . "\n";
