<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Fields\AcfFields;
use AiEditorDivi5\Pro\Fields\FieldValuePolicy;
use AiEditorDivi5\Pro\Fields\PostMetaStore;
use AiEditorDivi5\Pro\Kinds\PostMetaKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\WP\CustomFieldReader;
use AiEditorDivi5\WP\CustomFields;
use AiEditorDivi5\WP\FieldTarget;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Set custom field values on ONE post. Every field is checked first; if any is refused nothing is written. A write is verified by
 * reading the raw value back and a failure rolls back every field already written. One undoable batch per call.
 */
final class SetCustomFields implements ProTool
{
    public const NAME       = 'set_custom_fields';
    public const MAX_FIELDS = 25;
    private const SHOW_CHARS = 200;
    private const KEY_CHARS  = 80;
    private const MAX_MESSAGE = 6000;

    private BatchLog $log;
    private ?PostMetaStore $store;
    private ?\Closure $known;
    /** @var ?array{fields: array<string,array<string,mixed>>, prefixes: list<string>} */
    private ?array $acfIndex;

    /**
     * @param ?array{fields: array<string,array<string,mixed>>, prefixes: list<string>} $acfIndex test injection
     */
    public function __construct( BatchLog $log, ?PostMetaStore $store = null, ?\Closure $known = null, ?array $acfIndex = null )
    {
        $this->log      = $log;
        $this->store    = $store;
        $this->known    = $known;
        $this->acfIndex = $acfIndex;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_posts';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Set custom field values on ONE page, Divi Project or other post you can edit. Only keys the site knows; never protected or secret keys, code, or ACF lists and images. All or nothing; one undoable batch. dry_run previews. Read fields first with get_custom_fields.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id'      => [ 'type' => 'integer', 'description' => 'Post ID' ],
                    'fields'  => [
                        'type'        => 'array',
                        'description' => 'Up to 25 fields to set, each with a key and a text value',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'key'   => [ 'type' => 'string', 'description' => 'Custom field key' ],
                                'value' => [ 'type' => 'string', 'description' => 'New text value (at most 10,000 characters)' ],
                            ],
                            'required'   => [ 'key', 'value' ],
                        ],
                    ],
                    'dry_run' => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'id', 'fields' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $postId = (int) Args::int( $arguments, 'id' );
        $dry    = Args::bool( $arguments, 'dry_run' );
        $fields = $this->fieldsFrom( Args::listOf( $arguments, 'fields', self::MAX_FIELDS ) );

        $target = FieldTarget::resolve( $postId );
        if ( null !== $target['error'] || null === $target['post'] ) {
            throw new \InvalidArgumentException( "Post {$postId} not found." );
        }
        $post  = $target['post'];
        $store = $this->store();

        $plans    = [];
        $refusals = [];
        foreach ( $fields as $field ) {
            $plan = $this->plan( $postId, $field['key'], $field['value'], $store );
            if ( null !== $plan['refusal'] ) {
                $refusals[] = self::shown( $field['key'] ) . ' ' . $plan['refusal'];
                continue;
            }
            $plans[] = $plan;
        }
        if ( [] !== $refusals ) {
            $message = 'Nothing was written. ' . count( $refusals ) . ' field(s) refused: ' . implode( '; ', $refusals ) . '.';
            // Long enough for 25 refusals with 80-character keys; if a reason list ever exceeds it, the cut still ends in a full stop.
            if ( mb_strlen( $message ) > self::MAX_MESSAGE ) {
                $message = mb_substr( $message, 0, self::MAX_MESSAGE - 3 ) . '...';
            }
            throw new \InvalidArgumentException( $message );
        }

        $rows    = [];
        $changes = [];
        foreach ( $plans as $p ) {
            $unchanged = $p['before']['exists'] && $p['before']['value'] === $p['value'];
            $rows[]    = [
                'key'    => $p['key'],
                'status' => $unchanged ? 'unchanged' : ( $p['before']['exists'] ? 'set' : 'create' ),
                'before' => $p['before']['exists'] ? self::cut( (string) $p['before']['value'] ) : null,
                'after'  => self::cut( $p['value'] ),
            ];
            if ( ! $unchanged ) {
                $changes[] = $p;
            }
        }
        $page = [ 'id' => $postId, 'title' => (string) get_the_title( $post ), 'post_type' => (string) $post->post_type ];

        if ( [] === $changes || $dry ) {
            return [ 'changed' => false, 'dry_run' => $dry, 'post' => $page, 'fields' => $rows ]
                + ( [] === $changes ? [ 'message' => 'Every value is already what you asked for; nothing changed.' ] : [] );
        }

        // A write that returns false may still have stored something (a sanitize filter can mangle the value), and a store can
        // throw: so the current field joins the rollback set BEFORE it is written. Restoring `before` is idempotent.
        $written = [];
        $failed  = null;
        try {
            foreach ( $changes as $c ) {
                $written[] = $c;
                if ( ! $store->write( $postId, $c['key'], $c['value'], $c['acf'] ) ) {
                    $failed = $c['key'];
                    break;
                }
            }
        } catch ( \Throwable $e ) {
            $failed = $c['key'] ?? '';
        }
        if ( null !== $failed ) {
            $notRestored = $this->rollback( $postId, $written, $store );
            $shown       = self::shown( $failed );
            if ( [] !== $notRestored ) {
                throw new \InvalidArgumentException( "Could not write \"{$shown}\". These fields may not have been restored: " . implode( ', ', array_map( [ self::class, 'shown' ], $notRestored ) ) . '. Check them with get_custom_fields.' );
            }
            throw new \InvalidArgumentException( "Could not write \"{$shown}\"; nothing was changed." );
        }

        $items = [];
        foreach ( $written as $c ) {
            $item = Item::make(
                PostMetaKind::NAME,
                "{$postId}:{$c['key']}",
                "Custom field {$c['key']} on #{$postId}",
                [ 'exists' => $c['before']['exists'], 'value' => $c['before']['value'] ],
                [ 'exists' => true, 'value' => $c['value'] ]
            );
            if ( null !== $c['acf'] ) {
                $item['meta'] = [ 'acf' => $c['acf'] ];
            }
            $items[] = $item;
        }
        $count = count( $items );
        $batch = $this->log->record( self::NAME, "Set {$count} custom field(s) on \"{$page['title']}\" (#{$postId})", $items );

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'post' => $page, 'fields' => $rows ], $batch );
    }

    /**
     * @param list<mixed> $raw
     * @return list<array{key:string,value:string}>
     */
    private function fieldsFrom( array $raw ): array
    {
        $out  = [];
        $seen = [];
        foreach ( $raw as $i => $item ) {
            $n = $i + 1;
            if ( ! is_array( $item ) || array_is_list( $item ) ) {
                throw new \InvalidArgumentException( "\"fields\" item {$n} must be an object with \"key\" and \"value\"." );
            }
            $key = $item['key'] ?? null;
            if ( ! is_string( $key ) || '' === trim( $key ) ) {
                throw new \InvalidArgumentException( "\"fields\" item {$n}: \"key\" must be a non-empty string." );
            }
            if ( ! isset( $item['value'] ) || ! is_string( $item['value'] ) ) {
                throw new \InvalidArgumentException( "\"fields\" item {$n}: \"value\" must be a string." );
            }
            if ( $key !== trim( $key ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $key ) ) {
                throw new \InvalidArgumentException( "\"fields\" item {$n}: \"key\" must not have leading or trailing whitespace or control characters." );
            }
            if ( isset( $seen[ $key ] ) ) {
                throw new \InvalidArgumentException( "\"fields\": the key \"{$key}\" appears more than once." );
            }
            $seen[ $key ] = true;
            $out[]        = [ 'key' => $key, 'value' => $item['value'] ];
        }

        return $out;
    }

    /**
     * @return array{key:string,value:string,before:array{exists:bool,value:?string},acf:?string,refusal:?string}
     */
    private function plan( int $postId, string $key, string $value, PostMetaStore $store ): array
    {
        $plan = [ 'key' => $key, 'value' => $value, 'before' => [ 'exists' => false, 'value' => null ], 'acf' => null, 'refusal' => null ];

        $class = CustomFieldReader::classify( $key );
        if ( CustomFieldReader::HIDDEN === $class ) {
            return [ 'refusal' => 'is a protected or private key and is never written' ] + $plan;
        }
        if ( CustomFieldReader::SECRET === $class ) {
            return [ 'refusal' => 'looks like a password, token or licence key and is never written' ] + $plan;
        }

        // WordPress matches meta keys with SQL `meta_key = %s`, which is case-insensitive (and ignores trailing spaces) under the usual
        // collations: "Headline" would hit the stored "headline" row while skipping the exact-match checks below. So a key that differs
        // from an existing field, or from an ACF field or prefix, only by letter case is refused. Residual, accepted: a mis-cased NEW
        // key on a post that lacks the base key can still be created, because "known" is collation-insensitive too.
        $acf = $this->acf();
        foreach ( $store->keys( $postId ) as $existing ) {
            if ( 0 === strcasecmp( $existing, $key ) && $existing !== $key ) {
                return [ 'refusal' => 'differs only by letter case from the existing field "' . self::shown( $existing ) . '"; use the exact key' ] + $plan;
            }
        }
        foreach ( array_keys( $acf['fields'] ) as $name ) {
            $name = (string) $name;
            if ( 0 === strcasecmp( $name, $key ) && $name !== $key ) {
                return [ 'refusal' => 'differs only by letter case from the ACF field "' . self::shown( $name ) . '"; use the exact key' ] + $plan;
            }
        }
        foreach ( $acf['prefixes'] as $prefix ) {
            if ( 0 === stripos( $key, $prefix ) && ! str_starts_with( $key, $prefix ) ) {
                return [ 'refusal' => 'differs only by letter case from the ACF field "' . self::shown( rtrim( $prefix, '_' ) ) . '"; use the exact key' ] + $plan;
            }
        }

        if ( ! current_user_can( 'edit_post_meta', $postId, $key ) ) {
            return [ 'refusal' => 'is not allowed by WordPress for this post' ] + $plan;
        }

        $def = $acf['fields'][ $key ] ?? null;
        if ( null !== $def && $def['nested'] ) {
            return [ 'refusal' => 'belongs to a group in ACF; edit it in the ACF editor' ] + $plan;
        }
        if ( null === $def ) {
            foreach ( $acf['prefixes'] as $prefix ) {
                if ( str_starts_with( $key, $prefix ) ) {
                    return [ 'refusal' => 'belongs to a complex ACF field (a group, repeater or flexible content); edit it in the ACF editor' ] + $plan;
                }
            }
            if ( ! ( $this->known() )( $key ) ) {
                return [ 'refusal' => 'is not a custom field this site knows (no ACF field and no post uses it)' ] + $plan;
            }
        }

        $now = $store->read( $postId, $key );
        if ( $now['rows'] > 1 ) {
            return [ 'refusal' => 'is stored more than once on this post' ] + $plan;
        }
        if ( $now['structured'] ) {
            return [ 'refusal' => 'currently holds a list or object; edit it in the ACF editor' ] + $plan;
        }
        $plan['before'] = [ 'exists' => $now['exists'], 'value' => $now['value'] ];

        $why = FieldValuePolicy::refusal( $value );
        if ( null !== $why ) {
            return [ 'refusal' => 'value ' . $why ] + $plan;
        }
        // Core's wp_kses_post() rewrites a bare "&" to "&amp;"; that is not unsafe markup, so both sides are compared with it undone.
        // The value that is stored is still exactly what the caller sent, never the kses output.
        $norm = static fn ( string $s ): string => str_replace( '&amp;', '&', $s );
        if ( $norm( wp_kses_post( $value ) ) !== $norm( $value ) ) {
            return [ 'refusal' => 'value contains markup WordPress does not allow in post content' ] + $plan;
        }
        if ( null !== $def ) {
            $why = AcfFields::refusalFor( $def, $value );
            if ( null !== $why ) {
                return [ 'refusal' => $why ] + $plan;
            }
            $plan['acf'] = '' !== $def['field_key'] ? $def['field_key'] : null;
        }

        return $plan;
    }

    /**
     * Puts every written field back (newest first) and reports honestly which ones could not be restored.
     *
     * @param list<array{key:string,value:string,before:array{exists:bool,value:?string},acf:?string,refusal:?string}> $written
     * @return list<string> the keys that may not be back to their old value
     */
    private function rollback( int $postId, array $written, PostMetaStore $store ): array
    {
        $failed = [];
        foreach ( array_reverse( $written ) as $c ) {
            try {
                $ok = $c['before']['exists']
                    ? $store->write( $postId, $c['key'], (string) $c['before']['value'], $c['acf'] )
                    : $store->delete( $postId, $c['key'], $c['acf'] );
            } catch ( \Throwable $e ) {
                $ok = false;
            }
            if ( ! $ok ) {
                $failed[] = $c['key'];
            }
        }

        return $failed;
    }

    /** A key as shown in a message: cut at 80 characters. */
    private static function shown( string $key ): string
    {
        return mb_strlen( $key ) > self::KEY_CHARS ? mb_substr( $key, 0, self::KEY_CHARS ) . '...' : $key;
    }

    private static function cut( string $text ): string
    {
        return mb_strlen( $text ) > self::SHOW_CHARS ? mb_substr( $text, 0, self::SHOW_CHARS ) . '...' : $text;
    }

    private function store(): PostMetaStore
    {
        return $this->store ??= PostMetaStore::wp();
    }

    private function known(): \Closure
    {
        return $this->known ??= CustomFields::lookup();
    }

    /** @return array{fields: array<string,array<string,mixed>>, prefixes: list<string>} */
    private function acf(): array
    {
        return $this->acfIndex ??= AcfFields::index();
    }
}
