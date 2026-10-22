<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The cascade and inheritance for html_to_divi (styles=basic): one computed record per element of the cleaned DOM (html, body and every
 * element of the body), from the <style> text, the inline style="" texts and the presentational attributes HtmlSource hands over. Pure,
 * deterministic, no clock, no randomness, no network.
 *
 * SOUNDNESS. A property is `set` (or `initial` with a value) only when a browser at desktop width computes exactly that value for the
 * element. Whenever something this reader cannot evaluate might decide the value, the property is `unresolved` (and an inherited one stays
 * unresolved below, until an element's own declaration decides it). A wrong value is worse than none.
 *
 * THE CASCADE (per element and property). Candidates:
 * - the declarations of every rule of HtmlCss::stylesheet() whose subject matches the element (tag lowercase, null = any; ids and classes
 *   subsets of the element's, case-sensitive; `other` and combinators ignored, so a match means "could match"). A rule with
 *   poison_only = false APPLIES; a poison_only rule (compound or combinator selector, inside @media/@supports/@layer..., nested) is a
 *   POISON candidate whatever its value says;
 * - in a document that is not known to be in standards mode (no <!DOCTYPE html>), a rule whose subject matches only ASCII
 *   case-insensitively (quirks mode matches class and id selectors that way) is a POISON candidate (reason selector);
 * - the rules of the <style media="..."> blocks that apply to some screens only (HtmlSource css_media): POISON candidates of the sentinel
 *   rank (reason media);
 * - the inline declarations (HtmlCss::declarations of the style="" text);
 * - the presentational attributes (HTML hints, below every author rule): bgcolor and background -> background poison (background also an
 *   image), color and text -> color poison, face -> family poison, size -> size poison, align -> align poison (reason presentational);
 * - <center>: text-align center at the lowest priority (a UA rule). Chromium computes `-webkit-center` there (it also centres block
 *   children); for the text it renders the same as `center`, which is the value claimed (the differential harness normalises the two
 *   explicitly).
 * Rank, highest wins: importance; then tier (a rule of specificity HtmlCss::MAX_SPECIFICITY, compared lexicographically and never added
 * to, outranks every candidate of the same importance, inline ones included; then inline; then rules; then presentational hints; then the
 * UA <center> rule); then specificity; then order; then the declaration's position. A poison winner makes the property unresolved; there
 * is never a fall-back to a lower candidate. A winning `inherit` takes the parent's computed value.
 *
 * MARKERS (HtmlCss `effect` and `background-image`) are presence-only: importance, specificity and order are irrelevant and every
 * candidate rule counts (poison-only rules included). `effect` (opacity, filter, blend, text fill, color-scheme, animation, mask, text
 * shadow ...) on an element makes the colour of the element AND ITS SUBTREE unresolved and their background unknown (reason effect);
 * rules on html, :root and body count, since html and body are ancestors of every converted element. A <meta name="color-scheme">
 * that allows dark (HtmlSource color_scheme) is an effect on html: the default canvas and the initial text colour are the visitor's.
 * `background-image` makes the element's own background unknown (image_or_gradient); its descendants with a transparent background
 * have state none, so a reader that walks up for the backdrop (HtmlStyleBlocks) meets the unknown one: the backdrop is unresolved.
 *
 * INHERITANCE. color, family, size, weight and align inherit; background, padding, max width and radius do not. An element takes the
 * parent's computed value when no candidate exists, EXCEPT where the UA sheet sets the property on the element itself (OWN_ONLY, UA_OWN,
 * and in a page that may be in quirks mode QUIRKS_OWN): those are `initial` with value null ("the UA sheet decides; nothing to carry";
 * NOT #000000 or 16px), and what an element inherits from such a UA value is unresolved (reason unresolved). The UA background of
 * button and mark is unknown (reason unresolved).
 * Initial values (nothing declared up to html): color #000000, size 16.0, weight 400, family null (the browser's default font), align
 * null (start: its side depends on the text direction, so it carries nothing; a declared left, right, center or justify is absolute and
 * does not depend on dir, <bdo> or direction, which is why those need no input here).
 *
 * SIZES. px as is; em and % of the parent's computed size (unresolved or UA-decided parent -> unresolved); rem of the root (html) size,
 * which is itself resolved (62.5% -> 10px; unresolved -> rem unresolved). Chromium's monospace keyword quirk: when the family is exactly
 * the one generic `monospace` (pre, code, kbd, samp, tt by the UA sheet, or declared) and the size derives from the default keyword
 * (nothing, em or % up the chain; px and rem do not), the browser uses 13px: the size is then unresolved, also when the family is
 * unresolved (it may be monospace). Clamps are NOT applied here (HtmlStyleBlocks applies and counts them).
 *
 * BOX VALUES (own only). padding sides, max width and radius corners: px; em of the element's own computed size; rem of the root; an
 * integer (round half up); 'unresolved' when a poison wins or the base is unknown; null when nothing is declared (the UA padding of a
 * list or a cell is not described: null means "no author value", never 0). A radius is the four corners [tl, tr, br, bl] when any corner
 * is declared (an undeclared corner is 0), 'unresolved' when any corner is.
 *
 * THE TREE A BROWSER BUILDS. libxml2 (HTML 4 rules) builds a slightly different tree than a browser, and inheritance follows the tree:
 * - a <tr> (or a <td>/<th>) directly in a table inherits through the tbody (and tr) the browser inserts: their rules are applied;
 * - an element a browser fosters out of a table (anything but caption, colgroup, col, thead, tbody, tfoot, tr in a table; tr in a row
 *   group; td, th in a row) inherits from the table's parent;
 * - an HTML5 block (section, article, figure, main, header, footer, nav, aside, details ...) or another closer of an open <p> inside a
 *   <p> (button scope), and a heading directly inside a heading, close that element in a browser: the element and everything after it
 *   inside the closed one have an unknown parent (their inherited properties are unresolved; their own declarations still decide); when
 *   a formatting element (b, em, a, font ...) was open in between, the browser clones it into the block, so its children's parent is
 *   unknown too;
 * - in a page that may be in quirks mode, a <table> right after a <p> may sit inside that paragraph in the browser: unknown parent;
 * - a table part (td, tr, tbody, caption ...) outside any table is ignored by a browser: its record is all unresolved (the element
 *   does not exist) and its children inherit from its parent; inside a table but in a cell's content (not directly in a table, row
 *   group, row or colgroup), the browser closes the cell and starts a new part: its record is all unresolved, and its content and what
 *   follows it in the closed cell have an unknown parent.
 * Other parse differences (misnested formatting end tags) are not modelled; they change where formatting applies, not the computed
 * records of the elements both trees have.
 *
 * MATCHING. An id attribute is ONE token (never split); class splits on ASCII whitespace only. The DOM keeps 300 characters of a class or
 * id (phase 1): the whole value comes from HtmlSource cut_attrs.
 *
 * SCALE. The HtmlCss marker `scale` (zoom other than 1, the scale property, a transform that may scale) on the element or an ancestor:
 * text and boxes render at another size than computed, so size, padding, max width and radius are unresolved there (reason effect);
 * colours are unaffected.
 *
 * FAIL CLOSED. When HtmlSource reports `repaired` (a browser would repair the markup in a way libxml2 does not), the tree walked here is
 * not the browser's: EVERY element is unresolved for every property, and the result carries `repaired` (repairedNote() is the report
 * sentence). Rules of the same subject are merged once (best-ranked declaration per property, markers or'ed: the same cascade, proven by
 * a randomized test); past WORK_BUDGET candidate rules the rest of the page is unresolved and `work_cap` is true.
 *
 * NOT READ. External sheets (<link rel=stylesheet>, counted `external` like @import) and CSS beyond the caps (`css_cap`) mean the result is
 * relative to the text it was given: `unread` is true then, so the report can warn. No raw CSS text is ever returned: records hold states,
 * normalised values (#rrggbb, numbers, alignment keywords, family names HtmlCss validated) and reasons from HtmlCss::REASONS.
 *
 * CONTRACT FOR TASKS 8 AND 9.
 * - T9 calls resolve( $s['body'], $s['css'], $s['inline'], $s['presentational'], HtmlStyles::document( $s ) ) with the whole HtmlSource
 *   output ($document is required); `repaired`, `unread` and `work_cap` mean the report must warn (repairedNote() for repaired).
 * - T8 treats `initial` with value null, `mixed` and `unresolved` as "no claim": never a default of #000000 or 16px for them (only
 *   `initial` WITH a value is the CSS initial value). padding null means "no author value" (the UA padding of ul, td, fieldset is not 0).
 * - Backgrounds are own only: a descendant of an element with a background image or an unknown background has state none or unknown
 *   itself; T8 walks up the ancestors for the backdrop.
 * - A text module's record is the record of its `from` element(s) only (HtmlStyles::common() for several); its inline descendants
 *   (span, strong, a, code, mark, small) can have other values, and T8 must check or count them.
 * - Not modelled (plan D4): pseudo-element overlays (::before backgrounds, ::first-line colours) and inset box-shadows.
 */
