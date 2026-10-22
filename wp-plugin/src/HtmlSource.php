<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Step one of html_to_divi: turn a string of HTML into a SAFE, small DOM.
 *
 * The HTML is data. It is parsed (never fetched, never executed), everything that could carry code or that Divi
 * cannot reproduce is removed and written down in `flagged`, every attribute outside a short allow-list is dropped,
 * and every URL is checked. What comes back can only hold structure, text, links and images.
 */
final class HtmlSource
{
    public const MAX_BYTES    = 400000;
    public const MAX_ELEMENTS = 5000;
    public const MAX_DEPTH    = 50;
    /**
     * The collected <style> text is handed over cut at MAX_CSS_BYTES + 1 bytes (plain substr, possibly inside a character): one byte MORE than
     * the cap HtmlCss reads (HtmlCss::MAX_CSS_BYTES, kept equal by a test), so the CSS reader sees a text longer than its cap, counts `css_cap`
     * and poisons what the cut-off tail might have set, instead of reading a last rule that the cut shortened (#123456 -> #123).
     */
    public const MAX_CSS_BYTES = 100000;

    /**
     * Attributes that browsers still map to colour, background, font or alignment (HTML 3/4 leftovers): bgcolor and background (body, table,
     * tr, td, th), text, link, vlink and alink (body colours), color, face and size (font), align and valign (blocks, tables, cells ...).
     * Width, height, border, cellpadding and the like are NOT here: they restyle nothing a colour, font or alignment reader looks at. They are
     * never kept in the DOM; their NAMES are handed over per element, in this order, so the style reader knows a value there might be set by
     * something it cannot read.
     */
    private const PRESENTATIONAL = [ 'bgcolor', 'background', 'color', 'text', 'link', 'vlink', 'alink', 'face', 'size', 'align', 'valign' ];

    /** A <style> (or <link>) inside one of these is inert in a browser (template, scripting-on noscript, text-only elements): it is not read. */
    private const INERT_ANCESTORS = [ 'template', 'noscript', 'textarea', 'title', 'iframe', 'noembed', 'noframes', 'xmp', 'plaintext' ];

    /** The newest libxml2 this analysis was built and verified against (2.9.x; 2.14 rewrote the HTML tokenizer). */
    public const LIBXML_MAX_TESTED = 21399;

    /**
     * The fixed reasons of `repaired` (never input text): markup a browser's HTML5 parser builds differently from libxml2, so the tree the
     * style reader walks may not be the tree the browser styles. The scan does not model these constructs: it reports them (fail closed).
     */
    public const REPAIRS = [
        'parser'    => 'the HTML parser version is newer than the one this converter was tested with',
        'misnest'   => 'tags that close out of order, or an end tag that matches nothing open (a stray </div>, </p>, </br>, </img> or </source> included)',
        'button'    => 'a button inside a button',
        'nobr'      => 'a nobr inside a nobr',
        'a'         => 'a link inside a link',
        'list'      => 'a list item closed through another element',
        'twice'     => 'a second body or html tag with attributes',
        'head'      => 'a head tag after the page content started',
        'autoclose' => 'an element that libxml2 closes early where a browser keeps it open (for example a b or an h2 around a paragraph, a list directly inside a list)',
        'noscript'  => 'a noscript element whose end a browser finds somewhere else',
        'dialog'    => 'a dialog element (it changes the elements around it)',
        'control'   => 'control characters a browser keeps and this converter removes',
        'attrs'     => 'a tag with more than 200 attributes',
        'shadow'    => 'a declarative shadow root',
        'popover'   => 'a popover',
        'math'      => 'a style element inside math',
        'selfclose' => 'self-closing syntax (/>) on an element that is not void',
        'foreign'   => 'HTML inside svg or math that a browser moves out of it, or an end tag there that matches nothing',
        'obsolete'  => 'an obsolete element browsers parse in their own way (image, xmp, noembed, noframes, plaintext, listing, isindex, marquee, nextid, bgsound, keygen, menuitem, frameset, frame)',
        'entity'    => 'a character reference in a style, class, id, type, media or rel attribute',
        'rawtext'   => 'an end tag or comment opener inside script, style, title, textarea or iframe text',
        'comment'   => 'a comment written in a way parsers end differently (<!-->, <!--->, --!> or a comment that never ends)',
        'bogus'     => 'CDATA, a processing instruction or another markup declaration that is not a doctype',
        'doctype'   => 'a doctype that is not the first thing in the document',
        'tag'       => 'an unusual tag or attribute (an unexpected character in a name, an unquoted value with quotes or <, a slash between attributes, a tag that never ends)',
        'duplicate' => 'an attribute written twice',
        'after'     => 'content after the end of the document',
        'deep'      => 'markup nested too deeply or too large to check',
    ];

    /** Elements without an end tag. */
    private const VOID = [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr', 'param',
        'keygen', 'frame', 'basefont', 'bgsound', 'image' ];

    /** Elements whose end tag may be left out (a browser and libxml2 close them alike). */
    private const IMPLIED = [ 'p', 'li', 'dt', 'dd', 'tr', 'td', 'th', 'tbody', 'thead', 'tfoot', 'option', 'optgroup', 'colgroup', 'caption',
        'rt', 'rp', 'rb', 'rtc' ];

    /** Elements whose content is raw text (or escapable raw text) in a browser. */
    private const RAW_TEXT = [ 'script', 'style', 'textarea', 'title', 'iframe' ];

    /** Present = reported (not modelled). */
    private const OBSOLETE = [ 'image', 'xmp', 'noembed', 'noframes', 'plaintext', 'listing', 'isindex', 'marquee', 'nextid', 'bgsound', 'keygen',
        'menuitem', 'frameset', 'frame' ];

    /** HTML start tags that break out of svg and math content in a browser (and font with color, face or size). */
    private const BREAKOUT = [ 'b', 'big', 'blockquote', 'body', 'br', 'center', 'code', 'dd', 'div', 'dl', 'dt', 'em', 'embed', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'head', 'hr', 'i', 'img', 'li', 'listing', 'menu', 'meta', 'nobr', 'ol', 'p', 'pre', 'ruby', 's', 'small', 'span', 'strong',
        'strike', 'sub', 'sup', 'table', 'tt', 'u', 'ul', 'var' ];

    /** The attributes the converter reads: a character reference in them may decode differently. */
    private const READ_ATTRS = [ 'style', 'class', 'id', 'type', 'media', 'rel' ];

    /** Formatting elements (the adoption agency handles their end tags). */
    private const FORMATTING = [ 'a', 'b', 'big', 'code', 'em', 'font', 'i', 'nobr', 's', 'small', 'strike', 'strong', 'tt', 'u' ];

    /** Start tags that may come before the body without making one. */
    private const HEAD_TAGS = [ 'html', 'head', 'title', 'meta', 'link', 'style', 'script', 'base', 'noscript', 'template', 'body' ];

