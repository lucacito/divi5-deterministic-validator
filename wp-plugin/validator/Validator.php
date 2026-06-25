<?php

declare(strict_types=1);

namespace Divi5Validator;

/**
 * Deterministic Divi 5 layout validator.
 *
 * Input: a JSON envelope file produced by `make export-layouts`, containing
 * a `post_content` field with Gutenberg block HTML (see docs/SCHEMA.md §8).
 *
 * Validation runs in five ordered passes:
 *   1. Well-formedness  — valid JSON envelope, post_content present
 *   2. Block parsing    — valid Gutenberg block HTML, balanced tags, valid attrs JSON
 *   3. Schema           — known block types, builderVersion present
 *   4. Hierarchy        — nesting follows section→row→column→module rules
 *   5. Render-critical  — content-key innerContent shapes for leaf modules
 */
final class Validator
{
    // ---------------------------------------------------------------
    // Violation codes — never rename these; tests depend on them
    // ---------------------------------------------------------------

    // Pass 1 — envelope well-formedness
    public const E_INVALID_JSON           = 'INVALID_JSON';
    public const E_WRONG_ROOT_TYPE        = 'WRONG_ROOT_TYPE';
    public const E_EMPTY_DOCUMENT         = 'EMPTY_DOCUMENT';
    public const E_MISSING_POST_CONTENT   = 'MISSING_POST_CONTENT';

    // Pass 2 — block parsing
    public const E_BLOCK_PARSE_ERROR      = 'BLOCK_PARSE_ERROR';
    public const E_NO_BLOCKS_FOUND        = 'NO_BLOCKS_FOUND';

    // Pass 3 — schema conformance
    public const E_UNKNOWN_MODULE_TYPE    = 'UNKNOWN_MODULE_TYPE';
    public const E_MISSING_REQUIRED_FIELD = 'MISSING_REQUIRED_FIELD';
    public const E_WRONG_FIELD_TYPE       = 'WRONG_FIELD_TYPE';

    // Pass 4 — hierarchy integrity
    public const E_WRONG_NESTING          = 'WRONG_NESTING';
    public const E_UNEXPECTED_CHILD_TYPE  = 'UNEXPECTED_CHILD_TYPE';

    // Pass 5 — render-critical attributes
    public const E_RENDER_CRITICAL_MISSING = 'RENDER_CRITICAL_MISSING';
    public const E_SCALAR_WHERE_OBJECT     = 'SCALAR_WHERE_OBJECT';
    public const E_ORPHANED_REFERENCE      = 'ORPHANED_REFERENCE';

    private SchemaRules $schema;
    private BlockParser $parser;

    public function __construct(?SchemaRules $schema = null, ?BlockParser $parser = null)
    {
        $this->schema = $schema ?? new SchemaRules();
        $this->parser = $parser ?? new BlockParser();
    }

    public function validate(string $json): ValidationResult
    {
        $violations = [];

        // Pass 1 — envelope well-formedness
        $envelope = $this->passEnvelopeWellFormedness($json, $violations);
        if ($violations !== []) {
            return new ValidationResult($violations);
        }

        $postContent = $envelope['post_content'];

        // Pass 2 — block parsing
        $tree = $this->passBlockParsing($postContent, $violations);
        if ($violations !== []) {
            return new ValidationResult($violations);
        }

        // Pass 3 — schema conformance
        $this->passSchemaConformance($tree, $violations);

        // Pass 4 — hierarchy integrity
        $this->passHierarchyIntegrity($tree, $violations);

        // Pass 5 — render-critical attributes
        $this->passRenderCritical($tree, $violations);

        return new ValidationResult($violations);
    }

    // ---------------------------------------------------------------
    // Pass 1 — Envelope well-formedness
    // ---------------------------------------------------------------

    /** @param Violation[] $violations */
    private function passEnvelopeWellFormedness(string $json, array &$violations): ?array
    {
        if (trim($json) === '') {
            $violations[] = new Violation(self::E_EMPTY_DOCUMENT, 'The layout file is empty.', '$');
            return null;
        }

        $root = json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $violations[] = new Violation(
                self::E_INVALID_JSON,
                'The layout file is not valid JSON: ' . json_last_error_msg(),
                '$'
            );
            return null;
        }

        if (!($root instanceof \stdClass)) {
            $violations[] = new Violation(
                self::E_WRONG_ROOT_TYPE,
                sprintf('The layout root must be a JSON object, got %s.', is_array($root) ? 'array' : gettype($root)),
                '$'
            );
            return null;
        }

        $envelope = json_decode($json, associative: true);

        if (!array_key_exists('post_content', $envelope)) {
            $violations[] = new Violation(
                self::E_MISSING_POST_CONTENT,
                'Required field "post_content" is missing from the layout envelope.',
                '$.post_content'
            );
            return null;
        }

        if (!is_string($envelope['post_content'])) {
            $violations[] = new Violation(
                self::E_WRONG_FIELD_TYPE,
                '"post_content" must be a string.',
                '$.post_content'
            );
            return null;
        }

