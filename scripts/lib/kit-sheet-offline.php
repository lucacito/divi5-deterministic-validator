<?php
/**
 * Dev-only, no site: resolves the kit sheet of scripts/lib/kit-checks.cjs offline, through the same pipeline verify-kits.cjs runs on the dev
 * site (ImageTokens -> KitResolver -> RecipeTokens, then LayoutGate::check), for every shipped kit x every design language (or the pairs
 * named by --kit / --language). Reads the tokenised sheet markup from stdin, writes one JSON list to stdout:
 * [{ kit, language, leftovers: [...], tokenLeft: bool, valid: bool, violations: [codes], markup }]. scripts/test-verify-kits.cjs asserts
 * zero leftovers and a valid gate for every pair and checks the card expectations on the resolved markup (the 20 cards of the sheet:
 * plain cards and panels, the inverted and accent grounds, the button panels with their `on:"panel"` buttons, and the accent middle card) and of
 * the 10 item blocks (the `item` accordions on every band, the boxed and rule toggles on the two light ones: every state colour, frame and radius)
 * and the 2 contact forms (a `form` on the bg band and one on the surface band: the field fill, frame, radius, ink, padding and placeholder, the submit button and the captcha ink)
 * and the 1 image (a `divi/image` on the bg band whose radius token `{{aied:kit:radius-media}}` resolves to the kit's four media corners, its picture to a site-local pack URL).
 *
 * Usage: node -e '...' | php scripts/lib/kit-sheet-offline.php [--kit id] [--language id] [--no-markup]
 */

declare(strict_types=1);

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\ImageTokens;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\StyleKits;

require_once __DIR__ . '/../../tests/bootstrap.php';
foreach ( [ 'ImagePack', 'ImageTokens', 'DesignLanguages', 'RecipeTokens', 'StyleKits', 'KitResolver', 'KitAdapters', 'CodePolicy', 'LayoutGate' ] as $unit ) {
    require_once __DIR__ . '/../../wp-plugin/src/' . $unit . '.php';
}

$opts   = getopt( '', [ 'kit:', 'language:', 'no-markup' ] );
$markup = stream_get_contents( STDIN );
if ( ! is_string( $markup ) || '' === trim( $markup ) ) {
    fwrite( STDERR, "no sheet markup on stdin\n" );
    exit( 2 );
}

$kits      = isset( $opts['kit'] ) ? [ (string) $opts['kit'] ] : StyleKits::ids();
$languages = isset( $opts['language'] ) ? [ (string) $opts['language'] ] : DesignLanguages::ids();
$out       = [];
foreach ( $kits as $kitId ) {
    $kit = StyleKits::get( $kitId );
    foreach ( $languages as $langId ) {
        $lang = DesignLanguages::get( $langId );
        if ( null === $kit || null === $lang || [] !== StyleKits::problems( $kit ) ) {
            $out[] = [ 'kit' => $kitId, 'language' => $langId, 'leftovers' => [ 'unknown kit or language' ], 'tokenLeft' => true, 'valid' => false, 'violations' => [], 'markup' => '' ];
            continue;
        }
        $m = ImageTokens::resolve( $markup, 'https://example.test/assets/images/' );
        $m = RecipeTokens::resolve( KitResolver::resolve( $m, $kit ), $lang );
        $g = LayoutGate::check( '<!-- wp:divi/placeholder -->' . $m . '<!-- /wp:divi/placeholder -->' );
        $out[] = [
            'kit'        => $kitId,
            'language'   => $langId,
            'leftovers'  => KitResolver::leftovers( $m ),
            'tokenLeft'  => str_contains( $m, '{{aied:' ),
            'valid'      => $g->isValid(),
            'violations' => array_map( static fn( $v ): string => (string) $v->toArray()['code'], $g->violations() ),
            'markup'     => isset( $opts['no-markup'] ) ? '' : $m,
        ];
    }
}
echo json_encode( $out, JSON_UNESCAPED_SLASHES );
