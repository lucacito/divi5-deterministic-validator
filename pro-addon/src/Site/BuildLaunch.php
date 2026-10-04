<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Site;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\FrontPageKind;
use AiEditorDivi5\Pro\Settings\MenuBuilder;
use AiEditorDivi5\Pro\Tools\BuildSite;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The launch of a build: the only step that changes what visitors see. It publishes the build's draft pages, sets the page
 * flagged as the front page and rebuilds the live "Main Menu" from the published menu pages, as ONE previewed step (a dry
 * run returns a single-use, user-bound token) and ONE batch: the build's own batch grows by the status changes, the front
 * page and the menu, so one undo takes the whole site away again. Partial by design: a page that is gone, edited since the
 * preview, or whose slug was taken meanwhile is skipped (never published, never the front page, never in the menu) and the
 * rest goes live. A changed live front page or menu since the preview refuses the whole run instead: site settings are
 * never applied against a stale picture.
 */
final class BuildLaunch
{
    private const FRONT = -1;
    private const MENU  = -2;

    /** @param \Closure():int $clock */
    public function __construct( private BatchLog $log, private \Closure $clock )
    {
    }

    /** @param array<string,mixed> $row the open build @return array<string,mixed> */
    public function run( array $row, bool $dry, ?string $token ): array
    {
        $items = BuildSite::pageItems( $row );
        if ( [] === $items ) {
            throw new \InvalidArgumentException( 'This build has no pages yet. Add pages with add_page first.' );
        }
        $wantsMenu  = false;
        $wantsFront = false;
        foreach ( $items as $item ) {
            $wantsMenu  = $wantsMenu || ! empty( $item['meta']['in_menu'] );
            $wantsFront = $wantsFront || ! empty( $item['meta']['is_front'] );
        }
        if ( ! current_user_can( 'publish_pages' ) ) {
            throw new \InvalidArgumentException( 'You do not have permission to publish pages.' );
        }
        if ( $wantsMenu && ! current_user_can( 'edit_theme_options' ) ) {
            throw new \InvalidArgumentException( 'Launching this build changes the primary menu, which needs the edit_theme_options permission.' );
        }
        if ( $wantsFront && ! current_user_can( 'manage_options' ) ) {
            throw new \InvalidArgumentException( 'Launching this build sets the front page, which needs the manage_options permission.' );
        }

        $buildId     = (int) $row['id'];
        $location    = MenuBuilder::targetLocation();
        $frontBefore = FrontPageKind::read();
        $menuBefore  = MenuBuilder::snapshot( $location );
        $plan        = self::plan( $items );
        $map         = [ self::FRONT => Canon::hash( $frontBefore ), self::MENU => Canon::hash( MenuBuilder::canonical( $menuBefore ) ) ];
        foreach ( $plan as $p ) {
            if ( $p['will'] ) {
                $map[ $p['id'] ] = (string) $p['hash'];
            }
        }
        $argsHash = Canon::hash( [ 'build' => $buildId ] );
        $userId   = (int) get_current_user_id();

        if ( $dry ) {
            return $this->preview( $row, $plan, $frontBefore, $menuBefore, $location, PreviewToken::make( $argsHash, $map, ( $this->clock )(), $userId ) );
        }

        $opened = null === $token ? null : PreviewToken::open( $token, $argsHash, ( $this->clock )(), $userId );
        if ( null === $opened ) {
            throw new \InvalidArgumentException( 'A real launch needs the token from a dry run of the same build, made by you in the last 30 minutes and not used before. Call again with dry_run=true.' );
        }
        if ( ( $opened[ self::FRONT ] ?? null ) !== $map[ self::FRONT ] || ( $opened[ self::MENU ] ?? null ) !== $map[ self::MENU ] ) {
            throw new \InvalidArgumentException( 'The live front page or menu changed since the preview. Call again with dry_run=true.' );
        }
        PreviewToken::spend( (string) $token );

        return $this->apply( $row, $plan, $opened, $frontBefore, $menuBefore, $location );
    }

