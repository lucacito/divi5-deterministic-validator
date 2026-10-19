<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support;

require_once __DIR__ . '/ShapePaths.php';

/**
 * Paths that the path-only shape registry accepts but that are DEAD on one module (dev/test-side only), read from
 * fixtures/shapes/dead-on-module.txt. The registry cannot tell a slider's button group from the Button module's own
 * group, so this per-module list catches what it misses.
 */
final class DeadOnModule
{
    public const DEFAULT_FILE = __DIR__ . '/../../fixtures/shapes/dead-on-module.txt';

    /** An opening divi block comment with attributes, as KitResolver matches it: (name)(json). */
    private const BLOCK = '/<!--\s+wp:(divi\/[a-z0-9-]+)\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\})\s+\/?-->/s';

    /** @param list<array{module:string,prefix:string,evidence:string}> $entries */
    private function __construct( private readonly array $entries )
    {
    }

    public static function load( ?string $file = null ): self
    {
        $entries = [];
        foreach ( (array) @file( $file ?? self::DEFAULT_FILE, FILE_IGNORE_NEW_LINES ) as $line ) {
            $line = trim( (string) $line );
            if ( '' === $line || '#' === $line[0] ) {
                continue;
            }
            $parts = explode( '|', $line, 4 );
            if ( count( $parts ) === 4 ) {
                $entries[] = [ 'module' => $parts[0], 'prefix' => $parts[1], 'evidence' => $parts[2] ];
            }
        }
        return new self( $entries );
    }

    /** @return list<array{module:string,prefix:string,evidence:string}> */
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * "<module>: <path> (dead: <evidence>)" for every attribute path of a listed module's block in $text (markup or
     * Markdown holding markup) that is a dead prefix or lies below one. Unique; blocks in order, each block's paths sorted.
     *
     * @return list<string>
     */
    public function violations( string $text ): array
    {
        if ( false === preg_match_all( self::BLOCK, $text, $all, PREG_SET_ORDER ) ) {
            return [ 'the block scanner failed' ];
        }
        $out = [];
        foreach ( $all as $m ) {
            $attrs = json_decode( $m[2], true );
            if ( ! is_array( $attrs ) ) {
                continue;
            }
            foreach ( ShapePaths::fromBlockAttrs( $attrs ) as $path ) {
                foreach ( $this->entries as $e ) {
                    if ( $e['module'] === $m[1] && ( $path === $e['prefix'] || str_starts_with( $path, $e['prefix'] . '.' ) ) ) {
                        $out[ $m[1] . ': ' . $path . ' (dead: ' . $e['evidence'] . ')' ] = true;
                    }
                }
            }
        }
        return array_keys( $out );
    }
}