        return $envelope;
    }

    // ---------------------------------------------------------------
    // Pass 2 — Block parsing
    // ---------------------------------------------------------------

    /** @param Violation[] $violations */
    private function passBlockParsing(string $postContent, array &$violations): ?Block
    {
        $result = $this->parser->parse($postContent);

        if (!$result->isOk()) {
            foreach ($result->errors() as $msg) {
                $violations[] = new Violation(self::E_BLOCK_PARSE_ERROR, $msg, '$.post_content');
            }
            return null;
        }

        $root = $result->root();
        if ($root === null || $root->children() === []) {
            $violations[] = new Violation(
                self::E_NO_BLOCKS_FOUND,
                'No Divi blocks found in post_content.',
                '$.post_content'
            );
            return null;
        }

        return $root;
    }

    // ---------------------------------------------------------------
    // Pass 3 — Schema conformance
    // ---------------------------------------------------------------

    /** @param Violation[] $violations */
    private function passSchemaConformance(Block $root, array &$violations): void
    {
        $root->walk(function (Block $block, string $path) use (&$violations): void {
            if ($block->name() === '__root__') {
                return;
            }

            // Unknown block type
            if (!$this->schema->isKnownType($block->name())) {
                $violations[] = new Violation(
                    self::E_UNKNOWN_MODULE_TYPE,
                    "Unknown block type '{$block->name()}'.",
                    $path
                );
                return;
            }

            // builderVersion required on every Divi block except the root placeholder
            // (real exports show divi/placeholder carries no attrs at all)
            if ($block->name() === 'divi/placeholder') {
                return;
            }

            if ($block->attr('builderVersion') === null) {
                $violations[] = new Violation(
                    self::E_MISSING_REQUIRED_FIELD,
                    "Block '{$block->name()}' is missing required attribute 'builderVersion'.",
                    $path . '.builderVersion'
                );
            } elseif (!is_string($block->attr('builderVersion'))) {
                $violations[] = new Violation(
                    self::E_WRONG_FIELD_TYPE,
                    "Block '{$block->name()}' attribute 'builderVersion' must be a string.",
                    $path . '.builderVersion'
                );
            }
        });
    }

    // ---------------------------------------------------------------
    // Pass 4 — Hierarchy integrity
    // ---------------------------------------------------------------

    /** @param Violation[] $violations */
    private function passHierarchyIntegrity(Block $root, array &$violations): void
    {
        // Root's direct children must all be divi/placeholder
        foreach ($root->children() as $i => $child) {
            if ($child->name() !== 'divi/placeholder') {
                $violations[] = new Violation(
                    self::E_WRONG_NESTING,
                    "Top-level block must be 'divi/placeholder', got '{$child->name()}'.",
                    "__root__[$i]"
                );
            }
        }

        // Walk the block tree (skip __root__) and validate each structural block's children
        $root->walk(function (Block $block, string $path) use (&$violations): void {
            if ($block->name() === '__root__') {
                return;
            }

            $allowed = $this->schema->allowedChildrenOf($block->name());

            // Leaf modules must not have children
            if ($this->schema->isLeafModule($block->name()) && $block->children() !== []) {
                $violations[] = new Violation(
                    self::E_WRONG_NESTING,
                    "Leaf module '{$block->name()}' must not have children.",
                    $path
                );
                return;
            }

            // Structural blocks: validate each child's type
            foreach ($block->children() as $i => $child) {
                if ($allowed !== [] && !in_array($child->name(), $allowed, true)) {
                    $violations[] = new Violation(
                        self::E_UNEXPECTED_CHILD_TYPE,
                        "Block '{$child->name()}' is not a valid child of '{$block->name()}'. "
                            . 'Allowed: [' . implode(', ', $allowed) . '].',
                        $path . '[' . $i . ']'
                    );
                }
            }
        });
    }

    // ---------------------------------------------------------------
    // Pass 5 — Render-critical attributes
    // ---------------------------------------------------------------

    /** @param Violation[] $violations */
    private function passRenderCritical(Block $root, array &$violations): void
    {
        $root->walk(function (Block $block, string $path) use (&$violations): void {
            $allRules = $this->schema->contentKeyRules();
            $type     = $block->name();

            if (!isset($allRules[$type])) {
                return;
            }

            foreach ($allRules[$type] as [$contentKey, $required, $mustBeObject]) {
                $innerContent = $block->attr("$contentKey.innerContent");

                if ($innerContent === null) {
                    if ($required) {
                        $violations[] = new Violation(
                            self::E_RENDER_CRITICAL_MISSING,
                            "Block '$type' is missing render-critical attribute '$contentKey.innerContent'.",
                            "$path.$contentKey.innerContent"
                        );
                    }
                    continue;
                }

                $value = $block->attr("$contentKey.innerContent.desktop.value");

                if ($value === null) {
                    if ($required) {
                        $violations[] = new Violation(
                            self::E_RENDER_CRITICAL_MISSING,
                            "Block '$type' is missing render-critical attribute '$contentKey.innerContent.desktop.value'.",
                            "$path.$contentKey.innerContent.desktop.value"
                        );
                    }
                    continue;
                }

                if ($mustBeObject && !is_array($value)) {
                    $violations[] = new Violation(
                        self::E_SCALAR_WHERE_OBJECT,
                        "Block '$type': '$contentKey.innerContent.desktop.value' must be an object, got "
                            . gettype($value) . '. This causes a PHP fatal on render.',
                        "$path.$contentKey.innerContent.desktop.value"
                    );
                }
            }
        });
    }
}
