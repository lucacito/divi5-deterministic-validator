<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/ContrastCheck.php';
require_once __DIR__ . '/DesignVariety.php';
require_once __DIR__ . '/StyleReferences.php';
require_once __DIR__ . '/DefaultFallbacks.php';

/**
 * The advisory design check behind the `check-design` ability: it runs the plugin's deterministic design checks on any Divi 5
 * layout, whoever wrote it, and returns one answer. It never blocks, changes or saves anything, and it says what it could not
 * check instead of passing it. Each check keeps its own detail format.
 *
 * Checks: contrast (ContrastCheck: only definite failures), style_references (preset and colour ids this site does not have,
 * StyleReferences), variety (repeated skeletons, DesignVariety) and default_fallbacks (DefaultFallbacks: Divi's own default
 * look showing where a design was meant).
 */
final class DesignCheck
{
    /**
     * @param array<string,mixed>|null $known what exists on this site (StyleReferences::knownIds()), or null when it cannot be read
     * @param ?callable(string):?bool $moduleHasPreset whether the site has any preset for a module, for the stock-look checks
     * @return array{checks:array<string,array<string,mixed>>, problem_count:int, not_checked:list<string>, message:string}
     */
    public static function run( string $content, ?array $known = null, ?callable $moduleHasPreset = null ): array
    {
        $contrast = ContrastCheck::check( $content );

        $refs = [ 'status' => 'not_checked', 'reason' => 'This site\'s presets and colors could not be read.' ];
        if ( null !== $known && ( null !== ( $known['presets'] ?? null ) || null !== ( $known['colors'] ?? null ) || null !== ( $known['variables'] ?? null ) ) ) {
            $advice = StyleReferences::advise( $content, $known );
            $refs   = null === $advice ? [ 'status' => 'ok' ] : [ 'status' => 'problems', 'details' => $advice ];
        }

        $variety = DesignVariety::advise( $content );
        $variety = null === $variety
            ? [ 'status' => 'ok', 'note' => 'No repetition found, or the page has fewer than ' . DesignVariety::MIN_SECTIONS . ' sections and is not judged.' ]
            : [ 'status' => 'problems', 'details' => $variety ];

        $defaults = DefaultFallbacks::check( $content, $moduleHasPreset );

        $checks = [
            'contrast'          => [ 'status' => $contrast['status'] ] + $contrast,
            'style_references'  => $refs,
            'variety'           => $variety,
            'default_fallbacks' => $defaults,
        ];
        $problems   = 0;
        $notChecked = [];
        foreach ( $checks as $name => $check ) {
            if ( 'problems' === $check['status'] ) {
                $problems += 'contrast' === $name ? count( (array) $check['problems'] ) : ( 'default_fallbacks' === $name ? count( (array) ( $check['findings'] ?? [] ) ) : 1 );
            } elseif ( 'not_checked' === $check['status'] ) {
                $notChecked[] = $name;
            }
        }

        return [
            'checks'        => $checks,
            'problem_count' => $problems,
            'not_checked'   => $notChecked,
            'message'       => [] === $notChecked
                ? ( 0 === $problems ? 'Nothing found. This is advice only; nothing was changed.' : 'Problems found, each with its detail. This is advice only; nothing was changed or blocked.' )
                : ( 0 === $problems ? 'No problem found in what could be checked. ' : 'Problems found, each with its detail. ' ) . 'Could not check: ' . implode( ', ', $notChecked ) . '. This is advice only; nothing was changed or blocked.',
        ];
    }
}
