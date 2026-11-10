<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Kinds\PageCreatedKind;
use AiEditorDivi5\Pro\Lab\LabBriefs;
use AiEditorDivi5\Pro\Lab\LabCompile;
use AiEditorDivi5\Pro\Lab\LabLook;
use AiEditorDivi5\Pro\Lab\LabSupport;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Site\BuildLaunch;
use AiEditorDivi5\Pro\Site\BuildLock;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\StyleReferences;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Whole-site generation as one undoable build. `add_page` creates ONE validated DRAFT page per call and records it in one
 * growing batch (a "build"); an invalid page or a taken slug is refused at its own call and the build keeps every valid page.
 * `status` lists the build. Nothing becomes visible on the live site while pages are only added: the launch (publish, front
 * page, menu) is a separate, previewed step. Undo of the whole build is `undo_batch`.
 *
 * `add_page` takes either `content` (a Divi 5 layout) or `brief` (a Divi 5 Lab brief, compiled exactly as Lab compiles it).
 * The brief pages of one build share one look (design language + style kit): the first names it, later ones may leave it
 * out and take it, and a brief that names another look is refused (`LOOK_MISMATCH`). Each brief page's brief is stored in
 * the free Lab store and linked to the page, so the Lab screen lists it and can rebuild it; a person at the store's cap of
 * briefs is refused (`TOO_MANY_BRIEFS`) before anything is created. The `content` path is unchanged.
 */
final class BuildSite implements ProTool
{
    public const NAME        = 'build_site';
    public const MAX_PAGES   = 30;
    public const MAX_CONTENT = 1000000;

    private const ACTIONS = [ 'add_page', 'status', 'launch' ];

    /** The name the Lab guard gives this option in its refusal. */
    public const BRIEF_OPTION = "build_site's brief option";

    private BuildLock $lock;

    /** @var \Closure():int */
    private \Closure $clock;

