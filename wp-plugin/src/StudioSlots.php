<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The fill-in slots of a section recipe: every place where a recipe carries copy, a link or a picture that a Studio
 * brief may replace. Slots are derived from the recipe markup itself (no second data file), so a recipe edit cannot
 * leave a stale table; tests/golden/studio-slots.json pins the table and StudioSlotsTest::testCoverage fails when a
 * recipe carries copy in a place no slot reads. Pure: no site access.
 *
 * A slot id is `<block>.<index>[.<field>[.<k>]]`: `index` is the order among ALL blocks of that name in the recipe (the
 * numbering the other recipe audits use), `field` names the part (title, number, text, link, body ...), and `k` is the
 * 0-based order of a paragraph or heading inside an HTML field. Headings are `heading.<index>`, pictures `image.<index>`
 * (with `.alt`), `testimonial.<index>.portrait` and `<block>.<index>.background`.
 */
final class StudioSlots
{
    /** Attribute path of a heading's level, which decides its role. */
    private const HEADING_LEVEL = 'title.decoration.font.font.desktop.value.headingLevel';

    private const HEADING_TEXT = 'title.innerContent.desktop.value';

    private const HTML_BODY = 'content.innerContent.desktop.value';

    private const BACKGROUND = 'module.decoration.background.desktop.value.image.url';

    /** The `element` value of an HTML slot that is the whole field (loose text with no paragraph or heading element). */
    public const WHOLE = -1;

    /** Blocks whose `content.innerContent` is an HTML field (one slot per paragraph or heading). */
    private const HTML_BLOCKS = [ 'text', 'blurb', 'accordion-item', 'toggle', 'slide', 'testimonial' ];

    /** Plain-text fields per block: [field, attribute path, role]. */
    private const PLAIN = [
        'accordion-item' => [ [ 'title', 'title.innerContent.desktop.value', 'item_title' ] ],
        'toggle'         => [ [ 'title', 'title.innerContent.desktop.value', 'item_title' ] ],
        'slide'          => [ [ 'title', 'title.innerContent.desktop.value', 'item_title' ] ],
        'number-counter' => [ [ 'title', 'title.innerContent.desktop.value', 'item_title' ], [ 'number', 'number.innerContent.desktop.value', 'figure' ] ],
        'circle-counter' => [ [ 'title', 'title.innerContent.desktop.value', 'item_title' ], [ 'number', 'number.innerContent.desktop.value', 'figure' ] ],
        'contact-field'  => [ [ 'label', 'fieldItem.innerContent.desktop.value', 'field_label' ] ],
        'blurb'          => [ [ 'title', 'title.innerContent.desktop.value.text', 'item_title' ] ],
        'testimonial'    => [
            [ 'author', 'author.innerContent.desktop.value', 'attribution' ],
            [ 'job', 'jobTitle.innerContent.desktop.value', 'attribution' ],
            [ 'company', 'company.innerContent.desktop.value.text', 'attribution' ],
        ],
        'button'         => [ [ 'text', 'button.innerContent.desktop.value.text', 'button_label' ] ],
        'contact-form'   => [ [ 'button', 'button.innerContent.desktop.value.text', 'button_label' ] ],
    ];

    /**
     * Values under an `innerContent` key that are not copy, by path suffix, each with its reason. The coverage test
     * accepts only these and the slots; anything else it finds is a recipe change that needs a slot or a reason.
     *
     * @var array<string,string>
     */
    public const EXEMPT_PATHS = [
        'innerContent.desktop.value.unicode'            => 'An icon glyph code, not copy.',
        'innerContent.desktop.value.type'               => 'An icon font family name, not copy.',
        'innerContent.desktop.value.weight'             => 'An icon font weight, not copy.',
        'innerContent.desktop.value.useIcon'            => 'A switch (on or off), not copy.',
        'innerContent.desktop.value.icon.unicode'       => 'A blurb icon glyph code, not copy.',
        'innerContent.desktop.value.icon.type'          => 'A blurb icon font family name, not copy.',
        'innerContent.desktop.value.icon.weight'        => 'A blurb icon font weight, not copy.',
        'socialNetwork.innerContent.desktop.value.title' => 'A social network id, not copy.',
        'socialNetwork.innerContent.desktop.value.label' => 'A social network id, not copy.',
    ];

