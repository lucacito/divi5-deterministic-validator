<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\SiteLockingKind;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Settings\GlobalPresets;
use AiEditorDivi5\Pro\Settings\GlobalPresetsLock;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One Divi module preset. A CREATE item remembers whether the module already had presets and the default preset Divi added for it
 * (if any); undo removes what the batch created, only while it is exactly as the batch left it. An UPDATE item remembers the
 * previous name and attrs. The inverse of removing a created preset (a "recreate" item) carries the removed items so that undoing
 * the undo puts them back where they were. restore() runs under the site lock BatchUndo holds (see SiteLockingKind).
 */
final class GlobalPresetKind implements SiteLockingKind
{
    public const NAME = 'global_preset';

    private GlobalPresetsLock $lock;

    public function __construct( ?GlobalPresetsLock $lock = null )
    {
        $this->lock = $lock ?? new GlobalPresetsLock();
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string,mixed> $after GlobalPresets::state() of the created preset as stored
     * @param array{id:string,hash:string}|null $autoDefault the default preset Divi added for the module, if it did
     * @return array<string,mixed>
     */
    public static function itemForCreate( string $id, string $module, array $after, bool $moduleExisted, ?array $autoDefault ): array
    {
        $label  = 'Global preset "' . ( $after['name'] ?? '' ) . "\" ({$module}, {$id})";
        $before = [ 'module' => $module, 'created' => true, 'module_existed' => $moduleExisted, 'auto_default' => $autoDefault ];

        return Item::make( self::NAME, $id, $label, $before, $after );
    }

    /**
     * @param array<string,mixed> $before GlobalPresets::state() before
     * @param array<string,mixed> $after GlobalPresets::state() as stored now
     * @param array<string,mixed>|null $beforeStyleAttrs the preset's styleAttrs tree before the change (it can differ from attrs); null = not recorded, undo then uses attrs
     * @param bool $attrsAbsent the item had NO attrs key (one of Divi's empty default "shells"); undo then removes the attrs and styleAttrs keys again
     * @return array<string,mixed>
     */
    public static function itemForUpdate( string $id, string $module, array $before, array $after, ?array $beforeStyleAttrs = null, bool $attrsAbsent = false ): array
    {
        $label   = 'Global preset "' . ( $after['name'] ?? '' ) . "\" ({$module}, {$id})";
        $payload = [ 'module' => $module, 'created' => false, 'state' => $before ];
        if ( null !== $beforeStyleAttrs ) {
            $payload['style_attrs'] = $beforeStyleAttrs;
        }
        if ( $attrsAbsent ) {
            $payload['attrs_absent'] = true;
        }

        return Item::make( self::NAME, $id, $label, $payload, $after );
    }

    public function current( array $item ): mixed
    {
        $data = GlobalPresets::read();
        if ( null === $data ) {
            throw new \RuntimeException( "Divi's presets cannot be read." );
        }
        $found = GlobalPresets::find( $data, (string) $item['ref'] );

        return GlobalPresets::state( $found['item'] ?? null );
    }

    public function allowed( array $item ): bool
    {
        return GlobalPresets::permitted() && GlobalPresets::available();
    }

    public function lockSite(): bool
    {
        return $this->lock->acquire();
    }

    public function unlockSite(): void
    {
        $this->lock->release();
    }

    public function restore( array $item ): array
    {
        $id     = (string) $item['ref'];
        $before = is_array( $item['before'] ?? null ) ? $item['before'] : [];
        $module = (string) ( $before['module'] ?? '' );
        $data   = GlobalPresets::read();
        if ( null === $data ) {
            return [ 'ok' => false, 'reason' => 'divi_unavailable' ];
        }
        if ( ! empty( $before['created'] ) ) {
            return $this->removeCreated( $data, $id, $module, $before, (string) ( $item['after_hash'] ?? '' ) );
        }
        if ( isset( $before['recreate'] ) && is_array( $before['recreate'] ) ) {
            return $this->recreate( $data, $id, $module, $before );
        }

        return $this->revert( $data, $id, $module, $before );
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $before @return array<string,mixed> */
    private function removeCreated( array $data, string $id, string $module, array $before, string $afterHash ): array
    {
        $found = GlobalPresets::find( $data, $id );
        if ( null === $found || $found['module'] !== $module || ! GlobalPresets::managed( $module, $found['item'] ) ) {
            return [ 'ok' => false, 'reason' => 'not_found' ];
        }
        $state = GlobalPresets::state( $found['item'] );
        if ( Canon::hash( $state ) !== $afterHash || GlobalPresets::defaultId( $data, $module ) === $id ) {
            return [ 'ok' => false, 'reason' => 'changed_since' ]; // even when forced: never remove a preset someone changed after us
        }
        $removed = [ $id => $found['item'] ];
        $auto    = is_array( $before['auto_default'] ?? null ) ? $before['auto_default'] : null;
        if ( null !== $auto ) {
            $autoItem = $data['module'][ $module ]['items'][ $auto['id'] ] ?? null;
            if ( is_array( $autoItem ) ) {
                if ( Canon::hash( GlobalPresets::state( $autoItem ) ) !== (string) $auto['hash'] ) {
                    return [ 'ok' => false, 'reason' => 'changed_since' ];
                }
                $removed[ (string) $auto['id'] ] = $autoItem;
            }
        }
        $default       = GlobalPresets::defaultId( $data, $module );
        $moduleExisted = ! empty( $before['module_existed'] );
        foreach ( array_keys( $removed ) as $rid ) {
            unset( $data['module'][ $module ]['items'][ $rid ] );
        }
        if ( ! $moduleExisted ) {
            if ( [] !== $data['module'][ $module ]['items'] ) {
                return [ 'ok' => false, 'reason' => 'changed_since' ]; // someone added a preset to the module since
            }
            unset( $data['module'][ $module ] );
        }
        $res = $this->write( $data );
        if ( null === $res['after'] || null !== GlobalPresets::find( $res['after'], $id ) ) {
            return [ 'ok' => false, 'reason' => $res['reason'] ?? 'divi_refused' ];
        }
        $recreate = [ 'items' => $removed, 'default' => $moduleExisted ? '' : $default, 'module_existed' => $moduleExisted ];
        $inverse  = Item::make(
            self::NAME,
            $id,
            'Global preset "' . ( $state['name'] ?? '' ) . "\" ({$module}, {$id})",
            [ 'module' => $module, 'created' => false, 'recreate' => $recreate, 'state' => $state ],
            null
        );

        return [ 'ok' => true, 'inverse' => $inverse ];
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $before @return array<string,mixed> */
    private function recreate( array $data, string $id, string $module, array $before ): array
    {
        if ( null !== GlobalPresets::find( $data, $id ) ) {
            return [ 'ok' => false, 'reason' => 'changed_since' ];
        }
        $recreate      = $before['recreate'];
        $moduleExisted = ! empty( $recreate['module_existed'] );
        $items         = is_array( $recreate['items'] ?? null ) ? $recreate['items'] : [];
        if ( $moduleExisted && ! isset( $data['module'][ $module ]['items'] ) ) {
            return [ 'ok' => false, 'reason' => 'not_found' ];
        }
        if ( ! $moduleExisted ) {
            if ( isset( $data['module'][ $module ] ) ) {
                return [ 'ok' => false, 'reason' => 'changed_since' ];
            }
            $data['module'][ $module ] = [ 'default' => (string) ( $recreate['default'] ?? '' ), 'items' => [] ];
        }
        foreach ( $items as $rid => $rItem ) {
            $data['module'][ $module ]['items'][ (string) $rid ] = $rItem;
        }
        $res    = $this->write( $data );
        $stored = null === $res['after'] ? null : GlobalPresets::find( $res['after'], $id );
        if ( null === $stored || Canon::hash( GlobalPresets::state( $stored['item'] ) ) !== Canon::hash( $before['state'] ?? null ) ) {
            return [ 'ok' => false, 'reason' => $res['reason'] ?? 'divi_refused' ];
        }
        $auto = null;
        foreach ( $items as $rid => $rItem ) {
            if ( (string) $rid !== $id ) {
                $auto = [ 'id' => (string) $rid, 'hash' => Canon::hash( GlobalPresets::state( $rItem ) ) ];
            }
        }

        return [ 'ok' => true, 'inverse' => self::itemForCreate( $id, $module, (array) GlobalPresets::state( $stored['item'] ), $moduleExisted, $auto ) ];
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $before @return array<string,mixed> */
    private function revert( array $data, string $id, string $module, array $before ): array
    {
        $found    = GlobalPresets::find( $data, $id );
        $previous = $before['state'] ?? null;
        if ( null === $found || $found['module'] !== $module || ! GlobalPresets::managed( $module, $found['item'] ) || ! is_array( $previous ) || ! is_string( $previous['name'] ?? null ) || ! is_array( $previous['attrs'] ?? null ) ) {
            return [ 'ok' => false, 'reason' => 'not_found' ];
        }
        $absent   = ! empty( $before['attrs_absent'] );
        $recorded = is_array( $before['style_attrs'] ?? null ) ? $before['style_attrs'] : null;
        $style    = $recorded ?? ( $absent ? null : $previous['attrs'] );
        $nowState = GlobalPresets::state( $found['item'] );
        $nowStyle = is_array( $found['item']['styleAttrs'] ?? null ) ? $found['item']['styleAttrs'] : null;
        $entry    = $found['item'];
        $entry['name'] = $previous['name'];
        if ( $absent ) {
            unset( $entry['attrs'] ); // an empty default shell goes back to being one, byte for byte
        } else {
            $entry['attrs'] = $previous['attrs'];
        }
        if ( null === $style ) {
            unset( $entry['styleAttrs'] );
        } else {
            $entry['styleAttrs'] = $style;
        }
        $entry['updated'] = time();
        $data['module'][ $module ]['items'][ $id ] = $entry;
        $res    = $this->write( $data );
        $stored = null === $res['after'] ? null : GlobalPresets::find( $res['after'], $id )['item'] ?? null;
        if ( null === $stored || Canon::hash( GlobalPresets::state( $stored ) ) !== Canon::hash( $previous ) || Canon::hash( $stored['styleAttrs'] ?? null ) !== Canon::hash( $style ) || ( $absent && array_key_exists( 'attrs', $stored ) ) ) {
            return [ 'ok' => false, 'reason' => $res['reason'] ?? 'divi_refused' ];
        }

        return [ 'ok' => true, 'inverse' => self::itemForUpdate( $id, $module, (array) $nowState, (array) GlobalPresets::state( $stored ), $nowStyle, ! array_key_exists( 'attrs', $found['item'] ) ) ];
    }

    /**
     * Pre-flight, then Divi's replace-all save.
     *
     * @param array<string,mixed> $data
     * @return array{after:?array<string,mixed>, reason?:string}
     */
    private function write( array $data ): array
    {
        if ( [] !== GlobalPresets::unsafe( $data ) ) {
            return [ 'after' => null, 'reason' => 'unsafe_to_save' ];
        }
        $res = GlobalPresets::save( $data );

        return [ 'after' => $res['after'] ];
    }
}