    /** The HTML "special" elements a list-item start tag does not walk through (address, div and p excepted). */
    private const SPECIAL = [ 'applet', 'area', 'article', 'aside', 'base', 'basefont', 'bgsound', 'blockquote', 'body', 'br', 'button', 'caption',
        'center', 'col', 'colgroup', 'details', 'dir', 'dl', 'embed', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'frame', 'frameset',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hgroup', 'hr', 'html', 'iframe', 'img', 'input', 'keygen', 'link', 'listing', 'main',
        'marquee', 'menu', 'meta', 'nav', 'noembed', 'noframes', 'noscript', 'object', 'ol', 'param', 'plaintext', 'pre', 'script', 'search',
        'section', 'select', 'source', 'style', 'summary', 'table', 'tbody', 'td', 'template', 'textarea', 'tfoot', 'th', 'thead', 'title', 'tr',
        'track', 'ul', 'wbr', 'xmp' ];

    private const MAX_SCAN_DEPTH = 600;

    /** Attributes on one start tag: beyond this the scan reports `attrs` (libxml2's parse is quadratic in them). */
    private const MAX_SCAN_ATTRIBUTES = 200;

    /** Attributes on one start tag beyond which the INPUT is refused before libxml2 parses it (its cost grows with the square). */
    public const MAX_ATTRIBUTES = 1000;

    /**
     * libxml2 2.9's HTML 4 start-tag auto-close (htmlAutoClose) against the HTML5 parser, pinned from the element-pair matrix of the Task 6
     * review (round 3: '<div><X><Y>' for 98 x 98 tags, libxml2 2.9.13 loadHTML vs Chromium DOMParser; the data and its re-derivation on
     * the current machine are in tests/Support/html-autoclose-matrix.php and HtmlSourceTest). Current element X => new start tag Y =>
     *   'flag'   libxml2 closes X where the browser does not (the trees differ): reported as `autoclose`;
     *   'quirks' the same, only in quirks mode (a table in an open p);
     *   'agree'  libxml2 and the browser both close X: libxml2 then looks at the next open element, and so does the scan.
     * Pairs not listed: libxml2 nests Y in X. Void elements and table parts as X are left out (they are never the current node on the scan's
     * stack, or the resolver models them), and so are html, body and head as Y. A table part Y is looked up only outside a table.
     */
    private const AUTOCLOSE = [
        'a' => [ 'a' => 'agree', 'fieldset' => 'flag', 'table' => 'flag', 'td' => 'flag', 'th' => 'flag' ],
        'address' => [ 'dd' => 'flag', 'dl' => 'flag', 'dt' => 'flag', 'form' => 'flag', 'li' => 'flag', 'ul' => 'flag' ],
        'b' => [ 'center' => 'flag', 'p' => 'flag', 'td' => 'flag', 'th' => 'flag' ],
        'big' => [ 'p' => 'flag' ],
        'dd' => [ 'dt' => 'agree' ],
        'dir' => [ 'dd' => 'flag', 'dl' => 'flag', 'dt' => 'flag', 'form' => 'flag', 'ul' => 'flag' ],
        'dl' => [ 'form' => 'flag', 'li' => 'flag' ],
        'dt' => [ 'dd' => 'agree', 'dl' => 'flag' ],
        'font' => [ 'center' => 'flag', 'td' => 'flag', 'th' => 'flag' ],
        'form' => [ 'form' => 'flag' ],
        'h1' => [ 'fieldset' => 'flag', 'form' => 'flag', 'li' => 'flag', 'p' => 'flag', 'table' => 'flag' ],
        'h2' => [ 'fieldset' => 'flag', 'form' => 'flag', 'li' => 'flag', 'p' => 'flag', 'table' => 'flag' ],
        'h3' => [ 'fieldset' => 'flag', 'form' => 'flag', 'li' => 'flag', 'p' => 'flag', 'table' => 'flag' ],
        'h4' => [ 'fieldset' => 'flag', 'form' => 'flag', 'li' => 'flag', 'p' => 'flag', 'table' => 'flag' ],
        'h5' => [ 'fieldset' => 'flag', 'form' => 'flag', 'li' => 'flag', 'p' => 'flag', 'table' => 'flag' ],
        'h6' => [ 'fieldset' => 'flag', 'form' => 'flag', 'li' => 'flag', 'p' => 'flag', 'table' => 'flag' ],
        'i' => [ 'center' => 'flag', 'p' => 'flag', 'td' => 'flag', 'th' => 'flag' ],
        'legend' => [ 'fieldset' => 'flag' ],
        'li' => [ 'li' => 'agree' ],
        'menu' => [ 'dd' => 'flag', 'dl' => 'flag', 'dt' => 'flag', 'form' => 'flag', 'ul' => 'flag' ],
        'ol' => [ 'form' => 'flag', 'ul' => 'flag' ],
        'option' => [ 'optgroup' => 'agree', 'option' => 'agree' ],
        'p' => [ 'address' => 'agree', 'blockquote' => 'agree', 'caption' => 'flag', 'center' => 'agree', 'col' => 'flag', 'colgroup' => 'flag', 'dd' => 'agree', 'dir' => 'agree', 'div' => 'agree', 'dl' => 'agree', 'dt' => 'agree', 'fieldset' => 'agree', 'form' => 'agree', 'h1' => 'agree', 'h2' => 'agree', 'h3' => 'agree', 'h4' => 'agree', 'h5' => 'agree', 'h6' => 'agree', 'hr' => 'agree', 'li' => 'agree', 'menu' => 'agree', 'ol' => 'agree', 'p' => 'agree', 'pre' => 'agree', 'table' => 'quirks', 'tbody' => 'flag', 'td' => 'flag', 'tfoot' => 'flag', 'th' => 'flag', 'tr' => 'flag', 'ul' => 'agree' ],
        'pre' => [ 'dd' => 'flag', 'dl' => 'flag', 'dt' => 'flag', 'fieldset' => 'flag', 'form' => 'flag', 'li' => 'flag', 'table' => 'flag', 'ul' => 'flag' ],
        's' => [ 'p' => 'flag' ],
        'small' => [ 'p' => 'flag' ],
        'span' => [ 'td' => 'flag', 'th' => 'flag' ],
        'strike' => [ 'p' => 'flag' ],
        'tt' => [ 'p' => 'flag' ],
        'u' => [ 'p' => 'flag', 'td' => 'flag', 'th' => 'flag' ],
        'ul' => [ 'address' => 'flag', 'form' => 'flag', 'menu' => 'flag', 'ol' => 'flag', 'pre' => 'flag' ],
    ];

    /** Foreign elements whose content libxml2 and a browser parse differently when it holds a raw-text tag (HTML integration points). */
    private const INTEGRATION = [ 'title', 'desc', 'foreignobject', 'mi', 'mo', 'mn', 'ms', 'mtext' ];

