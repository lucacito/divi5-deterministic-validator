<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\ThemeTemplateKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Site\PreviewToken;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
use AiEditorDivi5\Pro\ThemeBuilder\TemplateStore;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enable, disable or re-target (where it applies) ONE existing template of the live Theme Builder. It never creates,
 * duplicates or deletes a template and never changes which layouts a template uses. Every real run needs the token
 * from a dry run of the same arguments: it is bound to the template's state at the preview (a template that changed
 * since is refused), to the user who previewed, and to 30 minutes, and it works once.
 */
final class ManageThemeTemplate implements ProTool
{
    public const NAME = 'manage_theme_template';

    private const ACTIONS   = [ 'assign', 'enable', 'disable' ];
    private const MAX_RULES = 50;
    private const CONDITION = '/^[A-Za-z0-9_:\-]{1,200}$/D';

    private \Closure $clock;

    /** @param (\Closure():int)|null $clock the time in seconds (the tests move it) */
    public function __construct( private BatchLog $log, ?\Closure $clock = null )
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_theme_options';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Enable or disable one live Divi Theme Builder template, or change where it applies (assign: use_on / exclude_from, in the ids list_theme_layouts shows). Affects the whole site: call with dry_run=true first, then repeat with the token it returns. Undoable. Cannot create or delete templates.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'template_id'  => [ 'type' => 'integer', 'description' => 'template_id from list_theme_layouts' ],
                    'action'       => [ 'type' => 'string', 'description' => 'assign, enable or disable', 'enum' => self::ACTIONS ],
                    'use_on'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'assign only: where the template applies (replaces the current list)' ],
                    'exclude_from' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'assign only: where it must not apply (replaces the current list)' ],
                    'dry_run'      => [ 'type' => 'boolean', 'description' => 'Preview only; returns the token a real run needs' ],
                    'token'        => [ 'type' => 'string', 'description' => 'The token from your dry run of the same arguments; it works once, within 30 minutes' ],
                ],
                'required'   => [ 'template_id', 'action' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        LayoutTarget::assertMay();
        $id     = (int) Args::int( $arguments, 'template_id' );
        $action = (string) Args::string( $arguments, 'action', true, 20 );
        if ( ! in_array( $action, self::ACTIONS, true ) ) {
            throw new \InvalidArgumentException( '"action" must be one of: ' . implode( ', ', self::ACTIONS ) . '.' );
        }
        $dry   = Args::bool( $arguments, 'dry_run' );
        $token = Args::string( $arguments, 'token', false, 8000 );
        $rules = self::rules( $arguments, $action );

        $before = TemplateStore::read( $id );
        if ( null === $before ) {
            throw new \InvalidArgumentException( "Template {$id} is not a template of the live Theme Builder. Call list_theme_layouts to see the template ids." );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            throw new \InvalidArgumentException( "You do not have permission to change template {$id}." );
        }
        $after = self::target( $before, $action, $rules );
        $label = [ 'template_id' => $id, 'title' => $before['title'], 'default' => '1' === $before['default'] ];
        $diff  = self::diff( $before, $after );

        if ( [] === $diff ) {
            return [ 'changed' => false, 'dry_run' => $dry, 'template' => $label, 'message' => 'The template already is exactly like that; nothing changed.' ];
        }
        $argsHash  = Canon::hash( [ 'id' => $id, 'action' => $action, 'rules' => $rules ] );
        $stateHash = Canon::hash( $before );
        $userId    = (int) get_current_user_id();
        if ( $dry ) {
            return [ 'changed' => false, 'dry_run' => true, 'template' => $label, 'would_change' => $diff, 'token' => PreviewToken::make( $argsHash, [ $id => $stateHash ], ( $this->clock )(), $userId ), 'message' => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this token to apply it; it affects the whole site.' ];
        }
        $map = null === $token ? null : PreviewToken::open( $token, $argsHash, ( $this->clock )(), $userId );
        if ( null === $map || ! isset( $map[ $id ] ) || ! hash_equals( $map[ $id ], $stateHash ) ) {
            throw new \InvalidArgumentException( 'A real run needs the token from a dry run of the same arguments, made by you in the last 30 minutes, not used before and taken while the template was in its current state. Call again with dry_run=true.' );
        }
        PreviewToken::spend( (string) $token );

        $written = TemplateStore::write( $after );
        if ( null === $written ) {
            // Divi refused, or the template is no longer a live one: nothing was changed.
            throw new \RuntimeException( 'Divi did not save the template as requested, so nothing is recorded. Check list_theme_layouts for its current state.' );
        }
        $differs = self::diff( $after, $written );
        if ( [] === self::diff( $before, $written ) ) {
            // Divi accepted the write but the template is as it was: nothing changed, so there is nothing to undo or record.
            return [ 'changed' => false, 'dry_run' => false, 'template' => $label, 'differs_from_request' => $differs, 'message' => 'Divi accepted the write, but the template is exactly as it was; nothing changed and nothing is recorded. See differs_from_request.' ];
        }
        // Divi accepted the write, so record what it REALLY wrote (a normal, undoable batch), even if that differs from the request.
        $batch   = $this->log->record( self::NAME, ucfirst( $action ) . " Theme Builder template \"{$before['title']}\" (#{$id})", [ ThemeTemplateKind::itemFor( $before, $written ) ] );
        $result  = [ 'changed' => true, 'dry_run' => false, 'template' => $label, 'changed_fields' => self::diff( $before, $written ), 'note' => 'This applies to the whole site. Divi\'s caches were cleared.' ];
        if ( [] !== $differs ) {
            $result['differs_from_request'] = $differs;
            $result['note']                 = 'Divi saved the template differently than requested (see differs_from_request); what Divi saved is recorded, and undo_batch can put the previous state back. This applies to the whole site. Divi\'s caches were cleared.';
        }

        return BatchLog::attach( $result, $batch );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array{use_on:?list<string>, exclude_from:?list<string>}
     */
    private static function rules( array $arguments, string $action ): array
    {
        $out = [ 'use_on' => null, 'exclude_from' => null ];
        foreach ( [ 'use_on', 'exclude_from' ] as $key ) {
            if ( ! array_key_exists( $key, $arguments ) || null === $arguments[ $key ] ) {
                continue;
            }
            if ( 'assign' !== $action ) {
                throw new \InvalidArgumentException( "\"{$key}\" is only used with action=assign." );
            }
            $list = $arguments[ $key ];
            if ( ! is_array( $list ) || ! array_is_list( $list ) || count( $list ) > self::MAX_RULES ) {
                throw new \InvalidArgumentException( "\"{$key}\" must be a list of at most " . self::MAX_RULES . ' strings.' );
            }
            foreach ( $list as $c ) {
                if ( ! is_string( $c ) || 1 !== preg_match( self::CONDITION, $c ) ) {
                    throw new \InvalidArgumentException( "\"{$key}\" must contain only condition ids as list_theme_layouts shows them (letters, digits, \":\", \"_\", \"-\"; at most 200 characters)." );
                }
            }
            $out[ $key ] = array_values( array_unique( $list ) );
        }
        if ( 'assign' === $action && null === $out['use_on'] && null === $out['exclude_from'] ) {
            throw new \InvalidArgumentException( 'action=assign needs "use_on" and/or "exclude_from".' );
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $state
     * @param array{use_on:?list<string>, exclude_from:?list<string>} $rules
     * @return array<string,mixed>
     */
    private static function target( array $state, string $action, array $rules ): array
    {
        if ( 'enable' === $action ) {
            $state['enabled'] = '1';
        } elseif ( 'disable' === $action ) {
            $state['enabled'] = '0';
        } else {
            if ( '1' === $state['default'] ) {
                throw new \InvalidArgumentException( 'The default website template applies everywhere, so it has no "use_on" list to change. Enable or disable it instead.' );
            }
            $state['use_on']       = $rules['use_on'] ?? $state['use_on'];
            $state['exclude_from'] = $rules['exclude_from'] ?? $state['exclude_from'];
        }

        return $state;
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,array{from:mixed,to:mixed}>
     */
    private static function diff( array $a, array $b ): array
    {
        $out = [];
        foreach ( [ 'enabled', 'use_on', 'exclude_from' ] as $field ) {
            if ( $a[ $field ] !== $b[ $field ] ) {
                $out[ $field ] = [ 'from' => $a[ $field ], 'to' => $b[ $field ] ];
            }
        }

        return $out;
    }
}
