<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\AdminPage;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StudioBrief;
use AiEditorDivi5\WP\StudioScreen;
use AiEditorDivi5\WP\StudioSlots;
use AiEditorDivi5\WP\StudioStack;
use AiEditorDivi5\WP\StudioStore;
use AiEditorDivi5\WP\StudioTable;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/AdminFormShims.php';
require_once __DIR__ . '/StudioScreenShims.php';
require_once __DIR__ . '/../wp-plugin/src/autoload.php';

/**
 * Lab (internal name Studio), plan 2 task 7: the admin screen. The markup is rendered by PHP, the behaviour is
 * added by assets/studio-core.js (pure functions, unit tested by scripts/test-studio-ui.cjs) and assets/studio.js.
 */
class StudioScreenTest extends TestCase
{
    private const ROOT = __DIR__ . '/../wp-plugin/';

    /** Attributes a person reads or hears. */
    private const VISIBLE_ATTRS = [ 'title', 'aria-label', 'placeholder', 'alt', 'value', 'label', 'aria-description' ];

    protected function setUp(): void
    {
        $GLOBALS['__enqueued']           = [];
        $GLOBALS['__wp_bloginfo']        = [ 'name' => 'Acme Plumbing', 'description' => 'Leaks fixed the same day' ];
        $GLOBALS['__wp_caps']            = [ 'manage_options' => true, 'edit_pages' => true, 'upload_files' => true ];
        $GLOBALS['__wp_current_user_id'] = 5;
        $GLOBALS['__wp_filters']         = [];
        $_GET                            = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $GLOBALS['wpdb'] = null;
        unset( $GLOBALS['__enqueued'], $GLOBALS['__wp_bloginfo'], $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'] );
    }

    private function render( ?bool $assistant = null ): string
    {
        ob_start();
        try {
            StudioScreen::render( $assistant );
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private function src( string $rel ): string
    {
        return (string) file_get_contents( self::ROOT . $rel );
    }

    private function dom( string $html ): \DOMXPath
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML( '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>' );
        libxml_clear_errors();

        return new \DOMXPath( $doc );
    }

    /** @return list<string> every non-empty text node of the rendered screen */
    private function texts( string $html ): array
    {
        $out = [];
        foreach ( $this->dom( $html )->query( '//body//text()' ) as $node ) {
            $t = trim( preg_replace( '/\s+/u', ' ', (string) $node->nodeValue ) ?? '' );
            if ( '' !== $t ) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /** @return list<string> the values of attributes a person reads or hears */
    private function visibleAttributes( string $html ): array
    {
        $out = [];
        foreach ( $this->dom( $html )->query( '//body//@*' ) as $attr ) {
            $owner = $attr->ownerElement;
            // The value of a choice (a radio, a checkbox, an option) is data sent to the server, not something a person reads.
            if ( 'value' === $attr->nodeName && ( 'option' === $owner->nodeName || in_array( $owner->getAttribute( 'type' ), [ 'radio', 'checkbox', 'hidden' ], true ) ) ) {
                continue;
            }
            if ( in_array( $attr->nodeName, self::VISIBLE_ATTRS, true ) ) {
                $out[] = (string) $attr->nodeValue;
            }
        }

        return $out;
    }

    /** @return list<string> every string in a nested array */
    private static function strings( mixed $value ): array
    {
        if ( is_string( $value ) ) {
            return [ $value ];
        }
        $out = [];
        if ( is_array( $value ) ) {
            foreach ( $value as $v ) {
                $out = array_merge( $out, self::strings( $v ) );
            }
        }

        return $out;
    }

    /** @return list<string> the string literals of a JavaScript file (single or double quoted) */
    private static function jsLiterals( string $js ): array
    {
        $js = (string) preg_replace( '~/\*.*?\*/~s', '', $js );
        $js = (string) preg_replace( '~^\s*//.*$~m', '', $js );
        preg_match_all( '~\'((?:[^\'\\\\\n]|\\\\.)*)\'|"((?:[^"\\\\\n]|\\\\.)*)"~', $js, $m );

        return array_values( array_filter( array_map( static fn ( $a, $b ) => '' !== $a ? $a : $b, $m[1], $m[2] ), static fn ( $s ) => '' !== $s ) );
    }

    // ---------------------------------------------------------------

    public function testTheTabIsRegisteredOnce(): void
    {
        $tabs = AdminPage::tabs( [] );
        $this->assertSame( [ 'dashboard', 'features', 'studio', 'settings' ], array_keys( $tabs ), 'a core tab between Features and Settings' );
        $this->assertSame( 'Lab', $tabs['studio'] );

        // An add-on cannot take the slug or add it a second time.
        $GLOBALS['__wp_filters']['jhmg_aied_admin_tabs'][] = static fn ( $t ) => $t + [ 'studio' => 'Hijack', 'extra' => 'Extra' ];
        $withExtra = AdminPage::tabs( apply_filters( 'jhmg_aied_admin_tabs', [] ) );
        $this->assertSame( [ 'dashboard', 'features', 'studio', 'settings', 'extra' ], array_keys( $withExtra ) );
        $this->assertSame( 'Lab', $withExtra['studio'] );

        $php = $this->src( 'src/AdminPage.php' );
        $this->assertSame( 1, substr_count( $php, "case 'studio':" ) );
        $this->assertSame( 1, substr_count( $php, 'StudioScreen::render()' ) );
        $this->assertStringContainsString( "require_once __DIR__ . '/StudioScreen.php';", $this->src( 'src/autoload.php' ) );
    }

    public function testFiveStepsRenderInOrder(): void
    {
        $html  = $this->render();
        $xpath = $this->dom( $html );

        $buttons = [];
        foreach ( $xpath->query( '//ol[contains(@class,"aied-lab-stepper")]/li/button' ) as $b ) {
            $buttons[] = trim( preg_replace( '/\s+/', ' ', $b->textContent ) );
            $this->assertSame( 'button', $b->getAttribute( 'type' ) );
        }
        $this->assertSame( [ '1 Goal and business', '2 Look and feel', '3 Sections', '4 Words and pictures', '5 Review and create' ], $buttons );
        $current = $xpath->query( '//ol[contains(@class,"aied-lab-stepper")]/li/button[@aria-current="step"]' );
        $this->assertSame( 1, $current->length, 'exactly one current step' );

        $headings = [];
        foreach ( $xpath->query( '//section[@data-step]' ) as $i => $panel ) {
            $this->assertSame( (string) ( $i + 1 ), $panel->getAttribute( 'data-step' ) );
            $h = $xpath->query( './/h2', $panel )->item( 0 );
            $this->assertNotNull( $h );
            $this->assertSame( '-1', $h->getAttribute( 'tabindex' ), 'focus moves to the step heading' );
            $headings[] = trim( $h->textContent );
            $this->assertSame( 0 === $i ? '' : 'hidden', $panel->hasAttribute( 'hidden' ) ? 'hidden' : '' );
        }
        $this->assertSame( [ 'Goal and business', 'Look and feel', 'Sections', 'Words and pictures', 'Review and create' ], $headings );

        $frame = $xpath->query( '//iframe' );
        $this->assertSame( 1, $frame->length );
        $this->assertNotSame( '', $frame->item( 0 )->getAttribute( 'title' ) );
        $this->assertSame( 'polite', $xpath->query( '//*[@id="aied-lab-status"]' )->item( 0 )->getAttribute( 'aria-live' ) );

        // Without JavaScript only one clear sentence shows; the app is hidden until the script starts it.
        $this->assertSame( 1, $xpath->query( '//noscript' )->length );
        $this->assertTrue( $xpath->query( '//*[@id="aied-lab-app"]' )->item( 0 )->hasAttribute( 'hidden' ) );

        // Every form control has a label.
        foreach ( $xpath->query( '//input[not(@type="hidden")]|//select|//textarea' ) as $control ) {
            $id = $control->getAttribute( 'id' );
            $labelled = ( '' !== $id && $xpath->query( '//label[@for="' . $id . '"]' )->length > 0 )
                || 'label' === $control->parentNode->nodeName
                || '' !== $control->getAttribute( 'aria-label' );
            $this->assertTrue( $labelled, 'unlabelled control ' . $id . ' ' . $control->getAttribute( 'name' ) );
        }

        // The help says plainly what the preview is and that nothing is permanent.
        $text = implode( ' ', $this->texts( $html ) );
        $this->assertStringContainsString( 'temporary private draft page', $text );
        $this->assertStringContainsString( 'Pages list', $text );
        $this->assertStringContainsString( 'after a day', $text );
        $this->assertStringContainsString( 'always drafts', $text );
        $this->assertStringContainsString( 'undone', $text );
        $this->assertStringContainsString( 'never invents', $text );
    }

    public function testConfigListsAllLanguagesKitsAndGoals(): void
    {
        $config = StudioScreen::config( 5 );

        $this->assertSame( 'nonce-for-ai_editor_divi5_studio', $config['nonce'] );
        $this->assertSame( admin_url( 'admin-ajax.php' ), $config['ajaxUrl'] );
        $this->assertSame( StudioBrief::GOALS, array_column( $config['goals'], 'id' ) );
        foreach ( $config['goals'] as $goal ) {
            $this->assertNotSame( '', $goal['label'] );
            $this->assertSame( StudioStack::forGoal( $goal['id'], 'balanced' ), $config['stacks'][ $goal['id'] ] );
        }
        $this->assertSame( DesignLanguages::ids(), array_column( $config['languages'], 'id' ) );
        foreach ( $config['languages'] as $language ) {
            $this->assertSame( DesignLanguages::get( $language['id'] )['summary'], $language['summary'] );
            $this->assertContains( $language['kit'], StyleKits::ids() );
        }
        $this->assertCount( 10, $config['languages'] );
        $this->assertSame( StyleKits::ids(), array_column( $config['kits'], 'id' ) );
        $this->assertSame( [ 'airy', 'balanced', 'compact' ], array_column( $config['densities'], 'id' ) );

        // The recipe catalog the screen needs for slots, variants and the item-count guard: the same as the server's.
        $this->assertSame( SectionRecipes::names(), array_keys( $config['recipes'] ) );
        foreach ( [ 'hero-cta', 'card-grid-3', 'pricing-three-tier' ] as $name ) {
            $recipe = $config['recipes'][ $name ];
            $this->assertSame( StudioStack::itemCount( $name ), $recipe['items'] );
            $this->assertSame( SectionRecipes::tags( $name )['role'], $recipe['role'] );
            $want = array_map( static fn ( $s ) => [ $s['id'], $s['role'], $s['kind'] ], StudioSlots::extract( (string) SectionRecipes::rawMarkup( $name ) ) );
            $this->assertSame( $want, array_map( static fn ( $s ) => [ $s['id'], $s['role'], $s['kind'] ], $recipe['slots'] ) );
        }
        $this->assertNotEmpty( $config['pack'] );
        $this->assertStringStartsWith( plugins_url( 'assets/images/', AI_EDITOR_DIVI5_FILE ), $config['pack'][0]['src'] );

        $this->assertSame( 'Acme Plumbing', $config['site']['name'] );
        $this->assertSame( 'Leaks fixed the same day', $config['site']['tagline'] );
        $this->assertNull( $config['brief'] );
        $this->assertTrue( $config['media'] );

        // Every i18n entry is a non-empty string.
        foreach ( $config['i18n'] as $key => $value ) {
            $this->assertIsString( $value, (string) $key );
            $this->assertNotSame( '', $value, (string) $key );
        }
    }

    public function testConfigCarriesOnlyTheUsersOwnBrief(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createStudioTable();
        $GLOBALS['__wp_options'] = [ StudioTable::DB_VERSION_OPTION => StudioTable::DB_VERSION ];
        $saved = StudioStore::save( 5, [ 'version' => 1, 'title' => 'Acme', 'goal' => 'book', 'language' => 'editorial', 'sections' => [ [ 'recipe' => 'hero-cta' ] ] ] );
        $this->assertTrue( $saved['ok'] );

        $_GET['brief'] = (string) $saved['id'];
        $mine = StudioScreen::config( 5 );
        $this->assertSame( $saved['id'], $mine['brief']['id'] );
        $this->assertSame( 'Acme', $mine['brief']['brief']['title'] );
        $this->assertNull( StudioScreen::config( 6 )['brief'], 'another user\'s brief id gives nothing' );

        $_GET['brief'] = '1 OR 1=1';
        $this->assertNull( StudioScreen::config( 5 )['brief'] );
        $GLOBALS['__wp_options'] = [];
    }

    public function testNoUserTextIsOutputUnescaped(): void
    {
        $GLOBALS['__wp_bloginfo'] = [ 'name' => 'Acme <script>alert(1)</script> & "Co"', 'description' => '"><img src=x onerror=alert(2)>' ];
        $html = $this->render();
        $this->assertStringNotContainsString( '<script', $html );
        $this->assertStringNotContainsString( '<img src=x', $html );
        $this->assertStringNotContainsString( 'onerror=alert(2)>', $html );
        $this->assertStringContainsString( 'value="Acme &lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Co&quot;"', $html );

        $xpath = $this->dom( $html );
        $this->assertSame( 'Acme <script>alert(1)</script> & "Co"', $xpath->query( '//input[@id="aied-lab-name"]' )->item( 0 )->getAttribute( 'value' ) );
        $this->assertSame( '"><img src=x onerror=alert(2)>', $xpath->query( '//input[@id="aied-lab-line"]' )->item( 0 )->getAttribute( 'value' ) );

        // The config carries them as plain strings, for value/textContent only.
        $config = StudioScreen::config( 5 );
        $this->assertSame( 'Acme <script>alert(1)</script> & "Co"', $config['site']['name'] );

        // WordPress stores the title HTML-escaped; the screen shows the characters, not the entities.
        $GLOBALS['__wp_bloginfo'] = [ 'name' => 'Tom &amp; Jerry&#039;s', 'description' => '' ];
        $this->assertSame( "Tom & Jerry's", StudioScreen::config( 5 )['site']['name'] );
    }

    public function testOnlyTheStudioTabLoadsStudioAssets(): void
    {
        $page = new AdminPage();
        foreach ( [ 'dashboard', 'features', 'settings', '' ] as $tab ) {
            $GLOBALS['__enqueued'] = [];
            $_GET = '' === $tab ? [] : [ 'tab' => $tab ];
            $page->enqueueAssets( 'toplevel_page_ai-editor-divi5' );
            $this->assertArrayHasKey( 'ai-editor-divi5-admin', $GLOBALS['__enqueued']['script'], $tab );
            $this->assertArrayNotHasKey( StudioScreen::HANDLE, $GLOBALS['__enqueued']['script'], $tab );
            $this->assertArrayNotHasKey( StudioScreen::HANDLE, $GLOBALS['__enqueued']['style'] ?? [], $tab );
            $this->assertArrayNotHasKey( 'media', $GLOBALS['__enqueued'], $tab );
        }

        $GLOBALS['__enqueued'] = [];
        $_GET = [ 'tab' => 'studio' ];
        $page->enqueueAssets( 'toplevel_page_ai-editor-divi5' );
        $this->assertStringEndsWith( 'assets/studio.css', $GLOBALS['__enqueued']['style'][ StudioScreen::HANDLE ] );
        $this->assertStringEndsWith( 'assets/studio.js', $GLOBALS['__enqueued']['script'][ StudioScreen::HANDLE ]['src'] );
        $this->assertStringEndsWith( 'assets/studio-core.js', $GLOBALS['__enqueued']['script'][ StudioScreen::CORE_HANDLE ]['src'] );
        $this->assertContains( StudioScreen::CORE_HANDLE, $GLOBALS['__enqueued']['script'][ StudioScreen::HANDLE ]['deps'] );
        $this->assertArrayHasKey( 'aiedLabConfig', $GLOBALS['__enqueued']['localize'][ StudioScreen::HANDLE ] );
        $this->assertTrue( $GLOBALS['__enqueued']['media'] );

        // Another admin screen loads nothing of ours.
        $GLOBALS['__enqueued'] = [];
        $page->enqueueAssets( 'edit.php' );
        $this->assertSame( [], $GLOBALS['__enqueued'] );

        // Without upload rights the Media Library picker is not loaded.
        $GLOBALS['__enqueued'] = [];
        $GLOBALS['__wp_caps']['upload_files'] = false;
        $page->enqueueAssets( 'toplevel_page_ai-editor-divi5' );
        $this->assertArrayNotHasKey( 'media', $GLOBALS['__enqueued'] );
        $this->assertFalse( $GLOBALS['__enqueued']['localize'][ StudioScreen::HANDLE ]['aiedLabConfig']['media'] );
    }

    public function testNoProMentionOutsideTheProCard(): void
    {
        $words = '/\bpro\b|premium|upgrade|licen[sc]e|divi5lab\.com/i';
        $html  = $this->render();
        foreach ( array_merge( $this->texts( $html ), $this->visibleAttributes( $html ), self::strings( StudioScreen::config( 5 )['i18n'] ) ) as $s ) {
            $this->assertDoesNotMatchRegularExpression( $words, $s );
        }
        foreach ( [ 'src/StudioScreen.php', 'assets/studio.js', 'assets/studio-core.js', 'assets/studio.css' ] as $f ) {
            $this->assertDoesNotMatchRegularExpression( $words, $this->src( $f ), $f );
        }
    }

    public function testEveryStringIsTranslatable(): void
    {
        $php = $this->src( 'src/StudioScreen.php' );

        // Every translation call names the plugin's text domain.
        preg_match_all( '/\b(?:__|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_n)\(\s*\'(?:[^\'\\\\]|\\\\.)*\'\s*(,\s*\'[^\']*\'\s*)?\)/', $php, $calls, PREG_SET_ORDER );
        $this->assertGreaterThan( 50, count( $calls ) );
        foreach ( $calls as $call ) {
            $this->assertStringContainsString( "'jhmg-ai-editor-for-divi-5'", $call[0], $call[0] );
        }
        preg_match_all( '/\b(?:__|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $php, $m );
        $translated = array_map( static fn ( $s ) => stripcslashes( $s ), $m[1] );

        // Every text a person reads on the rendered screen is a translated string or the site's own data.
        $data = [ 'Acme Plumbing', 'Leaks fixed the same day' ];
        foreach ( DesignLanguages::ids() as $id ) {
            $l      = DesignLanguages::get( $id );
            $data[] = (string) $l['title'];
            $data[] = (string) $l['summary'];
        }
        foreach ( StyleKits::ids() as $id ) {
            $data[] = (string) StyleKits::get( $id )['summary'];
            $data[] = StudioScreen::kitName( $id );
        }
        $html = $this->render();
        foreach ( array_merge( $this->texts( $html ), array_filter( $this->visibleAttributes( $html ), static fn ( $s ) => 1 === preg_match( '/[A-Za-z]{2}/', $s ) ) ) as $t ) {
            if ( in_array( $t, $data, true ) || in_array( $t, $translated, true ) || 1 === preg_match( '/^[0-9.,:]+$/', $t ) ) {
                continue;
            }
            $this->assertTrue( self::matchesAFormat( $t, $translated ), 'untranslated text on the screen: ' . $t );
        }

        // The scripts carry no sentences of their own: every word a person reads comes from the localized i18n.
        foreach ( [ 'assets/studio.js', 'assets/studio-core.js' ] as $f ) {
            foreach ( array_diff( self::jsLiterals( $this->src( $f ) ), [ 'use strict' ] ) as $literal ) {
                $this->assertDoesNotMatchRegularExpression( '/[A-Za-z]{2,} [A-Za-z]{2,}/', $literal, "$f has a hard-coded sentence: $literal" );
                $this->assertDoesNotMatchRegularExpression( '/^[A-Z][a-z]{2,}/', $literal, "$f has a hard-coded word: $literal" );
            }
        }
    }

    /** True when $text is one of the sprintf formats in $formats with its placeholders filled. */
    private static function matchesAFormat( string $text, array $formats ): bool
    {
        foreach ( $formats as $f ) {
            if ( ! str_contains( $f, '%' ) ) {
                continue;
            }
            $re = '/^' . preg_replace( '/%(?:\d+\$)?[sd]/', '.+', preg_quote( $f, '/' ) ) . '$/su';
            $re = str_replace( preg_quote( '.+', '/' ), '.+', $re );
            if ( 1 === preg_match( $re, $text ) ) {
                return true;
            }
        }

        return false;
    }

    public function testTheWordStudioNeverAppearsInAUserFacingString(): void
    {
        // The internal name must never reach a person. Three design-language summaries use the ordinary English word
        // (organic-warm, playful-pastel, brutalist-flat: shared data the guides serve too); those exact phrases only.
        $plain = static fn ( string $s ): string => str_ireplace( [ 'a ceramics studio', 'a creative studio', 'a studio manifesto' ], '', $s );
        $html  = $this->render();
        foreach ( array_merge( $this->texts( $html ), $this->visibleAttributes( $html ) ) as $s ) {
            $this->assertStringNotContainsStringIgnoringCase( 'studio', $plain( $s ) );
        }
        $config = StudioScreen::config( 5 );
        foreach ( [ 'i18n', 'goals', 'densities', 'roles', 'slotRoles', 'kits' ] as $key ) {
            foreach ( self::strings( $config[ $key ] ) as $s ) {
                $this->assertStringNotContainsStringIgnoringCase( 'studio', $s, $key );
            }
        }
        foreach ( self::strings( $config['languages'] ) as $s ) {
            $this->assertStringNotContainsStringIgnoringCase( 'studio', $plain( $s ) );
        }
        // In the scripts the word may only be part of an identifier: a class, an id, a handle or an ajax action.
        foreach ( [ 'assets/studio.js', 'assets/studio-core.js' ] as $f ) {
            foreach ( self::jsLiterals( $this->src( $f ) ) as $literal ) {
                if ( false !== stripos( $literal, 'studio' ) ) {
                    $this->assertMatchesRegularExpression( '/^[a-z0-9_.#\- \[\]="]*$/', $literal, "$f: $literal" );
                }
            }
        }
        // The stylesheet writes no visible text.
        $this->assertDoesNotMatchRegularExpression( '/(?<![\w-])content\s*:/', $this->src( 'assets/studio.css' ) );
    }

    public function testTheScriptsNeverWriteHtml(): void
    {
        foreach ( [ 'assets/studio.js', 'assets/studio-core.js' ] as $f ) {
            $js = $this->src( $f );
            foreach ( [ 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'createContextualFragment', 'DOMParser' ] as $bad ) {
                $this->assertStringNotContainsString( $bad, $js, "$f uses $bad" );
            }
        }
        $js = $this->src( 'assets/studio.js' );
        preg_match_all( '/\.srcdoc\s*=\s*([^;]+);/', $js, $m );
        $this->assertSame( [ 'preview.html' ], array_map( 'trim', $m[1] ), 'only the escaped approximation goes into srcdoc' );
        $this->assertStringContainsString( "'nonce'", $js, 'the nonce is sent as the POST field "nonce"' );
    }

    public function testTheScreenSourceEscapesEverything(): void
    {
        $php = $this->src( 'src/StudioScreen.php' );
        $this->assertStringContainsString( "if ( ! defined( 'ABSPATH' ) )", $php );
        $this->assertDoesNotMatchRegularExpression( '/<script\b/i', $php, 'no inline script blocks' );
        $this->assertDoesNotMatchRegularExpression( '/\son(click|submit|change|load|input)\s*=/i', $php );
        $this->assertStringNotContainsString( 'array_is_list', $php );
        // Every echo of a variable goes through an esc_ function.
        preg_match_all( '/echo\s+([^;]+);/', $php, $m );
        foreach ( $m[1] as $expr ) {
            $this->assertMatchesRegularExpression( '/^(esc_html|esc_attr|esc_url)\(|^\'[^\']*\'$/', trim( $expr ), 'unescaped echo: ' . $expr );
        }
    }

    public function testThePureScriptFunctionsPassTheirNodeTests(): void
    {
        $node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
        if ( '' === $node ) {
            $this->markTestSkipped( 'node is not installed' );
        }
        $out = [];
        exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/../scripts/test-studio-ui.cjs' ) . ' 2>&1', $out, $code );
        $this->assertSame( 0, $code, implode( "\n", $out ) );
    }

    /** The screen's layout swap (studio-core.js) follows StudioMerge::swapRecipe on the real library, pair for pair. */
    public function testTheScreensLayoutSwapMatchesTheServer(): void
    {
        $node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
        if ( '' === $node ) {
            $this->markTestSkipped( 'node is not installed' );
        }
        $catalog = StudioScreen::config( 5 )['recipes'];
        $pairs   = [];
        $briefs  = [];
        foreach ( [ 'hero-cta', 'card-grid-3', 'testimonial', 'faq-accordion', 'pricing-three-tier', 'cta-band' ] as $from ) {
            foreach ( StudioStack::variants( $from ) as $variant ) {
                $slots    = [];
                $pictures = [];
                foreach ( $catalog[ $from ]['slots'] as $slot ) {
                    if ( 'picture' === $slot['kind'] ) {
                        $pictures[ $slot['id'] ] = [ 'pack' => 'hero-blue-1' ];
                    } else {
                        $slots[ $slot['id'] ] = 'v ' . $slot['id'];
                    }
                }
                $pairs[]  = [ $from, $variant['recipe'], [ 'slots' => (object) $slots, 'pictures' => (object) $pictures ] ];
                $section  = [ 'recipe' => $from ] + ( [] === $slots ? [] : [ 'slots' => $slots ] ) + ( [] === $pictures ? [] : [ 'pictures' => $pictures ] );
                $briefs[] = [ $variant['recipe'], [ 'sections' => [ $section ] ] ];
            }
        }
        $this->assertGreaterThan( 30, count( $pairs ) );
        $file = tempnam( sys_get_temp_dir(), 'aied-swap' );
        file_put_contents( $file, (string) json_encode( [ 'catalog' => $catalog, 'pairs' => $pairs ] ) );
        $out = shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/../scripts/test-studio-ui.cjs' ) . ' --swap ' . escapeshellarg( $file ) );
        unlink( $file );
        $js = json_decode( (string) $out, true );
        $this->assertIsArray( $js );
        foreach ( $briefs as $i => [ $to, $brief ] ) {
            $php     = \AiEditorDivi5\WP\StudioMerge::swapRecipe( $brief, 0, $to );
            $kept    = $php['kept'];
            $dropped = $php['dropped'];
            sort( $kept );
            sort( $dropped );
            $this->assertSame( $kept, $js[ $i ]['kept'], "kept {$pairs[$i][0]} -> $to" );
            $this->assertSame( $dropped, $js[ $i ]['dropped'], "dropped {$pairs[$i][0]} -> $to" );
            $this->assertEquals( $php['brief']['sections'][0]['slots'] ?? [], $js[ $i ]['slots'] ?: [], "slots {$pairs[$i][0]} -> $to" );
        }
    }

    /** Every word the script asks for exists in the localized i18n (a missing key would show "undefined"). */
    public function testEveryStringTheScriptUsesIsLocalized(): void
    {
        $js   = $this->src( 'assets/studio.js' );
        $i18n = StudioScreen::config( 5 )['i18n'];
        preg_match_all( '/\bt\.([A-Za-z]+)\b/', $js, $direct );
        preg_match_all( "/plural\([^,]+,\s*'([A-Za-z]+)',\s*'([A-Za-z]+)'\)/", $js, $plurals );
        preg_match_all( "/:\s*'(reason[A-Za-z]+)'/", $js, $reasons );
        $keys = array_unique( array_merge( $direct[1], $plurals[1], $plurals[2], $reasons[1] ) );
        $this->assertGreaterThan( 40, count( $keys ) );
        $this->assertCount( 6, $reasons[1] );
        foreach ( $keys as $key ) {
            $this->assertArrayHasKey( $key, $i18n, "studio.js uses t.$key" );
        }
        foreach ( [ 'roles', 'slotRoles' ] as $map ) {
            foreach ( StudioScreen::config( 5 )[ $map ] as $label ) {
                $this->assertNotSame( '', $label );
            }
        }
        // Every slot role and recipe role in the library has a label a person reads.
        $config = StudioScreen::config( 5 );
        foreach ( $config['recipes'] as $name => $recipe ) {
            $this->assertArrayHasKey( $recipe['role'], $config['roles'], $name );
            foreach ( $recipe['slots'] as $slot ) {
                $this->assertArrayHasKey( $slot['role'], $config['slotRoles'], "$name {$slot['id']}" );
            }
        }
    }
    /**
     * The stepper and the preview use the whole of #wpbody-content while the page wrap keeps its normal width for the
     * other blocks: the wrap gets a modifier on this tab only, and the stylesheet un-caps it and re-caps everything
     * that is not the layout. (Measured in a real browser: the layout is as wide as #wpbody-content at 1600, 1280
     * and 900 px, the top bar and tabs keep their 1120px column.)
     */
    public function testTheLayoutUsesTheFullWidthWhileTheRestKeepsItsColumn(): void
    {
        $page = (string) file_get_contents( __DIR__ . '/../wp-plugin/src/AdminPage.php' );
        $css  = (string) file_get_contents( __DIR__ . '/../wp-plugin/assets/studio.css' );
        $this->assertStringContainsString( "'studio' === \$tab ? ' aied--lab' : ''", $page );
        $this->assertMatchesRegularExpression( '/\.aied\.aied--lab\s*\{[^}]*max-width:\s*none/', $css );
        $this->assertMatchesRegularExpression( '/\.aied-lab-layout\s*\{[^}]*width:\s*100%/', $css );
        // The blocks that are not the layout stay in the 1120px column.
        $this->assertMatchesRegularExpression( '/\.aied--lab \.aied-lab-intro[^{]*\{[^}]*max-width:\s*1120px/', $css );
        $this->assertMatchesRegularExpression( '/\.aied--lab > \*:not\(\.aied-view\)[^{]*\{[^}]*max-width:\s*1120px/s', $css );
    }

    /** Fix round 1, I1: no handler keeps a section object of its own; each looks the section up by key when it fires. */
    public function testNoHandlerHoldsAStaleSection(): void
    {
        $js = $this->src( 'assets/studio.js' );
        foreach ( [ 'textField(s,', 'pictureField(s,', 'openMedia(s,' ] as $stale ) {
            $this->assertStringNotContainsString( $stale, $js );
        }
        $this->assertGreaterThanOrEqual( 6, substr_count( $js, 'sectionByKey(' ), 'step 3 and step 4 handlers read the live state' );
        $this->assertStringContainsString( 'keepFocus(', $js, 'a redraw of step 4 keeps the field and the caret' );
    }

    /** Fix round 1, I2 (controller ruling 19): every send that carries a brief id reads the stored brief and merges it first. */
    public function testEverySendMergesTheStoredBriefFirst(): void
    {
        $js = $this->src( 'assets/studio.js' );
        $this->assertStringContainsString( 'core.prepareSend(', $js );
        // Save and create send the brief only through send(); nothing else posts a brief.
        $this->assertSame( 1, substr_count( $js, "send('save')" ) );
        $this->assertSame( 1, substr_count( $js, "send('create')" ) );
        $this->assertSame( 1, substr_count( $js, 'brief: JSON.stringify(out.brief)' ) );
        $this->assertSame( 2, substr_count( $js, 'brief: JSON.stringify(' ), 'send() and the read-only preview' );
        $this->assertStringNotContainsString( "api('save'", $js );
        $this->assertStringNotContainsString( "api('create'", $js );
        preg_match( '/function send\(.*?\n    \}/s', $js, $send );
        $this->assertNotEmpty( $send, 'one send() that every write uses' );
        $this->assertStringContainsString( "api('brief'", $send[0] );
        $this->assertStringContainsString( 't.syncFailed', $send[0], 'a failed read is told plainly and nothing is sent' );
        $this->assertArrayHasKey( 'syncFailed', StudioScreen::config( 5 )['i18n'] );
    }

    /** Fix round 1, I3: one busy gate for create, rebuild and hand-off; the review never re-enables Create mid-request. */
    public function testEveryWritingActionGoesThroughTheOneBusyGate(): void
    {
        $js = $this->src( 'assets/studio.js' );
        $this->assertSame( 1, substr_count( $js, 'core.makeGate(' ) );
        foreach ( [ 'create', 'rebuild', 'handoff' ] as $action ) {
            $this->assertMatchesRegularExpression( '/function ' . $action . '\(\) \{\s*gate\.run\(/', $js, $action );
        }
        $this->assertStringContainsString( "byId('aied-lab-create').disabled = gate.busy() || previewOk === false;", $js );
        $this->assertStringNotContainsString( 'button.disabled = false', $js );
    }

    /** Fix round 1, I4: the instruction box is on step 4 and the fallback goes there, so "the box" in the status is visible. */
    public function testTheHandoffFallbackIsShownOnTheStepThatHoldsIt(): void
    {
        $xpath = $this->dom( $this->render( true ) );
        $this->assertSame( 1, $xpath->query( '//section[@data-step="4"]//*[@id="aied-lab-instruction-box"]' )->length );
        $this->assertSame( 1, $xpath->query( '//section[@data-step="5"]//*[@id="aied-lab-handoff-2"]' )->length );
        $js = $this->src( 'assets/studio.js' );
        preg_match( '/function showInstruction\(.*?\n    \}/s', $js, $fallback );
        $this->assertNotEmpty( $fallback );
        $this->assertMatchesRegularExpression( "/goTo\(4\);.*instruction-box'\)\.hidden = false;.*aied-lab-instruction'\)\.focus\(\)/s", $fallback[0] );
        $this->assertStringContainsString( 'Instruction for your assistant', StudioScreen::config( 5 )['i18n']['copyManual'], 'the status names the box by its label' );
    }

    /**
     * Owner request (fix round 1): the steps column is narrow and the preview renders at a true device width, scaled
     * to fit. The widths the buttons mean are one table in PHP, in the config and in studio-core.js.
     */
    public function testThePreviewRendersAtATrueDeviceWidthBesideANarrowStepsColumn(): void
    {
        $this->assertSame( [ 'desktop' => 1280, 'tablet' => 768, 'phone' => 390 ], StudioScreen::DEVICES );
        $this->assertSame( StudioScreen::DEVICES, StudioScreen::config( 5 )['devices'] );
        $this->assertStringContainsString( 'var DEVICES = { desktop: 1280, tablet: 768, phone: 390 };', $this->src( 'assets/studio-core.js' ) );

        $css = $this->src( 'assets/studio.css' );
        $this->assertMatchesRegularExpression( '/\.aied-lab-layout\s*\{[^}]*grid-template-columns:\s*minmax\(340px,\s*420px\)\s+minmax\(0,\s*1fr\)/', $css );
        $this->assertMatchesRegularExpression( '/@media \(max-width: 1100px\)\s*\{\s*\.aied-lab-layout\s*\{\s*grid-template-columns:\s*1fr;/', $css, 'stacked at 1100px and below' );
        $this->assertDoesNotMatchRegularExpression( '/\.aied-lab-frame-wrap\[data-width/', $css, 'the device width is the frame\'s own, set by the script' );
        $this->assertMatchesRegularExpression( '/\.aied-lab-frame\s*\{[^}]*transform-origin:\s*0 0/', $css );

        $js = $this->src( 'assets/studio.js' );
        $this->assertStringContainsString( 'core.frameBox(', $js );
        $this->assertStringContainsString( 'ResizeObserver', $js );
        // The same box for the real preview and the approximation: one layout function, called after both.
        preg_match( '/function setFrame\(.*?\n    \}/s', $js, $set );
        $this->assertSame( 2, substr_count( $set[0] ?? '', 'layoutFrame()' ) );
    }

    /**
     * Found by the live gate (make verify-studio) at a 400px browser width: the plugin's tab bar (Dashboard, Features,
     * Lab, Settings and any add-on tabs) is one line and ran 75px past the screen edge on every tab, so the whole
     * admin page scrolled sideways. The tabs wrap onto a second line instead.
     */
    public function testTheTabBarWrapsOnANarrowScreen(): void
    {
        $css = (string) file_get_contents( __DIR__ . '/../wp-plugin/assets/admin.css' );
        $this->assertMatchesRegularExpression( '/\.aied-nav\s*\{[^}]*display:\s*flex;[^}]*flex-wrap:\s*wrap/', $css );
    }

    /**
     * Final fix pass, I2 (ruling 22): the hand-off names the tool StudioService::TOOL, which plan 3 adds. Until the MCP
     * tool catalog holds it, the screen shows no hand-off button, no instruction box, no "Get my assistant's words",
     * no sentence about an assistant, and the script never polls.
     */
    public function testTheAssistantHandoffIsHiddenUntilItsToolExists(): void
    {
        $this->assertNull( \AiEditorDivi5\WP\ToolCatalog::get( \AiEditorDivi5\WP\StudioService::TOOL ), 'plan 3 has not added the tool on this branch' );
        $this->assertFalse( StudioScreen::assistantReady() );
        $this->assertTrue( StudioScreen::assistantReady( static fn ( string $name ): ?array => [ 'name' => $name ] ) );
        $this->assertFalse( StudioScreen::config( 5 )['assistantReady'] );
        $this->assertTrue( StudioScreen::config( 5, true )['assistantReady'] );

        $ids  = [ 'aied-lab-handoff', 'aied-lab-refresh', 'aied-lab-instruction-box', 'aied-lab-instruction', 'aied-lab-handoff-2' ];
        $off  = $this->render();
        $on   = $this->render( true );
        foreach ( $ids as $id ) {
            $this->assertSame( 0, $this->dom( $off )->query( '//*[@id="' . $id . '"]' )->length, $id . ' is not shown' );
            $this->assertSame( 1, $this->dom( $on )->query( '//*[@id="' . $id . '"]' )->length, $id . ' is kept for plan 3' );
        }
        foreach ( $this->texts( $off ) as $text ) {
            $this->assertStringNotContainsStringIgnoringCase( 'assistant', $text, 'no sentence about an assistant while there is no tool' );
        }
        $js = $this->src( 'assets/studio.js' );
        $this->assertMatchesRegularExpression( '/function startPoll\(\) \{\s*if \(!cfg\.assistantReady\) \{ return; \}/', $js );
        $this->assertMatchesRegularExpression( "/if \(cfg\.assistantReady\) \{[^}]*addEventListener\('click', handoff\)/s", $js, 'the buttons are wired only when they exist' );
    }

    /**
     * Final fix pass, I1: a saved brief can be reopened. After a save or a create the address gets &brief=<id> (no
     * reload), a warning guards unsaved answers, and opening a brief that built a page gives Rebuild and Open in Divi
     * instead of Create.
     */
    public function testASavedBriefCanBeReopenedAndUnsavedAnswersAreGuarded(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createStudioTable();
        $GLOBALS['__wp_options'] = [ StudioTable::DB_VERSION_OPTION => StudioTable::DB_VERSION ];
        $saved = StudioStore::save( 5, [ 'version' => 1, 'title' => 'Acme', 'goal' => 'book', 'language' => 'editorial', 'sections' => [ [ 'recipe' => 'hero-cta' ] ] ] );
        $page            = new \WP_Post();
        $page->ID        = 321;
        $page->post_type = 'page';
        $GLOBALS['__wp_posts'][321] = $page;
        StudioStore::link( 5, $saved['id'], 321, str_repeat( 'a', 64 ) );
        $_GET['brief'] = (string) $saved['id'];
        $GLOBALS['__wp_caps']['edit_post:321'] = true;
        $brief = StudioScreen::config( 5 )['brief'];
        $this->assertSame( 321, $brief['page_id'] );
        $this->assertStringContainsString( 'post=321', (string) $brief['edit_url'] );
        $GLOBALS['__wp_caps']['edit_post:321'] = false;
        $this->assertNull( StudioScreen::config( 5 )['brief']['edit_url'], 'no link to a page the user may not edit' );
        unset( $GLOBALS['__wp_posts'][321] );
        $this->assertNull( StudioScreen::config( 5 )['brief']['edit_url'], 'no link to a page that is gone' );
        $GLOBALS['__wp_options'] = [];

        $js = $this->src( 'assets/studio.js' );
        $this->assertStringContainsString( "window.addEventListener('beforeunload'", $js );
        $this->assertMatchesRegularExpression( '/beforeunload[\s\S]{0,400}e\.preventDefault\(\);[\s\S]{0,120}e\.returnValue = t\.leaveWarning;/', $js );
        $this->assertStringContainsString( 'function remember(b)', $js );
        $this->assertSame( 2, substr_count( $js, "remember(x.sent);\n" ), 'the answers count as saved after a save and after a create' );
        $this->assertStringContainsString( "remember();\n        window.addEventListener('beforeunload'", $js, 'and as loaded at the start' );
        $this->assertStringContainsString( 'window.history.replaceState(null, \'\', core.briefUrl(window.location.href, briefId))', $js );
        $this->assertMatchesRegularExpression( "/function showCreated\([^)]*\) \{[\s\S]*?byId\('aied-lab-create'\)\.hidden = true;/", $js, 'once a page exists Create gives way to Rebuild' );
    }

    /** Final fix pass, I4a: the screen says honestly when the preview draft goes (the sweep runs only when the screen is used). */
    public function testThePreviewCleanupIsDescribedHonestly(): void
    {
        $text = implode( ' ', $this->texts( $this->render() ) );
        $this->assertStringNotContainsString( 'or after a day', $text );
        $this->assertSame( 2, substr_count( $text, 'the next time Lab makes a preview after a day' ) );
    }

    /** Final fix pass, S3: after Create the preview draft is gone, so the frame stops showing it. */
    public function testTheFrameIsClearedAfterCreate(): void
    {
        $js = $this->src( 'assets/studio.js' );
        $this->assertStringContainsString( "core.frameAfter('create', true) === 'clear'", $js );
        $this->assertArrayHasKey( 'previewRemoved', StudioScreen::config( 5 )['i18n'] );
    }
}
