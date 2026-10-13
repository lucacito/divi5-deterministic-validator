<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Kinds\ProductFieldsKind;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The part of a product write that update_products and bulk_adjust_prices share: apply the planned changes product by product, read each
 * back and compare it with what was asked (ProductDiff, against the FULL read-back state), and on any exception or difference put every
 * product the run started to change back, record NO batch and fail with a safe message. On success it records ONE batch of
 * `product_fields` items. The callers do everything before this (arguments, token, planning) and spend the token right before calling it.
 */
final class ProductUpdateRun
{
    public function __construct( private BatchLog $log, private ProductWriter $writer )
    {
    }

    /**
     * @param list<array<string,mixed>> $plans each: id, label, name, changes, previous (value shape), display
     * @param list<array<string,mixed>> $skipped
     * @param list<int> $unchanged
     * @param string $toolName the tool name stored with the batch
     * @param string $summary sprintf format with one %d for the batch summary, for example "Updated %d product(s)"
     * @param array<string,mixed> $extra more keys for the result
     * @return array<string,mixed>
     * @throws \InvalidArgumentException with a message that is safe to show
     */
    public function execute( array $plans, array $skipped, array $unchanged, string $toolName, string $summary, array $extra = [] ): array
    {
        $writer  = $this->writer;
        $done    = [];
        $failing = $plans[0];
        try {
            foreach ( $plans as $k => $plan ) {
                $failing = $plan;
                $done[]  = $plan;
                $writer->apply( $plan['id'], $plan['changes'] );
                $back = $writer->product( $plan['id'] );
                if ( null === $back ) {
                    throw new ProductSaveFailed( 'the saved product could not be read back' );
                }
                $asked = ProductFields::asShape( $plan['changes'] );
                $diff  = ProductDiff::compare( $asked, ProductFields::asShape( $back ) );
                if ( [] !== $diff ) {
                    throw new ProductSaveFailed( 'it was saved differently than asked (' . implode( ', ', $diff ) . ')' );
                }
                $plans[ $k ]['after']      = ProductFields::pick( $back, $plan['previous'] );
                $plans[ $k ]['name_after'] = (string) $back['name'];
            }
        } catch ( \Throwable $e ) {
            $notRestored = $this->rollback( $done );
            // Only our own constant messages (ProductSaveFailed) and WooCommerce's data exceptions are safe to show; any other message
            // (a foreign RuntimeException, a hook, a database error) could carry a server path.
            $safe   = $e instanceof ProductSaveFailed || is_a( $e, 'WC_Data_Exception' );
            $reason = $safe ? $e->getMessage() : 'WooCommerce could not save it';
            $text   = "Could not update {$failing['label']}: {$reason}.";
            if ( [] !== $notRestored ) {
                throw new \InvalidArgumentException( $text . ' These products may not be fully restored and should be checked: #' . implode( ', #', $notRestored ) . '.' );
            }
            throw new \InvalidArgumentException( $text . ' Every product this call had touched was put back; nothing was changed.' );
        }

        $updated = [];
        $records = [];
        foreach ( $plans as $plan ) {
            $row = [ 'id' => $plan['id'], 'name' => $plan['name_after'], 'changes' => $plan['display']['fields'] ];
            if ( [] !== $plan['display']['variations'] ) {
                $row['variations'] = $plan['display']['variations'];
            }
            $updated[] = $row;
            $records[] = ProductFieldsKind::itemFor( $plan['id'], self::cut( $plan['name_after'] ), self::what( $plan['changes'] ), $plan['previous'], $plan['after'] );
        }
        $batch = $this->log->record( $toolName, sprintf( $summary, count( $records ) ), $records );

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'updated' => $updated, 'skipped' => $skipped, 'unchanged' => $unchanged ] + $extra, $batch );
    }

    /**
     * Puts the previous values back on every product the run started to change, newest first, and checks each read-back.
     *
     * @param list<array<string,mixed>> $done
     * @return list<int> the ids that could not be put back
     */
    private function rollback( array $done ): array
    {
        $left = [];
        foreach ( array_reverse( $done ) as $plan ) {
            try {
                $this->writer->apply( $plan['id'], ProductFields::asChanges( $plan['previous'] ) );
                $back = $this->writer->product( $plan['id'] );
                if ( null === $back || [] !== ProductDiff::compare( $plan['previous'], ProductFields::asShape( $back ) ) ) {
                    $left[] = $plan['id'];
                }
            } catch ( \Throwable $e ) {
                $left[] = $plan['id'];
            }
        }

        return $left;
    }

    /**
     * Before and after of what a change set does, for the preview and the result.
     *
     * @param array<string,mixed> $changes
     * @param array<string,mixed> $current
     * @param array<string,list<string>> $slugsFor category_ids / tag_ids => the slugs that were asked for
     * @return array{fields: array<string,mixed>, variations: list<array<string,mixed>>}
     */
    public static function display( array $changes, array $current, array $slugsFor = [] ): array
    {
        $fields = [];
        foreach ( $changes as $field => $value ) {
            if ( 'variations' === $field ) {
                continue;
            }
            if ( 'category_ids' === $field || 'tag_ids' === $field ) {
                $key            = 'category_ids' === $field ? 'categories' : 'tags';
                $before         = 'category_ids' === $field ? $current['category_slugs'] : $current['tag_slugs'];
                $fields[ $key ] = [ 'before' => $before, 'after' => $slugsFor[ $field ] ?? $value ];
                continue;
            }
            $fields[ $field ] = [ 'before' => self::shown( $current[ $field ] ?? null ), 'after' => self::shown( $value ) ];
        }
        $rows = [];
        foreach ( (array) ( $changes['variations'] ?? [] ) as $vid => $row ) {
            $diff = [];
            foreach ( $row as $field => $value ) {
                $diff[ $field ] = [ 'before' => self::shown( $current['variations'][ $vid ][ $field ] ?? null ), 'after' => self::shown( $value ) ];
            }
            $rows[] = [ 'id' => (int) $vid, 'changes' => $diff ];
        }

        return [ 'fields' => $fields, 'variations' => $rows ];
    }

    /** A product name made safe and short for a label or a message. */
    public static function cut( string $name ): string
    {
        $name = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $name );

        return mb_strlen( $name ) > 60 ? mb_substr( $name, 0, 60 ) . '...' : $name;
    }

    /** @param array<string,mixed> $changes */
    private static function what( array $changes ): string
    {
        $names = [];
        foreach ( array_keys( $changes ) as $field ) {
            $names[] = 'variations' === $field ? count( (array) $changes['variations'] ) . ' variation row(s)' : (string) $field;
        }
        $what = implode( ', ', $names );

        return mb_strlen( $what ) > 120 ? mb_substr( $what, 0, 117 ) . '...' : $what;
    }

    private static function shown( mixed $value ): mixed
    {
        return is_string( $value ) && mb_strlen( $value ) > 200 ? mb_substr( $value, 0, 200 ) . '...' : $value;
    }
}
