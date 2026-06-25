<?php

declare(strict_types=1);

namespace Divi5Validator;

/**
 * Deterministic Divi 5 layout validator.
 *
 * Validation happens in five ordered passes. Each pass returns early if it
 * discovers a violation that makes subsequent passes meaningless (e.g. we
 * cannot check schema conformance on unparseable JSON).
 *
 * Schema-specific rules (passes 2–5) are stubs until real Divi 5 layout data
 * has been captured via `make export-layouts` and documented in docs/SCHEMA.md.
 * See SchemaRules.php for the constants and Rules\* classes for implementations.
 */
final class Validator
{
    // ---------------------------------------------------------------
    // Violation codes — never rename these once tests depend on them
    // ---------------------------------------------------------------

    // Pass 1 — well-formedness
    public const E_INVALID_JSON            = 'INVALID_JSON';
    public const E_WRONG_ROOT_TYPE         = 'WRONG_ROOT_TYPE';
    public const E_EMPTY_DOCUMENT          = 'EMPTY_DOCUMENT';

    // Pass 2 — schema conformance (populated after Phase 3)
    public const E_MISSING_REQUIRED_FIELD  = 'MISSING_REQUIRED_FIELD';
    public const E_WRONG_FIELD_TYPE        = 'WRONG_FIELD_TYPE';
    public const E_UNKNOWN_MODULE_TYPE     = 'UNKNOWN_MODULE_TYPE';

    // Pass 3 — hierarchy integrity (populated after Phase 3)
    public const E_WRONG_NESTING           = 'WRONG_NESTING';
    public const E_UNEXPECTED_CHILD_TYPE   = 'UNEXPECTED_CHILD_TYPE';

    // Pass 4 — referential integrity (populated after Phase 3)
    public const E_ORPHANED_REFERENCE      = 'ORPHANED_REFERENCE';

    // Pass 5 — render-critical attributes (populated after Phase 3)
    public const E_RENDER_CRITICAL_MISSING = 'RENDER_CRITICAL_MISSING';
    public const E_SCALAR_WHERE_OBJECT     = 'SCALAR_WHERE_OBJECT';

    private SchemaRules $schema;

    public function __construct(?SchemaRules $schema = null)
    {
        $this->schema = $schema ?? new SchemaRules();
    }

    /**
     * Validate a Divi 5 layout JSON string.
     *
     * @param string $json Raw JSON as exported from Divi 5.
     */
    public function validate(string $json): ValidationResult
    {
        $violations = [];

        // Pass 1 — well-formedness
        $decoded = $this->passWellFormedness($json, $violations);
        if ($violations !== []) {
            return new ValidationResult($violations);
        }

        // Pass 2 — schema conformance
        $this->passSchemaConformance($decoded, $violations);

        // Pass 3 — hierarchy integrity
        $this->passHierarchyIntegrity($decoded, $violations);

        // Pass 4 — referential integrity
        $this->passReferentialIntegrity($decoded, $violations);

        // Pass 5 — render-critical attributes
        $this->passRenderCritical($decoded, $violations);

        return new ValidationResult($violations);
    }

    // ---------------------------------------------------------------
    // Pass 1 — Well-formedness
    // ---------------------------------------------------------------

    private function passWellFormedness(string $json, array &$violations): mixed
    {
        if (trim($json) === '') {
            $violations[] = new Violation(
                self::E_EMPTY_DOCUMENT,
                'The layout JSON is empty.',
                '$'
            );
            return null;
        }

        // Decode without associative flag first to distinguish {} from [] reliably.
        // In PHP, json_decode('{}', true) returns [] which is indistinguishable
        // from json_decode('[]', true) when using array_is_list().
        $root = json_decode($json);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $violations[] = new Violation(
                self::E_INVALID_JSON,
                'The layout is not valid JSON: ' . json_last_error_msg(),
                '$'
            );
            return null;
        }

        if (!($root instanceof \stdClass)) {
            $violations[] = new Violation(
                self::E_WRONG_ROOT_TYPE,
                sprintf(
                    'The layout root must be a JSON object, got %s.',
                    is_array($root) ? 'array' : gettype($root)
                ),
                '$'
            );
            return null;
        }

        // Re-decode as associative array for uniform processing.
        return json_decode($json, associative: true);
    }

    // ---------------------------------------------------------------
    // Pass 2 — Schema conformance
    // NOTE: Rules are stubs until Phase 3 (schema discovery) completes.
    //       Replace the body of this method once docs/SCHEMA.md is written.
    // ---------------------------------------------------------------

    private function passSchemaConformance(mixed $decoded, array &$violations): void
    {
        if ($decoded === null) {
            return;
        }

        foreach ($this->schema->requiredTopLevelFields() as $field => $expectedType) {
            if (!array_key_exists($field, $decoded)) {
                $violations[] = new Violation(
                    self::E_MISSING_REQUIRED_FIELD,
                    "Required top-level field '$field' is missing.",
                    "\$.$field"
                );
                continue;
            }

            $actual = $decoded[$field];
            if (!$this->schema->typeMatches($actual, $expectedType)) {
                $violations[] = new Violation(
                    self::E_WRONG_FIELD_TYPE,
                    "Field '$field' must be of type '$expectedType', got " . gettype($actual) . '.',
                    "\$.$field"
                );
            }
        }

        // Walk all nodes and check module types
        $this->walkNodes($decoded, '$', function (mixed $node, string $path) use (&$violations): void {
            if (!is_array($node) || array_is_list($node)) {
                return;
            }

            $typeKey = $this->schema->moduleTypeKey();
            if ($typeKey !== null && isset($node[$typeKey])) {
                $type = $node[$typeKey];
                if (!$this->schema->isKnownModuleType($type)) {
                    $violations[] = new Violation(
                        self::E_UNKNOWN_MODULE_TYPE,
                        "Unknown module type '$type'.",
                        $path . '.' . $typeKey
                    );
                }
            }
        });
    }

    // ---------------------------------------------------------------
    // Pass 3 — Hierarchy integrity
    // NOTE: Stub until Phase 3 fills in hierarchy rules.
    // ---------------------------------------------------------------

    private function passHierarchyIntegrity(mixed $decoded, array &$violations): void
    {
        // Populated after docs/SCHEMA.md documents the real nesting rules.
    }

    // ---------------------------------------------------------------
    // Pass 4 — Referential integrity
    // NOTE: Stub until Phase 3 confirms whether Divi 5 uses internal IDs/refs.
    // ---------------------------------------------------------------

    private function passReferentialIntegrity(mixed $decoded, array &$violations): void
    {
        // Populated after docs/SCHEMA.md confirms ID/reference patterns.
    }

    // ---------------------------------------------------------------
    // Pass 5 — Render-critical attributes
    // NOTE: Stub until Phase 3 identifies which attrs cause fatal renders.
    // ---------------------------------------------------------------

    private function passRenderCritical(mixed $decoded, array &$violations): void
    {
        // Populated after Phase 3 identifies render-critical field requirements.
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Depth-first walk of all nodes in a decoded JSON structure.
     *
     * @param callable(mixed, string): void $callback
     */
    private function walkNodes(mixed $node, string $path, callable $callback): void
    {
        $callback($node, $path);

        if (is_array($node)) {
            foreach ($node as $key => $child) {
                $childPath = array_is_list($node)
                    ? $path . '[' . $key . ']'
                    : $path . '.' . $key;
                $this->walkNodes($child, $childPath, $callback);
            }
        }
    }
}
