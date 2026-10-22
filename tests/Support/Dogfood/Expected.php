<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support\Dogfood;

use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\RecipeTokens;

/**
 * What the REAL kit adapters write for one block, one role and one band (dogfood plan 2026-10-22, Task 2). Nothing is a
 * table kept here: a one-block marker markup is resolved exactly as a recipe is served (`KitResolver::resolve`, then
 * `RecipeTokens::resolve` with the design language), and the attributes of the resolved comment are the expectation.
 * Dev tooling: never shipped in `wp-plugin/`.
 */
final class Expected
{
    /** The band names a marker may carry (KitResolver's ROLE_SURFACES draws from these). */
    public const BANDS = [ 'light', 'surface', 'accent', 'dark', 'panel' ];

    /** @var array<string,?array<string,mixed>> */
    private array $memo = [];

    /**
     * @param array<string,mixed> $kit      a style kit as StyleKits::get() returns it
     * @param array<string,mixed> $language a design language as DesignLanguages::get() returns it
     */
    public function __construct( private readonly array $kit, private readonly array $language )
    {
    }

    /**
     * The resolved attributes KitResolver + RecipeTokens give a single `<!-- wp:<block> {"aied":{"kit":$role,"on":$on[,"ground":$ground]}} /-->`,
     * or null when the resolver leaves the marker (role, band or ground not taken). Memoised.
     *
     * @return array<string,mixed>|null
     */
    public function attrs( string $block, string $role, string $on, ?string $ground = null ): ?array
    {
        $memo = $block . '|' . $role . '|' . $on . '|' . ( $ground ?? '' );
        if ( array_key_exists( $memo, $this->memo ) ) {
            return $this->memo[ $memo ];
        }
        $marker = [ 'kit' => $role, 'on' => $on ];
        if ( null !== $ground ) {
            $marker['ground'] = $ground;
        }
        $markup   = '<!-- wp:' . $block . ' ' . json_encode( [ 'aied' => $marker ], JSON_UNESCAPED_SLASHES ) . ' /-->';
        $resolved = RecipeTokens::resolve( KitResolver::resolve( $markup, $this->kit ), $this->language );
        $attrs    = null;
        if ( [] === KitResolver::leftovers( $resolved ) && 1 === preg_match( '/^<!-- wp:[a-z0-9\/-]+ (\{.*\}) \/-->$/s', $resolved, $m ) ) {
            if ( str_contains( $resolved, '{{aied:' ) ) {
                throw new \RuntimeException( "The resolved expectation of $block/$role on $on still holds a token." );
            }
            $decoded = json_decode( $m[1], true );
            if ( ! is_array( $decoded ) ) {
                throw new \RuntimeException( "The resolved expectation of $block/$role on $on is not JSON." );
            }
            $attrs = $decoded;
        }

        return $this->memo[ $memo ] = $attrs;
    }

    /**
     * The bands the role takes on the block (KitResolver's ROLE_SURFACES, read through attrs(): a band whose marker is left is not taken).
     *
     * @return list<string>
     */
    public function bands( string $block, string $role ): array
    {
        $out = [];
        foreach ( self::BANDS as $band ) {
            if ( null !== $this->attrs( $block, $role, $band ) ) {
                $out[] = $band;
            }
        }

        return $out;
    }
}
