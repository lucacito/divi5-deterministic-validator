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
        'reopen'    => 'a formatting tag (such as b, i or a link) still open where a block ends a paragraph (a browser opens it again inside the block)',
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
        $html = self::browserBody( $html, $reopen );
        if ( $reopen && array() === $repaired ) {
            $repaired = [ self::REPAIRS['reopen'] ]; // the tree is the browser's except for the formatting elements it opens again
        }

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

        self::relocateHeadContent( $doc );
        self::relocateContentAfterHtml( $doc );

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

    /**
     * The HTML with the body where a browser has it, for libxml2: script and style text made inert (inertRawText()), an explicit <body>
     * where a browser starts it but libxml2 would still be in head (bodyStart()), then without the </body> and </html> end tags that
     * content follows (strayEndTags(), found on the HTML with that <body>), then with a </p> where a browser closes an open paragraph and
     * libxml2 does not (paragraphEnds()). Each step works on the result of the one before and leaves the HTML as given when it does not
     * apply. $reopen is set when a browser opens a formatting element again after such a </p> (parse() then reports `reopen`). Public so
     * a test helper can parse the same way.
     */
    public static function browserBody( string $html, ?bool &$reopen = null ): string
    {
        $html = self::inertRawText( $html );
        $at   = self::bodyStart( $html );
        if ( null !== $at ) {
            $html = substr( $html, 0, $at ) . '<body>' . substr( $html, $at );
        }
        foreach ( array_reverse( self::strayEndTags( $html ) ?? [] ) as [ $from, $length ] ) {
            $html = substr( $html, 0, $from ) . substr( $html, $from + $length );
        }
        $ends = self::paragraphEnds( $html );
        foreach ( array_reverse( $ends['ends'] ) as $at ) {
            $html = substr( $html, 0, $at ) . '</p>' . substr( $html, $at );
        }
        $reopen = $ends['reopen'];

        return $html;
    }

    /**
     * The HTML with every "</" inside script and style text written "<\/", or the HTML as given (no such text, or markup this does not
     * model). Public as a seam for tests.
     *
     * libxml2 2.9 ends script and style text at the first "</" followed by a letter; a browser ends it only at "</script" (or "</style")
     * followed by white space, "/" or ">", case-insensitive. So a script holding '</body>', '</div>' or '</html>' in a string or a comment
     * was cut short: the rest of its text became page text, and the end tag closed the elements around it (a heading after it became bold
     * text in a paragraph). "<\/" is the same value in a JavaScript or CSS string; the script is dropped and reported as before, and the
     * scan still reports the raw text (`rawtext`), so a style element with it stays unread.
     *
     * ONE forward pass in markup context (markupWalk()); it gives up (the HTML as given) on everything markupWalk() does not model, and on
     * a script whose text holds "<!--" (a browser's escaped script states).
     */
    public static function inertRawText( string $h ): string
    {
        $raw = self::markupWalk( $h );
        if ( null === $raw ) {
            return $h;
        }
        foreach ( array_reverse( $raw ) as [ $from, $to ] ) {
            $h = substr( $h, 0, $from ) . str_replace( '</', '<\/', substr( $h, $from, $to - $from ) ) . substr( $h, $to );
        }

        return $h;
    }

    /** Blocks libxml2 2.9 does not know (HTML5): a browser closes an open p at their start tag, libxml2 never does. */
    private const P_CLOSERS_NEW = [ 'article', 'aside', 'details', 'figcaption', 'figure', 'footer', 'header', 'hgroup', 'main', 'nav', 'search',
        'section', 'summary' ];

    /** Blocks libxml2 closes a p for only when the p is the current node; a browser closes it through open phrasing elements too. */
    private const P_CLOSERS_OLD = [ 'address', 'blockquote', 'center', 'dir', 'div', 'dl', 'fieldset', 'menu', 'ol', 'p', 'ul', 'h1', 'h2', 'h3', 'h4',
        'h5', 'h6', 'pre', 'hr' ];

    /**
     * The ordinary phrasing elements a browser pops when it closes a p, and libxml2 closes for a </p> (plus custom elements, any name with
     * a hyphen). Anything else between the p and the block (a button, object, select, table cell ... or an element this does not list)
     * leaves the p alone.
     */
    private const P_PHRASING = [ 'a', 'abbr', 'b', 'bdi', 'bdo', 'big', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'font', 'i', 'ins', 'kbd', 'label',
        'mark', 'nobr', 'q', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'time', 'tt', 'u', 'var' ];

    /**
     * The parse work paragraphEnds() may spend, in bytes parsed (two parses per round; one round is enough unless a block is still open
     * at the page's own </p>, and each round settles at least one candidate): 8 input caps, so a 20 KB page gets 80 rounds and a page at
     * the cap 4 (measured: about 0.4 s for 75 KB of such blocks, every one of them a round). Past it the settled ones are kept and the
     * rest of the page stays as libxml2 parses it.
     */
    private const P_WORK = 8 * self::MAX_BYTES;

    /** The text of the comments paragraphEnds() places to read libxml2's open elements; a page holding it is left alone. */
    private const P_MARK = 'aied-p-end-';

    /**
     * Where to write a </p> so that libxml2 closes an open paragraph where a browser does: byte offsets of block start tags, in document
     * order, and whether a browser then opens a formatting element again (`reopen`). Empty when nothing applies or anything is not
     * modelled (the HTML then stays as given). Public as a seam for tests.
     *
     * A browser closes a p ("close a p element": every element above it is popped) at the start tag of a block whenever a p is in button
     * scope. libxml2 2.9 (HTML 4) never does for the HTML5 blocks it does not know (section, article, nav, header, footer, aside, main,
     * figure, figcaption, details, summary, hgroup, search), and for the blocks it knows (div, h1-h6, ul, ol, blockquote ...) only when the
     * p is the current node, not through an open span or b. The block was kept inside the p: a section's heading became bold text in a
     * paragraph, and the text check could not see it.
     *
     * How (no model of libxml2's tree building): markupWalk() finds the candidate start tags in markup context; a comment is placed before
     * each one and libxml2 parses the page: a comment is added to the current node and libxml2 never moves a node, so the comment's
     * ancestors ARE libxml2's open elements at that tag. A </p> is written where, going up from there, only ordinary phrasing elements
     * (P_PHRASING) come before a p, and libxml2 kept the block inside that p (it did not close the p itself). The p and the elements above
     * it are closed from then on, so later tags do not see them. A second parse with the </p> and the comments checks that libxml2's open
     * elements at every candidate are exactly the expected ones. At the first candidate that differs (typically a </p> of the page that
     * now matches nothing, so an element it used to close stays open, as in a browser), the </p> before it are kept and the rest is decided
     * again on a parse with only those, within P_WORK; a </p> whose own effect differs is never written. A </p> adds no text and
     * libxml2 never reorders text, so every word stays where it was in document order. What still differs from a browser: a formatting
     * element (b, em, a ...) among the popped ones is opened again by a browser inside the block (`reopen`), and the page's own </p> that
     * now matches nothing is ignored by libxml2 where a browser makes an empty p of it (the scan treats a div the same way).
     *
     * @return array{ends:list<int>, reopen:bool}
     */
    public static function paragraphEnds( string $h ): array
    {
        $none  = [ 'ends' => [], 'reopen' => false ];
        $found = [];
        $seenP = false;
        $tags  = 0;
        $walk  = self::markupWalk( $h, static function ( int $at, string $name ) use ( &$found, &$seenP, &$tags ): void {
            ++$tags;
            // Before the first p start tag no p can be open.
            if ( $seenP && ( in_array( $name, self::P_CLOSERS_NEW, true ) || in_array( $name, self::P_CLOSERS_OLD, true ) ) ) {
                $found[] = [ $at, $name ];
            }
            $seenP = $seenP || 'p' === $name;
        } );
        // Script or style text holding "</" (not made inert): libxml2 would end it early, not modelled. More start tags than parse() takes
        // elements: the page is refused anyway, no parse work is spent on it.
        if ( null === $walk || array() !== $walk || array() === $found || $tags > self::MAX_ELEMENTS || false !== stripos( $h, self::P_MARK ) ) {
            return $none;
        }
        $ends   = []; // settled: at => whether a browser opens a formatting element again there
        $skip   = []; // candidate index => true: a </p> there did not do what was expected
        $from   = 0;  // the first candidate whose decision is not settled yet
        $rounds = max( 1, min( 1000, intdiv( self::P_WORK, 2 * max( 1, strlen( $h ) ) ) ) );
        for ( $round = 0; $round < $rounds; $round++ ) {
            $probe = self::markedDocument( $h, $found, array_keys( $ends ) );
            if ( null === $probe ) {
                return $none;
            }
            $closed = []; // spl_object_id => the element closed by a </p> of this round (held, so PHP keeps handing out the same object)
            $new    = []; // at => reopen
            $expect = []; // candidate index => libxml2's open elements expected there, innermost first
            for ( $k = $from, $count = count( $found ); $k < $count; $k++ ) {
                [ $at, $name ] = $found[ $k ];
                $mark  = $probe[1][ $k ];
                $chain = [];
                for ( $el = $mark->parentNode; $el instanceof \DOMElement; $el = $el->parentNode ) {
                    if ( ! isset( $closed[ spl_object_id( $el ) ] ) ) {
                        $chain[] = $el;
                    }
                }
                $block = self::elementAfter( $mark );
                if ( ! $block instanceof \DOMElement || strtolower( $block->tagName ) !== $name ) {
                    return $none;
                }
                $p       = null;
                $between = [];
                foreach ( $chain as $el ) {
                    $tag = strtolower( $el->tagName );
                    if ( 'p' === $tag ) {
                        $p = $el;
                        break;
                    }
                    if ( ! in_array( $tag, self::P_PHRASING, true ) && ! str_contains( $tag, '-' ) ) {
                        break;
                    }
                    $between[] = $el;
                }
                $names = array_map( static fn ( \DOMElement $el ): string => strtolower( $el->tagName ), $chain );
                if ( null !== $p && ! isset( $skip[ $k ] ) && self::isInside( $block, $p ) ) {
                    $reopen = false;
                    $closed[ spl_object_id( $p ) ] = $p;
                    foreach ( $between as $el ) {
                        $closed[ spl_object_id( $el ) ] = $el;
                        $reopen = $reopen || in_array( strtolower( $el->tagName ), self::FORMATTING, true );
                    }
                    $new[ $at ] = $reopen;
                    $names      = array_slice( $names, count( $between ) + 1 );
                }
                $expect[ $k ] = $names;
            }
            if ( array() === $new ) {
                break; // nothing more to write: every decision is settled
            }
            $check = self::markedDocument( $h, $found, array_merge( array_keys( $ends ), array_keys( $new ) ) );
            if ( null === $check ) {
                return $none;
            }
            $miss = null;
            foreach ( $expect as $k => $want ) {
                $names = [];
                for ( $el = $check[1][ $k ]->parentNode; $el instanceof \DOMElement; $el = $el->parentNode ) {
                    $names[] = strtolower( $el->tagName );
                }
                if ( $names !== $want ) {
                    $miss = $k;
                    break;
                }
            }
            if ( null === $miss ) {
                $ends += $new;
                break;
            }
            // Every candidate before the miss is as expected: its </p> are settled. The rest is decided again on a parse with them only
            // (a later </p> of the page may now match nothing, and libxml2's tree after it differs from this round's simulation).
            $settled = 0;
            foreach ( $new as $at => $reopen ) {
                if ( $at < $found[ $miss ][0] ) {
                    $ends[ $at ] = $reopen;
                    ++$settled;
                }
            }
            if ( 0 === $settled && isset( $new[ $found[ $miss ][0] ] ) ) {
                $skip[ $miss ] = true; // its own </p> went wrong: never written
            }
            $from = $miss;
            if ( $rounds - 1 === $round ) {
                break; // out of rounds: only the settled ones (the rest of the page stays as libxml2 parses it)
            }
        }
        if ( array() === $ends ) {
            return $none;
        }
        ksort( $ends );

        return [ 'ends' => array_keys( $ends ), 'reopen' => in_array( true, $ends, true ) ];
    }

    /** The first element after $node in document order that is not html, head or body (the element of the start tag after a mark). */
    private static function elementAfter( \DOMNode $node ): ?\DOMElement
    {
        $at = $node;
        while ( true ) {
            if ( $at !== $node && null !== $at->firstChild ) {
                $at = $at->firstChild;
            } else {
                while ( null !== $at && null === $at->nextSibling ) {
                    $at = $at->parentNode;
                }
                if ( null === $at ) {
                    return null;
                }
                $at = $at->nextSibling;
            }
            if ( $at instanceof \DOMElement && ! in_array( strtolower( $at->tagName ), [ 'html', 'head', 'body' ], true ) ) {
                return $at;
            }
        }
    }

    /**
     * libxml2's parse of $h with a P_MARK comment before each candidate tag (after a </p> at the offsets in $ends), and the comments by
     * candidate index, or null when a comment is not found exactly once (libxml2 read it as something else).
     *
     * @param list<array{0:int,1:string}> $found
     * @param list<int>                   $ends
     * @return ?array{0:\DOMDocument, 1:list<\DOMComment>}
     */
    private static function markedDocument( string $h, array $found, array $ends ): ?array
    {
        $out  = '';
        $last = 0;
        $ends = array_flip( $ends );
        foreach ( $found as $k => [ $at ] ) {
            $out .= substr( $h, $last, $at - $last ) . ( isset( $ends[ $at ] ) ? '</p>' : '' ) . '<!--' . self::P_MARK . $k . '-->';
            $last = $at;
        }
        $out .= substr( $h, $last );

        $doc  = new \DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $ok   = $doc->loadHTML( '<?xml encoding="UTF-8">' . $out, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        if ( ! $ok ) {
            return null;
        }
        $marks    = [];
        $comments = ( new \DOMXPath( $doc ) )->query( '//comment()' );
        foreach ( false === $comments ? [] : $comments as $c ) {
            $data = (string) $c->nodeValue;
            if ( 1 !== preg_match( '/^' . preg_quote( self::P_MARK, '/' ) . '(\d+)$/D', $data, $m ) ) {
                continue;
            }
            if ( isset( $marks[ (int) $m[1] ] ) || ! $c instanceof \DOMComment ) {
                return null;
            }
            $marks[ (int) $m[1] ] = $c;
        }
        if ( count( $marks ) !== count( $found ) ) {
            return null;
        }
        ksort( $marks );

        return [ $doc, array_values( $marks ) ];
    }

    /**
     * ONE forward pass over the markup as a browser tokenizes it: comments, the doctype, attribute values (through tag()), raw text and
     * svg/math content. $onStart gets the offset and name of every start tag outside raw text and svg/math. Returns the text extents
     * [from, to) of the script and style elements whose text holds "</", or null for anything the two parsers might tokenize differently:
     * an odd comment, CDATA, a bogus comment or processing instruction, a tag tag() rejects, a self-closing end tag or raw-text start tag,
     * xmp/noembed/noframes/plaintext/frameset/frame, raw text or a breakout tag inside svg/math, a mismatched svg/math end tag, raw text
     * without its end tag, a script holding "<!--", and textarea, title, iframe or noscript text that libxml2 (which reads it as markup)
     * could end elsewhere (see plainRawText()).
     *
     * A raw-text element ends where a browser ends it: at the first "</name" followed by white space, "/" or ">" (case-insensitive).
     *
     * @param ?callable(int,string):void $onStart
     * @return ?list<array{0:int,1:int}>
     */
    private static function markupWalk( string $h, ?callable $onStart = null ): ?array
    {
        $n       = strlen( $h );
        $lh      = strtolower( $h );
        $i       = 0 === strncmp( $h, "\xEF\xBB\xBF", 3 ) ? 3 : 0;
        $steps   = 0;
        $foreign = [];
        $raw     = [];
        while ( $i < $n ) {
            if ( ++$steps > self::MAX_SCAN_STEPS ) {
                return null;
            }
            $lt = strpos( $h, '<', $i );
            if ( false === $lt ) {
                break;
            }
            $c = $lt + 1 < $n ? $h[ $lt + 1 ] : '';
            if ( '!' === $c ) {
                if ( 0 === substr_compare( $h, '<!--', $lt, 4 ) ) {
                    $j = $lt + 4;
                    $e = strpos( $h, '-->', $j );
                    if ( ( $j < $n && '>' === $h[ $j ] ) || 0 === substr_compare( $h, '->', $j, 2 ) || false === $e || false !== strpos( substr( $h, $j, $e - $j ), '--!>' ) ) {
                        return null;
                    }
                    $i = $e + 3;
                    continue;
                }
                if ( 0 === substr_compare( $lh, '<!doctype', $lt, 9 ) && false !== ( $e = strpos( $h, '>', $lt ) ) ) {
                    $i = $e + 1;
                    continue;
                }

                return null;
            }
            if ( '?' === $c ) {
                return null;
            }
            $end = '/' === $c;
            $j   = $lt + ( $end ? 2 : 1 );
            if ( $j >= $n || ! ctype_alpha( $h[ $j ] ) ) {
                if ( $end ) {
                    return null;
                }
                $i = $lt + 1; // a lone < is text
                continue;
            }
            $tag = self::tag( $h, $lh, $j, $n, $steps );
            if ( is_string( $tag ) ) {
                return null;
            }
            [ $next, $name, , $selfClose ] = $tag;
            $i = $next;
            if ( in_array( $name, self::STRAY_UNSURE, true ) ) {
                return null;
            }
            if ( $end ) {
                if ( $selfClose ) {
                    return null;
                }
                if ( [] !== $foreign ) {
                    if ( end( $foreign ) !== $name ) {
                        return null;
                    }
                    array_pop( $foreign );
                }
                continue;
            }
            if ( [] !== $foreign ) {
                if ( in_array( $name, self::BREAKOUT, true ) || 'font' === $name || in_array( $name, self::STRAY_RAW, true ) ) {
                    return null;
                }
                if ( ! $selfClose ) {
                    $foreign[] = $name;
                    if ( count( $foreign ) > self::MAX_SCAN_DEPTH ) {
                        return null;
                    }
                }
                continue;
            }
            if ( 'svg' === $name || 'math' === $name ) {
                if ( ! $selfClose ) {
                    $foreign = [ $name ];
                }
                continue;
            }
            if ( null !== $onStart ) {
                $onStart( $lt, $name );
            }
            if ( ! in_array( $name, self::STRAY_RAW, true ) ) {
                continue;
            }
            if ( $selfClose ) {
                return null; // a browser ignores the slash and reads raw text, libxml2 closes the element
            }
            $e = $next;
            do {
                $e = strpos( $lh, '</' . $name, $e );
                if ( false === $e ) {
                    return null;
                }
                $after = $e + 2 + strlen( $name );
                $e     = $after;
            } while ( $after < $n && false === strpos( self::WS . '/>', $h[ $after ] ) );
            if ( $after >= $n ) {
                return null;
            }
            $e   -= 2 + strlen( $name );
            $text = substr( $h, $next, $e - $next );
            if ( 'script' === $name || 'style' === $name ) {
                if ( 'script' === $name && false !== strpos( $text, '<!--' ) ) {
                    return null;
                }
                if ( false !== strpos( $text, '</' ) ) {
                    $raw[] = [ $next, $e ];
                }
            } elseif ( ! self::plainRawText( $h, $lh, $next, $e, $steps ) ) {
                return null;
            }
            $steps += 1 + intdiv( $e - $next, 64 );
            $close  = self::tag( $h, $lh, $e + 2, $n, $steps );
            if ( is_string( $close ) || $close[3] ) {
                return null;
            }
            $i = $close[0];
        }

        return $raw;
    }

    /**
     * Whether libxml2, which reads textarea, title, iframe and noscript text as markup, sees nothing in $h[$from, $to) that could make
     * it end the element anywhere but at $to: no comment, CDATA, doctype or processing instruction, no script or style (raw text in
     * libxml2), no element markupWalk() does not model, no "</" without a letter, and every tag in it ends before $to.
     */
    private static function plainRawText( string $h, string $lh, int $from, int $to, int &$steps ): bool
    {
        $i = $from;
        while ( false !== ( $lt = strpos( $h, '<', $i ) ) && $lt < $to ) {
            if ( ++$steps > self::MAX_SCAN_STEPS ) {
                return false;
            }
            $c = $lt + 1 < $to ? $h[ $lt + 1 ] : '';
            if ( '!' === $c || '?' === $c ) {
                return false;
            }
            $end = '/' === $c;
            $j   = $lt + ( $end ? 2 : 1 );
            if ( $j >= $to || ! ctype_alpha( $h[ $j ] ) ) {
                if ( $end ) {
                    return false;
                }
                $i = $lt + 1;
                continue;
            }
            $tag = self::tag( $h, $lh, $j, $to, $steps );
            if ( is_string( $tag ) || in_array( $tag[1], [ 'script', 'style' ], true ) || in_array( $tag[1], self::STRAY_UNSURE, true ) ) {
                return false;
            }
            $i = $tag[0];
        }

        return true;
    }

    /** Head elements whose content bodyStart() skips to their end tag (raw text, escapable raw text, or a template's content). */
    private const HEAD_CONTENT = [ 'style', 'script', 'title', 'noscript', 'template' ];

    /** In a template's content: anything with its own text rules, after which the first </template is not certainly the end. */
    private const TEMPLATE_UNSURE = '#<(?:!|/?(?:script|style|textarea|title|template|iframe|xmp|noscript|noembed|noframes|plaintext|svg|math)[\s/>])#i';

    /**
     * The byte offset where a browser starts the body of a page that has no <body> tag before its first content, or null (an explicit
     * <body> first, no content at all, or something this does not model). Public as a seam for tests.
     *
     * libxml2 2.9 (an HTML 4 parser) stays in head mode after head content (style, link, meta, script, title, base), after a comment, an
     * <html> or an empty <head>, and also at the very start of a fragment. Loose text there makes it open an IMPLIED <p> (htmlCheckParagraph
     * for a text node whose parent is nothing, html or head), and an element it does not know (section, header, main ...) does not close
     * that p, so the p swallowed the blocks after the text: a section inside a paragraph, its h2 read as bold text. A browser ends the head
     * at the first non-white-space text or at the first start tag that is not head content (or at </body>, </html>, </br>) and keeps the
     * text a bare run in the body. parse() writes an explicit <body> at that offset, which every parser honours, so libxml2 builds the
     * browser's tree. It is ONE forward pass: white space, comments and a doctype stay in head; html, head, meta, link and base tags and
     * end tags a browser ignores there are skipped; style, script, title, noscript and template are skipped to their end tag. Anything the
     * two parsers might end differently (an odd comment, raw text with an end-tag-like run, a template holding raw text or a comment, a tag
     * the repairs scan does not model, a frameset) gives null, which leaves the HTML exactly as given (the phase-1 parse).
     */
    public static function bodyStart( string $h ): ?int
    {
        $n     = strlen( $h );
        $lh    = strtolower( $h );
        $i     = 0 === strncmp( $h, "\xEF\xBB\xBF", 3 ) ? 3 : 0;
        $steps = 0;
        while ( true ) {
            if ( ++$steps > self::MAX_SCAN_STEPS ) {
                return null;
            }
            $i += strspn( $h, self::WS, $i );
            if ( $i >= $n ) {
                return null;
            }
            if ( '<' !== $h[ $i ] ) {
                return $i; // text (a character reference included) starts the body
            }
            $c = $i + 1 < $n ? $h[ $i + 1 ] : '';
            if ( '!' === $c ) {
                if ( 0 === substr_compare( $h, '<!--', $i, 4 ) ) {
                    $j = $i + 4;
                    $e = strpos( $h, '-->', $j );
                    if ( ( $j < $n && '>' === $h[ $j ] ) || 0 === substr_compare( $h, '->', $j, 2 ) || false === $e || false !== strpos( substr( $h, $j, $e - $j ), '--!>' ) ) {
                        return null;
                    }
                    $i = $e + 3;
                    continue;
                }
                if ( 0 === substr_compare( $lh, '<!doctype', $i, 9 ) && false !== ( $e = strpos( $h, '>', $i ) ) ) {
                    $i = $e + 1;
                    continue;
                }

                return null;
            }
            if ( '?' === $c ) {
                return null;
            }
            $end = '/' === $c;
            $j   = $i + ( $end ? 2 : 1 );
            if ( $j >= $n || ! ctype_alpha( $h[ $j ] ) ) {
                return $end ? null : $i; // a lone < is text
            }
            $tag = self::tag( $h, $lh, $j, $n, $steps );
            if ( is_string( $tag ) ) {
                return null;
            }
            [ $next, $name, , $selfClose ] = $tag;
            if ( $end ) {
                if ( in_array( $name, [ 'body', 'html', 'br' ], true ) ) {
                    return $i;
                }
                $i = $next; // </head>, or an end tag a browser ignores before the body
                continue;
            }
            if ( 'body' === $name || 'frameset' === $name ) {
                return null;
            }
            if ( in_array( $name, [ 'html', 'head', 'meta', 'link', 'base' ], true ) ) {
                $i = $next;
                continue;
            }
            if ( ! in_array( $name, self::HEAD_CONTENT, true ) ) {
                return $i; // any other start tag starts the body
            }
            if ( $selfClose ) {
                return null;
            }
            $e = strpos( $lh, '</' . $name, $next );
            if ( false === $e ) {
                return null;
            }
            $after = $e + 2 + strlen( $name );
            if ( $after >= $n || false === strpos( self::WS . '/>', $h[ $after ] ) ) {
                return null;
            }
            $text = substr( $h, $next, $e - $next );
            if ( 'template' === $name ? 1 === preg_match( self::TEMPLATE_UNSURE, $text ) : ( 1 === preg_match( '#</[A-Za-z]#', $text ) || false !== strpos( $text, '<!--' ) ) ) {
                return null;
            }
            $close = strpos( $h, '>', $after );
            if ( false === $close ) {
                return null;
            }
            $i = $close + 1;
        }
    }

    /** Elements whose text strayEndTags() skips to their end tag: raw text and escapable raw text in a browser (noscript with scripting on). */
    private const STRAY_RAW = [ 'script', 'style', 'textarea', 'title', 'iframe', 'noscript' ];

    /** Elements strayEndTags() does not model (their text rules differ between the parsers, or a frameset replaces the body). */
    private const STRAY_UNSURE = [ 'xmp', 'noembed', 'noframes', 'plaintext', 'frameset', 'frame' ];

    /**
     * The </body> and </html> end tags that page content follows, as [offset, length] pairs in document order, or null (none, or
     * something this does not model). Public as a seam for tests.
     *
     * A browser never ends the body at these end tags: </body> only switches to "after body" (inside a table, a select or a template it
     * is ignored), </html> to "after after body", and the next text or start tag there is processed again "in body" with the stack of
     * open elements unchanged. So content after them is the body's content, inside whatever was still open, exactly as if the end tag
     * were not there. libxml2 2.9 instead closes everything at the end tag and parses what follows outside the body; loose text there
     * opens an IMPLIED <p> (htmlCheckParagraph, its parent is html) and an HTML5 element it does not know (section, header, main ...)
     * does not close it, so the next section was swallowed into a paragraph. parse() removes these end tags, which gives libxml2 the
     * browser's token stream. End tags with nothing but white space and comments after them are kept (the HTML then stays byte for byte
     * as given): comments are the only thing a browser places differently after them, and the converter reads none.
     *
     * ONE forward pass that knows markup from text: comments, the doctype, attribute values (through tag()), raw text (script, style,
     * textarea, title, iframe, noscript) and svg/math content. Anything the two parsers might tokenize differently gives null, which
     * leaves the HTML exactly as given: an odd comment, CDATA, a bogus comment or processing instruction, a tag tag() rejects, a
     * self-closing end tag or raw-text start tag, raw text holding a </letter run or <!--, xmp/noembed/noframes/plaintext/frameset, raw text or a breakout tag
     * inside svg/math, a mismatched svg/math end tag, and a </body> or </html> inside svg/math.
     *
     * @return ?list<array{0:int,1:int}>
     */
    public static function strayEndTags( string $h ): ?array
    {
        $n       = strlen( $h );
        $lh      = strtolower( $h );
        $i       = 0 === strncmp( $h, "\xEF\xBB\xBF", 3 ) ? 3 : 0;
        $steps   = 0;
        $foreign = [];
        $found   = []; // every </body> and </html> so far
        $kept    = 0;  // how many of them content follows
        while ( $i < $n ) {
            if ( ++$steps > self::MAX_SCAN_STEPS ) {
                return null;
            }
            $lt   = strpos( $h, '<', $i );
            $stop = false === $lt ? $n : $lt;
            if ( $stop > $i && strspn( $h, self::WS, $i, $stop - $i ) < $stop - $i ) {
                $kept = count( $found ); // text
            }
            if ( false === $lt ) {
                break;
            }
            $c = $lt + 1 < $n ? $h[ $lt + 1 ] : '';
            if ( '!' === $c ) {
                if ( 0 === substr_compare( $h, '<!--', $lt, 4 ) ) {
                    $j = $lt + 4;
                    $e = strpos( $h, '-->', $j );
                    if ( ( $j < $n && '>' === $h[ $j ] ) || 0 === substr_compare( $h, '->', $j, 2 ) || false === $e || false !== strpos( substr( $h, $j, $e - $j ), '--!>' ) ) {
                        return null;
                    }
                    $i = $e + 3;
                    continue;
                }
                if ( 0 === substr_compare( $lh, '<!doctype', $lt, 9 ) && false !== ( $e = strpos( $h, '>', $lt ) ) ) {
                    $i = $e + 1;
                    continue;
                }

                return null;
            }
            if ( '?' === $c ) {
                return null;
            }
            $end = '/' === $c;
            $j   = $lt + ( $end ? 2 : 1 );
            if ( $j >= $n || ! ctype_alpha( $h[ $j ] ) ) {
                if ( $end ) {
                    return null;
                }
                $kept = count( $found ); // a lone < is text
                $i    = $lt + 1;
                continue;
            }
            $tag = self::tag( $h, $lh, $j, $n, $steps );
            if ( is_string( $tag ) ) {
                return null;
            }
            [ $next, $name, , $selfClose ] = $tag;
            $i = $next;
            if ( in_array( $name, self::STRAY_UNSURE, true ) ) {
                return null;
            }
            if ( $end ) {
                if ( $selfClose ) {
                    return null;
                }
                if ( [] !== $foreign ) {
                    if ( end( $foreign ) !== $name ) {
                        return null; // a </body> or </html> in svg/math included
                    }
                    array_pop( $foreign );
                    continue;
                }
                if ( 'body' === $name || 'html' === $name ) {
                    $found[] = [ $lt, $next - $lt ];
                }
                continue;
            }
            if ( ! in_array( $name, [ 'html', 'head', 'body' ], true ) ) {
                $kept = count( $found ); // a start tag
            }
            if ( [] !== $foreign ) {
                if ( in_array( $name, self::BREAKOUT, true ) || 'font' === $name || in_array( $name, self::STRAY_RAW, true ) ) {
                    return null;
                }
                if ( ! $selfClose ) {
                    $foreign[] = $name;
                    if ( count( $foreign ) > self::MAX_SCAN_DEPTH ) {
                        return null;
                    }
                }
                continue;
            }
            if ( 'svg' === $name || 'math' === $name ) {
                if ( ! $selfClose ) {
                    $foreign = [ $name ];
                }
                continue;
            }
            if ( ! in_array( $name, self::STRAY_RAW, true ) ) {
                continue;
            }
            if ( $selfClose ) {
                return null; // a browser ignores the slash and reads raw text, libxml2 closes the element
            }
            $e = strpos( $lh, '</' . $name, $next );
            if ( false === $e ) {
                return null;
            }
            $after = $e + 2 + strlen( $name );
            if ( $after >= $n || false === strpos( self::WS . '/>', $h[ $after ] ) ) {
                return null;
            }
            $text = substr( $h, $next, $e - $next );
            if ( 1 === preg_match( '#</[A-Za-z]#', $text ) || false !== strpos( $text, '<!--' ) ) {
                return null;
            }
            $steps += 1 + intdiv( $e - $next, 64 );
            $close  = strpos( $h, '>', $after );
            if ( false === $close ) {
                return null;
            }
            $i = $close + 1;
        }
        $stray = array_slice( $found, 0, $kept );

        return array() === $stray ? null : $stray;
    }

    /** Elements that belong in <head>; anything else a parser finds there is page content (see relocateHeadContent). */
    private const HEAD_ELEMENTS = [ 'title', 'meta', 'link', 'style', 'script', 'base', 'noscript', 'template' ];

    /**
     * libxml2 (an HTML4 parser) keeps an element it does not know, such as the HTML5 section, header, nav, main, footer, article or aside,
     * INSIDE <head> when it follows head content (a <style>, <link> or <meta>); a browser ends the head there and starts the body. Left
     * alone the content was lost (or the page refused as having no content). Every direct child element of <head> that is not a head
     * element moves, in document order, to the start of <body> (created when missing), which is the tree a browser builds. Nothing is
     * dropped, so the text check counts the moved text; no repair note is needed because the result is the browser's tree.
     */
    private static function relocateHeadContent( \DOMDocument $doc ): void
    {
        $head = $doc->getElementsByTagName( 'head' )->item( 0 );
        if ( ! $head instanceof \DOMElement ) {
            return;
        }
        $moving = [];
        foreach ( $head->childNodes as $child ) {
            if ( $child instanceof \DOMElement && ! in_array( strtolower( $child->tagName ), self::HEAD_ELEMENTS, true ) ) {
                $moving[] = $child;
            }
        }
        if ( array() === $moving ) {
            return;
        }
        $body = $doc->getElementsByTagName( 'body' )->item( 0 );
        if ( ! $body instanceof \DOMElement ) {
            $html = $head->parentNode;
            if ( ! $html instanceof \DOMNode ) {
                return;
            }
            $body = $doc->createElement( 'body' );
            $html->insertBefore( $body, $head->nextSibling );
        }
        $first = $body->firstChild;
        foreach ( $moving as $el ) {
            $body->insertBefore( $el, $first );
        }
    }

    /** A node that is page content when found outside <body>: an element other than head/body and the head elements, text or a comment. */
    private static function isLooseContent( \DOMNode $node ): bool
    {
        if ( $node instanceof \DOMElement ) {
            return ! in_array( strtolower( $node->tagName ), array_merge( self::HEAD_ELEMENTS, [ 'head', 'body', 'html' ] ), true );
        }

        return in_array( $node->nodeType, [ XML_TEXT_NODE, XML_CDATA_SECTION_NODE, XML_COMMENT_NODE ], true );
    }

    /**
     * Content after </html> or </body> ends up where nothing reads it: libxml2 opens a SECOND top-level <html> node (or leaves a loose
     * node beside the document's <html>), or keeps the content as a sibling of <body> inside <html>. A browser appends all of it to the
     * body. Every such node moves, in document order, to the END of the first <body> (created when the first <html> has none): the
     * children of an extra <html>'s <body>, its other non-head elements and text, and loose elements, text and comments after the first
     * <html>. Head elements of an extra <html> stay where they are (the first <title> stays the title); doctype and processing
     * instructions stay. Nothing is dropped, so the text check counts the moved text; no repair note, because the result is the
     * browser's tree. Since the stray </body> and </html> end tags are removed before parsing (strayEndTags), this handles only the inputs
     * that function leaves alone (a markup shape it does not model, or content that is only white space and comments).
     */
    private static function relocateContentAfterHtml( \DOMDocument $doc ): void
    {
        $html = $doc->documentElement;
        if ( ! $html instanceof \DOMElement || 'html' !== strtolower( $html->tagName ) ) {
            return;
        }
        $body = null;
        foreach ( $html->childNodes as $child ) {
            if ( $child instanceof \DOMElement && 'body' === strtolower( $child->tagName ) ) {
                $body = $child;
                break;
            }
        }

        $moving = [];
        $emptied = [];
        // Siblings of <body> inside the first <html> that follow it (all of its loose children when there is no body).
        $after = null === $body;
        foreach ( $html->childNodes as $child ) {
            if ( $child === $body ) {
                $after = true;
            } elseif ( $after && self::isLooseContent( $child ) ) {
                $moving[] = $child;
            }
        }
        // Everything at the top level of the document after the first <html>.
        for ( $node = $html->nextSibling; $node instanceof \DOMNode; $node = $node->nextSibling ) {
            if ( ! $node instanceof \DOMElement || 'html' !== strtolower( $node->tagName ) ) {
                if ( self::isLooseContent( $node ) ) {
                    $moving[] = $node;
                }
                continue;
            }
            foreach ( $node->childNodes as $child ) {
                if ( $child instanceof \DOMElement && 'body' === strtolower( $child->tagName ) ) {
                    $emptied[] = $child;
                    foreach ( $child->childNodes as $grand ) {
                        $moving[] = $grand;
                    }
                } elseif ( self::isLooseContent( $child ) ) {
                    $moving[] = $child;
                }
            }
        }

        // Only whitespace and comments: nothing to carry, and no body is made for them.
        $meaningful = false;
        foreach ( $moving as $node ) {
            if ( $node instanceof \DOMElement || ( XML_COMMENT_NODE !== $node->nodeType && '' !== trim( (string) $node->nodeValue ) ) ) {
                $meaningful = true;
                break;
            }
        }
        if ( ! $meaningful ) {
            return;
        }
        if ( null === $body ) {
            $body = $doc->createElement( 'body' );
            $html->appendChild( $body );
        }
        foreach ( $moving as $node ) {
            $body->appendChild( $node );
        }
        foreach ( $emptied as $stray ) {
            $stray->parentNode?->removeChild( $stray );
        }
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
