<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support;

/**
 * The registry of attribute shapes proven by a real builder export or a live render (dev/test-side only).
 * File format: {"divi":"5.15.1","shapes":[{"path":..., "how":"export|probe", "evidence":...}, ...]}.
 */
final class ShapeRegistry
{
    public const DEFAULT_FILE = __DIR__ . '/../../fixtures/shapes/verified-shapes.json';

    /** @var array<string,true> every proven path and every proper prefix of one */
    private array $covered = [];

    /** @var array<string,true> exactly the registered paths */
    private array $leaves = [];

    /** @param list<array{path:string,how:string,evidence:string}> $entries */
    private function __construct( private readonly array $entries )
    {
        foreach ( $entries as $e ) {
            $this->leaves[ $e['path'] ] = true;
            $segments = explode( '.', $e['path'] );
            while ( $segments !== [] ) {
                $this->covered[ implode( '.', $segments ) ] = true;
                array_pop( $segments );
            }
        }
    }

    public static function load( ?string $file = null ): self
    {
        $file = $file ?? self::DEFAULT_FILE;
        $raw  = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
        $data = json_decode( $raw, true );
        $list = is_array( $data ) && is_array( $data['shapes'] ?? null ) ? $data['shapes'] : [];
        $entries = [];
        foreach ( $list as $e ) {
            if ( is_array( $e ) && isset( $e['path'] ) ) {
                $entries[] = [
                    'path'     => (string) $e['path'],
                    'how'      => (string) ( $e['how'] ?? '' ),
                    'evidence' => (string) ( $e['evidence'] ?? '' ),
                ];
            }
        }
        return new self( $entries );
    }

    /** A path is covered when it is a registered path or a proper prefix (a container) of one. */
    public function covers( string $normalizedPath ): bool
    {
        // Content below innerContent is never part of a shape (ShapePaths stops there).
        $cut = strpos( $normalizedPath, '.innerContent.' );
        if ( $cut !== false ) {
            $normalizedPath = substr( $normalizedPath, 0, $cut + strlen( '.innerContent' ) );
        }
        return isset( $this->covered[ $normalizedPath ] );
    }

    /**
     * A path is a registered leaf only when it is exactly a registered path: no prefix (container) coverage, and no
     * trimming below innerContent. The recipe lint uses this (a recipe uses leaves, and a leaf must have its own evidence).
     */
    public function coversLeaf( string $path ): bool
    {
        return isset( $this->leaves[ $path ] );
    }

    /** @return list<array{path:string,how:string,evidence:string}> */
    public function all(): array
    {
        return $this->entries;
    }
}
