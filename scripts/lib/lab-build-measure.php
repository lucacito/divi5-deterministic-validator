<?php

declare(strict_types=1);

/**
 * DEV-ONLY (not part of the plugin zip). The measurement behind the "Building a page: a brief or Divi markup" section
 * of docs/token-usage.md: the SAME six-section page built as (a) a brief through lab_build, (b) compiled Divi markup
 * through create_page, plus (c) the tool's own overhead. Used by scripts/measure-tokens.php (which writes the numbers
 * into the doc) and by tests/LabBuildDocsTest.php (which checks the doc still says what this code measures).
 *
 * Everything goes through the real McpHandler, so the sizes are of the real answers. Bytes are measured; tokens are
 * ESTIMATED as bytes / 4, exactly as in the rest of the doc. No model is called.
 */

use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\StudioBrief;
use AiEditorDivi5\WP\StudioCompiler;
use AiEditorDivi5\WP\StudioSlots;
use AiEditorDivi5\WP\StudioStack;
use AiEditorDivi5\WP\SectionRecipes;

require_once __DIR__ . '/../../tests/FakeWpdb.php';
require_once __DIR__ . '/../../tests/WpRestShims.php';
require_once __DIR__ . '/../../tests/WpInsertPostShims.php';
require_once __DIR__ . '/../../wp-plugin/src/autoload.php';

final class LabBuildMeasure
{
    public const GOAL = 'book';

    public const LANGUAGE = 'editorial';

    public const TITLE = 'Acme Plumbing';

    /** Plain words an owner might supply; the filled text is built from them. Fixed, so the measurement is repeatable. */
    private const WORDS = [
        'plumbing', 'repairs', 'emergency', 'boiler', 'local', 'family', 'trusted', 'drains', 'leaks', 'quote',
        'friendly', 'certified', 'same-day', 'bathroom', 'kitchen', 'installation', 'service', 'customers', 'honest', 'pricing',
        'neighbours', 'reliable', 'heating', 'maintenance', 'book', 'visit', 'tidy', 'work', 'guaranteed', 'fast',
    ];