final class HtmlStyles
{
    /** UA sheet facts the cascade must respect (D5): tag => properties taken ONLY from the element's own declarations. */
    public const OWN_ONLY = [ 'h1' => ['size','weight'], 'h2' => ['size','weight'], 'h3' => ['size','weight'], 'h4' => ['size','weight'],
        'h5' => ['size','weight'], 'h6' => ['size','weight'], 'a' => ['color'], 'button' => ['color','background','size','weight','family','align'],
        'pre' => ['family'], 'code' => ['family'], 'kbd' => ['family'], 'samp' => ['family'], 'tt' => ['family'] ];

    public const DEFAULT_SIZE = 16.0;

    /** More facts of Chromium's UA sheet (html.css), same meaning as OWN_ONLY, for elements HtmlSource keeps. */
    public const UA_OWN = [ 'b' => ['weight'], 'strong' => ['weight'], 'th' => ['weight','align'], 'caption' => ['align'],
        'small' => ['size'], 'big' => ['size'], 'sub' => ['size'], 'sup' => ['size'], 'rt' => ['size'], 'mark' => ['color','background'],
        'xmp' => ['family'], 'listing' => ['family'], 'plaintext' => ['family'], 'hr' => ['color'], 'optgroup' => ['weight'],
        'option' => ['weight'] ];

    /** The work budget: candidate rules examined over the whole document. Past it the rest of the page resolves as unresolved. */
    public const WORK_BUDGET = 3000000;

    /** The start of the report sentence for `repaired` (HtmlStyles::repairedNote). */
    public const REPAIRED_NOTE = 'the markup would be repaired by a browser in a way this converter does not model: styles were not carried: ';

    /**
     * Chromium's quirks-mode sheet: a table resets these. Its colour is `-internal-quirk-inherit`: the BODY's colour, not its parent's,
     * so in a page that may be in quirks mode a table without its own colour is unresolved unless its parent's colour is the body's.
     */
    public const QUIRKS_OWN = [ 'table' => ['size','weight','align'] ];

    /** Elements whose UA family is exactly the generic monospace. */
    private const MONOSPACE = [ 'pre', 'code', 'kbd', 'samp', 'tt', 'xmp', 'listing', 'plaintext' ];

    private const INHERITED = [ 'color', 'family', 'size', 'weight', 'align' ];

    /** HtmlCss read property => slot. */
    private const SLOTS = [ 'color' => 'color', 'background-color' => 'background', 'font-family' => 'family', 'font-size' => 'size',
        'font-weight' => 'weight', 'text-align' => 'align', 'padding-top' => 'pad.top', 'padding-right' => 'pad.right',
        'padding-bottom' => 'pad.bottom', 'padding-left' => 'pad.left', 'max-width' => 'max_width',
        'border-top-left-radius' => 'rad.0', 'border-top-right-radius' => 'rad.1', 'border-bottom-right-radius' => 'rad.2',
        'border-bottom-left-radius' => 'rad.3' ];

    private const SIDES = [ 'top', 'right', 'bottom', 'left' ];

    /** Presentational attribute => the slots it may set. */
    private const HINTS = [ 'bgcolor' => [ 'background' ], 'background' => [ 'background' ], 'color' => [ 'color' ], 'text' => [ 'color' ],
        'face' => [ 'family' ], 'size' => [ 'size' ], 'align' => [ 'align' ] ];

    /** Start tags that close an open <p> in a browser (HTML "close a p element"; table only outside quirks mode). */
    private const P_CLOSERS = [ 'address', 'article', 'aside', 'blockquote', 'center', 'details', 'dialog', 'dir', 'div', 'dl', 'fieldset',
        'figcaption', 'figure', 'footer', 'header', 'hgroup', 'main', 'menu', 'nav', 'ol', 'p', 'search', 'section', 'summary', 'ul',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'listing', 'form', 'plaintext', 'xmp', 'hr', 'li', 'dd', 'dt', 'table' ];

    private const HEADINGS = [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];

    /** "Button scope" boundaries: an open <p> behind one of these is not closed. */
    private const BUTTON_SCOPE = [ 'applet', 'caption', 'html', 'table', 'td', 'th', 'marquee', 'object', 'template', 'button' ];

    /** Formatting elements a browser clones into a block that closed them. */
    private const FORMATTING = [ 'a', 'b', 'big', 'code', 'em', 'font', 'i', 'nobr', 's', 'small', 'strike', 'strong', 'tt', 'u' ];

