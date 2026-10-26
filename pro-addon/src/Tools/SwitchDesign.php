<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\Pro\Design\DesignSwitch;
use AiEditorDivi5\Pro\Design\Detector;
use AiEditorDivi5\Pro\Design\MarkerRecovery;
use AiEditorDivi5\Pro\Design\MarkerSpace;
use AiEditorDivi5\Pro\Design\SwitchResult;
use AiEditorDivi5\Pro\Kinds\PostContentKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Site\PreviewToken;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\EditTarget;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\LayoutDiff;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\PageHistory;
use AiEditorDivi5\WP\StyleKits;
use AiEditorDivi5\WP\StyleReferences;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Restyles Divi 5 pages and Divi Projects built with the free plugin's recipes from one design (design language + style
 * kit) to another, deterministically (spec 2026-10-25-pro-design-switch-design.md section 7; plan Task 8). The engine is
 * `Design\DesignSwitch`; this class is the `FindReplaceSite` pattern around it: strict arguments, per page
 * `EditTarget::resolve` (pages and Projects the user can edit; never a Theme Builder layout), a size check (a page whose
 * stored or new content is over `PageHistory::MAX_BYTES` has no undo point and is refused), detection of the source design
 * when it is not given, the shared layout gate with the stored content as baseline, a preview with a single-use
 * `PreviewToken` (page id => "stored hash:planned hash", so a page edited since the preview OR a planned output that
 * changed, for example after a free-plugin update, is skipped), every save through the free `HistoryService::write`
 * (snapshotted) and ONE undoable batch of `post_content` items.
 *
 * Run-time guard (spec issue 4): the engine needs free classes and kit carrier roles that the published free 4.14.0
 * does not ship. They are NOT in `Compat::REQUIRED_CLASSES` (that would switch every Pro tool off); this tool alone
 * refuses with a plain "update the free plugin" message when one is missing.
 */
final class SwitchDesign implements ProTool
{
    public const NAME      = 'switch_design';
    public const MAX_PAGES = 25;

    /** How many entries each list of a page's report carries (each has a `*_count` beside it). */
    public const REPORT_MAX = 25;

    /** The free classes the engine calls (beyond `Compat::REQUIRED_CLASSES`). */
    public const REQUIRED_FREE = [
        'AiEditorDivi5\\WP\\BlockAttrs',
        'AiEditorDivi5\\WP\\DesignLanguages',
        'AiEditorDivi5\\WP\\StyleKits',
        'AiEditorDivi5\\WP\\KitAdapters',
        'AiEditorDivi5\\WP\\KitResolver',
        'AiEditorDivi5\\WP\\LanguageAdapters',
        'AiEditorDivi5\\WP\\LanguageResolver',
        'AiEditorDivi5\\WP\\PageHistory',
        'AiEditorDivi5\\WP\\RecipeTokens',
        'AiEditorDivi5\\WP\\SectionRecipes',
    ];

    public const MESSAGE_FREE_TOO_OLD = 'switch_design needs a newer version of the free plugin "JHMG AI Editor for Divi 5" (one with the type, rhythm and carrier style adapters). Update the free plugin; the other Pro tools keep working.';

    private \Closure $clock;
    private \Closure $switch;
    private \Closure $classExists;
    private \Closure $kitRoles;

    /**
     * @param (\Closure(int,string,string):array<string,mixed>)|null $write fn(postId, content, tool): HistoryService::write()'s result
     * @param (\Closure():int)|null $clock fn(): unix time
     * @param (\Closure(string,Design,Design,string):SwitchResult)|null $switch fn(page, from, to, mode): the engine's result
     * @param (\Closure(string):bool)|null $classExists whether a free class is installed (the guard's seam)
     * @param (\Closure():?array<string,list<string>>)|null $kitRoles the installed free `KitAdapters::APPLIED` (block => roles), null when unreadable
     */
    public function __construct(
        private BatchLog $log,
        private ?\Closure $write = null,
        ?\Closure $clock = null,
        ?\Closure $switch = null,
        ?\Closure $classExists = null,
        ?\Closure $kitRoles = null
    ) {
        $this->clock       = $clock ?? static fn (): int => time();
        $this->switch      = $switch ?? static fn ( string $page, Design $from, Design $to, string $mode ): SwitchResult => DesignSwitch::run( $page, $from, $to, $mode );
        $this->classExists = $classExists ?? static fn ( string $class ): bool => class_exists( $class );
        $this->kitRoles    = $kitRoles ?? static function (): ?array {
            $const = 'AiEditorDivi5\\WP\\KitAdapters::APPLIED';

            return defined( $const ) && is_array( constant( $const ) ) ? constant( $const ) : null;
        };
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_pages';
    }