    /**
     * The six-section brief of the "book" goal, every slot filled with words of about the length of the recipe's own
     * sample copy (so the filled page is as long as the library page it replaces), real-looking links and the pack's
     * pictures. Not an invention of facts: the words are generic filler of realistic size.
     *
     * @return array<string,mixed>
     */
    public static function brief(): array
    {
        $sections = [];
        $n        = 0;
        foreach ( StudioStack::forGoal( self::GOAL ) as $recipe ) {
            $slots    = [];
            $pictures = [];
            foreach ( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $recipe ) ) as $slot ) {
                if ( 'picture' === $slot['kind'] ) {
                    // The recipe's own built-in pack image, as the brief writes it: {"pack": token}.
                    $token                   = (string) preg_replace( '/^\{\{aied:image:(.+)\}\}$/', '$1', (string) $slot['sample'] );
                    $pictures[ $slot['id'] ] = [ 'pack' => $token ];
                } elseif ( 'link' === $slot['kind'] ) {
                    $slots[ $slot['id'] ] = '/contact/';
                } else {
                    $slots[ $slot['id'] ] = self::words( strlen( trim( strip_tags( (string) $slot['sample'] ) ) ), $n++ );
                }
            }
            $section = [ 'recipe' => $recipe, 'slots' => $slots ];
            if ( [] !== $pictures ) {
                $section['pictures'] = $pictures;
            }
            $sections[] = $section;
        }

        return [ 'title' => self::TITLE, 'goal' => self::GOAL, 'language' => self::LANGUAGE, 'sections' => $sections ];
    }

    /** About $length characters of the fixed words, starting at a position that depends on $seed, cut at a word end. */
    private static function words( int $length, int $seed ): string
    {
        $length = max( 4, $length );
        $out    = '';
        $count  = count( self::WORDS );
        for ( $i = 0; strlen( $out ) < $length; $i++ ) {
            $out .= ( '' === $out ? '' : ' ' ) . self::WORDS[ ( $seed * 7 + $i * 3 ) % $count ];
        }

        return ucfirst( $out );
    }

    /**
     * @return array<string,int|float|string> every number the doc quotes (bytes), plus descriptive counts
     */
    public static function run(): array
    {
        $saved = [ $GLOBALS['wpdb'] ?? null, $GLOBALS['__wp_options'] ?? [], $GLOBALS['__wp_posts'] ?? [], $GLOBALS['__wp_current_user_id'] ?? null, $GLOBALS['__wp_can'] ?? null ];
        try {
            return self::measure();
        } finally {
            [ $GLOBALS['wpdb'], $GLOBALS['__wp_options'], $GLOBALS['__wp_posts'], $GLOBALS['__wp_current_user_id'], $GLOBALS['__wp_can'] ] = $saved;
        }
    }

    /** @return array<string,int|float|string> */
    private static function measure(): array
    {
        $fresh = static function (): void {
            $GLOBALS['wpdb'] = new \Divi5Validator\Tests\FakeWpdb();
            $GLOBALS['wpdb']->createStudioTable();
            $GLOBALS['wpdb']->createHistoryTable();
            $GLOBALS['__wp_options']         = [
                \AiEditorDivi5\WP\StudioTable::DB_VERSION_OPTION  => \AiEditorDivi5\WP\StudioTable::DB_VERSION,
                \AiEditorDivi5\WP\HistoryTable::DB_VERSION_OPTION => \AiEditorDivi5\WP\HistoryTable::STAGE_MIGRATED,
            ];
            $GLOBALS['__wp_transients']      = [];
            $GLOBALS['__wp_posts']           = [];
            $GLOBALS['__wp_postmeta']        = [];
            $GLOBALS['__wp_inserts']         = [];
            $GLOBALS['__wp_can']             = true;
            $GLOBALS['__wp_current_user_id'] = 5;
        };
        $call = static function ( string $tool, array $args ): array {
            $req  = new WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] );
            $data = ( new McpHandler() )->handle( $req )->get_data();
            if ( isset( $data['error'] ) ) {
                throw new RuntimeException( $tool . ' failed: ' . $data['error']['message'] );
            }

            return [ (string) $data['result']['content'][0]['text'], (bool) ( $data['result']['isError'] ?? false ) ];
        };
        $ok = static function ( bool $isError, string $what ): void {
            if ( $isError ) {
                throw new RuntimeException( $what . ' answered with an error.' );
            }
        };

        $brief    = self::brief();
        $compiled = StudioCompiler::compile( $brief );
        if ( ! $compiled['ok'] || [] !== $compiled['unfilled'] || [] !== $compiled['refused'] ) {
            throw new RuntimeException( 'The measurement brief must compile with every slot filled.' );
        }
        $markup = (string) $compiled['markup'];
        $slots  = 0;
        foreach ( $brief['sections'] as $s ) {
            $slots += count( $s['slots'] ) + count( $s['pictures'] ?? [] );
        }

        $out = [
            'sections'     => count( $brief['sections'] ),
            'slots_filled' => $slots,
            'brief_json'   => strlen( (string) json_encode( $brief ) ),
            'markup'       => strlen( $markup ),
        ];

        // (a) lab_build: the preview call, then the call that makes the draft.
        $fresh();
        $previewArgs = [ 'action' => 'build', 'brief' => $brief ];
        [ $text, $err ] = $call( 'lab_build', $previewArgs );
        $ok( $err, 'lab_build preview' );
        $out['a_preview_args']   = strlen( (string) json_encode( $previewArgs ) );
        $out['a_preview_answer'] = strlen( $text );
        $createArgs = $previewArgs + [ 'create' => true ];
        [ $text, $err ] = $call( 'lab_build', $createArgs );
        $ok( $err, 'lab_build create' );
        $out['a_create_args']   = strlen( (string) json_encode( $createArgs ) );
        $out['a_create_answer'] = strlen( $text );
        $out['a_created']       = false !== strpos( $text, '"created":true' ) ? 1 : 0;

        // (b) create_page with the compiled markup of the same brief.
        $fresh();
        $pageArgs = [ 'title' => self::TITLE, 'post_content' => $markup ];
        [ $text, $err ] = $call( 'create_page', $pageArgs );
        $ok( $err, 'create_page' );
        $out['b_args']    = strlen( (string) json_encode( $pageArgs ) );
        $out['b_answer']  = strlen( $text );
        $out['b_created'] = false !== strpos( $text, '"created":true' ) ? 1 : 0;

        // (c) the tool's own overhead: its tools/list entry and catalog answers.
        $list = ( new McpHandler() )->handle( new WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ] ) )->get_data();
        $entry = null;
        foreach ( (array) $list['result']['tools'] as $tool ) {
            if ( 'lab_build' === $tool['name'] ) {
                $entry = $tool;
            }
        }
        if ( null === $entry ) {
            throw new RuntimeException( 'lab_build is not in tools/list.' );
        }
        $out['c_tool_entry'] = strlen( (string) json_encode( $entry ) );
        $out['c_tools_total'] = strlen( (string) json_encode( $list['result'] ) );
        $out['c_tools_count'] = count( (array) $list['result']['tools'] );
        $fresh();
        [ $text, $err ] = $call( 'lab_build', [ 'action' => 'catalog' ] );
        $ok( $err, 'lab_build catalog' );
        $out['c_catalog'] = strlen( $text );
        [ $text, $err ] = $call( 'lab_build', [ 'action' => 'catalog', 'role' => 'hero' ] );
        $ok( $err, 'lab_build catalog role' );
        $out['c_catalog_role'] = strlen( $text );
        [ $text, $err ] = $call( 'lab_build', [ 'action' => 'catalog', 'recipe' => 'hero-cta' ] );
        $ok( $err, 'lab_build catalog recipe' );
        $out['c_catalog_recipe'] = strlen( $text );

        return $out;
    }

    /** bytes / 4, rounded up: the estimate used everywhere in docs/token-usage.md. */
    public static function tokens( int $bytes ): int
    {
        return (int) ceil( $bytes / 4 );
    }

    /**
     * The doc section, from the measured numbers. The test checks the doc against run() through the numbers it contains.
     *
     * @param array<string,int|float|string> $m
     */
    public static function markdown( array $m ): string
    {
        $n   = static fn ( int|float|string $v ): string => number_format( (int) $v );
        $row = static fn ( string $what, string $detail, int $bytes ): string => "| {$what} | {$detail} | " . number_format( $bytes ) . ' | ' . number_format( self::tokens( $bytes ) ) . " |\n";

        $aOne   = (int) $m['a_create_args'] + (int) $m['a_create_answer'];
        $aTwo   = (int) $m['a_preview_args'] + (int) $m['a_preview_answer'] + $aOne;
        $b      = (int) $m['b_args'] + (int) $m['b_answer'];
        $over   = (int) $m['c_tool_entry'] + (int) $m['c_catalog'];
        $md     = "\n## Building a page: a brief or Divi markup\n\n";
        $md    .= 'The same {SECTIONS}-section page ("{GOAL}" goal, {LANGUAGE} look, {SLOTS} filled slots) built two ways. ';
        $md    .= "A: the Lab tool `lab_build` takes a short brief (the design language, the ordered sections and the words, links and pictures for each) and the plugin builds the layout. ";
        $md    .= "B: the assistant sends the finished Divi markup to `create_page`. For B the markup is the compiled markup of the very same brief, so both make the same page.\n\n";
        $md    .= "| What | Detail | Bytes | Approx. tokens |\n| --- | --- | ---: | ---: |\n";
        $md    .= $row( 'A: the brief alone', 'the brief object (title, goal, language, sections with their words)', (int) $m['brief_json'] );
        $md    .= $row( 'A: preview call written', 'lab_build action "build" with the brief (nothing saved)', (int) $m['a_preview_args'] );
        $md    .= $row( 'A: preview answer read', 'the report only, the markup is never sent back', (int) $m['a_preview_answer'] );
        $md    .= $row( 'A: create call written', 'the same call with create true (saves the brief and one draft page)', (int) $m['a_create_args'] );
        $md    .= $row( 'A: create answer read', 'the page reference and the report', (int) $m['a_create_answer'] );
        $md    .= $row( 'B: the Divi markup alone', 'the compiled markup of the same brief, as plain text', (int) $m['markup'] );
        $md    .= $row( 'B: create_page call written', 'title and post_content, as JSON (the markup is escaped inside it)', (int) $m['b_args'] );
        $md    .= $row( 'B: create_page answer read', 'the page reference', (int) $m['b_answer'] );
        $md    .= $row( 'A total, create only', 'create call plus its answer', $aOne );
        $md    .= $row( 'A total, preview then create', 'both calls and both answers', $aTwo );
        $md    .= $row( 'B total', 'create_page call plus its answer', $b );
        $md    .= $row( 'lab_build overhead: tool definition', 'its entry in tools/list, read once per conversation', (int) $m['c_tool_entry'] );
        $md    .= $row( 'lab_build overhead: one catalog answer', 'action "catalog" with no filter, read once before building', (int) $m['c_catalog'] );
        $md    .= $row( 'lab_build catalog, role "hero"', 'action "catalog" filtered to one role, when asked for', (int) $m['c_catalog_role'] );
        $md    .= $row( 'lab_build catalog, one recipe', 'action "catalog" with recipe "hero-cta": its slot ids, kinds and sample text', (int) $m['c_catalog_recipe'] );
        $saving = $b - $aOne;
        $md    .= "\nWhat this shows: for this page the brief route writes and reads " . $n( $aOne ) . ' bytes (about ' . $n( self::tokens( $aOne ) ) . ' tokens) and the markup route ' . $n( $b ) . ' bytes (about ' . $n( self::tokens( $b ) ) . " tokens); the answers are small in both, so the difference is almost all in what the assistant has to WRITE. "
                . 'The one-time overhead of the brief route (the tool definition plus one catalog answer) is ' . $n( $over ) . ' bytes (about ' . $n( self::tokens( $over ) ) . ' tokens). '
                . ( $saving > $over ? 'The saving on this one page (' . $n( $saving ) . ' bytes) is larger than that overhead.' : 'The saving on this one page (' . $n( $saving ) . ' bytes) is not larger than that overhead, so it would not pay back on a page this size.' ) . "\n";
        $md    .= "\nWhat it does not show:\n\n";
        $md    .= "- The markup in B is the plugin's own compiled output. An assistant that writes Divi markup freehand writes something different: shorter and plainer, or longer when it styles every block itself. The comparison is the brief against the finished, styled, checked layout it stands for, not against what a given model would write.\n";
        $md    .= "- Neither route counts the reading that comes before building. Writing styled markup usually needs the style guide (see its row above); writing a brief does not, but the assistant reads the catalog once, which is counted in the overhead rows.\n";
        $md    .= "- The words, links and pictures are the same in both. The saving is the layout structure and styling, so it grows with the number of sections and shrinks for a page that is mostly long text. The words here are generic filler of about the length of the library's own sample copy, not a real business's copy.\n";
        $md    .= "- Tokens are estimated as bytes / 4. The real count depends on the model, and escaped JSON usually costs more tokens per byte than plain English.\n";
        $md    .= "- A brief that is previewed and changed pays for the preview call each time; markup that is sent and then fixed pays for the whole markup each time. Neither is counted.\n";
        $md    .= "- Only the sizes are measured. Nothing here shows how well any assistant uses either route.\n";

        return strtr( $md, [
            '{SECTIONS}' => (string) $m['sections'],
            '{GOAL}'     => self::GOAL,
            '{LANGUAGE}' => self::LANGUAGE,
            '{SLOTS}'    => (string) $m['slots_filled'],
        ] );
    }
}