    /** Children a browser keeps inside a table part; anything else is fostered out. */
    private const TABLE_CHILDREN = [ 'table' => [ 'caption', 'colgroup', 'col', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th' ],
        'thead' => [ 'tr', 'td', 'th' ], 'tbody' => [ 'tr', 'td', 'th' ], 'tfoot' => [ 'tr', 'td', 'th' ], 'tr' => [ 'td', 'th' ] ];

    /** Table parts: outside a table a browser ignores their start tags. */
    private const TABLE_PARTS = [ 'caption', 'col', 'colgroup', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th' ];

    /** The elements a table part may sit in directly (as a set). */
    private const TABLE_CONTAINERS = [ 'table' => true, 'thead' => true, 'tbody' => true, 'tfoot' => true, 'tr' => true, 'colgroup' => true ];

    /** @var list<array{subject: array<string,mixed>, decls: list<array{0:string,1:?string,2:?string,3:list<int>}>, effect: bool, image: bool}> */
    private array $rules = [];

    /** @var array<string,array<string,list<int>>> index name => key => rule ids */
    private array $index = [ 'id' => [], 'class' => [], 'tag' => [], 'id_ci' => [], 'class_ci' => [] ];

    /** @var list<int> */
    private array $universal = [];

    /** @var array<string,int> */
    private array $ignored = [];

    /** @var array<string,array<string,mixed>> */
    private array $out = [];

    private bool $standards;
    private bool $colorScheme;
    private bool $mergeRules = true;
    private bool $stopped = false;
    private bool $workCap = false;
    private int $steps = 0;
    private int $seq = 0;

    /** @var ?array<string,mixed> the body's computed colour (for the quirks-mode table colour) */
    private ?array $bodyColor = null;

    /** @var array<string,array<mixed>> */
    private array $pool = [];

    /** @var array<string,int> subject key => rule id (rules of the same subject are merged) */
    private array $byKey = [];
    private ?float $rootSize = null;

    /** @var array<string,string> */
    private array $inline;

    /** @var array<string,list<string>> */
    private array $presentational;

    /** @var array<string,array<string,string>> */
    private array $cut;

    /**
     * The document facts resolve() needs, from HtmlSource::parse() output (missing keys: the conservative defaults).
     *
     * @param array<string,mixed> $source
     * @return array{doctype:bool, color_scheme:bool, external_css:int, cut_attrs:array<string,array<string,string>>, css_media:string, repaired:list<string>}
     */
    public static function document( array $source ): array
    {
        $repaired = [];
        foreach ( is_array( $source['repaired'] ?? null ) ? $source['repaired'] : [] as $reason ) {
            if ( is_string( $reason ) && in_array( $reason, HtmlSource::REPAIRS, true ) ) {
                $repaired[] = $reason;
            }
        }
        if ( array() === $repaired && is_array( $source['repaired'] ?? null ) && array() !== $source['repaired'] ) {
            $repaired[] = HtmlSource::REPAIRS['misnest']; // something unknown was reported: still fail closed
        }

        return [
            'repaired'     => $repaired,
            'doctype'      => true === ( $source['doctype'] ?? false ),
            'color_scheme' => true === ( $source['color_scheme'] ?? false ),
            'external_css' => is_int( $source['external_css'] ?? null ) ? max( 0, $source['external_css'] ) : 0,
            'cut_attrs'    => is_array( $source['cut_attrs'] ?? null ) ? $source['cut_attrs'] : [],
            'css_media'    => is_string( $source['css_media'] ?? null ) ? $source['css_media'] : '',
        ];
    }

    /**
     * @param array<string,string>       $inline         HtmlSource 'inline'
     * @param array<string,list<string>> $presentational HtmlSource 'presentational'
     * @param array<string,mixed>        $document       REQUIRED: HtmlStyles::document( HtmlSource::parse(...) ) (missing keys = conservative
     *                                                   defaults: standards mode NOT assumed). A test-only key `merge_rules` => false turns off
     *                                                   the merging of rules of the same subject (it is never set by document()).
     * @return array{nodes: array<string, array<string,mixed>>, ignored: array<string,int>, unread: bool, work_cap: bool, repaired: list<string>}
     *         nodes keyed by getNodePath(), in document order, html and body included
     *
     * Computed = array{
     *   color:  array{state:'set'|'initial'|'unresolved', value:?string, reason:?string},   initial value '#000000' (null when the UA sheet decides)
     *   family: array{state:'set'|'initial'|'unresolved', value:?list<string>, reason:?string},
     *   size:   array{state:'set'|'initial'|'unresolved', value:?float, reason:?string},    initial 16.0 (state initial)
     *   weight: array{state:'set'|'initial'|'unresolved', value:?int, reason:?string},      initial 400
     *   align:  array{state:'set'|'initial'|'unresolved', value:?string, reason:?string},   initial null (start)
     *   background: array{state:'none'|'set'|'unknown', value:?string, reason:?string},    not inherited
     *   padding: array{top:int|string|null, right:..., bottom:..., left:...},                px, 'unresolved' or null; not inherited
     *   max_width: int|string|null,  radius: list<int>|string|null                         'unresolved' or null; not inherited
     * }
     * reason is null unless the state is unresolved or unknown; then it is an HtmlCss::REASONS entry.
     * unread   = some CSS was not read or not resolved (a linked sheet, an @import, a cap, the work budget, repaired markup): the
     *            soundness is relative to the text that was given, and the report must warn.
     * work_cap = the work budget (WORK_BUDGET candidate rules) ran out: the elements from that point on are all unresolved.
     * repaired = HtmlSource `repaired`: a browser would repair the markup differently from libxml2, so EVERY element is unresolved
     *            (repairedNote() gives the report sentence).
     */
    public static function resolve( \DOMElement $body, string $css, array $inline, array $presentational, array $document ): array
    {
        $doc              = self::document( $document );
        $self             = new self( $doc, $inline, $presentational );
        $self->mergeRules = false !== ( $document['merge_rules'] ?? true );
        if ( array() !== $doc['repaired'] ) {
            $self->stopped = true; // fail closed: nothing is read, nothing is resolved
        } else {
            $self->readSheets( $css, $doc['css_media'] );
        }
        if ( $doc['external_css'] > 0 ) {
            self::bump( $self->ignored, 'external', $doc['external_css'] );
        }
        $self->walkDocument( $body );
        ksort( $self->ignored );

        return [
            'nodes'    => $self->out,
            'ignored'  => $self->ignored,
            'unread'   => ( $self->ignored['external'] ?? 0 ) > 0 || ( $self->ignored['css_cap'] ?? 0 ) > 0 || $self->workCap || array() !== $doc['repaired'],
            'work_cap' => $self->workCap,
            'repaired' => $doc['repaired'],
        ];
    }

    /** The report sentence for resolve()['repaired'] ('' when nothing was repaired). @param list<string> $repaired */
    public static function repairedNote( array $repaired ): string
    {
        return array() === $repaired ? '' : self::REPAIRED_NOTE . implode( '; ', $repaired ) . '.';
    }

    /**
     * What several merged pieces of one text module have in common: for each inherited property the shared record when every piece has
     * exactly the same one, else {state:'mixed', value:null, reason:'mixed'} (counted in $ignored['mixed'] once per property). A path
     * without a record makes the property unresolved. Background and box values are not merged (they belong to blocks, not to text).
     *
     * @param array<string,array<string,mixed>> $nodes resolve()['nodes']
     * @param list<string>                      $paths the module's source paths (HtmlMapper `from`)
     * @param array<string,int>                 $ignored
     * @return array<string,array{state:string, value:mixed, reason:?string}> color, family, size, weight, align
     */
    public static function common( array $nodes, array $paths, array &$ignored ): array
    {
        $out = [];
        foreach ( self::INHERITED as $prop ) {
            $first = null;
            $same  = array() !== $paths;
            foreach ( $paths as $i => $path ) {
                $rec = $nodes[ $path ][ $prop ] ?? [ 'state' => 'unresolved', 'value' => null, 'reason' => 'unresolved' ];
                if ( 0 === $i ) {
                    $first = $rec;
                } elseif ( $rec !== $first ) {
                    $same = false;
                }
            }
            if ( null === $first ) {
                $out[ $prop ] = [ 'state' => 'unresolved', 'value' => null, 'reason' => 'unresolved' ];
            } elseif ( $same ) {
                $out[ $prop ] = $first;
            } else {
                $out[ $prop ] = [ 'state' => 'mixed', 'value' => null, 'reason' => 'mixed' ];
                self::bump( $ignored, 'mixed' );
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $doc @param array<string,string> $inline @param array<string,list<string>> $presentational */
    private function __construct( array $doc, array $inline, array $presentational )
    {
        $this->standards      = $doc['doctype'];
        $this->colorScheme    = $doc['color_scheme'];
        $this->inline         = $inline;
        $this->presentational = $presentational;
        $this->cut            = $doc['cut_attrs'];
    }

    // ---------------------------------------------------------------
    // Rules
    // ---------------------------------------------------------------

    private function readSheets( string $css, string $media ): void
    {
        $sheet = HtmlCss::stylesheet( $css );
        self::merge( $this->ignored, $sheet['ignored'] );
        foreach ( $sheet['rules'] as $rule ) {
            $this->addRule( $rule, false );
        }
        if ( '' !== $media ) {
            $m = HtmlCss::stylesheet( $media );
            self::merge( $this->ignored, $m['ignored'] );
            foreach ( $m['rules'] as $rule ) {
                self::bump( $this->ignored, 'media', count( $rule['declarations'] ) );
                $this->addRule( $rule, true );
            }
        }
    }

    /**
     * Stores a rule with its declarations ranked once: [slot, value, poison reason, rank].
     *
     * @param array<string,mixed> $rule one HtmlCss rule
     * @param bool $someScreens a rule of a <style media> block for some screens: a poison of unknown rank
     */
    private function addRule( array $rule, bool $someScreens ): void
    {
        $sentinel = $someScreens || $rule['specificity'] === HtmlCss::MAX_SPECIFICITY;
        $poison   = $someScreens || true === $rule['poison_only'];
        $spec     = $sentinel ? [ 0, 0, 0 ] : array_values( $rule['specificity'] );
        $order    = $this->seq++; // the reading order (unique; not the number of stored rules, which merging keeps smaller)
        if ( $someScreens ) {
            $why = 'media';
        } elseif ( $sentinel ) {
            $why = 'unresolved';
        } else {
            $why = true === $rule['simple'] ? 'media' : 'selector';
        }
        $decls  = [];
        $effect = false;
        $image  = false;
        $scale  = false;
        foreach ( $rule['declarations'] as $k => $d ) {
            if ( 'effect' === $d['property'] ) {
                $effect = true;
                continue;
            }
            if ( 'background-image' === $d['property'] ) {
                $image = true;
                continue;
            }
            if ( 'scale' === $d['property'] ) {
                $scale = true;
                continue;
            }
            $slot = self::SLOTS[ $d['property'] ] ?? null;
            if ( null === $slot ) {
                continue;
            }
            $reason  = $d['poison'] ?? ( $poison ? $why : null );
            $decls[] = [ $slot, null === $reason ? $d['value'] : null, $reason, [ $d['important'] ? 1 : 0, $sentinel ? 2 : 0, $spec[0], $spec[1], $spec[2], $order, $k ] ];
        }
        if ( array() === $decls && ! $effect && ! $image && ! $scale ) {
            return;
        }
        $subject = $rule['subject'];
        if ( $this->mergeRules ) {
            // Matching depends only on the subject (tag, id set, class set) and ranks are static, so rules of one subject are one rule:
            // the best-ranked declaration per slot, the markers or'ed. Exactly the same cascade (HtmlStylesTest proves it), less work.
            $ids     = array_values( array_unique( $subject['ids'] ) );
            $classes = array_values( array_unique( $subject['classes'] ) );
            sort( $ids, SORT_STRING );
            sort( $classes, SORT_STRING );
            $key = serialize( [ $subject['tag'], $ids, $classes ] );
            if ( isset( $this->byKey[ $key ] ) ) {
                $r = &$this->rules[ $this->byKey[ $key ] ];
                foreach ( $decls as $d ) {
                    self::offer( $r['decls'], $d );
                }
                $r['effect'] = $r['effect'] || $effect;
                $r['image']  = $r['image'] || $image;
                $r['scale']  = $r['scale'] || $scale;
                unset( $r );

                return;
            }
            $best = [];
            foreach ( $decls as $d ) {
                self::offer( $best, $d );
            }
            $decls                = $best;
            $this->byKey[ $key ] = count( $this->rules );
        }
        $this->rules[] = [ 'subject' => $subject, 'decls' => $decls, 'effect' => $effect, 'image' => $image, 'scale' => $scale ];
        $id            = count( $this->rules ) - 1;
        if ( array() !== $subject['ids'] ) {
            $this->index['id'][ $subject['ids'][0] ][]                  = $id;
            $this->index['id_ci'][ self::lower( $subject['ids'][0] ) ][] = $id;
        } elseif ( array() !== $subject['classes'] ) {
            $this->index['class'][ $subject['classes'][0] ][]                  = $id;
            $this->index['class_ci'][ self::lower( $subject['classes'][0] ) ][] = $id;
        } elseif ( null !== $subject['tag'] ) {
            $this->index['tag'][ $subject['tag'] ][] = $id;
        } else {
            $this->universal[] = $id;
        }
    }

    // ---------------------------------------------------------------
    // The walk
    // ---------------------------------------------------------------

    private function walkDocument( \DOMElement $body ): void
    {
        $root    = $body->ownerDocument->documentElement;
        $initial = self::initialRecord();
        if ( $root instanceof \DOMElement && $root !== $body && 'html' === strtolower( $root->tagName ) ) {
            $html             = $this->element( $root, $initial, null, $this->colorScheme );
            $this->rootSize = 'unresolved' === $html['size']['state'] ? null : $html['size']['value'];
            $this->emit( $root, $html );
            $frames = [ (object) [ 'tag' => 'html', 'closed' => false ] ];
        } else {
            $html   = $initial;
            $frames = [];
            $this->rootSize = self::DEFAULT_SIZE;
        }
        $this->visit( $body, $html, $frames, null );
    }

    /**
     * @param array<string,mixed> $parent the computed record the element inherits from (as the libxml tree has it)
     * @param list<object>        $frames the open ancestors (tag, closed) for the browser's p and heading closing rules
     * @param ?array<string,mixed> $tableParent the record of the nearest table's parent (for fostered content)
     */
    private function visit( \DOMElement $el, array $parent, array $frames, ?array $tableParent ): void
    {
        $tag = strtolower( $el->tagName );
        if ( in_array( $tag, self::TABLE_PARTS, true ) && ! self::insideTable( $el ) ) {
            // A browser ignores this start tag: the element does not exist, its children belong to the parent.
            $this->emit( $el, self::phantomRecord() );
            foreach ( iterator_to_array( $el->childNodes ) as $child ) {
                if ( $child instanceof \DOMElement ) {
                    $this->visit( $child, $parent, $frames, $tableParent );
                }
            }

            return;
        }
        if ( in_array( $tag, self::TABLE_PARTS, true ) && ! isset( self::TABLE_CONTAINERS[ self::parentTag( $el ) ] ) ) {
            // A table part inside a cell's (or caption's) content: the browser closes that cell and starts a new part, so this element,
            // its content and what follows it in the closed cell have parents this reader does not model.
            for ( $i = count( $frames ) - 1; $i >= 0; $i-- ) {
                if ( in_array( $frames[ $i ]->tag, self::TABLE_PARTS, true ) ) {
                    $frames[ $i ]->closed = true;
                    break;
                }
            }
            $this->emit( $el, self::phantomRecord() );
            $unknown = self::unknownParent( $parent );
            foreach ( iterator_to_array( $el->childNodes ) as $child ) {
                if ( $child instanceof \DOMElement ) {
                    $this->visit( $child, $unknown, $frames, $tableParent );
                }
            }

            return;
        }
        $displaced = false;
        $clone     = false;
        foreach ( $frames as $f ) {
            if ( $f->closed ) {
                $displaced = true;
                break;
            }
        }
        $top = count( $frames ) - 1;
        if ( in_array( $tag, self::P_CLOSERS, true ) && ( 'table' !== $tag || $this->standards ) ) {
            for ( $i = $top; $i >= 0; $i-- ) {
                if ( in_array( $frames[ $i ]->tag, self::BUTTON_SCOPE, true ) ) {
                    break;
                }
                if ( 'p' === $frames[ $i ]->tag ) {
                    $frames[ $i ]->closed = true;
                    $displaced            = true;
                    for ( $j = $i + 1; $j <= $top; $j++ ) {
                        $clone = $clone || in_array( $frames[ $j ]->tag, self::FORMATTING, true );
                    }
                    $frames = array_slice( $frames, 0, $i ); // inside the block, the browser's open elements are those above the p
                    break;
                }
            }
        }
        // A heading start tag pops a heading that is the current node (after any p was closed).
        $last = count( $frames ) - 1;
        if ( in_array( $tag, self::HEADINGS, true ) && $last >= 0 && in_array( $frames[ $last ]->tag, self::HEADINGS, true ) ) {
            $frames[ $last ]->closed = true;
            $displaced               = true;
            $frames                  = array_slice( $frames, 0, $last );
        }
        if ( ! $this->standards && 'table' === $tag && self::previousElementTag( $el ) === 'p' ) {
            $displaced = true;
        }

        // The element's parent as the browser builds it.
        $ptag = $el->parentNode instanceof \DOMElement ? strtolower( $el->parentNode->tagName ) : '';
        if ( $displaced ) {
            $parent = self::unknownParent( $parent );
        } elseif ( isset( self::TABLE_CHILDREN[ $ptag ] ) && ! in_array( $tag, self::TABLE_CHILDREN[ $ptag ], true ) && null !== $tableParent ) {
            $parent = $tableParent; // fostered out of the table
        } else {
            if ( 'table' === $ptag && in_array( $tag, [ 'tr', 'td', 'th' ], true ) ) {
                $parent = $this->element( null, $parent, 'tbody', false );
            }
            if ( in_array( $ptag, [ 'table', 'thead', 'tbody', 'tfoot' ], true ) && in_array( $tag, [ 'td', 'th' ], true ) ) {
                $parent = $this->element( null, $parent, 'tr', false );
            }
        }

        if ( 'math' === $tag || self::insideMath( $el ) ) {
            // Chromium's MathML UA sheet: math { font-family: math; font-weight: normal } and math-depth scaling of scripts and fractions:
            // in math, inherited size, weight, family and align are unknown; the colour inherits normally (measured).
            $parent = self::mathParent( $parent );
        }
        $rec = $this->element( $el, $parent, null, false );
        $this->emit( $el, $rec );

        $childFrames   = $frames;
        $childFrames[] = (object) [ 'tag' => $tag, 'closed' => false ];
        // meter and progress show a UA shadow tree without a slot: their children are outside the flat tree and inherit nothing from them
        // (measured: initial values), so they get an unknown parent.
        $forChildren   = ( $clone || 'meter' === $tag || 'progress' === $tag ) ? self::unknownParent( $rec ) : $rec;
        $childTable    = 'table' === $tag ? $parent : $tableParent;
        foreach ( iterator_to_array( $el->childNodes ) as $child ) {
            if ( $child instanceof \DOMElement ) {
                $this->visit( $child, $forChildren, $childFrames, $childTable );
            }
        }
    }

    private static function parentTag( \DOMElement $el ): string
    {
        return $el->parentNode instanceof \DOMElement ? strtolower( $el->parentNode->tagName ) : '';
    }

    private static function insideMath( \DOMElement $el ): bool
    {
        for ( $p = $el->parentNode; $p instanceof \DOMElement; $p = $p->parentNode ) {
            if ( 'math' === strtolower( $p->tagName ) ) {
                return true;
            }
        }

        return false;
    }

    /** The parent of an element in math: inherited size, weight, family and align unknown, the colour kept. @param array<string,mixed> $parent @return array<string,mixed> */
    private static function mathParent( array $parent ): array
    {
        $u = self::unknownParent( $parent );
        $u['color'] = $parent['color'];

        return $u;
    }

    private static function insideTable( \DOMElement $el ): bool
    {
        for ( $p = $el->parentNode; $p instanceof \DOMElement; $p = $p->parentNode ) {
            if ( 'table' === strtolower( $p->tagName ) ) {
                return true;
            }
        }

        return false;
    }

    private static function previousElementTag( \DOMElement $el ): ?string
    {
        for ( $n = $el->previousSibling; null !== $n; $n = $n->previousSibling ) {
            if ( $n instanceof \DOMElement ) {
                return strtolower( $n->tagName );
            }
            if ( $n instanceof \DOMText && '' !== trim( $n->data, " \t\n\r\f" ) ) {
                return null;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------
    // One element
    // ---------------------------------------------------------------

    /**
     * The computed record of one element (or of a tbody/tr the browser adds: $el null, $virtual its tag).
     *
     * @param array<string,mixed> $parent
     * @return array<string,mixed> the internal record (inherited properties carry `ua` and `kw` flags)
     */
    private function element( ?\DOMElement $el, array $parent, ?string $virtual, bool $forcedEffect ): array
    {
        if ( $this->stopped ) {
            return self::phantomRecord();
        }
        $tag     = null !== $el ? strtolower( $el->tagName ) : (string) $virtual;
        $path    = null !== $el ? (string) $el->getNodePath() : '';
        $ids     = [];
        $classes = [];
        if ( null !== $el ) {
            $id = $this->cut[ $path ]['id'] ?? $el->getAttribute( 'id' );
            if ( '' !== $id ) {
                $ids[] = $id;
            }
            $class   = $this->cut[ $path ]['class'] ?? $el->getAttribute( 'class' );
            $classes = array_values( array_unique( preg_split( '/[\x09\x0A\x0C\x0D\x20]+/', $class, -1, PREG_SPLIT_NO_EMPTY ) ?: [] ) );
        }

        [ $best, $effect, $image, $scale ] = $this->cascade( $tag, $ids, $classes, null !== $el ? $path : null );
        if ( $this->stopped ) {
            return self::phantomRecord(); // the work budget ran out on this element
        }
        $effect = $effect || $forcedEffect || $parent['effect'];
        $scale  = $scale || $parent['scale'];

        $own = array_merge( self::OWN_ONLY[ $tag ] ?? [], self::UA_OWN[ $tag ] ?? [], $this->standards ? [] : ( self::QUIRKS_OWN[ $tag ] ?? [] ) );
        if ( 'rt' === $tag && null !== $el && $el->parentNode instanceof \DOMElement && 'ruby' === strtolower( $el->parentNode->tagName ) ) {
            $own[] = 'align'; // the UA sheet: ruby > rt { text-align: start } (rt in rtc, or outside ruby, inherits)
        }

        $rec = [ 'effect' => $effect, 'scale' => $scale ];
        foreach ( [ 'color', 'family', 'weight', 'align' ] as $prop ) {
            $rec[ $prop ] = $this->inherited( $prop, $best[ $prop ] ?? null, $parent, in_array( $prop, $own, true ) );
        }
        $rec['size'] = $this->size( $best['size'] ?? null, $parent, in_array( 'size', $own, true ), $tag, 'html' === $tag && null !== $el, $rec['family'] );
        if ( ! $this->standards && 'table' === $tag && null === ( $best['color'] ?? null )
            && ( null === $this->bodyColor || $rec['color']['state'] !== $this->bodyColor['state'] || $rec['color']['value'] !== $this->bodyColor['value'] ) ) {
            $rec['color'] = self::rec( 'unresolved', null, 'unresolved' ); // quirks: a table takes the body's colour, not its parent's
        }
        if ( 'body' === $tag && null !== $el ) {
            $this->bodyColor = $rec['color'];
        }
        if ( $effect ) {
            $rec['color'] = self::rec( 'unresolved', null, 'effect' );
        }
        if ( $scale ) {
            $rec['size'] = self::rec( 'unresolved', null, 'effect' ) + [ 'kw' => true ]; // rendered zoomed or scaled
        }

        // Not inherited.
        $bg = $best['background'] ?? null;
        if ( $effect ) {
            $rec['background'] = [ 'state' => 'unknown', 'value' => null, 'reason' => 'effect' ];
        } elseif ( null !== $bg && null !== $bg[2] ) {
            $rec['background'] = [ 'state' => 'unknown', 'value' => null, 'reason' => $bg[2] ];
        } elseif ( $image ) {
            $rec['background'] = [ 'state' => 'unknown', 'value' => null, 'reason' => 'image_or_gradient' ];
        } elseif ( null !== $bg && 'none' !== $bg[1] ) {
            $rec['background'] = [ 'state' => 'set', 'value' => $bg[1], 'reason' => null ];
        } elseif ( null === $bg && in_array( 'background', $own, true ) ) {
            $rec['background'] = [ 'state' => 'unknown', 'value' => null, 'reason' => 'unresolved' ];
        } else {
            $rec['background'] = [ 'state' => 'none', 'value' => null, 'reason' => null ];
        }
        $rec['padding'] = [];
        foreach ( self::SIDES as $side ) {
            $rec['padding'][ $side ] = $this->box( $best[ 'pad.' . $side ] ?? null, $rec['size'] );
        }
        $mw               = $best['max_width'] ?? null;
        $rec['max_width'] = ( null !== $mw && null === $mw[2] && 'none' === $mw[1] ) ? null : $this->box( $mw, $rec['size'] );
        $corners          = [];
        $any              = false;
        $unresolved       = false;
        for ( $k = 0; $k < 4; $k++ ) {
            $c = $best[ 'rad.' . $k ] ?? null;
            $any = $any || null !== $c;
            $v   = null === $c ? 0 : $this->box( $c, $rec['size'] );
            $unresolved  = $unresolved || 'unresolved' === $v;
            $corners[]   = $v;
        }
        $rec['radius'] = ! $any ? null : ( $unresolved ? 'unresolved' : $corners );
        if ( $scale ) {
            foreach ( self::SIDES as $side ) {
                $rec['padding'][ $side ] = null === $rec['padding'][ $side ] ? null : 'unresolved';
            }
            $rec['max_width'] = null === $rec['max_width'] ? null : 'unresolved';
            $rec['radius']    = null === $rec['radius'] ? null : 'unresolved';
        }

        return $rec;
    }

    /**
     * Every candidate of one element, reduced to the winner per slot, plus the markers.
     *
     * @param list<string> $ids
     * @param list<string> $classes
     * @return array{0: array<string,array{0:string,1:?string,2:?string,3:list<int>}>, 1: bool, 2: bool}
     */
    private function cascade( string $tag, array $ids, array $classes, ?string $path ): array
    {
        $best   = [];
        $effect = false;
        $image  = false;
        $scale  = false;

        // Rules.
        $cand = [];
        foreach ( $this->universal as $r ) {
            $cand[ $r ] = true;
        }
        foreach ( $this->index['tag'][ $tag ] ?? [] as $r ) {
            $cand[ $r ] = true;
        }
        foreach ( $ids as $id ) {
            foreach ( $this->index['id'][ $id ] ?? [] as $r ) {
                $cand[ $r ] = true;
            }
            if ( ! $this->standards ) {
                foreach ( $this->index['id_ci'][ self::lower( $id ) ] ?? [] as $r ) {
                    $cand[ $r ] = true;
                }
            }
        }
        foreach ( $classes as $class ) {
            foreach ( $this->index['class'][ $class ] ?? [] as $r ) {
                $cand[ $r ] = true;
            }
            if ( ! $this->standards ) {
                foreach ( $this->index['class_ci'][ self::lower( $class ) ] ?? [] as $r ) {
                    $cand[ $r ] = true;
                }
            }
        }
        // The work budget: candidate rules examined over the document (deterministic, independent of the clock).
        $this->steps += count( $cand ) + 1;
        if ( $this->steps > self::WORK_BUDGET ) {
            if ( ! $this->workCap ) {
                $this->workCap = true;
                self::bump( $this->ignored, 'css_cap' );
            }
            $this->stopped = true;

            return [ [], false, false, false ];
        }
        ksort( $cand );
        $idSet      = array_fill_keys( $ids, true );
        $classSet   = array_fill_keys( $classes, true );
        $lowIds     = null;
        $lowClasses = null;
        foreach ( array_keys( $cand ) as $r ) {
            $rule  = $this->rules[ $r ];
            $match = self::matches( $rule['subject'], $tag, $idSet, $classSet );
            $quirk = false;
            if ( ! $match && ! $this->standards ) {
                $lowIds     ??= array_fill_keys( array_map( [ self::class, 'lower' ], $ids ), true );
                $lowClasses ??= array_fill_keys( array_map( [ self::class, 'lower' ], $classes ), true );
                $sub            = $rule['subject'];
                $sub['ids']     = array_map( [ self::class, 'lower' ], $sub['ids'] );
                $sub['classes'] = array_map( [ self::class, 'lower' ], $sub['classes'] );
                $quirk          = self::matches( $sub, $tag, $lowIds, $lowClasses );
            }
            if ( ! $match && ! $quirk ) {
                continue;
            }
            $effect = $effect || $rule['effect'];
            $image  = $image || $rule['image'];
            $scale  = $scale || $rule['scale'];
            foreach ( $rule['decls'] as $d ) {
                if ( $quirk ) {
                    $d[1] = null;
                    $d[2] = 'selector'; // quirks mode would apply it; this reader does not know the mode
                }
                self::offer( $best, $d );
            }
        }

        if ( null !== $path ) {
            // Inline declarations.
            $text = $this->inline[ $path ] ?? null;
            if ( is_string( $text ) && '' !== $text ) {
                foreach ( HtmlCss::declarations( $text, $this->ignored ) as $k => $d ) {
                    if ( 'effect' === $d['property'] ) {
                        $effect = true;
                        continue;
                    }
                    if ( 'background-image' === $d['property'] ) {
                        $image = true;
                        continue;
                    }
                    if ( 'scale' === $d['property'] ) {
                        $scale = true;
                        continue;
                    }
                    $slot = self::SLOTS[ $d['property'] ] ?? null;
                    if ( null !== $slot ) {
                        self::offer( $best, [ $slot, null === $d['poison'] ? $d['value'] : null, $d['poison'], [ $d['important'] ? 1 : 0, 1, 0, 0, 0, 0, $k ] ] );
                    }
                }
            }
            // Presentational hints: below every author rule.
            foreach ( $this->presentational[ $path ] ?? [] as $k => $name ) {
                $slots = self::HINTS[ $name ] ?? [];
                if ( array() !== $slots ) {
                    self::bump( $this->ignored, 'presentational' );
                }
                if ( 'background' === $name ) {
                    $image = true;
                }
                foreach ( $slots as $slot ) {
                    self::offer( $best, [ $slot, null, 'presentational', [ 0, -1, 0, 0, 0, 0, $k ] ] );
                }
            }
        }
        if ( 'center' === $tag ) {
            self::offer( $best, [ 'align', 'center', null, [ 0, -2, 0, 0, 0, 0, 0 ] ] );
        }

        return [ $best, $effect, $image, $scale ];
    }

    /** @param array<string,array<int,mixed>> $best @param array{0:string,1:?string,2:?string,3:list<int>} $d */
    private static function offer( array &$best, array $d ): void
    {
        $cur = $best[ $d[0] ] ?? null;
        if ( null === $cur || self::outranks( $d[3], $cur[3] ) ) {
            $best[ $d[0] ] = $d;
        }
    }

    /** Lexicographic comparison of two ranks (never added). @param list<int> $a @param list<int> $b */
    private static function outranks( array $a, array $b ): bool
    {
        foreach ( $a as $i => $v ) {
            if ( $v !== $b[ $i ] ) {
                return $v > $b[ $i ];
            }
        }

        return false;
    }

    /** @param array<string,mixed> $subject @param array<string,true> $ids the element's id set @param array<string,true> $classes its class set */
    private static function matches( array $subject, string $tag, array $ids, array $classes ): bool
    {
        if ( null !== $subject['tag'] && $subject['tag'] !== $tag ) {
            return false;
        }
        foreach ( $subject['ids'] as $id ) {
            if ( ! isset( $ids[ $id ] ) ) {
                return false;
            }
        }
        foreach ( $subject['classes'] as $class ) {
            if ( ! isset( $classes[ $class ] ) ) {
                return false;
            }
        }

        return true;
    }

    // ---------------------------------------------------------------
    // Values
    // ---------------------------------------------------------------

    /**
     * color, family, weight or align.
     *
     * @param ?array{0:string,1:?string,2:?string,3:list<int>} $w the winner
     * @param array<string,mixed> $parent
     * @return array{state:string, value:mixed, reason:?string, ua:bool}
     */
    private function inherited( string $prop, ?array $w, array $parent, bool $uaOwn ): array
    {
        if ( null === $w ) {
            return $uaOwn ? self::rec( 'initial', null, null, true ) : self::inherit( $parent[ $prop ] );
        }
        if ( null !== $w[2] ) {
            return self::rec( 'unresolved', null, $w[2] );
        }
        if ( 'inherit' === $w[1] ) {
            return self::inherit( $parent[ $prop ] );
        }
        switch ( $prop ) {
            case 'family':
                return self::rec( 'set', explode( ', ', (string) $w[1] ), null );
            case 'weight':
                return self::rec( 'set', (int) $w[1], null );
            default:
                return self::rec( 'set', (string) $w[1], null );
        }
    }

    /**
     * font-size, with the keyword flag (kw: the size derives from the default keyword, through em and % only).
     *
     * @param ?array{0:string,1:?string,2:?string,3:list<int>} $w
     * @param array<string,mixed> $parent
     * @param array<string,mixed> $family the element's computed family
     * @return array{state:string, value:?float, reason:?string, ua:bool, kw:bool}
     */
    private function size( ?array $w, array $parent, bool $uaOwn, string $tag, bool $isRoot, array $family ): array
    {
        $p = $parent['size'];
        if ( null === $w ) {
            $r = $uaOwn ? self::rec( 'initial', null, null, true ) + [ 'kw' => true ] : self::inherit( $p ) + [ 'kw' => $p['kw'] ];
        } elseif ( null !== $w[2] ) {
            $r = self::rec( 'unresolved', null, $w[2] ) + [ 'kw' => true ];
        } elseif ( 'inherit' === $w[1] ) {
            $r = self::inherit( $p ) + [ 'kw' => $p['kw'] ];
        } else {
            [ $n, $unit ] = self::token( (string) $w[1] );
            if ( 'px' === $unit ) {
                $r = self::rec( 'set', $n, null ) + [ 'kw' => false ];
            } elseif ( 'rem' === $unit ) {
                // rem is absolute for the monospace quirk (measured: 1rem in monospace is 16px, not 13).
                $base = $isRoot ? self::DEFAULT_SIZE : $this->rootSize;
                $r    = null === $base ? self::rec( 'unresolved', null, 'unresolved' ) + [ 'kw' => true ] : self::rec( 'set', $n * $base, null ) + [ 'kw' => false ];
            } else {
                $base = ( 'unresolved' !== $p['state'] && ! $p['ua'] ) ? $p['value'] : null;
                $mult = '%' === $unit ? $n / 100.0 : $n;
                $r    = null === $base ? self::rec( 'unresolved', null, 'unresolved' ) + [ 'kw' => true ] : self::rec( 'set', $mult * (float) $base, null ) + [ 'kw' => $p['kw'] ];
            }
        }
        // Chromium's monospace keyword quirk (13px): the family is exactly `monospace`, or unknown, and the size comes from the keyword.
        if ( $r['kw'] && null !== $r['value'] ) {
            $mono = 'unresolved' === $family['state']
                || ( $family['ua'] && in_array( $tag, self::MONOSPACE, true ) )
                || ( is_array( $family['value'] ) && 1 === count( $family['value'] ) && 'monospace' === self::lower( $family['value'][0] ) );
            if ( $mono ) {
                $r = self::rec( 'unresolved', null, 'unresolved' ) + [ 'kw' => true ];
            }
        }

        return $r;
    }

    /**
     * A padding side, max width or radius corner in px (int, round half up), 'unresolved' or null.
     *
     * @param ?array{0:string,1:?string,2:?string,3:list<int>} $w
     * @param array<string,mixed> $size the element's own computed size
     */
    private function box( ?array $w, array $size ): int|string|null
    {
        if ( null === $w ) {
            return null;
        }
        if ( null !== $w[2] || null === $w[1] ) {
            return 'unresolved';
        }
        [ $n, $unit ] = self::token( $w[1] );
        switch ( $unit ) {
            case 'px':
                $px = $n;
                break;
            case 'rem':
                if ( null === $this->rootSize ) {
                    return 'unresolved';
                }
                $px = $n * $this->rootSize;
                break;
            case 'em':
                if ( 'unresolved' === $size['state'] || null === $size['value'] ) {
                    return 'unresolved';
                }
                $px = $n * (float) $size['value'];
                break;
            default:
                return 'unresolved';
        }

        return (int) floor( $px + 0.5 );
    }

    /** A canonical HtmlCss length token as [number, unit]; an unexpected form is [0.0, '?'] (never carried). @return array{0:float,1:string} */
    private static function token( string $v ): array
    {
        if ( 1 !== preg_match( '/^(\d+(?:\.\d+)?)(px|rem|em|%)$/', $v, $m ) ) {
            return [ 0.0, '?' ];
        }

        return [ (float) $m[1], $m[2] ];
    }

    // ---------------------------------------------------------------
    // Records
    // ---------------------------------------------------------------

    /** @return array<string,mixed> what html inherits from: the CSS initial values */
    private static function initialRecord(): array
    {
        return [
            'effect' => false,
            'scale'  => false,
            'color'  => self::rec( 'initial', '#000000', null ),
            'family' => self::rec( 'initial', null, null ),
            'size'   => self::rec( 'initial', self::DEFAULT_SIZE, null ) + [ 'kw' => true ],
            'weight' => self::rec( 'initial', 400, null ),
            'align'  => self::rec( 'initial', null, null ),
        ];
    }

    /** The parent of an element whose real parent in the browser's tree is not known: every inherited property unresolved. @param array<string,mixed> $parent @return array<string,mixed> */
    private static function unknownParent( array $parent ): array
    {
        $u = self::rec( 'unresolved', null, 'unresolved' );

        return [ 'effect' => $parent['effect'], 'scale' => $parent['scale'], 'color' => $u, 'family' => $u, 'size' => $u + [ 'kw' => true ], 'weight' => $u, 'align' => $u ];
    }

    /** The record of an element a browser does not build: nothing about it is known. @return array<string,mixed> */
    private static function phantomRecord(): array
    {
        $u   = self::rec( 'unresolved', null, 'unresolved' );
        $rec = [ 'effect' => false, 'scale' => false, 'color' => $u, 'family' => $u, 'size' => $u + [ 'kw' => true ], 'weight' => $u, 'align' => $u ];

        return $rec + [
            'background' => [ 'state' => 'unknown', 'value' => null, 'reason' => 'unresolved' ],
            'padding'    => [ 'top' => 'unresolved', 'right' => 'unresolved', 'bottom' => 'unresolved', 'left' => 'unresolved' ],
            'max_width'  => 'unresolved',
            'radius'     => 'unresolved',
        ];
    }

    /** @param array<string,mixed> $p @return array{state:string, value:mixed, reason:?string, ua:bool} */
    private static function inherit( array $p ): array
    {
        if ( $p['ua'] ) {
            return self::rec( 'unresolved', null, 'unresolved' );
        }

        return self::rec( $p['state'], $p['value'], $p['reason'] );
    }

    /** @return array{state:string, value:mixed, reason:?string, ua:bool} */
    private static function rec( string $state, mixed $value, ?string $reason, bool $ua = false ): array
    {
        return [ 'state' => $state, 'value' => $value, 'reason' => $reason, 'ua' => $ua ];
    }

    /** @param array<string,mixed> $rec */
    private function emit( \DOMElement $el, array $rec ): void
    {
        $path = $el->getNodePath();
        if ( null === $path ) {
            return;
        }
        $out = [];
        foreach ( [ 'color', 'family', 'size', 'weight', 'align' ] as $prop ) {
            $out[ $prop ] = $this->shared( [ 'state' => $rec[ $prop ]['state'], 'value' => $rec[ $prop ]['value'], 'reason' => $rec[ $prop ]['reason'] ] );
        }
        $out['background'] = $this->shared( $rec['background'] );
        $out['padding']    = $this->shared( $rec['padding'] );
        $out['max_width']  = $rec['max_width'];
        $out['radius']     = is_array( $rec['radius'] ) ? $this->shared( $rec['radius'] ) : $rec['radius'];
        $this->out[ $path ] = $out;
    }

    /**
     * One shared copy of equal sub-records (PHP arrays are copy-on-write, so a page of thousands of elements holds each distinct record
     * once). Same values, less memory.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function shared( array $value ): array
    {
        $key = serialize( $value );

        return $this->pool[ $key ] ??= $value;
    }

    /** ASCII lower case (locale-independent). */
    private static function lower( string $s ): string
    {
        return strtr( $s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
    }

    /** @param array<string,int> $into @param array<string,int> $from */
    private static function merge( array &$into, array $from ): void
    {
        foreach ( $from as $k => $n ) {
            self::bump( $into, $k, $n );
        }
    }

    /** @param array<string,int> $ignored */
    private static function bump( array &$ignored, string $reason, int $n = 1 ): void
    {
        if ( $n > 0 && in_array( $reason, HtmlCss::REASONS, true ) ) {
            $ignored[ $reason ] = ( $ignored[ $reason ] ?? 0 ) + $n;
        }
    }
}