    private const TABLE_PARTS = [ 'caption', 'col', 'colgroup', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr' ];

    /** The scan's step budget (tags + attributes + raw-text checks), tied to the input cap: past it the markup is reported. */
    private const MAX_SCAN_STEPS = 2 * self::MAX_BYTES;

    private const WS = " \t\n\f\r";

    /** Removed with their content. tag => why it is not converted (plain English, shown to the owner). */
    private const DROP = [
        'script'   => 'JavaScript is never converted.',
        'style'    => 'CSS blocks are never converted as code.',
        'noscript' => 'Script fallbacks are not converted.',
        'template' => 'Templates are not converted.',
        'iframe'   => 'Embedded pages and videos are not converted. Add a video or map module in Divi.',
        'object'   => 'Embedded objects are not converted.',
        'embed'    => 'Embedded content is not converted.',
        'applet'   => 'Embedded content is not converted.',
        'frame'    => 'Frames are not converted.',
        'frameset' => 'Frames are not converted.',
        'video'    => 'Video is not converted. Add a video module in Divi.',
        'audio'    => 'Audio is not converted. Add an audio module in Divi.',
        'canvas'   => 'Canvas drawings are not converted.',
        'map'      => 'Image maps are not converted.',
        'svg'      => 'Inline SVG graphics are not converted. Upload the image to the Media Library.',
        'form'     => 'Forms are not converted. Use Divi’s contact form module or a form plugin.',
        'input'    => 'Form fields are not converted.',
        'select'   => 'Form fields are not converted.',
        'textarea' => 'Form fields are not converted.',
        'dialog'   => 'Dialogs are not converted.',
        'link'     => '',
        'meta'     => '',
        'base'     => '',
        'head'     => '',
        'title'    => '',
    ];

    /**
     * Elements that are void in HTML5 but that libxml2's older parser may treat as containers, swallowing the
     * content that follows them. Their children are moved out before the element is dropped, so no copy is lost.
     */
    private const VOID_QUIRK = [ 'embed', 'input', 'link', 'meta', 'base' ];

    /** Attributes kept per tag; everything else is dropped. `class`, `id` and `role` are read by the mapper and never emitted. */
    private const ATTRS = [
        '*'     => [ 'class', 'id', 'role' ],
        'a'     => [ 'href', 'title', 'target' ],
        'img'   => [ 'src', 'alt', 'title', 'width', 'height' ],
        'td'    => [ 'colspan', 'rowspan' ],
        'th'    => [ 'colspan', 'rowspan' ],
        'ol'    => [ 'start' ],
        'li'    => [],
    ];

    /**
     * Besides the cleaned body, these keys hand over what the cleaning removed so a style reader can use it (the `style` attribute and the
     * presentational attributes are NEVER left in the DOM):
     *   css            the text of the <style> elements of the whole document (head and body) that apply in a screen browser, in document
     *                  order, joined with "\n", read before the elements are removed, cut at MAX_CSS_BYTES + 1 bytes (see MAX_CSS_BYTES).
     *                  A <style> is SKIPPED (and written down in style_flags) when it sits inside template, noscript, textarea, title, iframe,
     *                  noembed, noframes, xmp or plaintext, has a media attribute other than empty, "all" or "screen", a type other than
     *                  empty or "text/css", or the disabled attribute. This is conservative: it can cost a style, never carry a false one.
     *                  A <style> inside svg, form, object, video or canvas fallback content is kept (it applies).
     *   inline         DOMNode::getNodePath() => the text of the style attribute, for <html>, <body> and every kept element
     *   presentational DOMNode::getNodePath() => the names (see PRESENTATIONAL) of the presentational attributes it had
     *   external_css   int, the number of <link rel=stylesheet> elements (any href; "alternate" ones and inert ones do not count): the converter
     *                  never fetches them, so colours and fonts may differ
     *   style_flags    list of flagged entries (type, reason, path) about CSS that was not read: the skipped <style> elements and, when
     *                  external_css > 0, one entry for the linked stylesheets. Kept apart from `flagged` so the styles=none report
     *                  stays byte-identical to phase 1; the styles=basic report merges them.
     *   css_media      the text of the <style> elements skipped ONLY because of a media attribute that may match a screen (anything but a
     *                  list of print/speech types, for example "(min-width:768px)"), same order, joining and cut as `css`. They apply at some
     *                  window widths, so a reader must treat every rule in them as a poison of unknown rank, never as a value.
     *   doctype        true only when the HTML starts (after whitespace and an optional byte order mark) with <!DOCTYPE html>: the page is
     *                  then in standards mode. Without it a browser may be in quirks mode, where class and id selectors match ASCII
     *                  case-insensitively and tables do not inherit fonts. Any other start (a legacy doctype, a comment first) is false,
     *                  which is the conservative answer.
     *   color_scheme   true when a <meta name="color-scheme"> asks for anything but light: the default canvas and text colours are then
     *                  the visitor's choice, not white and black.
     *   cut_attrs      getNodePath() => ['class' => whole value, 'id' => whole value] for the elements whose class or id the DOM keeps cut
     *                  at 300 characters (phase 1 behaviour, unchanged), so a selector reader can match the classes beyond the cut.
     *   repaired       list of REPAIRS sentences (empty when none): a linear scan of the raw tags found markup a browser repairs in a way
     *                  libxml2 does not (tags that close out of order, a formatting end tag over a block, a button in a button, a nobr in a
     *                  nobr, a link in a link, a list item closed through a div, a second body/html tag with attributes, a declarative shadow
     *                  root, a popover, a style inside math). It errs on the side of reporting: a style reader must then carry nothing.
     * The paths are computed after every removal and move, so they are the paths of the returned DOM. An element that was removed (or
     * sits inside a removed one) hands over nothing.
     *
     * Limit for a selector reader: the DOM keeps only class, id, role and a few per-tag attributes, and dropped elements (script, form, svg ...)
     * are removed, so attribute selectors and structural selectors (:nth-child, :first-child, sibling combinators) would be evaluated on a pruned
     * tree. A reader must treat them as unreadable (poison), not evaluate them.
     *
     * @return array{error:?string, body:?\DOMElement, flagged:list<array{type:string,reason:string,path:string}>, title:string, h1s:list<string>, stats:array<string,int>, css:string, inline:array<string,string>, presentational:array<string,list<string>>, external_css:int, style_flags:list<array{type:string,reason:string,path:string}>, css_media:string, doctype:bool, color_scheme:bool, cut_attrs:array<string,array<string,string>>, repaired:list<string>}
     */
    public static function parse( string $html ): array
    {
        $out = [ 'error' => null, 'body' => null, 'flagged' => [], 'title' => '', 'h1s' => [], 'stats' => [ 'elements' => 0, 'removed' => 0, 'urls_dropped' => 0 ], 'css' => '', 'inline' => [], 'presentational' => [], 'external_css' => 0, 'style_flags' => [], 'css_media' => '', 'doctype' => false, 'color_scheme' => false, 'cut_attrs' => [], 'repaired' => [] ];

        if ( '' === trim( $html ) ) {
            $out['error'] = 'The HTML is empty.';

            return $out;
        }
        if ( strlen( $html ) > self::MAX_BYTES ) {
            $out['error'] = 'The HTML is larger than ' . self::MAX_BYTES . ' bytes. Convert it in parts.';

            return $out;
        }
        if ( ! mb_check_encoding( $html, 'UTF-8' ) ) {
            $out['error'] = 'The HTML is not valid UTF-8. Save it as UTF-8 and try again.';

            return $out;
        }
        if ( self::maxAttributes( $html ) > self::MAX_ATTRIBUTES ) {
            $out['error'] = 'A tag in the HTML has more than ' . self::MAX_ATTRIBUTES . ' attributes. Remove them or convert the page in parts.';

            return $out;
        }
        // Read on the HTML as given (a browser keeps control characters), before they are removed for libxml2.
        $standards = self::standards( $html );
        $repaired  = self::repairs( $html, LIBXML_VERSION );
        // NUL bytes and other control characters have no place in a page; drop them before parsing.
        $html = (string) preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $html );

        $doc  = new \DOMDocument();
        $prev = libxml_use_internal_errors( true );
        // The xml declaration makes libxml read the string as UTF-8. NONET: nothing is ever fetched.
        $loaded = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        if ( ! $loaded ) {
            $out['error'] = 'The HTML could not be read.';

            return $out;
        }

        $total = $doc->getElementsByTagName( '*' )->length;
        if ( $total > self::MAX_ELEMENTS ) {
            $out['error'] = 'The HTML has more than ' . self::MAX_ELEMENTS . ' elements. Convert it in parts.';

            return $out;
        }
        $body = $doc->getElementsByTagName( 'body' )->item( 0 );
        if ( ! $body instanceof \DOMElement ) {
            $out['error'] = 'The HTML has no content.';

            return $out;
        }
        if ( self::depth( $body ) > self::MAX_DEPTH ) {
            $out['error'] = 'The HTML is nested deeper than ' . self::MAX_DEPTH . ' levels, which is almost always a sign of generated markup. Simplify it or convert it in parts.';

            return $out;
        }

        // The suggested title: <title>, else the first <h1>. Only suggested, never silently used.
        $titleNode = $doc->getElementsByTagName( 'title' )->item( 0 );
        $out['title'] = $titleNode instanceof \DOMNode ? self::collapse( $titleNode->textContent ) : '';

        self::collectCss( $doc, $out );
        $out['repaired'] = $repaired;
        $out['doctype']      = $standards;
        $out['color_scheme'] = self::darkCapable( $doc );

        // Style and presentational attributes are read from the node BEFORE they are removed; the paths are taken at the end.
        $handOver = [];
        $cut      = [];
        $root     = $doc->documentElement;
        if ( $root instanceof \DOMElement && $root !== $body ) {
            self::takeStyleAttributes( $root, $handOver );
        }
        self::takeStyleAttributes( $body, $handOver );
        self::cleanAttributes( $body, $out, $cut );
        self::clean( $body, $out, $handOver, $cut );
        foreach ( $cut as [ $el, $name, $whole ] ) {
            $path = ( $el === $body || self::isInside( $el, $body ) ) ? $el->getNodePath() : null;
            if ( null !== $path ) {
                $out['cut_attrs'][ $path ][ $name ] = $whole;
            }
        }
        foreach ( $handOver as [ $el, $style, $presentational ] ) {
            if ( $el !== $root && $el !== $body && ! self::isInside( $el, $body ) ) {
                continue;
            }
            $path = $el->getNodePath();
            if ( null === $path ) {
                continue;
            }
            if ( '' !== $style ) {
                $out['inline'][ $path ] = $style;
            }
            if ( array() !== $presentational ) {
                $out['presentational'][ $path ] = $presentational;
            }
        }
        foreach ( iterator_to_array( $body->getElementsByTagName( 'h1' ) ) as $h1 ) {
            $out['h1s'][] = self::collapse( $h1->textContent );
        }
        if ( '' === $out['title'] && array() !== $out['h1s'] ) {
            $out['title'] = $out['h1s'][0];
        }
        $out['title']             = function_exists( 'mb_substr' ) ? mb_substr( $out['title'], 0, 200 ) : substr( $out['title'], 0, 200 );
        $out['body']              = $body;
        $out['stats']['elements'] = $body->getElementsByTagName( '*' )->length;

        return $out;
    }

