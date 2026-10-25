<?php
/**
 * Dev-only, no site: resolves the type sheet of scripts/lib/language-type-checks.cjs offline, through the pipeline the plugin serves a recipe with
 * (SectionRecipes::resolveMarkup(): the language's markers, then the kit's, then colour and font tokens), with the language and ITS OWN kit,
 * then LayoutGate::check, for every design language (or the one named by --language). Reads the tokenised sheet markup from stdin, writes one
 * JSON list to stdout:
 * [{ language, kit, leftovers: [...], tokenLeft: bool, valid: bool, violations: [codes], markup }].
 * scripts/test-verify-language-type.cjs asserts zero leftovers and a valid gate for every language and reads the expectations from the markup;
 * scripts/test-language-type-run.cjs builds its synthetic pages from it. A sheet that resolveMarkup() refuses (a marker the language or the kit
 * cannot resolve) is reported with its leftovers and an empty markup, never partially resolved.
 *
 * Usage: node -e '...' | php scripts/lib/language-type-sheet.php [--language id] [--no-markup]
 */

declare(strict_types=1);

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\ImageTokens;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LanguageResolver;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;

require_once __DIR__ . '/../../tests/bootstrap.php';
foreach ( [ 'ImagePack', 'ImageTokens', 'DesignLanguages', 'RecipeTokens', 'StyleKits', 'BlockAttrs', 'KitResolver', 'KitAdapters', 'LanguageAdapters', 'LanguageResolver', 'SectionRecipes', 'CodePolicy', 'LayoutGate' ] as $unit ) {
    require_once __DIR__ . '/../../wp-plugin/src/' . $unit . '.php';
}

$opts   = getopt( '', [ 'language:', 'no-markup' ] );
$markup = stream_get_contents( STDIN );
if ( ! is_string( $markup ) || '' === trim( $markup ) ) {
    fwrite( STDERR, "no sheet markup on stdin\n" );
    exit( 2 );
}

// Built-in image tokens (the rhythm sheet's photo band) resolve to site-local URLs first, as SectionRecipes::recipe serves a recipe (ImageTokens -> language -> kit -> colour/font).
$markup    = ImageTokens::resolve( $markup, 'https://example.test/assets/images/' );

$languages = isset( $opts['language'] ) ? [ (string) $opts['language'] ] : DesignLanguages::ids();
$out       = [];
foreach ( $languages as $langId ) {
    $lang  = DesignLanguages::get( $langId );
    $kitId = is_array( $lang ) && is_string( $lang['kit'] ?? null ) ? $lang['kit'] : null;
    $kit   = null === $kitId ? null : StyleKits::get( $kitId );
    if ( null === $lang || null === $kit || [] !== StyleKits::problems( $kit ) ) {
        $out[] = [ 'language' => $langId, 'kit' => $kitId, 'leftovers' => [ 'unknown language, or its kit is unknown or invalid' ], 'tokenLeft' => true, 'valid' => false, 'violations' => [], 'markup' => '' ];
        continue;
    }
    $m = SectionRecipes::resolveMarkup( $markup, $lang, $kit );
    if ( null === $m ) {
        // Refused: say why (what each step leaves), and hand out no markup.
        $step = LanguageResolver::resolve( $markup, $lang );
        $left = array_merge( LanguageResolver::leftovers( $step ), KitResolver::leftovers( RecipeTokens::resolve( KitResolver::resolve( $step, $kit ), $lang ) ) );
        $out[] = [ 'language' => $langId, 'kit' => $kitId, 'leftovers' => [] === $left ? [ 'resolveMarkup refused the sheet' ] : array_values( array_unique( $left ) ), 'tokenLeft' => true, 'valid' => false, 'violations' => [], 'markup' => '' ];
        continue;
    }
    $g = LayoutGate::check( '<!-- wp:divi/placeholder -->' . $m . '<!-- /wp:divi/placeholder -->' );
    $out[] = [
        'language'   => $langId,
        'kit'        => $kitId,
        'leftovers'  => KitResolver::leftovers( $m ),
        'tokenLeft'  => str_contains( $m, '{{aied:' ),
        'valid'      => $g->isValid(),
        'violations' => array_map( static fn( $v ): string => (string) $v->toArray()['code'], $g->violations() ),
        'markup'     => isset( $opts['no-markup'] ) ? '' : $m,
    ];
}
echo json_encode( $out, JSON_UNESCAPED_SLASHES );
