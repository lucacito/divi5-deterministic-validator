<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\JsonLeaves;
use AiEditorDivi5\Pro\Design\PageScan;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

/**
 * Design switch, Task 2: string leaves of block attribute JSON, found by a real tokenizer and rewritten without touching
 * any other byte (spec section 5.3, plan Review Focus 4).
 */
class JsonLeavesTest extends TestCase
{
    /**
     * Every recipe page, raw (tokens and markers) and built under the ten languages with their default kits.
     *
     * @return array<string,string>
     */
    private static function pages(): array
    {
        static $pages = null;
        if ( null === $pages ) {
            $pages = [];
            foreach ( SectionRecipes::names() as $name ) {
                $pages[ $name . ' raw' ] = (string) SectionRecipes::rawMarkup( $name );
                foreach ( DesignLanguages::ids() as $id ) {
                    $pages[ $name . ' @ ' . $id ] = (string) SectionRecipes::recipe( $name, $id );
                }
            }
        }

        return $pages;
    }

    /**
     * The string leaves of a decoded JSON value, the reference traversal.
     *
     * @return list<array{0:string,1:string,2:string}> [path, key, value]
     */
    private static function reference( mixed $node, string $path = '', string $key = '' ): array
    {
        if ( is_string( $node ) ) {
            return [ [ $path, $key, $node ] ];
        }
        $out = [];
        if ( $node instanceof \stdClass ) {
            foreach ( get_object_vars( $node ) as $k => $v ) {
                $out = array_merge( $out, self::reference( $v, $path . '.' . $k, (string) $k ) );
            }
        } elseif ( is_array( $node ) ) {
            foreach ( $node as $v ) {
                $out = array_merge( $out, self::reference( $v, $path . '[]', '' ) );
            }
        }

        return $out;
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private static function triples( array $leaves ): array
    {
        return array_map( static fn ( array $l ): array => [ $l['path'], $l['key'], $l['value'] ], $leaves );
    }

    public function testStringsFindsEveryStringLeaf(): void
    {
        $blocks = 0;
        $leaves = 0;
        $lang   = DesignLanguages::get( 'editorial' );
        $this->assertIsArray( $lang );
        foreach ( self::pages() as $label => $page ) {
            foreach ( PageScan::scan( $page, $lang ) as $i => $b ) {
                if ( null === $b['json'] ) {
                    continue;
                }
                $found = JsonLeaves::strings( $b['json'] );
                $this->assertSame( self::reference( json_decode( $b['json'] ) ), self::triples( $found ), $label . ' block ' . $i );
                foreach ( $found as $l ) {
                    $this->assertSame( $l['value'], json_decode( '"' . substr( $b['json'], $l['offset'], $l['length'] ) . '"' ), 'the span is the raw string' );
                    $this->assertSame( '"', $b['json'][ $l['offset'] - 1 ] );
                    $this->assertSame( '"', $b['json'][ $l['offset'] + $l['length'] ] );
                }
                ++$blocks;
                $leaves += count( $found );
            }
        }
        $this->assertGreaterThan( 15000, $blocks );
        $this->assertGreaterThan( 100000, $leaves );
    }

    public function testUntouchedBytesStayIdentical(): void
    {
        $lang = DesignLanguages::get( 'editorial' );
        $this->assertIsArray( $lang );
        $edited = 0;
        foreach ( self::pages() as $label => $page ) {
            $k = 0;
            foreach ( PageScan::scan( $page, $lang ) as $i => $b ) {
                if ( null === $b['json'] ) {
                    continue;
                }
                $leaves = JsonLeaves::strings( $b['json'] );
                if ( [] === $leaves ) {
                    continue;
                }
                $leaf = $leaves[ $k++ % count( $leaves ) ];
                $raw  = '{{aied:color:edited \"' . $i . '\"}}';
                $json = JsonLeaves::replace( $b['json'], [ $leaf['offset'] => $raw ] );
                $end  = $leaf['offset'] + $leaf['length'];
                $this->assertSame( substr( $b['json'], 0, $leaf['offset'] ), substr( $json, 0, $leaf['offset'] ), $label . ' block ' . $i . ': bytes before' );
                $this->assertSame( substr( $b['json'], $end ), substr( $json, $leaf['offset'] + strlen( $raw ) ), $label . ' block ' . $i . ': bytes after' );

                // The rest of the page, spliced back, is byte-identical too.
                $new = substr( $page, 0, $b['offset'] ) . $json . substr( $page, $b['offset'] + $b['length'] );
                $at  = $b['offset'] + $leaf['offset'];
                $this->assertSame( substr( $page, 0, $at ), substr( $new, 0, $at ) );
                $this->assertSame( substr( $page, $b['offset'] + $end ), substr( $new, $at + strlen( $raw ) ) );

                // Only that leaf changed its decoded value; every other leaf kept its raw bytes.
                $after = JsonLeaves::strings( $json );
                $this->assertCount( count( $leaves ), $after );
                $delta = strlen( $raw ) - $leaf['length'];
                foreach ( $leaves as $j => $l ) {
                    if ( $l['offset'] === $leaf['offset'] ) {
                        $this->assertSame( '{{aied:color:edited "' . $i . '"}}', $after[ $j ]['value'] );
                        continue;
                    }
                    $shift = $l['offset'] > $leaf['offset'] ? $delta : 0;
                    $this->assertSame( $l['offset'] + $shift, $after[ $j ]['offset'] );
                    $this->assertSame( substr( $b['json'], $l['offset'], $l['length'] ), substr( $json, $after[ $j ]['offset'], $after[ $j ]['length'] ) );
                }
                ++$edited;
            }
        }
        $this->assertGreaterThan( 15000, $edited );
    }

    public function testEveryLeafCanBeReplacedAtOnce(): void
    {
        $lang = DesignLanguages::get( 'luxe' );
        $this->assertIsArray( $lang );
        foreach ( [ 'pricing-three-tier', 'contact-form', 'features-bento' ] as $name ) {
            foreach ( PageScan::scan( (string) SectionRecipes::recipe( $name, 'luxe' ), $lang ) as $b ) {
                if ( null === $b['json'] ) {
                    continue;
                }
                $leaves = JsonLeaves::strings( $b['json'] );
                $edits  = [];
                foreach ( array_reverse( $leaves ) as $n => $l ) { // order of $edits must not matter
                    $edits[ $l['offset'] ] = 'v' . $n;
                }
                $json = JsonLeaves::replace( $b['json'], $edits );
                $want = json_decode( $b['json'] );
                $vals = array_column( self::triples( JsonLeaves::strings( $json ) ), 2 );
                $this->assertSame( array_reverse( array_map( static fn ( int $n ): string => 'v' . $n, array_keys( $leaves ) ) ), $vals );
                $this->assertSame( count( self::reference( $want ) ), count( $vals ) );
                $this->assertSame( $b['json'], JsonLeaves::replace( $b['json'], [] ), 'no edit, no change' );
            }
        }
    }

    public function testEscapedStringsAreSpannedExactly(): void
    {
        $json   = '{"a":"say \"hi\"","b":{"c":["x\\\\y","<<>","a\/b","é\n\t","",  "é😀"]},"k\"q":"v","n":[1,-2.5e3,true,false,null,{}],"d":"-->"}';
        $leaves = JsonLeaves::strings( $json );
        $this->assertSame( self::reference( json_decode( $json ) ), self::triples( $leaves ) );
        $this->assertSame( [ 'say "hi"', 'x\\y', '<<>', 'a/b', "\u{e9}\n\t", '', 'é😀', 'v', '-->' ], array_column( self::triples( $leaves ), 2 ) );
        $this->assertSame( [ '.a', '.b.c[]', '.b.c[]', '.b.c[]', '.b.c[]', '.b.c[]', '.b.c[]', '.k"q', '.d' ], array_column( self::triples( $leaves ), 0 ) );
        $this->assertSame( [ 'a', '', '', '', '', '', '', 'k"q', 'd' ], array_column( self::triples( $leaves ), 1 ) );
        $raws = array_map( static fn ( array $l ): string => substr( $json, $l['offset'], $l['length'] ), $leaves );
        $this->assertSame( [ 'say \"hi\"', 'x\\\\y', '<<>', 'a\/b', 'é\n\t', '', 'é😀', 'v', '-->' ], $raws );

        // Replace the escaped ones; the neighbours keep their escapes byte for byte.
        $out = JsonLeaves::replace( $json, [ $leaves[0]['offset'] => '<b>', $leaves[3]['offset'] => 'c\/d' ] );
        $this->assertSame( str_replace( [ 'say \"hi\"', 'a\/b' ], [ '<b>', 'c\/d' ], $json ), $out );
        $this->assertSame( '<b>', json_decode( $out )->a );

        // New content must be the raw content of exactly one string.
        foreach ( [ 'a"b', 'a\\', "a\nb", '\x', '\u12', "\xff", '\ud800' ] as $bad ) {
            try {
                JsonLeaves::replace( $json, [ $leaves[0]['offset'] => $bad ] );
                $this->fail( 'accepted ' . var_export( $bad, true ) );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( 'raw content', $e->getMessage() );
            }
        }
        // An offset that is not a leaf's start (a key, a number, the middle of a string) is refused.
        foreach ( [ 1, 2, $leaves[0]['offset'] + 1, strpos( $json, '-2.5' ) ] as $off ) {
            try {
                JsonLeaves::replace( $json, [ $off => 'x' ] );
                $this->fail( 'accepted offset ' . $off );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( 'string leaf', $e->getMessage() );
            }
        }
    }

    public function testInvalidJsonIsRefused(): void
    {
        $invalid = [
            '', ' ', '[]', '"a"', '1', 'null', '{', '{"a":"b"', '{"a":"b",}', '{"a":}', "{'a':'b'}", '{a:"b"}', '{"a":"b"} x',
            '{"a":"b"}{}', '{"a":tru}', '{"a":01}', '{"a":1.}', '{"a":.5}', '{"a":+1}', '{"a":"\x"}', '{"a":"\u12"}',
            "{\"a\":\"b\nc\"}", "{\"a\":\"\x01\"}", "{\"a\":\"\xff\"}", '{"a":"\ud800"}', '{"a" "b"}', '{"a":["b",]}', '{"a":["b" "c"]}',
            '{"a":"b",,"c":"d"}', str_repeat( '{"a":', 600 ) . '1' . str_repeat( '}', 600 ),
        ];
        foreach ( $invalid as $json ) {
            $this->assertNull( json_decode( $json ) instanceof \stdClass ? 'decodes' : null, 'the reference refuses ' . var_export( $json, true ) );
            $this->assertSame( [], JsonLeaves::strings( $json ), var_export( $json, true ) );
            $this->assertSame( $json, JsonLeaves::replace( $json, [ 6 => 'x' ] ), var_export( $json, true ) );
        }
        foreach ( [ '{}', " {\"a\" : \"b\" }\n", '{"a":[]}', '{"":"x"}', '{"a":1e5,"b":-0.0E-2}' ] as $json ) {
            $this->assertInstanceOf( \stdClass::class, json_decode( $json ) );
            $this->assertSame( self::reference( json_decode( $json ) ), self::triples( JsonLeaves::strings( $json ) ), var_export( $json, true ) );
        }
    }

    public function testObjectEndFindsTheObjectInsideLargerText(): void
    {
        $text = 'x {"a":"} -->","b":[{"c":"}"}]} -->rest';
        $this->assertSame( strpos( $text, ' -->rest' ), JsonLeaves::objectEnd( $text, 2 ) );
        $this->assertSame( strpos( $text, ' -->rest' ), JsonLeaves::objectEnd( $text, 1 ), 'leading whitespace is skipped' );
        $this->assertSame( -1, JsonLeaves::objectEnd( $text, 0 ) );
        $this->assertSame( -1, JsonLeaves::objectEnd( '{"a":', 0 ) );
        $this->assertSame( -1, JsonLeaves::objectEnd( '{"a":1', 0 ) );
        $this->assertSame( -1, JsonLeaves::objectEnd( '', 0 ) );
    }
}