    /** @var array<string,list<string>> recipe name => its slot ids (memo for recipesWithSlot) */
    private static array $idsByRecipe = [];

    /**
     * @return list<array{id:string,block:string,index:int,kind:string,role:string,sample:string,rich:bool,path:string,element:?int}>
     *   `path` is the dot-separated attribute path inside the block; `element` is the k of an HTML slot (WHOLE for loose text), else null.
     */
    public static function extract( string $rawMarkup ): array
    {
        $slots  = [];
        $counts = [];
        if ( false === preg_match_all( BlockAttrs::BLOCK, $rawMarkup, $blocks, PREG_SET_ORDER ) ) {
            return [];
        }
        foreach ( $blocks as $block ) {
            $name  = $block[2];
            $short = substr( $name, strlen( 'divi/' ) );
            $i     = $counts[ $name ] = ( $counts[ $name ] ?? -1 ) + 1;
            $attrs = BlockAttrs::decode( $block[3] );
            if ( null === $attrs ) {
                continue;
            }
            $add = static function ( string $id, string $kind, string $role, string $sample, bool $rich, string $path, ?int $element = null ) use ( &$slots, $name, $i ): void {
                $slots[] = [ 'id' => $id, 'block' => $name, 'index' => $i, 'kind' => $kind, 'role' => $role, 'sample' => $sample, 'rich' => $rich, 'path' => $path, 'element' => $element ];
            };
            $base = $short . '.' . $i;

            $bg = self::get( $attrs, self::BACKGROUND );
            if ( is_string( $bg ) && str_contains( $bg, '{{aied:image:' ) ) {
                $add( $base . '.background', 'picture', 'picture', $bg, false, self::BACKGROUND );
            }
            if ( 'heading' === $short ) {
                $text = self::get( $attrs, self::HEADING_TEXT );
                if ( is_string( $text ) && ! self::isDynamic( $text ) ) {
                    $add( 'heading.' . $i, 'text', self::headingRole( self::get( $attrs, self::HEADING_LEVEL ) ), $text, false, self::HEADING_TEXT );
                }
            }
            foreach ( self::PLAIN[ $short ] ?? [] as [ $field, $path, $role ] ) {
                $text = self::get( $attrs, $path );
                if ( is_string( $text ) && ! self::isDynamic( $text ) ) {
                    $add( $base . '.' . $field, 'text', $role, $text, false, $path );
                }
            }
            if ( 'button' === $short && self::get( $attrs, 'button.innerContent.desktop.value' ) instanceof \stdClass ) {
                // Every button has a link slot. Most recipe buttons carry no `linkUrl` yet; the real exports write it
                // beside `text` (`"text":"Click Here","linkUrl":"#"`), so filling adds the key. A loop's own link stays the loop's.
                $link = self::get( $attrs, 'button.innerContent.desktop.value.linkUrl' );
                if ( null === $link || ( is_string( $link ) && ! self::isDynamic( $link ) ) ) {
                    $add( $base . '.link', 'link', 'link', (string) $link, false, 'button.innerContent.desktop.value.linkUrl' );
                }
            }
            if ( in_array( $short, self::HTML_BLOCKS, true ) ) {
                $html = self::get( $attrs, self::HTML_BODY );
                if ( is_string( $html ) && ! self::isDynamic( $html ) ) {
                    $els = self::elements( $html );
                    foreach ( $els as $k => $el ) {
                        $add( $base . '.body.' . $k, 'text', self::elementRole( $el['tag'] ), $el['text'], $el['rich'], self::HTML_BODY, $k );
                    }
                    // Loose text with no paragraph or heading element is one slot for the whole field.
                    if ( [] === $els && '' !== self::plain( $html ) ) {
                        $add( $base . '.body.0', 'text', 'body', self::plain( $html ), 1 === preg_match( '/<[a-z]/i', $html ), self::HTML_BODY, self::WHOLE );
                    }
                }
            }
            if ( 'image' === $short ) {
                self::picture( $attrs, $add, 'image.' . $i, 'image.innerContent.desktop.value.src', 'image.innerContent.desktop.value.alt' );
            }
            if ( 'before-after-image' === $short ) {
                self::picture( $attrs, $add, $base . '.before', 'beforeImage.innerContent.desktop.value.src', 'beforeImage.innerContent.desktop.value.alt' );
                self::picture( $attrs, $add, $base . '.after', 'afterImage.innerContent.desktop.value.src', 'afterImage.innerContent.desktop.value.alt' );
            }
            if ( 'testimonial' === $short ) {
                self::picture( $attrs, $add, $base . '.portrait', 'portrait.innerContent.desktop.value.url', 'portrait.innerContent.desktop.value.alt' );
            }
        }

        return $slots;
    }