    /**
     * What each page would do: publish, or be skipped and why.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array{id:int, title:string, slug:string, meta:array<string,mixed>, will:bool, reason:?string, hash:?string}>
     */
    private static function plan( array $items ): array
    {
        $plan = [];
        foreach ( $items as $item ) {
            $id    = (int) $item['ref'];
            $meta  = is_array( $item['meta'] ?? null ) ? $item['meta'] : [];
            $post  = get_post( $id );
            $entry = [ 'id' => $id, 'title' => $post ? (string) $post->post_title : (string) ( $meta['menu_label'] ?? '' ), 'slug' => $post ? (string) ( $post->post_name ?? '' ) : '', 'meta' => $meta, 'will' => false, 'reason' => 'not_available', 'hash' => null ];
            if ( $post && 'page' === ( $post->post_type ?? 'page' ) && 'draft' === $post->post_status && current_user_can( 'edit_post', $id ) ) {
                $others = array_filter( array_map( 'intval', (array) get_posts( [ 'post_type' => 'page', 'name' => $entry['slug'], 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'fields' => 'ids', 'posts_per_page' => 5, 'no_found_rows' => true ] ) ), static fn ( int $o ): bool => $o !== $id );
                if ( '' !== $entry['slug'] && [] !== $others ) {
                    $entry['reason'] = 'slug_taken';
                } else {
                    $entry['will']   = true;
                    $entry['reason'] = null;
                    $entry['hash']   = Canon::hash( [ 'title' => (string) $post->post_title, 'content' => (string) $post->post_content, 'slug' => $entry['slug'], 'status' => (string) $post->post_status ] );
                }
            }
            $plan[] = $entry;
        }

        return $plan;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $plan
     * @param array{show_on_front:string, page_on_front:int} $frontBefore
     * @param array<string,mixed> $menuBefore
     * @return array<string,mixed>
     */
    private function preview( array $row, array $plan, array $frontBefore, array $menuBefore, string $location, string $token ): array
    {
        $pages = [];
        $front = null;
        $menu  = 0;
        foreach ( $plan as $p ) {
            $line = [ 'id' => $p['id'], 'title' => $p['title'], 'slug' => $p['slug'], 'will_publish' => $p['will'] ];
            if ( ! $p['will'] ) {
                $line['reason'] = $p['reason'];
            } else {
                $front = ! empty( $p['meta']['is_front'] ) ? $p['id'] : $front;
                $menu += ! empty( $p['meta']['in_menu'] ) ? 1 : 0;
            }
            $pages[] = $line;
        }
        $publish = count( array_filter( $plan, static fn ( array $p ): bool => $p['will'] ) );

        return [
            'changed'       => false,
            'dry_run'       => true,
            'build'         => BuildSite::summary( $row ),
            'would_publish' => $publish,
            'would_skip'    => count( $plan ) - $publish,
            'pages'         => $pages,
            'front_page'    => null === $front ? null : [ 'from' => $frontBefore['page_on_front'], 'to' => $front ],
            'menu'          => 0 === $menu ? null : [ 'location' => '' === $location ? null : $location, 'replaces_items' => count( (array) $menuBefore['items'] ), 'new_items' => $menu ],
            'token'         => $token,
            'message'       => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this token to publish these pages' . ( null === $front ? '' : ', set the front page' ) . ( 0 === $menu ? '' : ' and rebuild the menu' ) . '. Pages skipped now stay drafts.',
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $plan
     * @param array<int,string> $opened the preview's page => hash map (and the front/menu hashes)
     * @param array{show_on_front:string, page_on_front:int} $frontBefore
     * @param array<string,mixed> $menuBefore
     * @return array<string,mixed>
     */
    private function apply( array $row, array $plan, array $opened, array $frontBefore, array $menuBefore, string $location ): array
    {
        $buildId   = (int) $row['id'];
        $recorded  = [];
        $published = [];
        $skipped   = [];
        $live      = [];
        foreach ( $plan as $p ) {
            $base = [ 'id' => $p['id'], 'title' => $p['title'] ];
            if ( ! $p['will'] ) {
                $skipped[] = $base + [ 'reason' => $p['reason'] ];
                continue;
            }
            if ( ! array_key_exists( $p['id'], $opened ) ) {
                $skipped[] = $base + [ 'reason' => 'not_in_preview' ];
                continue;
            }
            if ( $opened[ $p['id'] ] !== $p['hash'] ) {
                $skipped[] = $base + [ 'reason' => 'changed_since_preview' ];
                continue;
            }
            if ( is_wp_error( wp_update_post( [ 'ID' => $p['id'], 'post_status' => 'publish' ], true ) ) ) {
                $skipped[] = $base + [ 'reason' => 'publish_failed' ];
                continue;
            }
            $now      = get_post( $p['id'] );
            $status   = $now ? (string) $now->post_status : 'publish';
            $realSlug = $now ? (string) ( $now->post_name ?? '' ) : $p['slug'];
            $line     = $base + [ 'slug' => $realSlug, 'link' => get_permalink( $p['id'] ) ];
            if ( $realSlug !== $p['slug'] ) {
                $line['slug_changed'] = [ 'planned' => $p['slug'], 'real' => $realSlug ];
            }
            $published[] = $line;
            $recorded[]  = Item::make( 'post_status', (string) $p['id'], 'Status of "' . $p['title'] . '" (#' . $p['id'] . ')', 'draft', $status );
            if ( 'publish' === $status ) {
                $live[] = $p;
            }
        }

        if ( [] === $published ) {
            return [ 'changed' => false, 'dry_run' => false, 'published' => [], 'skipped' => $skipped, 'build' => BuildSite::summary( $row ), 'message' => 'Nothing was published; the build is still open. Pages skipped now stay drafts.' ];
        }

        $frontId = null;
        foreach ( $live as $p ) {
            if ( ! empty( $p['meta']['is_front'] ) ) {
                $frontId = $p['id'];
                break;
            }
        }
        if ( null !== $frontId ) {
            FrontPageKind::set( 'page', $frontId );
            $recorded[] = Item::make( 'front_page', 'front_page', 'Front page', $frontBefore, FrontPageKind::read() );
        }

        $menu    = null;
        $message = 'The build was launched.';
        $menuIn  = array_values( array_filter( $live, static fn ( array $p ): bool => ! empty( $p['meta']['in_menu'] ) ) );
        if ( [] !== $menuIn ) {
            $menuItems = array_map( static fn ( array $p ): array => [
                'title' => '' !== (string) ( $p['meta']['menu_label'] ?? '' ) ? (string) $p['meta']['menu_label'] : $p['title'], 'type' => 'post_type', 'object' => 'page', 'object_id' => $p['id'], 'url' => '',
                'parent' => -1, 'classes' => '', 'target' => '', 'attr_title' => '', 'xfn' => '', 'description' => '',
            ], $menuIn );
            $added     = MenuBuilder::build( $menuItems, $location );
            $after     = MenuBuilder::snapshot( $location );
            $recorded[] = Item::make( 'menu', $location, 'Main Menu (primary navigation)', $menuBefore, MenuBuilder::canonical( $after ) );
            $menu      = [ 'items' => $added, 'location' => '' === $location ? null : $location ];
            if ( '' === $location ) {
                $message .= ' The menu "' . MenuBuilder::MENU_NAME . '" was built, but this theme has no menu location, so it was not assigned. Assign it under Appearance > Menus.';
            }
            if ( $added < count( $menuItems ) ) {
                $message .= ' ' . ( count( $menuItems ) - $added ) . ' of ' . count( $menuItems ) . ' menu items could not be added.';
            }
        }

        $stored = $this->log->append( $buildId, $recorded ) && $this->log->close( $buildId, 'applied' );
        $batch  = $stored ? BatchLog::describe( (array) $this->log->find( $buildId ) ) : [ 'id' => null, 'undoable' => false, 'note' => 'The build went live, but this could not be recorded, so undo_batch cannot undo it.' ];

        return [
            'changed'    => true,
            'dry_run'    => false,
            'published'  => $published,
            'skipped'    => $skipped,
            'front_page' => $frontId,
            'menu'       => $menu,
            'build'      => BuildSite::summary( (array) ( $this->log->find( $buildId ) ?? $row ) ),
            'batch'      => $batch,
            'message'    => $message,
        ];
    }
}
