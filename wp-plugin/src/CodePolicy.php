<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Block;
use Divi5Validator\BlockParser;
use Divi5Validator\Violation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The plugin never saves custom code through a layout: no custom CSS fields, no Divi Code modules, no
 * script/style/iframe/object/embed markup, no `javascript:` URLs, no event-handler attributes, no PHP tags.
 * Pure and deterministic like the validator: it only reports violations, it never changes content.
 *
 * Code that is ALREADY in the stored content of the page being edited (for example custom CSS the owner added
 * in Divi's Visual Builder) is the baseline: it is preserved untouched and never counted. Only new or changed
 * code is refused.
 *
 * Two independent scans feed the verdict. The TREE scan walks the parsed blocks and reports exact paths. The RAW
 * backstop scans the whole content as text, unparsed and with nothing stripped, because the block parser (first
 * `-->` ends a delimiter) and WordPress/the browser disagree about comments and delimiters: anything the parser
 * does not see as a block can still be code. A backstop finding is only reported for a code the tree did not
 * already report (the same code seen twice is one problem).
 */
final class CodePolicy
{
    public const E_CUSTOM_CSS  = 'CUSTOM_CSS_NOT_ALLOWED';
    public const E_CODE_MODULE = 'CODE_MODULE_NOT_ALLOWED';
    public const E_SCRIPT      = 'SCRIPT_CONTENT_NOT_ALLOWED';

    private const CODE_MODULES    = [ 'divi/code', 'divi/fullwidth-code' ];
    private const CSS_FIELDS      = [ 'freeForm', 'mainElement' ];
    /** A real event handler name; ordinary words that start with "on" (onion, online, only...) are not one. */
    private const EVENT_ATTR_NAME  = '/^on[a-z]{3,}$/';
    private const NOT_EVENT_NAMES  = [ 'onion', 'onions', 'online', 'only', 'once', 'onto', 'onset', 'ongoing', 'onward', 'onwards', 'onsite', 'onboard', 'onboarding' ];
    /**
     * WordPress core's own block-delimiter grammar, copied verbatim from WP_Block_Parser::next_token() in
     * wp-includes/class-wp-block-parser.php of the WordPress installed in the dev container (version.php says 7.1).
     * It is the independent second parser of the structural guard: what WordPress treats as a block delimiter.
     */
    private const WP_DELIMITER    = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';
    private const URL_KEY         = '/url|link|href|src|action|poster/i';
    private const UNSCANNABLE     = 'unscannable';
    /** Raw-backstop units that have no natural end (an unclosed element or tag) are cut here, so an edit far away cannot change them. */
    private const LONE_UNIT_CAP   = 500;
    /** A scan keeps at most this many findings (memory); more than that is refused as one extra, unmatchable finding. */
    private const MAX_FINDINGS    = 2000;
    /** Work bounds: bigger content is refused without being scanned (the delimiter grammar is quadratic on crafted input). */
    private const MAX_BYTES       = 1000000;
    private const MAX_MARKERS     = 10000;

    /**
     * @return list<Violation>
     */
    public static function violations( string $content, ?string $baseline = null ): array
    {
        // Bound the work before any regex runs: refuse what is too big to check safely.
        $tooLarge = self::tooLargeViolation( $content );
        if ( null !== $tooLarge ) {
            return [ $tooLarge ];
        }

        $now = self::findings( $content );
        if ( [] === $now['tree'] && [] === $now['raw'] && [] === $now['guard'] ) {
            return [];
        }

        $before = [ 'tree' => [], 'raw' => [], 'guard' => [] ];
        // A baseline over the limits is not scanned (that would spend the work on stored content): it counts as an
        // empty baseline, so nothing on it is preserved. That fails closed.
        if ( null !== $baseline && '' !== $baseline && ! self::tooLarge( $baseline ) ) {
            $before = self::findings( $baseline );
        }

        // Markup units have the same fingerprint in both scans, so the same code moved between an attribute and
        // the text between blocks is still the same code; a second copy is caught by the raw family's own count.
        $tree = self::subtract( $now['tree'], array_merge( $before['tree'], $before['raw'] ) );
        $raw  = self::subtract( $now['raw'], $before['raw'] );
        $grd  = self::subtract( $now['guard'], $before['guard'] );

        $reported = [];
        foreach ( $tree as $f ) {
            $reported[ $f['code'] ] = true;
        }
        $out = $tree;
        foreach ( $raw as $f ) {
            if ( ! isset( $reported[ $f['code'] ] ) ) {
                $out[] = $f;
            }
        }
        foreach ( $out as $f ) {
            $reported[ $f['code'] ] = true;
        }
        foreach ( $grd as $f ) {
            if ( ! isset( $reported[ $f['code'] ] ) ) {
                $out[] = $f;
            }
        }

        return array_map( static fn ( array $f ): Violation => new Violation( $f['code'], $f['message'], $f['path'] ), $out );
    }

    /** True when the content is over the size or comment-marker limit (nothing is scanned or validated then). */
    public static function tooLarge( string $content ): bool
    {
        return strlen( $content ) > self::MAX_BYTES || substr_count( $content, '<!--' ) > self::MAX_MARKERS;
    }

    /** The single violation for content over the limits (naming the limit and the actual number), or null. */
    public static function tooLargeViolation( string $content ): ?Violation
    {
        $hit   = [];
        $bytes = strlen( $content );
        if ( $bytes > self::MAX_BYTES ) {
            $hit[] = sprintf( '%s bytes (limit %s)', number_format( $bytes ), number_format( self::MAX_BYTES ) );
        }
        $markers = substr_count( $content, '<!--' );
        if ( $markers > self::MAX_MARKERS ) {
            $hit[] = sprintf( '%s comment markers (limit %s)', number_format( $markers ), number_format( self::MAX_MARKERS ) );
        }
        if ( [] === $hit ) {
            return null;
        }

        return new Violation(
            self::E_SCRIPT,
            sprintf( 'This layout is too large or has too many block comments to check safely: %s. Split it into smaller pages.', implode( ' and ', $hit ) ),
            'post_content'
        );
    }

    /**
     * The findings that are not covered by an identical finding of the baseline (multiset subtraction: a second
     * identical copy is new).
     *
     * @param list<array{code:string, fingerprint:string, path:string, message:string}> $found
     * @param list<array{code:string, fingerprint:string, path:string, message:string}> $baseline
     * @return list<array{code:string, fingerprint:string, path:string, message:string}>
     */
    private static function subtract( array $found, array $baseline ): array
    {
        $allowed = [];
        foreach ( $baseline as $f ) {
            $allowed[ $f['fingerprint'] ] = ( $allowed[ $f['fingerprint'] ] ?? 0 ) + 1;
        }
        $out = [];
        foreach ( $found as $f ) {
            if ( ( $allowed[ $f['fingerprint'] ] ?? 0 ) > 0 ) {
                --$allowed[ $f['fingerprint'] ];
                continue;
            }
            $out[] = $f;
        }

        return $out;
    }

    /**
     * @return array{tree: list<array{code:string, fingerprint:string, path:string, message:string}>, raw: list<array{code:string, fingerprint:string, path:string, message:string}>, guard: list<array{code:string, fingerprint:string, path:string, message:string}>}
     */
    private static function findings( string $content ): array
    {
        $tree   = [];
        $parsed = ( new BlockParser() )->parse( $content );
        $root   = $parsed->root();
        if ( null !== $root ) {
            $root->walk( static function ( Block $block, string $path ) use ( &$tree ): void {
                if ( in_array( $block->name(), self::CODE_MODULES, true ) ) {
                    self::push( $tree, self::finding(
                        self::E_CODE_MODULE,
                        $block->name() . '|' . md5( (string) json_encode( $block->attrs() ) ),
                        $path,
                        sprintf( 'The %s module (raw HTML or JavaScript) is not allowed: this plugin never saves custom code.', $block->name() )
                    ) );
                }
                self::scan( $block->attrs(), $path, '', false, false, $tree );
            } );
        }

        return [ 'tree' => $tree, 'raw' => self::backstop( $content ), 'guard' => self::guard( $content, $root ) ];
    }

    // ---- the tree scan ---------------------------------------------------------------------------

    /**
     * @param list<array{code:string, fingerprint:string, path:string, message:string}> $out
     */
    private static function scan( mixed $value, string $path, string $key, bool $urlish, bool $inCss, array &$out ): void
    {
        if ( is_string( $value ) ) {
            if ( $inCss ) {
                if ( '' !== trim( $value ) ) {
                    self::push( $out, self::finding(
                        self::E_CUSTOM_CSS,
                        'css|' . $key . '|' . md5( $value ),
                        $path,
                        'Custom CSS is not allowed: this plugin never saves custom CSS. Use the module\'s own style settings instead.'
                    ) );
                }

                return;
            }
            foreach ( self::stringUnits( $value, $urlish ) as [ $kind, $unit ] ) {
                self::push( $out, self::unitFinding( $kind, $unit, $path ) );
            }

            return;
        }
        if ( ! is_array( $value ) ) {
            return;
        }

        // A custom HTML attribute entry such as {"name":"onclick","value":"..."}: the name is the field name of its value.
        $attrName = null;
        if ( isset( $value['name'] ) && is_string( $value['name'] ) && array_key_exists( 'value', $value ) ) {
            $attrName = strtolower( trim( $value['name'], " \t\n\r\0\x0B\xC2\xA0" ) ); // also a no-break space
            if ( self::isEventName( $attrName ) ) {
                self::push( $out, self::finding(
                    self::E_SCRIPT,
                    'attr|' . $attrName . '|' . md5( (string) json_encode( $value['value'] ) ),
                    $path,
                    sprintf( 'The event-handler attribute "%s" is not allowed: this plugin never saves custom code.', substr( $attrName, 0, 40 ) )
                ) );
            }
        }

        foreach ( $value as $k => $child ) {
            $css   = $inCss || 'css' === $k || ( is_string( $k ) && in_array( $k, self::CSS_FIELDS, true ) );
            $isUrl = $urlish || ( is_string( $k ) && 1 === preg_match( self::URL_KEY, $k ) )
                || ( null !== $attrName && 'value' === $k && 1 === preg_match( self::URL_KEY, $attrName ) );
            self::scan( $child, $path . '.' . $k, is_string( $k ) ? $k : $key, $isUrl, $css, $out );
        }
    }

    /**
     * Every unit of code in one attribute string: the markup units of the text, plus the value itself when it
     * is a `javascript:` URL.
     *
     * @return list<array{0:string, 1:string}> [ kind, unit ]
     */
    private static function stringUnits( string $value, bool $urlish ): array
    {
        $text = self::normalise( $value );
        if ( null === $text ) {
            return [ [ self::UNSCANNABLE, $value ] ];
        }
        $units = self::textUnits( $text, $value, null );
        if ( self::isUnscannable( $units ) ) {
            return $units;
        }

        // (a) The whole value is a script URL. Tabs, newlines and other control characters inside the scheme are
        // ignored by browsers; plain spaces are only tolerated in a URL-like field (the field or any of its
        // ancestors is called url, link, href, src, action or poster), so prose such as
        // "JavaScript: the good parts" is not mistaken for code.
        $lead = ltrim( $text, "\x00..\x20" );
        if ( $urlish ) {
            $compact = self::compact( $lead );
            if ( null === $compact ) {
                return [ [ self::UNSCANNABLE, $value ] ];
            }
            $isUrl = 1 === preg_match( '/^javascript:/i', $compact );
        } else {
            $strict = preg_replace( '/[\x00-\x1f]+/', '', $lead );
            if ( null === $strict ) {
                return [ [ self::UNSCANNABLE, $value ] ];
            }
            $isUrl = 1 === preg_match( '/^javascript\s*:(?!\s)/i', $strict );
        }
        if ( $isUrl ) {
            $units[] = [ 'jsurl', trim( $text ) ];
        }

        return $units;
    }

    // ---- the structural differential guard ---------------------------------------------------------

    /**
     * Content that the validator's block parser and WordPress read differently is refused at the root, instead
     * of hunting every way code can hide in the difference. WordPress core's own delimiter grammar is run as a
     * second parser; the guard reports (1) comment markup left over once every WordPress delimiter is removed
     * (a stray or malformed comment: browsers end comments at `-->`, `--!>` and `<!-->`, so it can hide or expose
     * anything) and (2) every block (name and attributes) that only one of the two parsers sees. Run on the baseline too, so an identical situation already on the page is kept.
     *
     * @return list<array{code:string, fingerprint:string, path:string, message:string}>
     */
    private static function guard( string $content, ?Block $root ): array
    {
        $unscannable = [ self::unitFinding( self::UNSCANNABLE, $content, 'post_content' ) ];
        $out         = [];

        // An unterminated comment opener can never be part of a valid layout. It is reported here, and the scan
        // below still runs: its diff and stray findings bind to the real content, so a baseline that already holds
        // an unterminated opener cannot excuse a changed block list.
        $openers = substr_count( $content, '<!--' );
        $closers = substr_count( $content, '-->' );
        if ( $openers > $closers ) {
            $at = (int) strrpos( $content, '<!--' );
            self::push( $out, self::finding(
                self::E_SCRIPT,
                sprintf( 'open|%d|%d|%s', $openers, $closers, md5( substr( $content, $at, self::LONE_UNIT_CAP ) ) ),
                'post_content',
                sprintf( 'Block comment markup is malformed or ambiguous (%d comment openers but only %d closers): this plugin never saves content that browsers and WordPress could read differently.', $openers, $closers )
            ) );
        }

        // WordPress's delimiter grammar is applied to the text from each `<!--` to the next one, never to the
        // whole content: a real delimiter never contains `<!--` (block attributes are serialised with `--`
        // escaped), and one regex run per piece keeps the work linear on crafted input.
        $wp    = [];
        $first = strpos( $content, '<!--' );
        $at    = false === $first ? strlen( $content ) : $first;
        $rest  = substr( $content, 0, $at );
        while ( $at < strlen( $content ) ) {
            $next  = strpos( $content, '<!--', $at + 4 );
            $piece = false === $next ? substr( $content, $at ) : substr( $content, $at, $next - $at );
            $at    = false === $next ? strlen( $content ) : $next;
            $r     = preg_match( self::WP_DELIMITER, $piece, $set, PREG_UNMATCHED_AS_NULL );
            if ( false === $r ) {
                return $unscannable;
            }
            if ( 0 === $r ) {
                $rest .= $piece; // not a delimiter: a stray comment
                continue;
            }
            $rest .= substr( $piece, strlen( $set[0] ) );
            if ( null !== $set['closer'] ) {
                continue;
            }
            $name = (string) preg_replace( '~^core/~', '', ( $set['namespace'] ?? 'core/' ) . $set['name'] );
            $wp[] = [ $name, null !== ( $set['attrs'] ?? null ) ? json_decode( (string) $set['attrs'], true ) : [] ];
        }

        $ours = [];
        if ( null !== $root ) {
            $root->walk( static function ( Block $block ) use ( &$ours ): void {
                if ( '__root__' !== $block->name() ) {
                    $ours[] = [ (string) preg_replace( '~^core/~', '', $block->name() ), $block->attrs() ];
                }
            } );
        }
        // Blocks (name + attributes) that only one of the two parsers sees. A block both see is of no interest here:
        // the tree scan covers it. Each unseen block is its own finding, bound to its own content, so an unterminated
        // comment already on the page keeps excusing only the hidden blocks it already hid, never a changed one.
        $seen = [ 'WordPress' => [], 'the validator' => [] ];
        foreach ( [ 'WordPress' => $wp, 'the validator' => $ours ] as $who => $list ) {
            foreach ( $list as $block ) {
                $sig                    = md5( (string) json_encode( $block ) );
                $seen[ $who ][ $sig ]   = [ ( $seen[ $who ][ $sig ][0] ?? 0 ) + 1, $block[0] ];
            }
        }
        foreach ( [ 'WordPress' => 'the validator', 'the validator' => 'WordPress' ] as $who => $other ) {
            foreach ( $seen[ $who ] as $sig => [ $count, $name ] ) {
                $extra = $count - ( $seen[ $other ][ $sig ][0] ?? 0 );
                for ( $i = 0; $i < $extra; $i++ ) {
                    self::push( $out, self::finding(
                        self::E_SCRIPT,
                        'diff|' . $who . '|' . $sig,
                        'post_content',
                        sprintf( 'Block comment markup is malformed or ambiguous (%s reads a %s block that %s does not): this plugin never saves content that browsers and WordPress could read differently.', $who, substr( $name, 0, 40 ), $other )
                    ) );
                }
            }
        }

        if ( false === preg_match_all( '/<!--.*?(?:-->|--!>|\z)|-->|--!>/s', $rest, $s ) ) {
            return $unscannable;
        }
        foreach ( $s[0] as $stray ) {
            $unit = substr( $stray, 0, self::LONE_UNIT_CAP );
            self::push( $out, self::finding(
                self::E_SCRIPT,
                'stray|' . md5( $unit ),
                'post_content',
                sprintf( 'Block comment markup is malformed or ambiguous (stray comment %s): this plugin never saves content that browsers and WordPress could read differently.', substr( trim( $unit ), 0, 40 ) )
            ) );
        }

        return $out;
    }

    private static function isEventName( string $name ): bool
    {
        return 1 === preg_match( self::EVENT_ATTR_NAME, $name ) && ! in_array( $name, self::NOT_EVENT_NAMES, true );
    }

    // ---- the raw backstop ------------------------------------------------------------------------

    /**
     * Scans the whole content as text, whatever the block parser makes of it. The text is the content with its
     * JSON escapes undone (never json_decode: the content need not be valid), then entity-normalised two ways:
     * N1 keeps &lt; &gt; &quot; &#39; &amp; encoded (markup scan, so prose about `<script>` stays prose), N2 is a
     * full decode (so `&lt;!-- wp:divi/code` cannot hide a module).
     *
     * @return list<array{code:string, fingerprint:string, path:string, message:string}>
     */
    private static function backstop( string $content ): array
    {
        $raw = self::jsonUnescape( $content );
        $n1  = null === $raw ? null : self::normalise( $raw );
        $n2  = null === $raw ? null : self::fullDecode( $raw );
        if ( null === $raw || null === $n1 || null === $n2 ) {
            return [ self::unitFinding( self::UNSCANNABLE, $content, 'post_content' ) ];
        }

        $out = [];

        // Code modules, even inside what the parser takes for a comment or an attribute string.
        if ( false === preg_match_all( '/<!--\s*\/?wp:divi\/(?:fullwidth-)?code\b/i', $n2, $m, PREG_OFFSET_CAPTURE ) ) {
            return [ self::unitFinding( self::UNSCANNABLE, $content, 'post_content' ) ];
        }
        foreach ( $m[0] as [ $delimiter, $offset ] ) {
            $unit  = substr( $n2, $offset, 600 );
            $end   = strpos( $unit, '-->', strlen( $delimiter ) );
            $unit  = false === $end ? $unit : substr( $unit, 0, $end + 3 );
            self::push( $out, self::finding(
                self::E_CODE_MODULE,
                'rawcode|' . md5( $unit ),
                'post_content',
                sprintf( 'A Divi Code module (%s) is not allowed: this plugin never saves custom code.', substr( trim( $delimiter ), 0, 40 ) )
            ) );
        }

        // Custom CSS fields, found by their JSON keys. Each unit is the key and its own value (not the text after
        // it), so unchanged CSS cancels against the baseline wherever it sits.
        foreach ( [
            '/"(?:freeForm|mainElement)"\s*:\s*(?:"[^"]{0,2000}"?|[^,}\]]{0,2000})/' => '/"?\s*:\s*"?\s*[^\s",}\]]/',
            '/"css"\s*:\s*\{[^}]{0,200}/'                                        => '/":\s*"\s*[^"\s]/',
        ] as $pattern => $nonBlank ) {
            if ( false === preg_match_all( $pattern, $raw, $m ) ) {
                return [ self::unitFinding( self::UNSCANNABLE, $content, 'post_content' ) ];
            }
            foreach ( $m[0] as $unit ) {
                if ( 1 !== preg_match( $nonBlank, $unit ) ) {
                    continue; // an empty value is not CSS
                }
                if ( str_starts_with( $unit, '"css"' ) ) {
                    // Identify the css object by its string values only, so moving the same CSS to another
                    // breakpoint (desktop -> phone) is not a different unit.
                    $values = preg_match_all( '/":\s*"([^"]*)/', $unit, $v ) ? implode( '|', $v[1] ) : $unit;
                    $unit   = '"css":' . $values;
                }
                self::push( $out, self::finding(
                    self::E_CUSTOM_CSS,
                    'rawcss|' . md5( $unit ),
                    'post_content',
                    sprintf( 'Custom CSS (%s) is not allowed: this plugin never saves custom CSS.', substr( trim( $unit ), 0, 40 ) )
                ) );
            }
        }

        // A script URL or an event-handler attribute entry written as plain JSON.
        if ( false === preg_match_all( '/"[A-Za-z_-]*(?:url|link|href|src|action|poster)[A-Za-z_-]*"\s*:\s*"([^"]{0,300})/i', $n1, $m ) ) {
            return [ self::unitFinding( self::UNSCANNABLE, $content, 'post_content' ) ];
        }
        foreach ( $m[1] as $value ) {
            $compact = self::compact( $value );
            if ( null === $compact ) {
                return [ self::unitFinding( self::UNSCANNABLE, $content, 'post_content' ) ];
            }
            if ( 1 === preg_match( '/^javascript:/i', $compact ) ) {
                self::push( $out, self::unitFinding( 'jsurl', $value, 'post_content' ) );
            }
        }
        if ( false === preg_match_all( '/"name"\s*:\s*"\s*(on[a-z]{3,})\s*"[^}]{0,120}/i', $n1, $m ) ) {
            return [ self::unitFinding( self::UNSCANNABLE, $content, 'post_content' ) ];
        }
        foreach ( $m[0] as $i => $unit ) {
            if ( str_contains( $unit, '"value"' ) && self::isEventName( strtolower( $m[1][ $i ] ) ) ) {
                self::push( $out, self::unitFinding( 'attr', $unit, 'post_content' ) );
            }
        }

        // Markup, everywhere: between blocks, inside comments and inside delimiters.
        foreach ( self::textUnits( $n1, $content, self::LONE_UNIT_CAP ) as [ $kind, $unit ] ) {
            self::push( $out, self::unitFinding( $kind, $unit, 'post_content' ) );
        }

        return $out;
    }

    /** Undoes JSON string escapes textually: \uXXXX, \" \\ \/ \b \f \n \r \t. Null if the regex failed. */
    private static function jsonUnescape( string $text ): ?string
    {
        if ( ! str_contains( $text, '\\' ) ) {
            return $text;
        }

        return preg_replace_callback(
            '/\\\\(?:u([0-9a-fA-F]{4})|(.))/s',
            static function ( array $m ): string {
                if ( isset( $m[2] ) && '' !== $m[2] ) {
                    $map = [ 'b' => "\x08", 'f' => "\x0c", 'n' => "\n", 'r' => "\r", 't' => "\t", '"' => '"', '\\' => '\\', '/' => '/' ];

                    return $map[ $m[2] ] ?? $m[0];
                }

                return self::codepoint( (int) hexdec( $m[1] ) );
            },
            $text
        );
    }

    /** Entities decoded to a fixpoint (at most three rounds). Null if a regex failed. */
    private static function fullDecode( string $text ): ?string
    {
        for ( $i = 0; $i < 3; $i++ ) {
            $next = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            if ( $next === $text ) {
                break;
            }
            $text = $next;
        }

        return $text;
    }

    // ---- shared text scanners --------------------------------------------------------------------

    /**
     * The units of code in a piece of NORMALISED text (an attribute string or the whole raw content): whole
     * script, style, iframe, object and embed elements, PHP tags, script URLs after a URL attribute, and whole
     * opening tags that carry an event handler. A unit is the entire offending text, so changing any part of it
     * is a new finding. Returns a single "unscannable" unit when a regex fails (fail closed).
     *
     * @param ?int $cap cut units that have no natural end (unclosed element, unterminated tag) to this length
     * @return list<array{0:string, 1:string}> [ kind, unit ]
     */
    private static function textUnits( string $text, string $raw, ?int $cap ): array
    {
        $fail  = [ [ self::UNSCANNABLE, $raw ] ];
        $units = [];

        foreach ( [
            'element' => '/<(?:script|style|iframe|object|embed)\b.*?(?:<\/(?:script|style|iframe|object|embed)\s*>|\z)/is',
            'php'     => '/<\?(?:php|=).*?(?:\?>|\z)/is',
        ] as $kind => $pattern ) {
            if ( false === preg_match_all( $pattern, $text, $m ) ) {
                return $fail;
            }
            foreach ( $m[0] as $unit ) {
                $closed = 1 === preg_match( '/(?:<\/[a-z]+\s*>|\?>)\z/i', $unit );
                $units[] = [ $kind, ( null !== $cap && ! $closed ) ? substr( $unit, 0, $cap ) : $unit ];
            }
        }

        // A script URL right after a URL-like attribute (the value may hide the scheme with tabs or newlines).
        if ( false === preg_match_all( '/\b(?:href|src|action|formaction|data|poster|xlink:href)\s*=\s*["\']?([^"\'<>]*)/i', $text, $m ) ) {
            return $fail;
        }
        foreach ( $m[1] as $token ) {
            $compact = self::compact( $token );
            if ( null === $compact ) {
                return $fail;
            }
            if ( 1 === preg_match( '/^javascript:/i', $compact ) ) {
                $units[] = [ 'jsurl', $token ];
            }
        }

        $tags = self::handlerTags( $text, $cap );
        if ( null === $tags ) {
            return $fail;
        }
        foreach ( $tags as $tag ) {
            $units[] = [ 'handler', $tag ];
        }

        return $units;
    }

    /**
     * The opening tags that carry an event-handler attribute (`on…=`), tokenised the way HTML5 does: a quote only
     * opens a value right after `=`, so `<a x=a"b onclick=…>` and `<a x" onclick=…>` still have an onclick
     * attribute, while `title="Book online = save"` has none. Null if a regex failed.
     *
     * @return list<string>|null
     */
    private static function handlerTags( string $text, ?int $cap ): ?array
    {
        $tags = [];
        $pos  = 0;
        $len  = strlen( $text );
        while ( $pos < $len ) {
            $r = preg_match( '/<[a-z]/i', $text, $m, PREG_OFFSET_CAPTURE, $pos );
            if ( false === $r ) {
                return null;
            }
            if ( 0 === $r ) {
                break;
            }
            $start = (int) $m[0][1];
            $i     = $start + 1;
            if ( 1 !== preg_match( '/\G[^\s\/>]*/', $text, $name, 0, $i ) ) {
                return null;
            }
            $i      += strlen( $name[0] );
            $handler = false;
            $closed  = false;
            while ( $i < $len ) {
                $r = preg_match(
                    '/\G[\s\/]*+(?:(>)|((?:=|[^\s\/>=])[^\s\/>=]*+)(?:\s*+(=)\s*+(?:"[^"]*+"?|\'[^\']*+\'?|([^\s>]*+)))?)?/',
                    $text,
                    $a,
                    PREG_UNMATCHED_AS_NULL,
                    $i
                );
                if ( 1 !== $r ) {
                    return null;
                }
                $step = strlen( $a[0] );
                if ( 0 === $step ) {
                    ++$i; // cannot happen; guarantees progress
                    continue;
                }
                $i += $step;
                if ( null !== $a[1] ) {
                    $closed = true;
                    break;
                }
                if ( null !== $a[2] && null !== $a[3] && self::isEventName( strtolower( $a[2] ) ) ) {
                    $handler = true;
                }
                // HTML5 lets an unquoted value swallow `/onerror=…`, which no browser runs; it is refused anyway
                // because it is only ever written to look like a handler.
                if ( null !== $a[4] && 1 === preg_match( '/["\'\/]on[a-z]+\s*=/i', $a[4] ) ) {
                    $handler = true;
                }
            }
            if ( $handler ) {
                $tag    = substr( $text, $start, $i - $start );
                $tags[] = ( null !== $cap && ! $closed ) ? substr( $tag, 0, $cap ) : $tag;
            }
            $pos = max( $i, $start + 1 );
        }

        return $tags;
    }

    /**
     * Decodes HTML entities (up to three rounds, for double encoding) so `&#106;avascript:`, `&#106avascript:`
     * (no semicolon), `&#x0000006a;` and `jav&#x61;script:` are seen as what a browser sees. The structural
     * characters < > " ' stay encoded: an entity can never open a tag or close an attribute, so "&lt;script&gt;"
     * in prose is text, not code. Null if a regex failed.
     */
    private static function normalise( string $text ): ?string
    {
        for ( $i = 0; $i < 3; $i++ ) {
            if ( ! str_contains( $text, '&' ) ) {
                break;
            }
            $next = preg_replace_callback(
                '/&(?:#[xX]([0-9a-fA-F]+);?|#([0-9]+);?|[a-zA-Z][a-zA-Z0-9]{1,31};)/',
                static function ( array $m ): string {
                    if ( isset( $m[1] ) && '' !== $m[1] ) {
                        $digits = ltrim( $m[1], '0' );
                        $cp     = strlen( $digits ) > 6 ? 0x110000 : (int) hexdec( '' === $digits ? '0' : $digits );
                    } elseif ( isset( $m[2] ) && '' !== $m[2] ) {
                        $digits = ltrim( $m[2], '0' );
                        $cp     = strlen( $digits ) > 7 ? 0x110000 : (int) ( '' === $digits ? '0' : $digits );
                    } else {
                        $decoded = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

                        return in_array( $decoded, [ '<', '>', '"', "'" ], true ) ? $m[0] : $decoded;
                    }

                    return in_array( $cp, [ 60, 62, 34, 39 ], true ) ? $m[0] : self::codepoint( $cp );
                },
                $text
            );
            if ( null === $next ) {
                return null;
            }
            if ( $next === $text ) {
                break;
            }
            $text = $next;
        }

        return $text;
    }

    /** One code point as UTF-8; an impossible one (0, beyond Unicode, a surrogate) becomes U+FFFD. */
    private static function codepoint( int $cp ): string
    {
        if ( $cp <= 0 || $cp > 0x10FFFF || ( $cp >= 0xD800 && $cp <= 0xDFFF ) ) {
            return "\u{FFFD}";
        }
        if ( $cp < 0x80 ) {
            return chr( $cp );
        }

        return html_entity_decode( '&#' . $cp . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    /** The text without any ASCII control character or space; null if the regex failed. */
    private static function compact( string $text ): ?string
    {
        return preg_replace( '/[\x00-\x20]+/', '', $text );
    }

    /**
     * Appends a finding, keeping at most MAX_FINDINGS (then one marker that no baseline can cover, so the content
     * is refused rather than silently truncated).
     *
     * @param list<array{code:string, fingerprint:string, path:string, message:string}> $out
     * @param array{code:string, fingerprint:string, path:string, message:string} $finding
     */
    private static function push( array &$out, array $finding ): void
    {
        $n = count( $out );
        if ( $n > self::MAX_FINDINGS ) {
            // Count the overflow in the marker's fingerprint, so one more unit is still a different marker.
            $marker                   = $out[ self::MAX_FINDINGS ];
            $total                    = 1 + (int) substr( $marker['fingerprint'], (int) strrpos( $marker['fingerprint'], '|' ) + 1 );
            $out[ self::MAX_FINDINGS ] = self::finding( self::E_SCRIPT, 'toomany|' . $total, 'post_content', $marker['message'] );

            return;
        }
        if ( self::MAX_FINDINGS === $n ) {
            $finding = self::finding( self::E_SCRIPT, 'toomany|1', 'post_content', 'Too many pieces of code to check: this plugin refuses content it cannot check in full.' );
        }
        $out[] = $finding;
    }

    /** @param list<array{0:string, 1:string}> $units */
    private static function isUnscannable( array $units ): bool
    {
        return 1 === count( $units ) && self::UNSCANNABLE === $units[0][0];
    }

    // ---- findings --------------------------------------------------------------------------------

    /** @return array{code:string, fingerprint:string, path:string, message:string} */
    private static function unitFinding( string $kind, string $unit, string $path ): array
    {
        $message = self::UNSCANNABLE === $kind
            ? 'Script content (content could not be scanned) is not allowed: this plugin refuses what it cannot check.'
            : sprintf( 'Script or embed content (%s) is not allowed: this plugin never saves custom code.', substr( trim( $unit ), 0, 40 ) );

        return self::finding( self::E_SCRIPT, $kind . '|' . md5( $unit ), $path, $message );
    }

    /** @return array{code:string, fingerprint:string, path:string, message:string} */
    private static function finding( string $code, string $fingerprint, string $path, string $message ): array
    {
        return [ 'code' => $code, 'fingerprint' => $code . '|' . $fingerprint, 'path' => $path, 'message' => $message ];
    }
}