    public function definition(): array
    {
        $id = [ 'type' => 'integer', 'minimum' => 1 ];

        return [
            'name'        => self::NAME,
            'description' => 'Restyle Divi 5 pages built with this plugin to another design language and/or style kit, deterministically. It switches colours, fonts, headline type, section spacing and kit-styled modules. Always dry_run=true first, then repeat with the token. Undoable.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'page_id'       => $id + [ 'description' => 'One page or Divi Project id (or use page_ids)' ],
                    'page_ids'      => [ 'type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => self::MAX_PAGES, 'description' => 'Up to 25 page or Project ids, switched as one batch (or use page_id)' ],
                    'to_language'   => [ 'type' => 'string', 'description' => 'Target design language id (get_style_guide topic design-languages); omitted keeps the page\'s own' ],
                    'to_kit'        => [ 'type' => 'string', 'description' => 'Target style kit id, or "default" for the target language\'s own kit; omitted keeps the page\'s own' ],
                    'from_language' => [ 'type' => 'string', 'description' => 'Optional. The language the page was built with (detected otherwise)' ],
                    'from_kit'      => [ 'type' => 'string', 'description' => 'Optional. The kit the page was built with (detected otherwise)' ],
                    'mode'          => [ 'type' => 'string', 'enum' => DesignSwitch::MODES, 'description' => 'leave (default): only blocks that exactly match a kit style switch; conform: near matches are re-styled too' ],
                    'dry_run'       => [ 'type' => 'boolean', 'description' => 'Preview only; returns the report and the token a real run needs' ],
                    'token'         => [ 'type' => 'string', 'description' => 'The token from your dry run of the same arguments; it works once, within 30 minutes' ],
                ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $args = $this->arguments( $arguments );
        $this->guard();
        $this->validateIds( $args );
        $this->checkMode( $args['mode'] );

        $argsHash = Canon::hash( [ 'tool' => self::NAME, 'ids' => $args['ids'], 'to_language' => $args['to_language'], 'to_kit' => self::normalisedToKit( $args ), 'from_language' => $args['from_language'], 'from_kit' => $args['from_kit'], 'mode' => $args['mode'] ] );

        $plan = [];
        foreach ( $args['ids'] as $id ) {
            $plan[] = $this->plan( $id, $args );
        }

        if ( $args['dry_run'] ) {
            return $this->preview( $plan, $argsHash );
        }

        $map = null === $args['token'] ? null : PreviewToken::open( $args['token'], $argsHash, ( $this->clock )(), (int) get_current_user_id() );
        if ( null === $map ) {
            throw new \InvalidArgumentException( 'A real run needs the token from a dry run of the same arguments, made by you in the last 30 minutes and not used before. Call again with dry_run=true.' );
        }
        PreviewToken::spend( (string) $args['token'] );

        return $this->apply( $plan, $map );
    }

    /**
     * The installed free plugin's support for the switch, or the reason it has none (a refusal message). Public so the
     * guard can be tested with planted class lists.
     */
    public function unsupported(): ?string
    {
        foreach ( self::REQUIRED_FREE as $class ) {
            if ( ! ( $this->classExists )( $class ) ) {
                return self::MESSAGE_FREE_TOO_OLD;
            }
        }
        $applied = ( $this->kitRoles )();
        if ( ! is_array( $applied ) ) {
            return self::MESSAGE_FREE_TOO_OLD;
        }
        // Every (block, role) the engine recovers must be one the installed kit adapters apply (the carrier roles of
        // testimonial, blurb and number counter are the newest). A role the free plugin adds later is simply not recovered.
        foreach ( MarkerSpace::ROLE_BLOCKS as $role => $blocks ) {
            foreach ( $blocks as $block ) {
                if ( ! in_array( $role, (array) ( $applied[ $block ] ?? [] ), true ) ) {
                    return self::MESSAGE_FREE_TOO_OLD;
                }
            }
        }

        return null;
    }

    private function guard(): void
    {
        $why = $this->unsupported();
        if ( null !== $why ) {
            throw new \InvalidArgumentException( $why );
        }
    }

    /**
     * Strict argument parsing (types and shapes only; ids are checked against the installed lists after the guard).
     *
     * @param array<string,mixed> $a
     * @return array{ids:list<int>, to_language:?string, to_kit:?string, from_language:?string, from_kit:?string, mode:string, dry_run:bool, token:?string}
     */
    private function arguments( array $a ): array
    {
        $hasOne  = array_key_exists( 'page_id', $a ) && null !== $a['page_id'];
        $hasMany = array_key_exists( 'page_ids', $a ) && null !== $a['page_ids'];
        if ( $hasOne === $hasMany ) {
            throw new \InvalidArgumentException( 'Pass exactly one of "page_id" (one id) or "page_ids" (a list of 1 to ' . self::MAX_PAGES . ' ids).' );
        }
        if ( $hasOne ) {
            $ids = [ (int) Args::int( $a, 'page_id' ) ];
        } else {
            $ids = [];
            foreach ( Args::listOf( $a, 'page_ids', self::MAX_PAGES ) as $id ) {
                if ( ! is_int( $id ) || $id < 1 ) {
                    throw new \InvalidArgumentException( '"page_ids" must be a list of positive integers.' );
                }
                $ids[] = $id;
            }
            $ids = array_values( array_unique( $ids ) );
            sort( $ids );
        }
        $out = [ 'ids' => $ids ];
        foreach ( [ 'to_language', 'to_kit', 'from_language', 'from_kit' ] as $key ) {
            $out[ $key ] = Args::string( $a, $key, false, 64 );
            if ( null !== $out[ $key ] && '' === trim( $out[ $key ] ) ) {
                throw new \InvalidArgumentException( "\"{$key}\" must not be empty." );
            }
        }
        if ( null === $out['to_language'] && null === $out['to_kit'] ) {
            throw new \InvalidArgumentException( 'Pass "to_language", "to_kit" or both: the design to switch to.' );
        }
        $mode = Args::string( $a, 'mode', false, 20 ) ?? 'leave';
        if ( ! in_array( $mode, DesignSwitch::MODES, true ) ) {
            throw new \InvalidArgumentException( '"mode" must be one of: ' . implode( ', ', DesignSwitch::MODES ) . '.' );
        }
        $out['mode']    = $mode;
        $out['dry_run'] = Args::bool( $a, 'dry_run' );
        $out['token']   = Args::string( $a, 'token', false, 8000 );

        return $out;
    }

    /** @param array<string,mixed> $args */
    private function validateIds( array $args ): void
    {
        $languages = DesignLanguages::ids();
        $kits      = StyleKits::ids();
        foreach ( [ 'to_language', 'from_language' ] as $key ) {
            if ( null !== $args[ $key ] && ! in_array( $args[ $key ], $languages, true ) ) {
                throw new \InvalidArgumentException( sprintf( 'Unknown design language "%s" in "%s". Valid ids: %s.', $args[ $key ], $key, implode( ', ', $languages ) ) );
            }
        }
        foreach ( [ 'to_kit', 'from_kit' ] as $key ) {
            $ok = null === $args[ $key ] || in_array( $args[ $key ], $kits, true ) || ( 'to_kit' === $key && 'default' === $args[ $key ] );
            if ( ! $ok ) {
                throw new \InvalidArgumentException( sprintf( 'Unknown style kit "%s" in "%s". Valid ids: %s%s.', $args[ $key ], $key, 'to_kit' === $key ? 'default, ' : '', implode( ', ', $kits ) ) );
            }
        }
    }

    /** `conform` is opt-in and arrives with plan Task 7: until the engine has it, it is refused plainly (never a 500). */
    private function checkMode( string $mode ): void
    {
        if ( 'leave' === $mode ) {
            return;
        }
        try {
            $d = Design::make( (string) DesignLanguages::ids()[0], (string) StyleKits::ids()[0] );
            DesignSwitch::run( '', $d, $d, $mode );
        } catch ( \LogicException $e ) {
            if ( $e instanceof \InvalidArgumentException ) {
                throw $e;
            }
            throw new \InvalidArgumentException( "The \"{$mode}\" mode is not available in this version of the Pro add-on yet. Use mode \"leave\"." );
        }
    }

    /** The `to_kit` the argument hash carries: "default" resolved when the target language is given. @param array<string,mixed> $args */
    private static function normalisedToKit( array $args ): ?string
    {
        if ( 'default' === $args['to_kit'] && null !== $args['to_language'] ) {
            return self::ownKit( (string) $args['to_language'] );
        }

        return $args['to_kit'];
    }

    private static function ownKit( string $languageId ): string
    {
        $language = DesignLanguages::get( $languageId );

        return is_array( $language ) ? (string) ( $language['kit'] ?? '' ) : '';
    }

    /**
     * One page's plan: what the switch would write and whether it may.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function plan( int $id, array $args ): array
    {
        $target = EditTarget::resolve( $id );
        if ( null !== $target['error'] || null === $target['post'] ) {
            // Only the id and the reason: the user may not be allowed to know anything else about this post.
            return [ 'id' => $id, 'skip' => EditTarget::FORBIDDEN === $target['error'] ? 'forbidden' : 'not_found' ];
        }
        $post   = $target['post'];
        $stored = (string) $post->post_content;
        $row    = [ 'id' => $id, 'title' => (string) $post->post_title, 'kind' => (string) $target['kind'], 'stored' => $stored ];
        if ( strlen( $stored ) > PageHistory::MAX_BYTES ) {
            return $row + [ 'skip' => 'too_large_for_undo', 'detail' => 'The page is over ' . PageHistory::MAX_BYTES . ' bytes, so no undo point could be kept.' ];
        }

        $to = null;
        if ( null !== $args['to_language'] && null !== $args['to_kit'] ) {
            $to = Design::make( (string) $args['to_language'], (string) self::normalisedToKit( $args ) );
        }
        $found = Detector::detect( $stored, $args['from_language'], $args['from_kit'], $to );
        $from  = [ 'language' => $found['language'], 'kit' => $found['kit'], 'source' => $found['source'], 'confidence' => $found['confidence'] ];
        if ( null !== $found['refused'] ) {
            $skip = Detector::REFUSED_LOW_CONFIDENCE === $found['refused'] ? 'low_confidence' : 'detection_ambiguous';

            return $row + [ 'skip' => $skip, 'detail' => (string) $found['message'], 'from' => $from + [ 'runner_up' => $found['runner_up'] ] ];
        }
        $source     = Design::make( (string) $found['language'], (string) $found['kit'] );
        $toLanguage = $args['to_language'] ?? $source->languageId;
        $toKit      = match ( true ) {
            'default' === $args['to_kit'] => self::ownKit( $toLanguage ),
            null === $args['to_kit']      => $source->kitId,
            default                       => (string) $args['to_kit'],
        };
        $to  = Design::make( $toLanguage, $toKit );
        $row += [ 'from' => $from, 'to' => [ 'language' => $to->languageId, 'kit' => $to->kitId ] ];
        if ( $source->equals( $to ) ) {
            // `DesignSwitch::run()` returns the page unchanged when the source equals the target (plan Task 7), also in
            // `conform` mode, so a drifted block is not conformed to the page's own design: say so instead of "nothing to change".
            $detail = 'conform' === $args['mode']
                ? 'The page already has this design. conform does not tidy a page within its own design: nothing was changed.'
                : 'The page already has this design; nothing to change.';

            return $row + [ 'skip' => 'same_design', 'detail' => $detail ];
        }

        $result = ( $this->switch )( $stored, $source, $to, $args['mode'] );
        $row   += [ 'report' => self::report( $result ) ];
        if ( null !== $result->error ) {
            return $row + [ 'skip' => 'unresolved', 'detail' => 'The target design could not be applied to this page; it was left as it is.' ];
        }
        if ( ! $result->changed || 0 === $result->recovered() ) {
            return $row + [ 'skip' => 'nothing_to_switch', 'detail' => 'No colour, font or kit style of the source design was found to switch.' ];
        }
        $new = $result->content;
        if ( strlen( $new ) > PageHistory::MAX_BYTES ) {
            return $row + [ 'skip' => 'too_large_for_undo', 'detail' => 'The switched page would be over ' . PageHistory::MAX_BYTES . ' bytes, so no undo point could be kept.' ];
        }
        $gate = LayoutGate::check( $new, $stored );
        if ( ! $gate->isValid() ) {
            return $row + [ 'skip' => 'invalid_result', 'violations' => self::codes( $gate ) ];
        }

        return $row + [ 'new' => $new, 'hash' => substr( Canon::hash( $stored ), 0, 16 ) . ':' . substr( Canon::hash( $new ), 0, 16 ) ];
    }

    /**
     * The engine's report without noise: the left and assumed lists cut to REPORT_MAX with their totals, and the
     * report-only "larger match preferred" notes (a block switched as the larger of two matching kit styles: D4) only
     * counted, never listed one line per card.
     *
     * @return array<string,mixed>
     */
    public static function report( SwitchResult $r ): array
    {
        $out     = $r->toArray( self::REPORT_MAX );
        $listed  = [];
        $larger  = 0;
        foreach ( $r->assumed as $a ) {
            if ( MarkerRecovery::ASSUMED_LARGER === ( $a['reason'] ?? null ) ) {
                $larger++;
            } else {
                $listed[] = $a;
            }
        }
        $out['assumed']                = array_slice( $listed, 0, self::REPORT_MAX );
        $out['assumed_count']          = count( $listed );
        $out['larger_match_preferred'] = $larger;
        unset( $out['changed'], $out['error'] );

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $plan
     * @return array<string,mixed>
     */
    private function preview( array $plan, string $argsHash ): array
    {
        $pages = [];
        $map   = [];
        foreach ( $plan as $p ) {
            $row = self::row( $p );
            if ( isset( $p['new'] ) ) {
                $map[ $p['id'] ] = $p['hash'];
                $diff            = LayoutDiff::compare( $p['stored'], $p['new'] );
                $row            += [ 'valid' => true, 'violations' => [], 'diff' => $diff['diff']['summary'] ?? null ];
                $row             = StyleReferences::attach( $row, $p['new'], $p['stored'] );
            }
            $pages[] = $row;
        }
        $out = [
            'changed'      => false,
            'dry_run'      => true,
            'would_change' => count( $map ),
            'would_skip'   => count( $plan ) - count( $map ),
            'pages'        => $pages,
            'token'        => null,
        ];
        if ( [] === $map ) {
            return $out + [ 'message' => 'Preview only. No page would change, so there is no token.' ];
        }
        $out['token'] = PreviewToken::make( $argsHash, $map, ( $this->clock )(), (int) get_current_user_id() );

        return $out + [ 'message' => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this token to apply it.' ];
    }

    /**
     * @param list<array<string,mixed>> $plan
     * @param array<int,string> $map id => "stored hash:planned hash" the preview saw
     * @return array<string,mixed>
     */
    private function apply( array $plan, array $map ): array
    {
        $write   = $this->write ?? static fn ( int $id, string $content, string $tool ): array => HistoryService::write( $id, $content, $tool );
        $items   = [];
        $done    = [];
        $skipped = [];
        $targets = [];
        foreach ( $plan as $p ) {
            if ( ! isset( $p['new'] ) ) {
                $skipped[] = self::row( $p );
                continue;
            }
            $base = [ 'id' => $p['id'], 'title' => $p['title'], 'kind' => $p['kind'] ];
            if ( ! isset( $map[ $p['id'] ] ) ) {
                $skipped[] = $base + [ 'reason' => 'not_in_preview' ];
                continue;
            }
            if ( $map[ $p['id'] ] !== $p['hash'] ) {
                $skipped[] = $base + [ 'reason' => 'changed_since_preview' ];
                continue;
            }
            $result = $write( $p['id'], $p['new'], self::NAME );
            if ( empty( $result['ok'] ) ) {
                $skipped[] = $base + [ 'reason' => 'save_failed' ];
                continue;
            }
            $snapshot = is_array( $result['snapshot'] ?? null ) ? $result['snapshot'] : [];
            $now      = get_post( $p['id'] );
            $items[]  = PostContentKind::itemFor( $p['id'], $p['title'], $now ? (string) $now->post_content : $p['new'], $snapshot );
            $done[]   = $base + [ 'from' => $p['from'], 'to' => $p['to'] ];
            $targets[ $p['to']['language'] . ' + ' . $p['to']['kit'] ] = true;
        }
        if ( [] === $items ) {
            return [ 'changed' => false, 'dry_run' => false, 'switched' => 0, 'pages' => [], 'skipped' => $skipped, 'message' => 'Nothing was saved.' ];
        }
        $to    = 1 === count( $targets ) ? (string) array_key_first( $targets ) : 'their new designs';
        $batch = $this->log->record( self::NAME, 'Switched ' . count( $items ) . ' page(s) to ' . $to, $items );

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'switched' => count( $items ), 'pages' => $done, 'skipped' => $skipped ], $batch );
    }

    /**
     * What the client sees of a planned page (never the content).
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function row( array $p ): array
    {
        $row = [ 'id' => $p['id'] ];
        foreach ( [ 'title', 'kind', 'from', 'to' ] as $k ) {
            if ( array_key_exists( $k, $p ) ) {
                $row[ $k ] = $p[ $k ];
            }
        }
        if ( isset( $p['skip'] ) ) {
            $row['reason'] = $p['skip'];
            foreach ( [ 'detail', 'violations' ] as $k ) {
                if ( isset( $p[ $k ] ) ) {
                    $row[ $k ] = $p[ $k ];
                }
            }
            if ( 'invalid_result' === $p['skip'] ) {
                $row['valid'] = false;
            }
        }
        if ( isset( $p['report'] ) ) {
            $row['report'] = $p['report'];
        }

        return $row;
    }

    /** @return list<string> */
    private static function codes( \Divi5Validator\ValidationResult $gate ): array
    {
        $codes = [];
        foreach ( $gate->violations() as $v ) {
            $codes[ $v->toArray()['code'] ] = true;
        }

        return array_slice( array_keys( $codes ), 0, 10 );
    }
}
