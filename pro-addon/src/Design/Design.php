<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Design;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\StyleKits;

/**
 * A design: one design language and one style kit, both validated, as the free plugin serves them. The tool's
 * `"default"` is NOT accepted here; the tool maps it to a concrete pair first.
 */
final class Design
{
    /**
     * @param array<string,mixed> $language
     * @param array<string,mixed> $kit
     */
    private function __construct(
        public readonly string $languageId,
        public readonly string $kitId,
        public readonly array $language,
        public readonly array $kit
    ) {
    }

    /** @throws \InvalidArgumentException naming the valid ids when an id is unknown, or when the kit is invalid. */
    public static function make( string $languageId, string $kitId ): self
    {
        $language = DesignLanguages::get( $languageId );
        if ( null === $language ) {
            throw new \InvalidArgumentException( sprintf( 'Unknown design language "%s". Valid ids: %s.', $languageId, implode( ', ', DesignLanguages::ids() ) ) );
        }
        $kit = StyleKits::get( $kitId );
        if ( null === $kit ) {
            throw new \InvalidArgumentException( sprintf( 'Unknown style kit "%s". Valid ids: %s.', $kitId, implode( ', ', StyleKits::ids() ) ) );
        }
        $problems = StyleKits::problems( $kit );
        if ( [] !== $problems ) {
            throw new \InvalidArgumentException( sprintf( 'Style kit "%s" is not valid: %s', $kitId, implode( '; ', $problems ) ) );
        }

        return new self( $languageId, $kitId, $language, $kit );
    }

    public function equals( Design $other ): bool
    {
        return $this->languageId === $other->languageId && $this->kitId === $other->kitId;
    }
}
