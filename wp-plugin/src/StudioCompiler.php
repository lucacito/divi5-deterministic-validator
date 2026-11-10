<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The Studio compiler: a brief in, valid Divi 5 markup and an honest report out. Each section is a recipe with its
 * slots filled (StudioSlots::fill) and then run through the same serve pipeline every recipe goes through
 * (SectionRecipes::resolveRaw: language, kit, colour and font tokens, images); the sections are joined and the whole
 * page passes LayoutGate. Deterministic and pure: no AI, no network, no site access (an optional callable answers
 * "does this site page exist" so the caller decides how to look it up).
 */
final class StudioCompiler
{
    /**
     * @param mixed $brief anything a request body can decode to; a non-object is a NOT_AN_OBJECT problem
     * @param ?callable(string):bool $pageExists true when a site path points at a page that exists; null skips the check
     * @param string $homeUrl the site's home URL, to recognise an https link to the site itself
     * @return array{ok:bool,markup:?string,problems:list<array{path:string,code:string,message:string}>,violations:list<array<string,mixed>>,unfilled:list<array{section:int,recipe:string,slot:string,role:string}>,refused:list<array{section:int,slot:string,code:string}>,warnings:list<array{section:int,slot:string,code:string,message:string}>,advice:array<string,mixed>}
     */
    public static function compile( mixed $brief, ?callable $pageExists = null, string $homeUrl = '' ): array
    {
        $result = [ 'ok' => false, 'markup' => null, 'problems' => [], 'violations' => [], 'unfilled' => [], 'refused' => [], 'warnings' => [], 'advice' => [] ];
        $check  = StudioBrief::check( $brief );
        if ( ! $check['ok'] || null === $check['brief'] ) {
            $result['problems'] = $check['problems'];

            return $result;
        }
        $clean = $check['brief'];
        $parts = [];
        foreach ( $clean['sections'] as $i => $section ) {
            $raw      = (string) SectionRecipes::rawMarkup( $section['recipe'] );
            $values   = ( $section['slots'] ?? [] ) + ( $section['pictures'] ?? [] );
            $slots    = StudioSlots::extract( $raw );
            $fill     = StudioSlots::fill( $raw, $values );
            $resolved = SectionRecipes::resolveRaw( $fill['markup'], $clean['language'], $clean['kit'] ?? null );
            if ( null === $resolved ) {
                $result['problems'][] = [ 'path' => 'sections.' . $i, 'code' => 'RECIPE_UNRESOLVED', 'message' => 'The recipe "' . $section['recipe'] . '" could not be built with this language and kit.' ];
                continue;
            }
            $parts[] = $resolved;
            $roles   = array_column( $slots, 'role', 'id' );
            foreach ( $fill['unfilled'] as $id ) {
                $result['unfilled'][] = [ 'section' => $i, 'recipe' => $section['recipe'], 'slot' => $id, 'role' => $roles[ $id ] ];
            }
            foreach ( $fill['refused'] as $id => $code ) {
                $result['refused'][] = [ 'section' => $i, 'slot' => (string) $id, 'code' => $code ];
            }
            if ( null !== $pageExists ) {
                foreach ( $slots as $slot ) {
                    $value = $values[ $slot['id'] ] ?? null;
                    if ( 'link' !== $slot['kind'] || ! is_string( $value ) || ! in_array( $slot['id'], $fill['filled'], true ) ) {
                        continue;
                    }
                    $path = StudioLinks::sitePath( $value, $homeUrl );
                    if ( null !== $path && ! $pageExists( $path ) ) {
                        $result['warnings'][] = [ 'section' => $i, 'slot' => $slot['id'], 'code' => 'LINK_PAGE_MISSING', 'message' => 'No page was found at ' . $path . ' on this site.' ];
                    }
                }
            }
        }
        if ( [] !== $result['problems'] ) {
            $result['unfilled'] = $result['refused'] = $result['warnings'] = [];

            return $result;
        }
        $markup = self::serializeAttributes( implode( "\n\n", $parts ) );
        $gate   = LayoutGate::check( $markup );
        $result['markup']     = $markup;
        $result['ok']         = $gate->isValid();
        $result['violations'] = array_map( static fn ( $v ): array => $v->toArray(), $gate->violations() );
        $variety              = DesignVariety::advise( $markup );
        $loops                = LoopAdvisory::advise( $markup );
        if ( null !== $variety ) {
            $result['advice']['design_variety'] = $variety;
        }
        if ( null !== $loops ) {
            $result['advice']['loops'] = $loops;
        }

        return $result;
    }

    /** A block opener and its attribute JSON (the shape WordPress's own block parser reads). */
    private const OPENER = '/(<!--\s+wp:[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*\s+)(\{(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+\})(\s+\/?-->)/s';

    /**
     * Writes every block's attribute JSON as WordPress's serialize_block_attributes() does: "<", ">", "&" and "--" as \u
     * escapes. Many recipes keep raw HTML in their JSON; for a user without unfiltered_html, kses (wp_pre_kses_less_than)
     * turns such a block comment into visible text when the page is saved (found by the live gate, Ruling 13). Only bytes
     * change: a block whose decoded attributes would differ is left as it was.
     */
    private static function serializeAttributes( string $markup ): string
    {
        $out = preg_replace_callback(
            self::OPENER,
            static function ( array $m ): string {
                if ( 1 !== preg_match( '/[<>&]|--/', $m[2] ) ) {
                    return $m[0];
                }
                $json = str_replace( [ '--', '<', '>', '&' ], [ '\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026' ], $m[2] );
                $was  = json_decode( $m[2], true );

                return null !== $was && json_decode( $json, true ) === $was ? $m[1] . $json . $m[3] : $m[0];
            },
            $markup
        );

        return is_string( $out ) ? $out : $markup;
    }
}
