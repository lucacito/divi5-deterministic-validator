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
                $refusals[] = $field['key'] . ' ' . $plan['refusal'];
                continue;
            }
            $plans[] = $plan;
        }
        if ( [] !== $refusals ) {
            $message = 'Nothing was written. ' . count( $refusals ) . ' field(s) refused: ' . implode( '; ', $refusals ) . '.';
            throw new \InvalidArgumentException( mb_substr( $message, 0, 1500 ) );
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

        $written = [];
        foreach ( $changes as $c ) {
            if ( ! $store->write( $postId, $c['key'], $c['value'], $c['acf'] ) ) {
                $this->rollback( $postId, $written, $store );
                throw new \InvalidArgumentException( "Could not write \"{$c['key']}\"; nothing was changed." );
            }
            $written[] = $c;
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
        if ( ! current_user_can( 'edit_post_meta', $postId, $key ) ) {
            return [ 'refusal' => 'is not allowed by WordPress for this post' ] + $plan;
        }

        $acf = $this->acf();
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
        if ( wp_kses_post( $value ) !== $value ) {
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

    /** @param list<array{key:string,value:string,before:array{exists:bool,value:?string},acf:?string,refusal:?string}> $written */
    private function rollback( int $postId, array $written, PostMetaStore $store ): void
    {
        foreach ( array_reverse( $written ) as $c ) {
            if ( $c['before']['exists'] ) {
                $store->write( $postId, $c['key'], (string) $c['before']['value'], $c['acf'] );
            } else {
                $store->delete( $postId, $c['key'], $c['acf'] );
            }
        }
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