    /**
     * @param (\Closure():int)|null               $clock        the time in seconds (the tests move it)
     * @param (\Closure(string):bool)|null        $classExists  seam for the Lab guard: whether a class is installed
     * @param (\Closure(string,string):bool)|null $methodExists seam for the Lab guard: whether a class has a method
     */
    public function __construct(
        private BatchLog $log,
        ?BuildLock $lock = null,
        ?\Closure $clock = null,
        private ?\Closure $classExists = null,
        private ?\Closure $methodExists = null
    ) {
        $this->lock  = $lock ?? new BuildLock();
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'publish_pages';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Build a whole Divi 5 site as one undoable build. add_page creates one checked DRAFT page per call (an invalid page or a taken slug is refused, the build continues); status lists it; launch publishes it (dry_run first, then repeat with the token). Undo it all: undo_batch.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'action'     => [ 'type' => 'string', 'description' => 'add_page, status or launch', 'enum' => self::ACTIONS ],
                    'title'      => [ 'type' => 'string', 'description' => 'add_page: the page title (plain text); not with brief' ],
                    'slug'       => [ 'type' => 'string', 'description' => 'add_page: the page slug; it must not be used by another page' ],
                    'content'    => [ 'type' => 'string', 'description' => 'add_page: the Divi 5 layout (it must pass every check), or pass brief instead' ],
                    'brief'      => [
                        'type'        => 'object',
                        'description' => 'add_page: a Divi 5 Lab brief instead of content (the same brief lab_build takes); its title is the page title. Later brief pages may leave out language and kit: they take the build\'s look',
                        'properties'  => [
                            'version'  => [ 'type' => 'integer' ],
                            'title'    => [ 'type' => 'string' ],
                            'goal'     => [ 'type' => 'string' ],
                            'language' => [ 'type' => 'string' ],
                            'kit'      => [ 'type' => 'string' ],
                            'sections' => [
                                'type'  => 'array',
                                'items' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'recipe'   => [ 'type' => 'string' ],
                                        'slots'    => [ 'type' => 'object', 'additionalProperties' => true ],
                                        'pictures' => [ 'type' => 'object', 'additionalProperties' => true ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'menu_label' => [ 'type' => 'string', 'description' => 'add_page: the menu label (default: the title), at most 100 bytes' ],
                    'in_menu'    => [ 'type' => 'boolean', 'description' => 'add_page: put the page in the primary menu at launch (default true if you may edit menus, else false)' ],
                    'is_front'   => [ 'type' => 'boolean', 'description' => 'add_page: make this the front page at launch (one page per build)' ],
                    'build_id'   => [ 'type' => 'integer', 'description' => 'The build to use: returned by the first add_page; required for later pages and for launch' ],
                    'dry_run'    => [ 'type' => 'boolean', 'description' => 'launch: preview only; returns the token a real launch needs' ],
                    'token'      => [ 'type' => 'string', 'description' => 'launch: the token from your dry run of the same build; it works once, within 30 minutes' ],
                ],
                'required'   => [ 'action' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $action = (string) Args::string( $arguments, 'action', true, 20 );
        if ( ! in_array( $action, self::ACTIONS, true ) ) {
            throw new \InvalidArgumentException( '"action" must be one of: ' . implode( ', ', self::ACTIONS ) . '.' );
        }

        return match ( $action ) {
            'add_page' => $this->addPage( $arguments ),
            'status'   => $this->status( $arguments ),
            default    => $this->launch( $arguments ),
        };
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private function addPage( array $a ): array
    {
        if ( array_key_exists( 'brief', $a ) && null !== $a['brief'] ) {
            return $this->addBriefPage( $a );
        }
        $title = trim( sanitize_text_field( (string) Args::string( $a, 'title', true, 200 ) ) );
        if ( '' === $title ) {
            throw new \InvalidArgumentException( '"title" must contain text.' );
        }
        $slug = sanitize_title( (string) Args::string( $a, 'slug', true, 200 ) );
        if ( '' === $slug ) {
            throw new \InvalidArgumentException( '"slug" must contain letters or digits.' );
        }
        if ( strlen( $slug ) > 200 ) {
            throw new \InvalidArgumentException( '"slug" is too long: at most 200 characters once cleaned (accents and symbols count for several).' );
        }
        $content = (string) Args::string( $a, 'content', true, self::MAX_CONTENT );
        $label   = array_key_exists( 'menu_label', $a ) && null !== $a['menu_label'] ? trim( sanitize_text_field( (string) Args::string( $a, 'menu_label', true, 100 ) ) ) : '';
        $canMenu = current_user_can( 'edit_theme_options' );
        // The default is "in the menu" only for someone who may launch a menu: a page flagged for a menu or the front page that its
        // owner may not change could never be launched, and no action edits a page's flags afterwards.
        $inMenu  = ! array_key_exists( 'in_menu', $a ) || null === $a['in_menu'] ? $canMenu : Args::bool( $a, 'in_menu' );
        $isFront = Args::bool( $a, 'is_front' );
        $buildId = Args::int( $a, 'build_id', false );
        if ( $inMenu && ! $canMenu ) {
            throw new \InvalidArgumentException( 'Putting a page in the primary menu needs the edit_theme_options permission, which you do not have. Pass "in_menu": false.' );
        }
        if ( $isFront && ! current_user_can( 'manage_options' ) ) {
            throw new \InvalidArgumentException( 'Making a page the front page needs the manage_options permission, which you do not have. Leave out "is_front".' );
        }

        $verdict = LayoutGate::check( $content );
        if ( ! $verdict->isValid() ) {
            throw new \InvalidArgumentException( self::refusal( $verdict->violations() ) );
        }

        $userId = (int) get_current_user_id();
        if ( ! $this->lock->acquire( $userId ) ) {
            throw new \InvalidArgumentException( 'Another add_page call is still running for you. Wait a moment and call again.' );
        }
        try {
            return $this->create( $userId, $buildId, $title, $slug, $content, '' === $label ? $title : $label, $inMenu, $isFront );
        } finally {
            $this->lock->release( $userId );
        }
    }

    /** @return array<string,mixed> */
    /**
     * add_page with a Lab brief: guard, the build's look filled in or enforced, the brief compiled exactly as Lab compiles
     * it, then the same lock, slug and insert path as a content page.
     *
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function addBriefPage( array $a ): array
    {
        if ( array_key_exists( 'content', $a ) && null !== $a['content'] ) {
            throw new \InvalidArgumentException( 'Pass either "content" or "brief", not both.' );
        }
        if ( array_key_exists( 'title', $a ) && null !== $a['title'] ) {
            throw new \InvalidArgumentException( 'Leave out "title" when you pass "brief": the brief\'s own title is the page title.' );
        }
        $brief = $a['brief'];
        if ( ! is_array( $brief ) || ( [] !== $brief && array_is_list( $brief ) ) ) {
            throw new \InvalidArgumentException( '"brief" must be an object.' );
        }
        $why = LabSupport::unsupported( self::BRIEF_OPTION, $this->classExists, $this->methodExists );
        if ( null !== $why ) {
            throw new \InvalidArgumentException( $why );
        }

        $slug = sanitize_title( (string) Args::string( $a, 'slug', true, 200 ) );
        if ( '' === $slug ) {
            throw new \InvalidArgumentException( '"slug" must contain letters or digits.' );
        }
        if ( strlen( $slug ) > 200 ) {
            throw new \InvalidArgumentException( '"slug" is too long: at most 200 characters once cleaned (accents and symbols count for several).' );
        }
        $label   = array_key_exists( 'menu_label', $a ) && null !== $a['menu_label'] ? trim( sanitize_text_field( (string) Args::string( $a, 'menu_label', true, 100 ) ) ) : '';
        $canMenu = current_user_can( 'edit_theme_options' );
        $inMenu  = ! array_key_exists( 'in_menu', $a ) || null === $a['in_menu'] ? $canMenu : Args::bool( $a, 'in_menu' );
        $isFront = Args::bool( $a, 'is_front' );
        $buildId = Args::int( $a, 'build_id', false );
        if ( $inMenu && ! $canMenu ) {
            throw new \InvalidArgumentException( 'Putting a page in the primary menu needs the edit_theme_options permission, which you do not have. Pass "in_menu": false.' );
        }
        if ( $isFront && ! current_user_can( 'manage_options' ) ) {
            throw new \InvalidArgumentException( 'Making a page the front page needs the manage_options permission, which you do not have. Leave out "is_front".' );
        }

        // The cap is checked first, before the lock and before any insert: a stored brief is never pruned to make room
        // (create() checks it again under the lock, right before the insert).
        $userId = (int) get_current_user_id();
        self::assertBriefRoom( $userId );

        // The build is read here to gate the brief before the lock (as the content path gates before the lock); create()
        // reads it again under the lock.
        $build  = $this->buildFor( $buildId, $userId );
        $brief  = self::fillLook( $brief, null === $build ? null : self::buildLook( $build ) );

        $compiled = LabCompile::compile( $brief );
        if ( empty( $compiled['ok'] ) || ! is_string( $compiled['markup'] ?? null ) ) {
            throw new \InvalidArgumentException( self::briefRefusal( $compiled ) );
        }
        $clean = LabCompile::clean( $brief );
        $title = (string) ( $clean['title'] ?? '' );
        $look  = LabLook::of( $brief );
        if ( '' === $title || null === $look ) {
            throw new \InvalidArgumentException( 'The page was not created: the brief could not be read. Check it with lab_build and try again.' );
        }
        $markup = $compiled['markup'];

        if ( ! $this->lock->acquire( $userId ) ) {
            throw new \InvalidArgumentException( 'Another add_page call is still running for you. Wait a moment and call again.' );
        }
        $lab = null;
        try {
            $result = $this->create( $userId, $buildId, $title, $slug, $markup, '' === $label ? $title : $label, $inMenu, $isFront, [ 'look' => $look ], $clean, $lab );
        } finally {
            $this->lock->release( $userId );
        }
        $stored        = is_array( $lab ) && true === $lab['stored'];
        $result['lab'] = [
            'brief_id' => $stored ? $lab['brief_id'] : null,
            'stored'   => $stored,
            'unfilled' => count( (array) $compiled['unfilled'] ),
            'report'   => [
                'problems'   => $compiled['problems'],
                'violations' => $compiled['violations'],
                'unfilled'   => $compiled['unfilled'],
                'refused'    => $compiled['refused'],
                'warnings'   => $compiled['warnings'],
                'advice'     => $compiled['advice'],
            ],
        ];
        if ( ! $stored ) {
            $result['lab']['note'] = 'The page was created and is in the build, but its brief could not be saved in Divi 5 Lab, so the Lab screen does not list this page.';
        }

        return $result;
    }

    /**
     * The brief with the build's look filled in, or a refusal. No look yet, or several (`'mixed'`): the brief must name its
     * language and nothing is enforced. One look: a brief without a language takes the whole look; a brief that names the
     * build's language without a kit takes the build's kit; any other look is `LOOK_MISMATCH`. An unknown language is left
     * to the compile, which reports it.
     *
     * @param array<string,mixed>                           $brief
     * @param array{language:string,kit:string}|string|null $look
     * @return array<string,mixed>
     */
    private static function fillLook( array $brief, array|string|null $look ): array
    {
        $named = array_key_exists( 'language', $brief ) && null !== $brief['language'];
        if ( ! is_array( $look ) ) {
            if ( ! $named ) {
                throw new \InvalidArgumentException( 'The page was not created: this brief must name its "language" (and may name its "kit"). '
                    . ( null === $look ? 'The first brief page of a build sets the look the later ones take.' : 'This build\'s pages have several looks, so every brief names its own.' ) );
            }

            return $brief;
        }
        $hasKit = array_key_exists( 'kit', $brief ) && null !== $brief['kit'];
        if ( ! $named ) {
            $brief = LabLook::with( $brief, $look['language'], $hasKit ? null : $look['kit'] );
        } elseif ( ! $hasKit && $brief['language'] === $look['language'] ) {
            $brief = LabLook::with( $brief, null, $look['kit'] );
        }
        $mine = LabLook::of( $brief );
        if ( null !== $mine && $mine !== $look ) {
            throw new \InvalidArgumentException( 'LOOK_MISMATCH: the page was not created. This build\'s pages use the design language "' . $look['language']
                . '" with the style kit "' . $look['kit'] . '", and this brief asks for another look. Leave out "language" and "kit" to use the build\'s look, '
                . 'restyle the build\'s pages with lab_restyle first, or start another build.' );
        }

        return $brief;
    }

    /** @param array<string,mixed> $compiled a failed `StudioCompiler::compile()` result */
    private static function briefRefusal( array $compiled ): string
    {
        $lines = [];
        foreach ( [ 'problems', 'violations' ] as $key ) {
            foreach ( (array) ( $compiled[ $key ] ?? [] ) as $p ) {
                if ( is_array( $p ) && is_string( $p['code'] ?? null ) ) {
                    $path    = substr( (string) ( $p['path'] ?? '' ), 0, 80 );
                    $lines[] = $p['code'] . ( '' === $path ? '' : ' at ' . $path ) . ( 'violations' === $key && is_string( $p['message'] ?? null ) ? ': ' . $p['message'] : '' );
                }
            }
        }
        $more  = count( $lines ) > 10 ? ' (and ' . ( count( $lines ) - 10 ) . ' more)' : '';
        $lines = array_slice( $lines, 0, 10 );

        return 'The page was not created: the brief did not pass. ' . ( [] === $lines ? 'It could not be built.' : implode( '; ', $lines ) . $more )
            . ' lab_build (action catalog) lists the goals, languages, kits, recipes and slots.';
    }

    /** Refuses a brief page when the person already holds the Lab store's maximum of briefs (nothing is pruned). */
    private static function assertBriefRoom( int $userId ): void
    {
        if ( LabBriefs::count( $userId ) >= LabBriefs::max() ) {
            throw new \InvalidArgumentException( 'TOO_MANY_BRIEFS: the page was not created. You already have ' . LabBriefs::max()
                . ' saved Divi 5 Lab briefs, the most Lab keeps. Delete briefs you no longer need in the Lab screen, then call again.' );
        }
    }

    /**
     * @param array<string,mixed>      $extraMeta added to the item's meta after the content path's keys (brief pages: `look`)
     * @param array<string,mixed>|null $brief     brief pages: the clean brief, stored and linked after the insert, before the append
     * @param array{brief_id:?int,stored:bool}|null $lab out: what happened to the brief
     */
    private function create( int $userId, ?int $buildId, string $title, string $slug, string $content, string $label, bool $inMenu, bool $isFront, array $extraMeta = [], ?array $brief = null, ?array &$lab = null ): array
    {
        $build = $this->buildFor( $buildId, $userId );
        $items = null === $build ? [] : self::pageItems( $build );
        if ( count( $items ) >= self::MAX_PAGES ) {
            throw new \InvalidArgumentException( 'A build holds at most ' . self::MAX_PAGES . ' pages. Launch it or undo it with undo_batch, then start another.' );
        }
        if ( $isFront ) {
            foreach ( $items as $item ) {
                if ( ! empty( $item['meta']['is_front'] ) ) {
                    throw new \InvalidArgumentException( 'Page #' . $item['ref'] . ' is already the front page of this build; only one page can be.' );
                }
            }
        }
        $taken = get_posts( [ 'post_type' => 'page', 'name' => $slug, 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true ] );
        if ( [] !== $taken ) {
            $holder = (int) $taken[0];
            throw new \InvalidArgumentException( 'The slug "' . $slug . '" is already used by ' . ( current_user_can( 'edit_post', $holder ) ? 'page #' . $holder : 'another page' ) . '. Choose a different slug.' );
        }

        if ( null !== $brief ) {
            self::assertBriefRoom( $userId );
        }

        $fresh = null === $build;
        if ( $fresh ) {
            $opened = $this->log->open( self::NAME, 'Site build' );
            if ( null === $opened ) {
                throw new \InvalidArgumentException( 'The build could not be started. Try again.' );
            }
            $buildId = (int) $opened['id'];
        } else {
            $buildId = (int) $build['id'];
        }

        // Always a draft; the Divi 5 builder flags make the page open in the Divi 5 editor. wp_slash: wp_insert_post unslashes.
        $id = wp_insert_post( wp_slash( [
            'post_type'    => 'page',
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'draft',
            'post_name'    => $slug,
            'meta_input'   => [ '_et_pb_use_divi_5' => 'on', '_et_pb_use_builder' => 'on' ],
        ] ), true );
        if ( is_wp_error( $id ) ) {
            if ( $fresh ) {
                $this->log->close( $buildId, 'undone' );
            }
            // WordPress's own error text stays out of the answer.
            throw new \InvalidArgumentException( 'WordPress could not create the page. Nothing was changed; try again.' );
        }
        $post = get_post( (int) $id );
        if ( null !== $brief ) {
            // The content hash is of the page as WordPress stored it (re-read), never of the markup that was sent.
            $lab                   = LabBriefs::storeAndLink( $userId, $brief, (int) $id, $post ? (string) $post->post_content : '' );
            $extraMeta['brief_id'] = $lab['brief_id'];
        }
        $item = PageCreatedKind::itemFor( $post, [ 'in_menu' => $inMenu, 'is_front' => $isFront, 'menu_label' => $label ] + $extraMeta );
        if ( ! $this->log->append( $buildId, [ $item ] ) ) {
            wp_delete_post( (int) $id, true );
            if ( null !== ( $lab['brief_id'] ?? null ) ) {
                LabBriefs::delete( $userId, (int) $lab['brief_id'] );
            }
            if ( $fresh ) {
                $this->log->close( $buildId, 'undone' );
            }
            throw new \InvalidArgumentException( 'The page could not be recorded in the build, so it was removed again. Try again.' );
        }

        return StyleReferences::attach( [
            'created' => true,
            'build'   => self::summary( (array) $this->log->find( $buildId ) ),
            'page'    => [ 'id' => (int) $id, 'title' => $title, 'slug' => $slug, 'status' => 'draft', 'link' => get_permalink( (int) $id ) ],
        ], $content );
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private function status( array $a ): array
    {
        $userId  = (int) get_current_user_id();
        $buildId = Args::int( $a, 'build_id', false );
        if ( null === $buildId ) {
            $row = $this->log->openBuild( $userId );
            if ( null === $row ) {
                throw new \InvalidArgumentException( 'You have no open build. Call add_page to start one.' );
            }
        } else {
            $row = $this->owned( $buildId, $userId );
        }
        $pages = [];
        foreach ( self::pageItems( $row ) as $item ) {
            $post    = get_post( (int) $item['ref'] );
            $pages[] = [
                'id'         => (int) $item['ref'],
                'title'      => $post ? (string) $post->post_title : '',
                'slug'       => $post ? self::cleanSlug( (string) ( $post->post_name ?? '' ) ) : '',
                'status'     => $post ? (string) $post->post_status : 'missing',
                'in_menu'    => ! empty( $item['meta']['in_menu'] ),
                'is_front'   => ! empty( $item['meta']['is_front'] ),
                'menu_label' => (string) ( $item['meta']['menu_label'] ?? '' ),
            ];
        }

        return [ 'build' => self::summary( $row ), 'pages' => $pages ];
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private function launch( array $a ): array
    {
        $buildId = (int) Args::int( $a, 'build_id' );
        $dry     = Args::bool( $a, 'dry_run' );
        $token   = Args::string( $a, 'token', false, 8000 );
        $userId  = (int) get_current_user_id();
        // The same per-user lock as add_page, taken BEFORE the build is read: a page appended while this call waited must be in
        // the build the launch plans, or it would be sealed into a launched build without ever having been launched.
        if ( ! $this->lock->acquire( $userId ) ) {
            throw new \InvalidArgumentException( 'Another build_site call is still running for you. Wait a moment and call again.' );
        }
        try {
            $row = $this->owned( $buildId, $userId );
            if ( BatchLog::BUILDING !== $row['status'] ) {
                throw new \InvalidArgumentException( "Build {$buildId} is finished (" . self::state( $row ) . ') and cannot be launched again.' );
            }

            return ( new BuildLaunch( $this->log, $this->clock ) )->run( $row, $dry, $token );
        } finally {
            $this->lock->release( $userId );
        }
    }

    /** The user's own build by id, whatever its state; anything else is "not found" (nothing leaks about other users' batches). @return array<string,mixed> */
    private function owned( int $buildId, int $userId ): array
    {
        $row = $this->log->find( $buildId );
        if ( null === $row || self::NAME !== $row['tool'] || (int) $row['user_id'] !== $userId ) {
            throw new \InvalidArgumentException( "Build {$buildId} was not found." );
        }

        return $row;
    }

    /** @return array<string,mixed>|null the open build to add to, or null: start a new one */
    private function buildFor( ?int $buildId, int $userId ): ?array
    {
        if ( null !== $buildId ) {
            $row = $this->owned( $buildId, $userId );
            if ( BatchLog::BUILDING !== $row['status'] ) {
                throw new \InvalidArgumentException( "Build {$buildId} is finished (" . self::state( $row ) . '). Call add_page without build_id to start a new build.' );
            }

            return $row;
        }
        $open = $this->log->openBuild( $userId );
        if ( null !== $open ) {
            throw new \InvalidArgumentException( 'You already have an open build (#' . $open['id'] . '). Pass "build_id": ' . $open['id'] . ' to add this page to it, or undo it with undo_batch to start over.' );
        }

        return null;
    }

    /** @param array<string,mixed> $row @return list<array<string,mixed>> the page_created items of a build, in add order */
    public static function pageItems( array $row ): array
    {
        return array_values( array_filter( (array) ( $row['items'] ?? [] ), static fn ( $i ): bool => is_array( $i ) && PageCreatedKind::NAME === ( $i['kind'] ?? '' ) ) );
    }

    /** @param array<string,mixed> $row */
    public static function state( array $row ): string
    {
        return match ( (string) $row['status'] ) {
            BatchLog::BUILDING => 'open',
            'applied'          => 'launched',
            default            => 'undone',
        };
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function summary( array $row ): array
    {
        $items = self::pageItems( $row );
        $front = null;
        foreach ( $items as $item ) {
            if ( ! empty( $item['meta']['is_front'] ) ) {
                $front = (int) $item['ref'];
            }
        }

        $summary = [
            'id'         => (int) $row['id'],
            'state'      => self::state( $row ),
            'pages'      => count( $items ),
            'in_menu'    => count( array_filter( $items, static fn ( $i ): bool => ! empty( $i['meta']['in_menu'] ) ) ),
            'front_page' => $front,
        ];
        // Only builds with a brief page carry a look, so a content-only build answers exactly as before.
        $look = self::buildLook( $row );
        if ( null !== $look ) {
            $summary['look'] = $look;
        }

        return $summary;
    }

    /**
     * The look of a build's brief pages: `{language, kit}` when they share one, `'mixed'` when they do not (only after a
     * partial restyle), null when the build has no brief page. Each page counts with the look of its brief as stored now
     * (a `lab_restyle` changes it), or with the look recorded when it was added when that brief is gone or was not stored.
     *
     * @param array<string,mixed> $row
     * @return array{language:string,kit:string}|string|null
     */
    public static function buildLook( array $row ): array|string|null
    {
        $looks = [];
        foreach ( self::pageItems( $row ) as $item ) {
            $look    = $item['meta']['look'] ?? null;
            $briefId = $item['meta']['brief_id'] ?? null;
            if ( null !== $look && is_int( $briefId ) && $briefId > 0 ) {
                $live = LabBriefs::brief( (int) ( $row['user_id'] ?? 0 ), $briefId );
                $live = null === $live ? null : LabLook::of( $live );
                if ( null !== $live ) {
                    $look = $live;
                }
            }
            if ( is_array( $look ) && is_string( $look['language'] ?? null ) && is_string( $look['kit'] ?? null ) ) {
                $one                                         = [ 'language' => $look['language'], 'kit' => $look['kit'] ];
                $looks[ $one['language'] . "\n" . $one['kit'] ] = $one;
            }
        }
        if ( [] === $looks ) {
            return null;
        }

        return 1 === count( $looks ) ? array_values( $looks )[0] : 'mixed';
    }

    /** A trashed page's slug carries core's `__trashed` suffix. */
    private static function cleanSlug( string $slug ): string
    {
        return (string) preg_replace( '/__trashed$/', '', $slug );
    }

    /** @param list<\Divi5Validator\Violation> $violations */
    private static function refusal( array $violations ): string
    {
        $lines = [];
        foreach ( array_slice( $violations, 0, 10 ) as $v ) {
            $a       = $v->toArray();
            $lines[] = $a['code'] . ' at ' . $a['path'] . ': ' . $a['message'];
        }
        $more = count( $violations ) > 10 ? ' (and ' . ( count( $violations ) - 10 ) . ' more)' : '';

        return 'The page was not created: it failed the checks. ' . implode( '; ', $lines ) . $more;
    }
}
