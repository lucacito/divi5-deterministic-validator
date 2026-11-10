<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one implementation of the lab_build tool (the assistant's way into Lab), called by both the MCP handler and the
 * REST controller so the two can never drift: argument checks first, then one private method per action.
 *
 * `catalog` (what the library offers) and `open` (a stored brief and what it still lacks) only read. `fill` writes words
 * into a stored brief, guarded by the brief's hash so it can never overwrite what a person saved in the meantime.
 * `build` previews a brief you hand it (nothing is saved) and, with `create` true, saves it and a draft page. `fill` with
 * `create` true also builds (or rebuilds) the page after the words are saved. Building is always StudioService; this
 * class repeats none of its overwrite rules.
 */
final class StudioAssistant
{
    public const ENDPOINT = 'lab_build';

    /** Keys the tool accepts. Anything else is refused by name rather than ignored. */
    private const KNOWN = [ 'action', 'recipe', 'role', 'brief_id', 'base_hash', 'sections', 'brief', 'create' ];

    private const ACTIONS = [ 'catalog', 'open', 'fill', 'build' ];

    /** The one answer for a brief that is missing or belongs to another user (the two must look the same). */
    public const NOT_FOUND_MESSAGE = 'Brief not found. It may belong to another WordPress user than the one this connection signs in as.';

    /** The answer when a build would save one brief too many. */
    public const TOO_MANY_BRIEFS = 'TOO_MANY_BRIEFS';

    /** Slot ids (slots and pictures together) one entry of a fill may carry. */
    private const MAX_SLOT_IDS = 200;

    /** A sample text is cut to this many characters. */
    private const SAMPLE_CHARS = 80;

    /** A hash is 64 lowercase hex characters (sha256). */
    private const HASH_PATTERN = '/^[0-9a-f]{64}$/D';

    private const HOW = 'Lab builds a page from a short brief: a design language, a goal and an ordered list of ready-made sections (recipes), each with its words, links and pictures. '
        . 'Actions: catalog (this list; add recipe for one section\'s slots); open (read a stored brief: its base_hash and the empty slots); '
        . 'fill (write words: brief_id, the base_hash from open and sections; with create true the sections may be left out and the page is built from the stored brief); '
        . 'build (previews a whole brief you send, nothing is saved; with create true it saves the brief and a draft page). '
        . 'fill with create true also rebuilds the brief\'s draft unless the owner edited, published or deleted it (then a new draft is made and the reason given). '
        . 'If fill answers 409 CONFLICT the person changed the brief, nothing was written: open again and fill again with the new base_hash. '
        . 'Never invent facts, prices, names or numbers; leave a slot empty if you do not know it (it is reported as unfilled). '
        . 'An empty slot keeps its sample copy on the draft, and the items of a layout (cards, rows) cannot be removed or added, so pick layouts whose items you can fill; fill cannot add or remove sections (build with a new brief can). '
        . 'goal is only stored: it picks the sections the Lab screen suggests and does not change the page built. The language sets the overall look; a kit refines buttons, cards, rules and forms (default: the language\'s own; get_style_guide topic=style-kits lists them, if you have that tool). '
        . 'Everything is a draft the owner publishes; create true needs permission to publish pages.';

    /** One working brief for catalog (the tests check that it validates and builds; its words only show the format). */
    private const EXAMPLE_BRIEF = [
        'version'  => 1,
        'title'    => 'Example page title',
        'goal'     => 'explain',
        'language' => 'editorial',
        'kit'      => 'quiet-solid',
        'sections' => [
            [ 'recipe' => 'hero-cta', 'slots' => [ 'heading.1' => 'An example headline', 'button.0.text' => 'An example button' ] ],
            [ 'recipe' => 'card-grid-3' ],
        ],
    ];

    private const RULES = 'Fill only slots whose words you actually know. Leave the rest empty; they stay as placeholders and are listed as unfilled. '
        . 'fill needs the base_hash that open returned; a 409 CONFLICT means the person changed the brief, so open again. '
        . 'Links are https:// addresses, mailto:, tel:, #anchors or paths on this site starting with /. A picture is an object: {"pack": built-in pack token} or {"url": address}, where the address is https:// or a path on this site starting with /. '
        . 'Do not invent facts. The result is always a draft.';

    /**
     * @param array<string,mixed> $args
     * @return array{status:int, body:array<string,mixed>} status: 200 ok, 400 bad input, 403 not allowed, 404 brief not found, 409 conflict, 422 brief or layout refused, 500 failure
     */
    public static function run( array $args ): array
    {
        if ( ! current_user_can( PageAccess::CAP ) ) {
            UsageTracker::log( self::ENDPOINT, null, 'error' );

            return self::fail( 403, 'You do not have permission to use Lab.' );
        }

        $unknown = array_diff( array_keys( $args ), self::KNOWN );
        if ( array() !== $unknown ) {
            return self::fail( 400, 'Unknown argument(s): ' . implode( ', ', array_map( 'strval', $unknown ) ) . '. Use: ' . implode( ', ', self::KNOWN ) . '.' );
        }

        $action   = PageAccess::stringArg( $args, 'action' );
        $recipe   = PageAccess::stringArg( $args, 'recipe' );
        $role     = PageAccess::stringArg( $args, 'role' );
        $baseHash = PageAccess::stringArg( $args, 'base_hash' );
        $create   = PageAccess::boolArg( $args, 'create' );
        if ( null === $action || null === $recipe || null === $role || null === $baseHash ) {
            return self::fail( 400, 'action, recipe, role and base_hash must be strings.' );
        }
        if ( null === $create ) {
            return self::fail( 400, 'create must be a boolean (true or false).' );
        }
        if ( array_key_exists( 'sections', $args ) && null !== $args['sections'] ) {
            if ( ! is_array( $args['sections'] ) || ! self::isList( $args['sections'] ) ) {
                return self::fail( 400, 'sections must be a list of objects.' );
            }
            foreach ( $args['sections'] as $entry ) {
                if ( ! is_array( $entry ) ) {
                    return self::fail( 400, 'sections must be a list of objects.' );
                }
            }
        }
        // `brief` is only used by build: it must be an object.
        if ( array_key_exists( 'brief', $args ) && null !== $args['brief'] && ( ! is_array( $args['brief'] ) || ( array() !== $args['brief'] && self::isList( $args['brief'] ) ) ) ) {
            return self::fail( 400, 'brief must be an object.' );
        }
        $briefId = null;
        if ( array_key_exists( 'brief_id', $args ) && null !== $args['brief_id'] ) {
            if ( ! is_int( $args['brief_id'] ) || $args['brief_id'] < 1 ) {
                return self::fail( 400, 'brief_id must be a whole number of 1 or more.' );
            }
            $briefId = $args['brief_id'];
        }
        if ( '' === $action ) {
            return self::fail( 400, 'action is required. Use one of: ' . implode( ', ', self::ACTIONS ) . '.' );
        }
        if ( ! in_array( $action, self::ACTIONS, true ) ) {
            return self::fail( 400, 'Unknown action. Use one of: ' . implode( ', ', self::ACTIONS ) . '.' );
        }

        if ( $create ) {
            if ( 'fill' !== $action && 'build' !== $action ) {
                return self::fail( 400, 'create applies only to build and fill.' );
            }
            // Checked before anything is written, so a fill that cannot build does not save its words either.
            if ( ! current_user_can( 'publish_pages' ) ) {
                UsageTracker::log( self::ENDPOINT, null, 'error' );

                return self::fail( 403, 'You do not have permission to create pages with Lab (publishing pages is required).' );
            }
        }

        switch ( $action ) {
            case 'catalog':
                return self::catalog( $recipe, $role );
            case 'open':
                return self::open( $briefId );
            case 'fill':
                return self::fill( $briefId, $baseHash, $args['sections'] ?? null, $create );
            default:
                return self::build( $args['brief'] ?? null, $create );
        }
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function catalog( string $recipe, string $role ): array
    {
        if ( '' !== $recipe ) {
            if ( ! in_array( $recipe, SectionRecipes::names(), true ) ) {
                return self::fail( 400, 'Unknown recipe "' . SectionRecipes::plain( $recipe ) . '". Call catalog without recipe to list the names.' );
            }
            UsageTracker::log( self::ENDPOINT, null, 'read' );

            $info = SectionRecipes::describe( $recipe ) ?? [ 'title' => '', 'description' => '' ];

            return [ 'status' => 200, 'body' => [
                'recipe'      => $recipe,
                'title'       => $info['title'],
                'description' => $info['description'],
                'role'        => self::roleOf( $recipe ),
                'slots'       => self::slotList( $recipe ),
            ] ];
        }

        $recipes = [];
        foreach ( SectionRecipes::names() as $name ) {
            $recipes[ self::roleOf( $name ) ][] = $name;
        }
        if ( '' !== $role ) {
            if ( ! isset( $recipes[ $role ] ) ) {
                return self::fail( 400, 'Unknown role "' . SectionRecipes::plain( $role ) . '". Use one of: ' . implode( ', ', array_keys( $recipes ) ) . '.' );
            }
            $recipes = [ $role => $recipes[ $role ] ];
        }
        $languages = [];
        foreach ( DesignLanguages::ids() as $id ) {
            $languages[] = [ 'id' => $id, 'name' => (string) ( DesignLanguages::get( $id )['title'] ?? $id ) ];
        }
        UsageTracker::log( self::ENDPOINT, null, 'read' );

        return [ 'status' => 200, 'body' => [
            'goals'         => StudioBrief::GOALS,
            'languages'     => $languages,
            'kits'          => StyleKits::ids(),
            'recipes'       => $recipes,
            'pictures'      => [
                'pack' => array_values( array_filter( array_map( static fn ( array $i ): string => (string) ( $i['token'] ?? '' ), ImagePack::manifest()['images'] ), static fn ( string $t ): bool => '' !== $t ) ),
                'how'  => 'A picture slot takes {"pack": token} or {"url": address}; the address is https:// or a path on this site starting with /. Picture slots are listed with kind "picture".',
            ],
            'how'           => self::HOW,
            'example_brief' => self::EXAMPLE_BRIEF,
        ] ];
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function open( ?int $briefId ): array
    {
        if ( null === $briefId ) {
            return self::fail( 400, 'brief_id is required for open.' );
        }
        $stored = StudioStore::get( get_current_user_id(), $briefId );
        if ( null === $stored ) {
            UsageTracker::log( self::ENDPOINT, null, 'error' );

            return self::fail( 404, self::NOT_FOUND_MESSAGE );
        }

        $brief = $stored['brief'];
        $view  = self::sectionView( $brief );
        $page  = self::pageOf( $stored['page_id'] );
        UsageTracker::log( self::ENDPOINT, null === $page ? null : $page['id'], 'read' );

        return [ 'status' => 200, 'body' => [
            'brief_id'       => $stored['id'],
            'base_hash'      => $stored['brief_hash'],
            'brief'          => $brief,
            'sections'       => $view['sections'],
            'unfilled_count' => $view['unfilled'],
            'page'           => $page,
            'rules'          => self::RULES,
        ] ];
    }

    /**
     * Write words (and pictures) into sections of a stored brief. Everything is checked before anything is written; the
     * one write is a compare-and-set on the brief's hash, so a brief the person saved meanwhile is never overwritten.
     *
     * @param mixed $sections the raw `sections` argument (already known to be a list of arrays, or null)
     * @return array{status:int, body:array<string,mixed>}
     */
    private static function fill( ?int $briefId, string $baseHash, mixed $sections, bool $create = false ): array
    {
        if ( null === $briefId ) {
            return self::fail( 400, 'brief_id is required for fill.' );
        }
        if ( 1 !== preg_match( self::HASH_PATTERN, $baseHash ) ) {
            return self::fail( 400, 'base_hash is required for fill: the 64-character base_hash that open returned.' );
        }
        // With create true the words may all be written already: no sections then means "write nothing, build the page".
        $entries = $create && ( null === $sections || array() === $sections ) ? [] : self::fillEntries( $sections );
        if ( is_string( $entries ) ) {
            return self::fail( 400, $entries );
        }

        $user   = get_current_user_id();
        $stored = StudioStore::get( $user, $briefId );
        if ( null === $stored ) {
            UsageTracker::log( self::ENDPOINT, null, 'error' );

            return self::fail( 404, self::NOT_FOUND_MESSAGE );
        }
        $brief = $stored['brief'];
        $count = count( (array) ( $brief['sections'] ?? [] ) );
        foreach ( $entries as $entry ) {
            if ( $entry['section'] >= $count ) {
                return self::fail( 400, 'Section ' . $entry['section'] . ' does not exist: this brief has ' . $count . ' section(s), numbered from 0.' );
            }
        }

        $applied = [];
        $ignored = [];
        foreach ( $entries as $entry ) {
            $merged  = StudioMerge::slots( $brief, $entry['section'], $entry['incoming'] );
            $brief   = $merged['brief'];
            foreach ( $merged['applied'] as $id ) {
                $applied[] = [ 'section' => $entry['section'], 'slot' => $id ];
            }
            foreach ( $merged['ignored'] as $id ) {
                $ignored[] = [ 'section' => $entry['section'], 'slot' => $id ];
            }
        }

        if ( array() === $applied ) {
            // Nothing could be written: do not touch the row (a pointless save would change the hash and make the person's
            // open screen see a conflict), but still refuse a stale hash so the assistant learns it is out of date.
            if ( ! hash_equals( $stored['brief_hash'], $baseHash ) ) {
                return self::conflict( $stored['brief_hash'] );
            }
            $fresh = $stored;
        } else {
            $checked = StudioBrief::check( $brief );
            if ( ! $checked['ok'] ) {
                UsageTracker::log( self::ENDPOINT, null, 'invalid' );

                return [ 'status' => 422, 'body' => [
                    'code'     => StudioService::INVALID_BRIEF,
                    'error'    => 'The brief would have problems with these words; nothing was saved. See problems.',
                    'problems' => array_slice( array_map( static fn ( array $p ): array => [
                        'path'    => (string) ( $p['path'] ?? '' ),
                        'code'    => (string) ( $p['code'] ?? '' ),
                        'message' => (string) ( $p['message'] ?? '' ),
                    ], $checked['problems'] ), 0, StudioBrief::MAX_PROBLEMS ),
                ] ];
            }
            $saved = StudioStore::save( $user, (array) $checked['brief'], $briefId, $baseHash );
            if ( ! $saved['ok'] ) {
                UsageTracker::log( self::ENDPOINT, null, 'error' );
                if ( StudioStore::CONFLICT === $saved['code'] ) {
                    $current = StudioStore::get( $user, $briefId );
                    if ( null === $current ) { // the brief was deleted in the meantime: the same answer as a missing brief
                        return self::fail( 404, self::NOT_FOUND_MESSAGE );
                    }

                    return self::conflict( $current['brief_hash'] );
                }
                if ( StudioStore::NOT_FOUND === $saved['code'] ) {
                    return self::fail( 404, self::NOT_FOUND_MESSAGE );
                }
                if ( StudioStore::TOO_LARGE === $saved['code'] ) {
                    return self::fail( 422, 'The brief would be too large; nothing was saved.' );
                }

                return self::fail( 500, 'The brief could not be saved.' );
            }
            $fresh = StudioStore::get( $user, $briefId );
            if ( null === $fresh ) {
                return self::fail( 500, 'The brief could not be read back.' );
            }
        }
        $view  = self::sectionView( $fresh['brief'] );
        $saved = 'Saved ' . count( $applied ) . ' slot(s)' . ( array() === $ignored ? '' : '; ' . count( $ignored ) . ' could not be used (see ignored)' ) . '. '
            . $view['unfilled'] . ' slot(s) are still empty.';

        if ( $create ) {
            // The words are saved (or the stored brief was already current); now build the page from the STORED brief.
            // StudioService decides overwrite versus a new draft; nothing of its rules is repeated here.
            $built = null === $fresh['page_id']
                ? StudioService::create( $user, $fresh['brief'], $briefId )
                : StudioService::rebuild( $user, $briefId );
            if ( ! $built['ok'] ) {
                UsageTracker::log( self::ENDPOINT, null, 'error' );
                $failure = self::serviceFailure( $built );
                // Say plainly what the failed build did NOT undo, so the assistant does not write the words again.
                $failure['body']['words_saved'] = array() !== $applied;
                $failure['body']['brief_id']    = $fresh['id'];
                $failure['body']['base_hash']   = $fresh['brief_hash'];
                if ( array() !== $applied ) {
                    $failure['body']['message'] .= ' Your words were saved; only the page was not built.';
                }

                return $failure;
            }
            $pageId = (int) $built['page_id'];
            $mode   = isset( $built['mode'] ) ? (string) $built['mode'] : 'created';
            // The build may have saved the brief in its normalised form: answer with the hash that is stored NOW.
            $after = StudioStore::get( $user, $briefId );
            UsageTracker::log( self::ENDPOINT, $pageId, 'valid' );

            return [ 'status' => 200, 'body' => [
                'brief_id'       => $fresh['id'],
                'base_hash'      => null === $after ? $fresh['brief_hash'] : $after['brief_hash'],
                'applied'        => $applied,
                'ignored'        => $ignored,
                'unfilled_count' => $view['unfilled'],
                'report'         => $built['report'],
                'page'           => self::pageRef( $pageId, null ),
                'build'          => [ 'mode' => $mode, 'reason' => $built['reason'] ?? null, 'page' => self::pageRef( $pageId, $built['edit_url'] ?? null ) ],
                'message'        => $saved . ' ' . $built['message'],
            ] ];
        }

        $preview = StudioService::preview( $user, $fresh['brief'] );
        $page    = self::pageOf( $fresh['page_id'] );
        UsageTracker::log( self::ENDPOINT, null === $page ? null : $page['id'], 'valid' );

        return [ 'status' => 200, 'body' => [
            'brief_id'       => $fresh['id'],
            'base_hash'      => $fresh['brief_hash'],
            'applied'        => $applied,
            'ignored'        => $ignored,
            'unfilled_count' => $view['unfilled'],
            'report'         => $preview['report'],
            'page'           => $page,
            'message'        => $saved . ' The page itself was not changed.',
        ] ];
    }

    /**
     * Preview a whole brief (nothing is saved, the markup is never returned) or, with `create`, save it and make one
     * draft page through StudioService::create.
     *
     * @return array{status:int, body:array<string,mixed>}
     */
    private static function build( mixed $brief, bool $create ): array
    {
        if ( ! is_array( $brief ) || array() === $brief ) {
            return self::fail( 400, 'brief is required for build: the whole brief as an object (title, goal, language, sections).' );
        }
        $user = get_current_user_id();

        if ( ! $create ) {
            $preview = StudioService::preview( $user, $brief );
            if ( ! $preview['ok'] ) {
                UsageTracker::log( self::ENDPOINT, null, 'invalid' );
                $failure = self::serviceFailure( $preview );
                $failure['body'] = [ 'valid' => false, 'created' => false ] + $failure['body'] + [ 'report' => $preview['report'], 'markup_omitted' => true ];

                return $failure;
            }
            UsageTracker::log( self::ENDPOINT, null, 'valid' );

            return [ 'status' => 200, 'body' => [
                'valid'          => true,
                'created'        => false,
                'report'         => $preview['report'],
                'markup_omitted' => true,
                'message'        => $preview['message'],
                'next'           => 'Nothing was saved. Call build again with create true to save this brief and a draft page; or save the words in a Lab brief and use fill.',
            ] ];
        }

        if ( self::wouldPushOutABrief( $user, $brief ) ) {
            UsageTracker::log( self::ENDPOINT, null, 'error' );

            return [ 'status' => 422, 'body' => [
                'code'    => self::TOO_MANY_BRIEFS,
                'created' => false,
                'message' => 'You already have ' . StudioStore::MAX_PER_USER . ' saved Lab briefs. Delete some in the Lab screen before the assistant saves another.',
            ] ];
        }

        $made = StudioService::create( $user, $brief, null );
        if ( ! $made['ok'] ) {
            UsageTracker::log( self::ENDPOINT, null, 'error' );
            $failure         = self::serviceFailure( $made );
            $failure['body'] = [ 'created' => false ] + $failure['body'] + [ 'report' => $made['report'] ];

            return $failure;
        }
        $pageId = (int) $made['page_id'];
        $stored = null === $made['brief_id'] ? null : StudioStore::get( $user, (int) $made['brief_id'] );
        UsageTracker::log( self::ENDPOINT, $pageId, 'valid' );

        return [ 'status' => 200, 'body' => [
            'created'   => true,
            'page'      => self::pageRef( $pageId, $made['edit_url'] ),
            'brief_id'  => $made['brief_id'],
            'base_hash' => null === $stored ? null : $stored['brief_hash'],
            'duplicate' => (bool) $made['duplicate'],
            'report'    => $made['report'],
            'message'   => $made['message'] . ' It is a draft: the owner reviews and publishes it.',
        ] ];
    }

    /**
     * True when saving this brief as a NEW row would make the store drop the user's oldest brief (the store keeps
     * MAX_PER_USER). An assistant retrying build in a loop must not be able to wipe a person's unfinished briefs, so the
     * tool refuses instead. Not the case for: a brief that does not validate (the service refuses it on its own, writing
     * nothing), and a repeat of a brief just turned into a live page (the service returns that page and saves nothing).
     */
    private static function wouldPushOutABrief( int $user, array $brief ): bool
    {
        if ( StudioStore::countFor( $user ) < StudioStore::MAX_PER_USER ) {
            return false;
        }
        $checked = StudioBrief::check( $brief );
        if ( ! $checked['ok'] ) {
            return false;
        }
        $hash   = StudioStore::hashOf( (array) $checked['brief'] );
        $recent = null === $hash ? null : StudioStore::recentlyLinked( $user, $hash, StudioService::DUPLICATE_SECONDS );
        if ( null !== $recent && null !== $recent['page_id'] ) {
            $post = get_post( $recent['page_id'] );
            if ( $post && 'trash' !== (string) ( $post->post_status ?? '' ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Shape-check the `sections` argument of fill.
     *
     * @return list<array{section:int, incoming:array<string,mixed>}>|string the entries, or the 400 message
     */
    private static function fillEntries( mixed $sections ): array|string
    {
        if ( ! is_array( $sections ) || array() === $sections ) {
            return 'sections is required for fill: a list of 1 to ' . StudioBrief::MAX_SECTIONS . ' objects like {"section":0,"slots":{...},"pictures":{...}}.';
        }
        if ( count( $sections ) > StudioBrief::MAX_SECTIONS ) {
            return 'At most ' . StudioBrief::MAX_SECTIONS . ' sections per call.';
        }
        $seen = [];
        $out  = [];
        foreach ( $sections as $entry ) {
            if ( ! is_array( $entry ) || ( array() !== $entry && self::isList( $entry ) ) ) {
                return 'Each entry of sections must be an object like {"section":0,"slots":{...},"pictures":{...}}.';
            }
            $unknown = array_diff( array_keys( $entry ), [ 'section', 'slots', 'pictures' ] );
            if ( array() !== $unknown ) {
                return 'Unknown key in a sections entry: ' . implode( ', ', array_map( 'strval', $unknown ) ) . '. Use section, slots, pictures.';
            }
            if ( ! isset( $entry['section'] ) || ! is_int( $entry['section'] ) || $entry['section'] < 0 ) {
                return 'Each sections entry needs "section": a whole number of 0 or more.';
            }
            if ( isset( $seen[ $entry['section'] ] ) ) {
                return 'Section ' . $entry['section'] . ' appears twice; send each section once.';
            }
            $seen[ $entry['section'] ] = true;
            $incoming = [];
            foreach ( [ 'slots', 'pictures' ] as $key ) {
                if ( ! array_key_exists( $key, $entry ) ) {
                    continue;
                }
                if ( ! is_array( $entry[ $key ] ) || ( array() !== $entry[ $key ] && self::isList( $entry[ $key ] ) ) ) {
                    return $key . ' must be an object of slot id => value.';
                }
                foreach ( $entry[ $key ] as $id => $value ) {
                    if ( array_key_exists( (string) $id, $incoming ) ) {
                        return 'Slot "' . SectionRecipes::plain( (string) $id ) . '" appears in both slots and pictures.';
                    }
                    $incoming[ (string) $id ] = $value;
                }
            }
            if ( count( $incoming ) > self::MAX_SLOT_IDS ) {
                return 'Section ' . $entry['section'] . ' has more than ' . self::MAX_SLOT_IDS . ' slot ids; send fewer per section.';
            }
            if ( array() === $incoming ) {
                return 'Section ' . $entry['section'] . ' has no slots or pictures to write.';
            }
            $out[] = [ 'section' => $entry['section'], 'incoming' => $incoming ];
        }

        return $out;
    }

    /**
     * Every section of a brief with its slots, and how many slots are still empty.
     *
     * @param array<string,mixed> $brief
     * @return array{sections:list<array<string,mixed>>, unfilled:int}
     */
    private static function sectionView( array $brief ): array
    {
        $unfilled = 0;
        $sections = [];
        foreach ( (array) ( $brief['sections'] ?? [] ) as $i => $section ) {
            $section  = (array) $section;
            $name     = (string) ( $section['recipe'] ?? '' );
            $resolved = StudioBrief::resolveRecipe( $name );
            $words    = (array) ( $section['slots'] ?? [] );
            $images   = (array) ( $section['pictures'] ?? [] );
            $slots    = [];
            foreach ( null === $resolved ? [] : self::slotList( $resolved ) as $slot ) {
                $value  = 'picture' === $slot['kind'] ? ( $images[ $slot['id'] ] ?? '' ) : ( $words[ $slot['id'] ] ?? '' );
                $filled = '' !== $value && [] !== $value;
                $unfilled += $filled ? 0 : 1;
                $slots[]   = $slot + [ 'value' => $value, 'filled' => $filled ];
            }
            $entry = [ 'section' => (int) $i, 'recipe' => $name, 'slots' => $slots ];
            if ( null === $resolved ) {
                $entry['recipe_missing'] = true;
            }
            $sections[] = $entry;
        }

        return [ 'sections' => $sections, 'unfilled' => $unfilled ];
    }

    /**
     * The linked page, only when it exists and the current user may edit it (a page someone else wrote must not leak its
     * id or status through a brief that happens to link to it).
     *
     * @return array{id:int,status:string}|null
     */
    private static function pageOf( ?int $pageId ): ?array
    {
        if ( null === $pageId || ! current_user_can( 'edit_post', $pageId ) ) {
            return null;
        }
        $post = get_post( $pageId );

        return $post ? [ 'id' => $pageId, 'status' => (string) ( $post->post_status ?? '' ) ] : null;
    }

    /** True for an array whose keys are 0..n-1 (array_is_list needs WordPress 6.5). */
    private static function isList( array $a ): bool
    {
        return array_keys( $a ) === range( 0, count( $a ) - 1 ) || array() === $a;
    }

    private static function roleOf( string $recipe ): string
    {
        $role = (string) ( SectionRecipes::tags( $recipe )['role'] ?? '' );

        return '' === $role ? 'other' : $role;
    }

    /** @return list<array{id:string, kind:string, role:string, sample:string}> */
    private static function slotList( string $recipe ): array
    {
        $out = [];
        foreach ( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $recipe ) ) as $slot ) {
            $sample = 'picture' === $slot['kind'] ? '' : self::sampleOf( (string) $slot['sample'] );
            $out[]  = [ 'id' => $slot['id'], 'kind' => $slot['kind'], 'role' => $slot['role'], 'sample' => $sample ];
        }

        return $out;
    }

    /** A sample text cut to SAMPLE_CHARS at a word boundary, with an ellipsis when something was left out. */
    private static function sampleOf( string $html ): string
    {
        $text = trim( wp_strip_all_tags( $html ) );
        if ( mb_strlen( $text ) <= self::SAMPLE_CHARS ) {
            return $text;
        }
        $cut = mb_substr( $text, 0, self::SAMPLE_CHARS );
        if ( 1 !== preg_match( '/^\s$/u', mb_substr( $text, self::SAMPLE_CHARS, 1 ) ) && 1 === preg_match( '/^(.{20,})\s\S*$/us', $cut, $m ) ) {
            $cut = $m[1];
        }

        return rtrim( $cut ) . '…';
    }

    /**
     * The page a build made, with its real status (a duplicate answer may point at a page that has been published since).
     *
     * @return array{id:int,status:string,link:?string}
     */
    private static function pageRef( int $pageId, ?string $link ): array
    {
        $post = get_post( $pageId );

        return [ 'id' => $pageId, 'status' => $post ? (string) ( $post->post_status ?? 'draft' ) : 'draft', 'link' => $link ];
    }

    /** A service refusal as a tool answer: its code and message only (the callers add a report where it helps). */
    private static function serviceFailure( array $result ): array
    {
        $code = (string) ( $result['code'] ?? StudioService::INTERNAL_ERROR );

        return [ 'status' => self::serviceStatus( $code ), 'body' => [ 'code' => $code, 'message' => (string) ( $result['message'] ?? '' ) ] ];
    }

    /** INVALID_BRIEF, LAYOUT_INVALID and RECIPE_REMOVED are the caller's to fix (422), NOT_FOUND is 404, everything else 500. */
    private static function serviceStatus( string $code ): int
    {
        return match ( $code ) {
            StudioService::INVALID_BRIEF, StudioService::LAYOUT_INVALID, StudioService::RECIPE_REMOVED => 422,
            StudioService::NOT_FOUND => 404,
            default => 500,
        };
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function conflict( string $currentHash ): array
    {
        return [ 'status' => 409, 'body' => [
            'code'              => StudioStore::CONFLICT,
            'current_base_hash' => $currentHash,
            'message'           => 'The brief was changed after you opened it, so nothing was written. Open it again and fill again with the new base_hash.',
        ] ];
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function fail( int $status, string $message ): array
    {
        return [ 'status' => $status, 'body' => [ 'error' => $message ] ];
    }
}