    /** The URL if it is safe to keep (http, https, mailto, tel, anchors, relative paths), else null. */
    public static function safeUrl( string $url ): ?string
    {
        $url = trim( (string) preg_replace( '/[\x00-\x20\x7f]+/', '', $url ) );
        if ( '' === $url || strlen( $url ) > 2000 ) {
            return null;
        }
        if ( 1 === preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $url, $m ) ) {
            return in_array( strtolower( $m[1] ), [ 'http', 'https', 'mailto', 'tel' ], true ) ? $url : null;
        }
        // No scheme: a relative path, an anchor or a query. Protocol-relative and backslash forms are not kept.
        if ( str_starts_with( $url, '//' ) || str_contains( $url, '\\' ) ) {
            return null;
        }

        return $url;
    }

    /** Whitespace collapsed to single spaces, trimmed. */
    public static function collapse( string $s ): string
    {
        return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
    }

    /** A readable position such as "section[2] > div[1] > h3[1]" for the report. */
    public static function path( \DOMNode $node ): string
    {
        $parts = [];
        while ( $node instanceof \DOMElement && 'body' !== strtolower( $node->tagName ) ) {
            $index = 1;
            for ( $sib = $node->previousSibling; null !== $sib; $sib = $sib->previousSibling ) {
                if ( $sib instanceof \DOMElement && $sib->tagName === $node->tagName ) {
                    ++$index;
                }
            }
            array_unshift( $parts, strtolower( $node->tagName ) . '[' . $index . ']' );
            $node = $node->parentNode;
        }

        return array() === $parts ? 'body' : implode( ' > ', array_slice( $parts, -4 ) );
    }

    /**
     * Fills $out['css'], $out['external_css'] and $out['style_flags'] (see parse()).
     *
     * @param array<string,mixed> $out
     */
    private static function collectCss( \DOMDocument $doc, array &$out ): void
    {
        $parts = [];
        $bytes = 0;
        $media = [];
        $mediaBytes = 0;
        foreach ( iterator_to_array( $doc->getElementsByTagName( 'style' ) ) as $style ) {
            $why = self::whyStyleIsSkipped( $style );
            if ( null !== $why ) {
                if ( count( $out['style_flags'] ) < 100 ) {
                    $out['style_flags'][] = [ 'type' => 'style', 'reason' => 'A CSS block was not read: ' . $why . '.', 'path' => self::path( $style ) ];
                }
                $text = trim( $style->textContent );
                if ( self::forSomeScreens( $style ) && '' !== $text && $mediaBytes <= self::MAX_CSS_BYTES ) {
                    $media[]     = $text;
                    $mediaBytes += strlen( $text ) + 1;
                }
                continue;
            }
            if ( $bytes > self::MAX_CSS_BYTES ) {
                continue; // already over the cap: keep scanning only so every skipped block is still written down
            }
            $text = trim( $style->textContent );
            if ( '' === $text ) {
                continue;
            }
            $parts[] = $text;
            $bytes  += strlen( $text ) + 1;
        }
        $css = implode( "\n", $parts );
        $out['css'] = strlen( $css ) > self::MAX_CSS_BYTES + 1 ? substr( $css, 0, self::MAX_CSS_BYTES + 1 ) : $css;
        $css = implode( "\n", $media );
        $out['css_media'] = strlen( $css ) > self::MAX_CSS_BYTES + 1 ? substr( $css, 0, self::MAX_CSS_BYTES + 1 ) : $css;

        $links = 0;
        $first = null;
        foreach ( iterator_to_array( $doc->getElementsByTagName( 'link' ) ) as $link ) {
            $rel = preg_split( '/\s+/', strtolower( trim( $link->getAttribute( 'rel' ) ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
            if ( in_array( 'stylesheet', $rel, true ) && ! in_array( 'alternate', $rel, true ) && null === self::inertAncestor( $link ) ) {
                ++$links;
                $first ??= $link;
            }
        }
        $out['external_css'] = $links;
        if ( $links > 0 && $first instanceof \DOMElement ) {
            $out['style_flags'][] = [
                'type'   => 'link',
                'reason' => 1 === $links ? '1 linked stylesheet was not read: colours and fonts may differ.' : $links . ' linked stylesheets were not read: colours and fonts may differ.',
                'path'   => self::path( $first ),
            ];
        }
    }

    /**
     * A <style> that is skipped only because of its media attribute, and whose media list may match a screen: every item that is not
     * exactly a print or speech type (with an optional "only") might, so it is handed over as css_media.
     */
    private static function forSomeScreens( \DOMElement $style ): bool
    {
        if ( null !== self::inertAncestor( $style ) ) {
            return false;
        }
        $type = strtolower( $style->getAttribute( 'type' ) );
        if ( '' !== $type && 'text/css' !== $type ) {
            return false;
        }
        foreach ( explode( ',', strtolower( $style->getAttribute( 'media' ) ) ) as $item ) {
            if ( 1 !== preg_match( '/^[\t\n\f\r ]*(?:only[\t\n\f\r ]+)?(?:print|speech)[\t\n\f\r ]*$/', $item ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The repairs scan (see `repaired` in parse()), public as a seam for tests. ONE forward pass over the raw bytes, an explicit state
     * machine: data, tag open (name, attributes with quoting), comment, raw text (script, style, textarea, title, iframe) and foreign content
     * (svg/math depth) in the same pass. Every forward search starts where the scan is and the scan jumps past what it found, so the work is
     * O(n) in the input bytes; a step budget (MAX_SCAN_STEPS) bounds it anyway. FAIL CLOSED: the first construct it does not model is
     * reported and the scan stops; it never throws and returns only REPAIRS sentences.
     *
     * @param int $libxml the libxml2 version (LIBXML_VERSION; a parameter so tests can inject one)
     * @return list<string>
     */
    public static function repairs( string $html, int $libxml = LIBXML_VERSION ): array
    {
        if ( $libxml > self::LIBXML_MAX_TESTED ) {
            $key = 'parser';
        } elseif ( 1 === preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $html ) ) {
            $key = 'control'; // parse() removes them, a browser keeps them (a vertical tab first even changes the mode)
        } else {
            $key = self::scan( $html, self::standards( $html ) );
        }

        return null === $key ? [] : [ self::REPAIRS[ $key ] ];
    }

    /**
     * The largest number of attributes on one start tag (a linear count: names outside quoted values, up to each tag's end). parse() refuses
     * an input beyond MAX_ATTRIBUTES before libxml2 reads it.
     */
    private static function maxAttributes( string $h ): int
    {
        $n   = strlen( $h );
        $max = 0;
        $i   = 0;
        while ( false !== ( $lt = strpos( $h, '<', $i ) ) ) {
            $p = $lt + 1;
            if ( $p >= $n || ! ctype_alpha( $h[ $p ] ) ) {
                $i = $p;
                continue;
            }
            $p    += strcspn( $h, self::WS . '/>', $p );
            $count = 0;
            while ( $p < $n && '>' !== $h[ $p ] ) {
                $p += strspn( $h, self::WS . '/', $p );
                if ( $p >= $n || '>' === $h[ $p ] ) {
                    break;
                }
                ++$count;
                $p += max( 1, strcspn( $h, self::WS . '/>=', $p ) );
                $p += strspn( $h, self::WS, $p );
                if ( $p < $n && '=' === $h[ $p ] ) {
                    ++$p;
                    $p += strspn( $h, self::WS, $p );
                    if ( $p < $n && ( '"' === $h[ $p ] || "'" === $h[ $p ] ) ) {
                        $e = strpos( $h, $h[ $p ], $p + 1 );
                        $p = false === $e ? $n : $e + 1;
                    } else {
                        $p += strcspn( $h, self::WS . '>', $p );
                    }
                }
            }
            $max = max( $max, $count );
            $i   = min( $n, $p + 1 );
        }

        return $max;
    }

    /** Standards mode: <!DOCTYPE html> first, after an optional byte order mark and white space (nothing else is trusted). */
    public static function standards( string $html ): bool
    {
        return 1 === preg_match( '/\A(?:\xEF\xBB\xBF)?[\t\n\f\r ]*<!doctype[\t\n\f\r ]+html[\t\n\f\r ]*>/i', $html );
    }

    /** @return ?string the REPAIRS key of the first construct that is not modelled, or null */
    private static function scan( string $h, bool $standards ): ?string
    {
        $n = strlen( $h );
        if ( $n > self::MAX_BYTES ) {
            return 'deep';
        }
        $lh       = strtolower( $h ); // ASCII lower case, for tag and attribute names
        $i        = 0 === strncmp( $h, "\xEF\xBB\xBF", 3 ) ? 3 : 0; // a byte order mark is not content (both parsers skip it)
        $noscript = null;              // where a browser ends the open noscript (it is raw text with scripting on)
        $steps   = 0;
        $stack   = [];
        $open    = [];
        $foreign = []; // the open svg/math elements (empty: HTML content)
        $content = false;
        $sawHtml = false;
        $sawBody = false;
        $ended   = false;
        while ( $i < $n ) {
            if ( ++$steps > self::MAX_SCAN_STEPS ) {
                return 'deep';
            }
            $lt   = strpos( $h, '<', $i );
            $stop = false === $lt ? $n : $lt;
            if ( $stop > $i && strspn( $h, self::WS, $i, $stop - $i ) < $stop - $i ) {
                if ( $ended ) {
                    return 'after';
                }
                $content = true;
            }
            if ( false === $lt ) {
                break;
            }
            $c = $lt + 1 < $n ? $h[ $lt + 1 ] : '';
            if ( '!' === $c ) {
                if ( 0 === substr_compare( $h, '<!--', $lt, 4 ) ) {
                    $j = $lt + 4;
                    if ( ( $j < $n && '>' === $h[ $j ] ) || 0 === substr_compare( $h, '->', $j, 2 ) ) {
                        return 'comment'; // <!--> and <!---> end at once in a browser
                    }
                    $e = strpos( $h, '-->', $j );
                    if ( false === $e ) {
                        return 'comment';
                    }
                    if ( false !== strpos( substr( $h, $j, $e - $j ), '--!>' ) ) {
                        return 'comment'; // --!> ends a comment in a browser
                    }
                    $i = $e + 3;
                    continue;
                }
                if ( 0 === substr_compare( $lh, '<!doctype', $lt, 9 ) ) {
                    if ( [] !== $stack || $content ) {
                        return 'doctype';
                    }
                    $e = strpos( $h, '>', $lt );
                    if ( false === $e ) {
                        return 'tag';
                    }
                    $i = $e + 1;
                    continue;
                }
                return 'bogus'; // CDATA or a bogus comment
            }
            if ( '?' === $c ) {
                $e = strpos( $h, '>', $lt );
                if ( false === $e || '?' !== $h[ $e - 1 ] ) {
                    return 'bogus'; // a browser ends a processing instruction at the first >, libxml2 at the question mark and >
                }
                $i = $e + 1;
                continue;
            }
            $end = '/' === $c;
            $j   = $lt + ( $end ? 2 : 1 );
            if ( $j >= $n || ! ctype_alpha( $h[ $j ] ) ) {
                if ( $end ) {
                    return 'bogus'; // </> or a bogus comment
                }
                if ( $ended ) {
                    return 'after';
                }
                $content = true;
                $i       = $lt + 1;
                continue;
            }
            $tag = self::tag( $h, $lh, $j, $n, $steps );
            if ( is_string( $tag ) ) {
                return $tag;
            }
            [ $i, $name, $attrs, $selfClose ] = $tag;

            if ( $end ) {
                if ( $selfClose ) {
                    return 'tag';
                }
                if ( 'noscript' === $name && [] === $foreign ) {
                    if ( $noscript !== $lt ) {
                        return 'noscript'; // the browser ended it somewhere else (inside an attribute, a comment ...)
                    }
                    $noscript = null;
                }
                if ( [] !== $foreign ) {
                    if ( end( $foreign ) !== $name ) {
                        return 'foreign';
                    }
                    array_pop( $foreign );
                    continue;
                }
                if ( 'html' === $name ) {
                    $ended = true;
                    continue;
                }
                if ( 'body' === $name || 'head' === $name ) {
                    continue;
                }
                if ( end( $stack ) === $name ) {
                    --$open[ array_pop( $stack ) ];
                    continue;
                }
                if ( in_array( $name, self::FORMATTING, true ) || ( $open[ $name ] ?? 0 ) < 1 ) {
                    return 'misnest';
                }
                while ( [] !== $stack && end( $stack ) !== $name && in_array( end( $stack ), self::IMPLIED, true ) ) {
                    --$open[ array_pop( $stack ) ];
                }
                if ( end( $stack ) !== $name ) {
                    return 'misnest';
                }
                --$open[ array_pop( $stack ) ];
                continue;
            }

            // A start tag.
            if ( $ended ) {
                return 'after';
            }
            if ( isset( $attrs['shadowrootmode'] ) ) {
                return 'shadow';
            }
            if ( isset( $attrs['popover'] ) ) {
                return 'popover';
            }
            if ( [] !== $foreign ) {
                if ( in_array( $name, self::BREAKOUT, true ) || ( 'font' === $name && ( isset( $attrs['color'] ) || isset( $attrs['face'] ) || isset( $attrs['size'] ) ) ) ) {
                    return 'foreign';
                }
                if ( 'style' === $name && in_array( 'math', $foreign, true ) ) {
                    return 'math';
                }
                if ( in_array( $name, self::RAW_TEXT, true ) && in_array( end( $foreign ), self::INTEGRATION, true ) ) {
                    return 'foreign'; // HTML inside an integration point: libxml2 and a browser read the raw text differently
                }
                if ( ! $selfClose ) {
                    $foreign[] = $name;
                    if ( count( $foreign ) > self::MAX_SCAN_DEPTH ) {
                        return 'deep';
                    }
                }
                continue;
            }
            if ( in_array( $name, self::OBSOLETE, true ) ) {
                return 'obsolete';
            }
            if ( 'dialog' === $name ) {
                return 'dialog'; // removed before the style reader sees it, but it closes an open p in a browser
            }
            if ( 'noscript' === $name ) {
                if ( null !== $noscript ) {
                    return 'noscript';
                }
                $e        = strpos( $lh, '</noscript', $i );
                $noscript = false === $e ? -1 : $e;
                if ( -1 === $noscript ) {
                    return 'noscript';
                }
            }
            if ( $selfClose && ! in_array( $name, self::VOID, true ) && 'svg' !== $name && 'math' !== $name ) {
                return 'selfclose'; // a browser ignores the slash, libxml2 closes the element
            }
            if ( 'svg' === $name || 'math' === $name ) {
                $content = true;
                if ( ! $selfClose ) {
                    $foreign = [ $name ];
                }
                continue;
            }
            if ( 'html' === $name ) {
                if ( ( $sawHtml || $content || [] !== $stack ) && [] !== $attrs ) {
                    return 'twice';
                }
                $sawHtml = true;
                continue;
            }
            if ( 'body' === $name ) {
                if ( ( $sawBody || $content ) && [] !== $attrs ) {
                    return 'twice';
                }
                $sawBody = true;
                $content = true;
                continue;
            }
            if ( 'head' === $name ) {
                if ( $content || $sawBody ) {
                    return 'head';
                }
                continue;
            }
            if ( ! in_array( $name, self::HEAD_TAGS, true ) ) {
                $content = true;
            }
            if ( in_array( $name, self::RAW_TEXT, true ) ) {
                // The text runs to </name followed by a space, / or >. libxml2 2.9 may end it at any </letter, and a browser treats
                // <!-- in a script specially: either is reported.
                $e = strpos( $lh, '</' . $name, $i );
                if ( false === $e ) {
                    return 'rawtext';
                }
                $after = $e + 2 + strlen( $name );
                if ( $after >= $n || false === strpos( self::WS . '/>', $h[ $after ] ) ) {
                    return 'rawtext';
                }
                $text = substr( $h, $i, $e - $i );
                if ( 1 === preg_match( '#</[A-Za-z]#', $text ) || ( 'script' === $name && false !== strpos( $text, '<!--' ) ) ) {
                    return 'rawtext';
                }
                $steps += 1 + intdiv( $e - $i, 64 );
                $close = strpos( $h, '>', $after );
                if ( false === $close ) {
                    return 'rawtext';
                }
                $i = $close + 1;
                continue;
            }
            if ( in_array( $name, self::VOID, true ) ) {
                continue;
            }
            if ( ( 'button' === $name || 'nobr' === $name || 'a' === $name ) && ( $open[ $name ] ?? 0 ) > 0 ) {
                return $name;
            }
            // A new cell in a table: the browser closes the open cell and what is open in it; libxml2 closes the same elements when
            // each of them auto-closes for the cell (AUTOCLOSE), so the scan does too. Otherwise the stack is left alone.
            if ( ( 'td' === $name || 'th' === $name ) && ( $open['table'] ?? 0 ) > 0 ) {
                for ( $x = count( $stack ) - 1; $x >= 0; $x-- ) {
                    $t = $stack[ $x ];
                    if ( 'td' === $t || 'th' === $t ) {
                        while ( count( $stack ) > $x ) {
                            --$open[ array_pop( $stack ) ];
                        }
                        break;
                    }
                    if ( ! isset( self::AUTOCLOSE[ $t ][ $name ] ) ) {
                        break;
                    }
                }
            }
            // libxml2's HTML 4 auto-close: it closes the CURRENT node for some new tags, then looks at the next one (AUTOCLOSE).
            if ( ! in_array( $name, self::TABLE_PARTS, true ) || ( $open['table'] ?? 0 ) < 1 ) {
                for ( $x = count( $stack ) - 1; $x >= 0; $x-- ) {
                    $rel = self::AUTOCLOSE[ $stack[ $x ] ][ $name ] ?? null;
                    if ( null === $rel ) {
                        break;
                    }
                    if ( 'flag' === $rel || ( 'quirks' === $rel && ! $standards ) ) {
                        return 'autoclose';
                    }
                }
            }
            if ( in_array( $name, [ 'li', 'dd', 'dt' ], true ) ) {
                $match = 'li' === $name ? [ 'li' ] : [ 'dd', 'dt' ];
                for ( $x = count( $stack ) - 1, $walk = 0; $x >= 0; $x--, $walk++ ) {
                    if ( $walk >= 64 ) {
                        return 'deep';
                    }
                    $t = $stack[ $x ];
                    if ( in_array( $t, $match, true ) ) {
                        for ( $y = count( $stack ) - 1; $y > $x; $y-- ) {
                            if ( 'p' !== $stack[ $y ] ) {
                                return 'list'; // the browser closes the item through what is open inside it
                            }
                        }
                        while ( count( $stack ) > $x ) {
                            --$open[ array_pop( $stack ) ];
                        }
                        break;
                    }
                    if ( in_array( $t, self::SPECIAL, true ) && ! in_array( $t, [ 'address', 'div', 'p' ], true ) ) {
                        break;
                    }
                }
            } elseif ( in_array( $name, self::IMPLIED, true ) && end( $stack ) === $name ) {
                --$open[ array_pop( $stack ) ]; // a sibling closes the previous one (p, option, td ...)
            }
            $stack[]       = $name;
            $open[ $name ] = ( $open[ $name ] ?? 0 ) + 1;
            if ( count( $stack ) > self::MAX_SCAN_DEPTH ) {
                return 'deep';
            }
        }

        if ( null !== $noscript ) {
            return 'noscript'; // the browser's end of the noscript was never reached by the scan
        }

        return null;
    }

    /**
     * One tag from its name at $j: [index after it, lower-case name, attribute set, self-closing], or the REPAIRS key of what the scan
     * does not model in it (an odd name, an attribute written twice, an unusual attribute, a character reference in a read attribute).
     *
     * @return array{0:int,1:string,2:array<string,true>,3:bool}|string
     */
    private static function tag( string $h, string $lh, int $j, int $n, int &$steps )
    {
        $k    = $j + strcspn( $h, self::WS . '/>', $j );
        $name = substr( $lh, $j, $k - $j );
        if ( 1 !== preg_match( '/^[a-z][a-z0-9-]*$/D', $name ) ) {
            return 'tag';
        }
        $attrs = [];
        $p     = $k;
        while ( true ) {
            if ( ++$steps > self::MAX_SCAN_STEPS ) {
                return 'deep';
            }
            $p += strspn( $h, self::WS, $p );
            if ( $p >= $n ) {
                return 'tag'; // the tag never ends
            }
            $ch = $h[ $p ];
            if ( '>' === $ch ) {
                return [ $p + 1, $name, $attrs, false ];
            }
            if ( '/' === $ch ) {
                if ( $p + 1 < $n && '>' === $h[ $p + 1 ] ) {
                    return [ $p + 2, $name, $attrs, true ];
                }
                return 'tag'; // a slash between attributes
            }
            $q     = $p + strcspn( $h, self::WS . '/>=', $p );
            $aname = substr( $lh, $p, $q - $p );
            if ( 1 !== preg_match( '/^[a-z_][a-z0-9_.:-]*$/D', $aname ) ) {
                return 'tag';
            }
            if ( isset( $attrs[ $aname ] ) ) {
                return 'duplicate';
            }
            $attrs[ $aname ] = true;
            if ( count( $attrs ) > self::MAX_SCAN_ATTRIBUTES ) {
                return 'attrs';
            }
            $p               = $q + strspn( $h, self::WS, $q );
            if ( $p < $n && '=' === $h[ $p ] ) {
                ++$p;
                $p += strspn( $h, self::WS, $p );
                if ( $p >= $n ) {
                    return 'tag';
                }
                if ( '"' === $h[ $p ] || "'" === $h[ $p ] ) {
                    $e = strpos( $h, $h[ $p ], $p + 1 );
                    if ( false === $e ) {
                        return 'tag';
                    }
                    $value = substr( $h, $p + 1, $e - $p - 1 );
                    $p     = $e + 1;
                    if ( $p < $n && false === strpos( self::WS . '/>', $h[ $p ] ) ) {
                        return 'tag'; // no space between attributes
                    }
                } else {
                    $q     = $p + strcspn( $h, self::WS . '>', $p );
                    $value = substr( $h, $p, $q - $p );
                    $p     = $q;
                    if ( false !== strpbrk( $value, "\"'<=`" ) ) {
                        return 'tag';
                    }
                }
                if ( in_array( $aname, self::READ_ATTRS, true ) && false !== strpos( $value, '&' ) && 1 === preg_match( '/&(?!(?:amp|lt|gt|quot|apos|nbsp);)/', $value ) ) {
                    return 'entity'; // libxml2 2.9 does not know the HTML5 names (&colon; &sol; ...); numeric ones are reported too
                }
            }
        }
    }

    /** A <meta name="color-scheme"> (anywhere that is not inert) that allows anything but light. */
    private static function darkCapable( \DOMDocument $doc ): bool
    {
        foreach ( iterator_to_array( $doc->getElementsByTagName( 'meta' ) ) as $meta ) {
            if ( null !== self::inertAncestor( $meta ) || 'color-scheme' !== strtolower( trim( $meta->getAttribute( 'name' ) ) ) ) {
                continue;
            }
            $content = strtolower( trim( (string) preg_replace( '/[\t\n\f\r ]+/', ' ', $meta->getAttribute( 'content' ) ) ) );
            if ( ! in_array( $content, [ '', 'normal', 'light', 'only light' ], true ) ) {
                return true;
            }
        }

        return false;
    }

    /** Plain-English reason a <style> element is not read, or null when it is. */
    private static function whyStyleIsSkipped( \DOMElement $style ): ?string
    {
        $inert = self::inertAncestor( $style );
        if ( null !== $inert ) {
            return 'it sits inside <' . $inert . '>, which a browser does not apply';
        }
        $media = strtolower( trim( $style->getAttribute( 'media' ) ) );
        if ( ! in_array( $media, [ '', 'all', 'screen' ], true ) ) {
            return 'its media attribute limits it to some devices or window sizes';
        }
        // Not trimmed: a browser applies "" and "text/css" in any case, and not " text/css" (measured in Chromium).
        $type = strtolower( $style->getAttribute( 'type' ) );
        if ( '' !== $type && 'text/css' !== $type ) {
            return 'it is not plain CSS';
        }
        // The disabled attribute does nothing on <style> (measured in Chromium: the block applies), so it is read.

        return null;
    }

    /** The tag name of the nearest ancestor that makes a <style>/<link> inert, or null. */
    private static function inertAncestor( \DOMNode $node ): ?string
    {
        for ( $p = $node->parentNode; $p instanceof \DOMElement; $p = $p->parentNode ) {
            $tag = strtolower( $p->tagName );
            if ( in_array( $tag, self::INERT_ANCESTORS, true ) ) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * Writes down an element's style attribute text and presentational attribute names, in place of removing them unseen.
     *
     * @param list<array{0:\DOMElement,1:string,2:list<string>}> $handOver
     */
    private static function takeStyleAttributes( \DOMElement $el, array &$handOver ): void
    {
        $style = $el->hasAttribute( 'style' ) ? trim( $el->getAttribute( 'style' ) ) : '';
        $names = [];
        foreach ( self::PRESENTATIONAL as $name ) {
            if ( $el->hasAttribute( $name ) ) {
                $names[] = $name;
            }
        }
        if ( '' !== $style || array() !== $names ) {
            $handOver[] = [ $el, $style, $names ];
        }
    }

    private static function isInside( \DOMNode $node, \DOMElement $root ): bool
    {
        for ( $p = $node->parentNode; null !== $p; $p = $p->parentNode ) {
            if ( $p === $root ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed>                                 $out
     * @param list<array{0:\DOMElement,1:string,2:list<string>}> $handOver
     * @param list<array{0:\DOMElement,1:string,2:string}>        $cut
     */
    private static function clean( \DOMElement $body, array &$out, array &$handOver, array &$cut ): void
    {
        // Collect first, change after: removing nodes while walking skips siblings.
        $remove  = [];
        $elements = [];
        $stack   = [ $body ];
        while ( array() !== $stack ) {
            $node = array_pop( $stack );
            foreach ( iterator_to_array( $node->childNodes ) as $child ) {
                if ( $child instanceof \DOMElement ) {
                    $tag = strtolower( $child->tagName );
                    if ( isset( self::DROP[ $tag ] ) ) {
                        $remove[] = $child;
                        if ( in_array( $tag, self::VOID_QUIRK, true ) ) {
                            $stack[] = $child; // what it swallowed is moved out later, so it must be cleaned too
                        }
                        continue;
                    }
                    $elements[] = $child;
                    $stack[]    = $child;
                } elseif ( ! $child instanceof \DOMText ) {
                    $remove[] = $child; // comments, processing instructions, CDATA
                }
            }
        }

        foreach ( $remove as $node ) {
            if ( $node instanceof \DOMElement ) {
                $reason = self::DROP[ strtolower( $node->tagName ) ];
                if ( '' !== $reason && count( $out['flagged'] ) < 100 ) {
                    $out['flagged'][] = [ 'type' => strtolower( $node->tagName ), 'reason' => $reason, 'path' => self::path( $node ) ];
                }
                ++$out['stats']['removed'];
            }
            if ( null !== $node->parentNode ) {
                if ( $node instanceof \DOMElement && in_array( strtolower( $node->tagName ), self::VOID_QUIRK, true ) ) {
                    while ( null !== $node->firstChild ) {
                        $node->parentNode->insertBefore( $node->firstChild, $node );
                    }
                }
                $node->parentNode->removeChild( $node );
            }
        }

        foreach ( $elements as $el ) {
            if ( null === $el->parentNode ) {
                continue; // inside something that was removed
            }
            self::takeStyleAttributes( $el, $handOver );
            self::cleanAttributes( $el, $out, $cut );
        }
    }

    /**
     * The style attributes must have been read (takeStyleAttributes) before this removes them.
     *
     * @param array<string,mixed>                          $out
     * @param list<array{0:\DOMElement,1:string,2:string}> $cut receives the whole class or id value before it is cut
     */
    private static function cleanAttributes( \DOMElement $el, array &$out, array &$cut ): void
    {
        $tag   = strtolower( $el->tagName );
        $keep  = array_merge( self::ATTRS['*'], self::ATTRS[ $tag ] ?? [] );
        $names = [];
        foreach ( iterator_to_array( $el->attributes ) as $attr ) {
            $names[] = $attr->name;
        }
        foreach ( $names as $name ) {
            if ( ! in_array( strtolower( $name ), $keep, true ) ) {
                $el->removeAttribute( $name );
            }
        }
        foreach ( [ 'href', 'src' ] as $urlAttr ) {
            if ( $el->hasAttribute( $urlAttr ) ) {
                $safe = self::safeUrl( $el->getAttribute( $urlAttr ) );
                if ( null === $safe ) {
                    $el->removeAttribute( $urlAttr );
                    ++$out['stats']['urls_dropped'];
                    if ( count( $out['flagged'] ) < 100 ) {
                        $out['flagged'][] = [ 'type' => $urlAttr, 'reason' => 'An unsafe or unsupported address (for example javascript: or data:) was removed.', 'path' => self::path( $el ) ];
                    }
                } else {
                    $el->setAttribute( $urlAttr, $safe );
                }
            }
        }
        foreach ( [ 'class', 'id', 'role' ] as $meta ) {
            if ( $el->hasAttribute( $meta ) && strlen( $el->getAttribute( $meta ) ) > 300 ) {
                if ( 'role' !== $meta ) {
                    $cut[] = [ $el, $meta, $el->getAttribute( $meta ) ];
                }
                $el->setAttribute( $meta, substr( $el->getAttribute( $meta ), 0, 300 ) );
            }
        }
    }

    private static function depth( \DOMElement $root ): int
    {
        $max   = 0;
        $stack = [ [ $root, 1 ] ];
        while ( array() !== $stack ) {
            [ $node, $d ] = array_pop( $stack );
            $max = max( $max, $d );
            if ( $max > self::MAX_DEPTH ) {
                return $max;
            }
            foreach ( $node->childNodes as $child ) {
                if ( $child instanceof \DOMElement ) {
                    $stack[] = [ $child, $d + 1 ];
                }
            }
        }

        return $max;
    }
}
