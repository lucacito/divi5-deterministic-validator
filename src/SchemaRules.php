<?php

declare(strict_types=1);

namespace Divi5Validator;

/**
 * Encapsulates all schema knowledge derived from empirical observation of real
 * Divi 5 exports (docs/SCHEMA.md).
 *
 * This class starts as a minimal stub. After `make export-layouts` produces
 * real fixtures and Phase 3 documents the schema, replace the placeholder
 * methods with real data.
 *
 * IMPORTANT: Do not invent Divi 5 structure from memory or Divi 4 knowledge.
 * All constants here must be verified against actual exports.
 */
class SchemaRules
{
    /**
     * Top-level fields that must exist in every valid Divi 5 layout document.
     *
     * Key   = field name
     * Value = expected PHP type ('array', 'string', 'integer', 'double', 'boolean')
     *
     * STUB: populated after Phase 3 schema discovery.
     *
     * @return array<string, string>
     */
    public function requiredTopLevelFields(): array
    {
        // TODO: replace with real required fields observed in exports.
        // Example (hypothetical — DO NOT trust until verified):
        //   return ['version' => 'string', 'layouts' => 'array'];
        return [];
    }

    /**
     * The key within a module/node object that identifies its type.
     *
     * STUB: return null until Phase 3 confirms the real key name.
     * Example candidates: 'type', 'name', 'blockType', 'module' — unknown until observed.
     */
    public function moduleTypeKey(): ?string
    {
        // TODO: set to the real type discriminator field name after Phase 3.
        return null;
    }

    /**
     * Returns true if $type is a recognised Divi 5 module/block type.
     *
     * STUB: returns true for everything until Phase 3 gives us the real list.
     * After Phase 3, this becomes a whitelist check.
     */
    public function isKnownModuleType(string $type): bool
    {
        // TODO: replace with real whitelist after Phase 3 schema discovery.
        // Example: return in_array($type, ['section', 'row', 'column', 'text', ...], true);
        return true;
    }

    /**
     * Returns true if the value matches the expected type string.
     */
    public function typeMatches(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'array'   => is_array($value),
            'string'  => is_string($value),
            'integer' => is_int($value),
            'double'  => is_float($value) || is_int($value),
            'boolean' => is_bool($value),
            'null'    => is_null($value),
            default   => false,
        };
    }
}
