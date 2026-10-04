<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Kinds\PageCreatedKind;
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
 */
final class BuildSite implements ProTool
{
    public const NAME        = 'build_site';
    public const MAX_PAGES   = 30;
    public const MAX_CONTENT = 1000000;

    private const ACTIONS = [ 'add_page', 'status', 'launch' ];

    private BuildLock $lock;

    /** @var \Closure():int */
    private \Closure $clock;

    /** @param (\Closure():int)|null $clock the time in seconds (the tests move it) */
    public function __construct( private BatchLog $log, ?BuildLock $lock = null, ?\Closure $clock = null )
    {
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
                    'title'      => [ 'type' => 'string', 'description' => 'add_page: the page title (plain text)' ],
                    'slug'       => [ 'type' => 'string', 'description' => 'add_page: the page slug; it must not be used by another page' ],
                    'content'    => [ 'type' => 'string', 'description' => 'add_page: the Divi 5 layout (it must pass every check)' ],
                    'menu_label' => [ 'type' => 'string', 'description' => 'add_page: the menu label (default: the title), at most 100 bytes' ],
                    'in_menu'    => [ 'type' => 'boolean', 'description' => 'add_page: put the page in the primary menu at launch (default true)' ],
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
        $inMenu  = ! array_key_exists( 'in_menu', $a ) || null === $a['in_menu'] ? true : Args::bool( $a, 'in_menu' );
        $isFront = Args::bool( $a, 'is_front' );
        $buildId = Args::int( $a, 'build_id', false );

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
    private function create( int $userId, ?int $buildId, string $title, string $slug, string $content, string $label, bool $inMenu, bool $isFront ): array
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

        $fresh = null === $build;
        if ( $fresh ) {
            $opened = $this->log->open( self::NAME, 'Site build' );
            if ( null === $opened ) {
                throw new \RuntimeException( 'The build could not be started.' );
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
            throw new \RuntimeException( $id->get_error_message() );
        }
        $post = get_post( (int) $id );
        $item = PageCreatedKind::itemFor( $post, [ 'in_menu' => $inMenu, 'is_front' => $isFront, 'menu_label' => $label ] );
        if ( ! $this->log->append( $buildId, [ $item ] ) ) {
            wp_delete_post( (int) $id, true );
            if ( $fresh ) {
                $this->log->close( $buildId, 'undone' );
            }
            throw new \RuntimeException( 'The page could not be recorded in the build, so it was removed again. Try again.' );
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

        return [
            'id'         => (int) $row['id'],
            'state'      => self::state( $row ),
            'pages'      => count( $items ),
            'in_menu'    => count( array_filter( $items, static fn ( $i ): bool => ! empty( $i['meta']['in_menu'] ) ) ),
            'front_page' => $front,
        ];
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