    /** @return list<string> names of the recipes (library order) that have a slot with this id */
    public static function recipesWithSlot( string $slotId ): array
    {
        $out = [];
        foreach ( SectionRecipes::names() as $name ) {
            if ( ! isset( self::$idsByRecipe[ $name ] ) ) {
                self::$idsByRecipe[ $name ] = array_column( self::extract( (string) SectionRecipes::rawMarkup( $name ) ), 'id' );
            }
            if ( in_array( $slotId, self::$idsByRecipe[ $name ], true ) ) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * The headings and paragraphs of an HTML field in document order (empty and &nbsp;-only ones skipped).
     *
     * @return list<array{tag:string,text:string,rich:bool}>
     */
    public static function elements( string $html ): array
    {
        $out = [];
        if ( false === preg_match_all( '~<(h[1-6]|p)(\s[^>]*)?>(.*?)</\1\s*>~is', $html, $m, PREG_SET_ORDER ) ) {
            return [];
        }
        foreach ( $m as $el ) {
            $text = self::plain( $el[3] );
            if ( '' === $text ) {
                continue;
            }
            $out[] = [ 'tag' => strtolower( $el[1] ), 'text' => $text, 'rich' => 1 === preg_match( '/<[a-z]/i', $el[3] ) ];
        }

        return $out;
    }

    /** Inner HTML as one line of plain text (tags dropped, entities decoded, spaces and non-breaking spaces trimmed). */
    private static function plain( string $inner ): string
    {
        $text = html_entity_decode( strip_tags( $inner ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;

        return preg_replace( '/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $text ) ?? $text;
    }

    private static function headingRole( mixed $level ): string
    {
        return match ( $level ) {
            'h3' => 'subhead',
            'h4', 'h5', 'h6' => 'eyebrow',
            default => 'headline',
        };
    }

    private static function elementRole( string $tag ): string
    {
        return match ( $tag ) {
            'h1', 'h2' => 'headline',
            'h3' => 'subhead',
            'h4', 'h5', 'h6' => 'eyebrow',
            default => 'body',
        };
    }

    /** @param callable(string,string,string,string,bool,string,?int=):void $add */
    private static function picture( \stdClass $attrs, callable $add, string $id, string $srcPath, string $altPath ): void
    {
        $src = self::get( $attrs, $srcPath );
        if ( is_string( $src ) ) {
            $add( $id, 'picture', 'picture', $src, false, $srcPath );
        }
        $alt = self::get( $attrs, $altPath );
        if ( is_string( $alt ) ) {
            $add( $id . '.alt', 'alt', 'alt', $alt, false, $altPath );
        }
    }

    /** A Divi dynamic-content token (a loop's own title, link, price ...): owned by the loop, never a slot. */
    public static function isDynamic( string $value ): bool
    {
        return str_starts_with( ltrim( $value ), '$variable(' );
    }

    /** The value at a dot-separated path inside decoded block attributes, or null. */
    public static function get( \stdClass $attrs, string $path ): mixed
    {
        $node = $attrs;
        foreach ( explode( '.', $path ) as $key ) {
            if ( ! $node instanceof \stdClass || ! property_exists( $node, $key ) ) {
                return null;
            }
            $node = $node->{$key};
        }

        return $node;
    }
}
