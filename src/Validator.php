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
 *   5. Render-critical  — content-key innerContent shapes for leaf modules (hand-written
 *                         rules plus the live-proven render fatals of VerifiedContentTypes)
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
    public const E_OBJECT_WHERE_SCALAR     = 'OBJECT_WHERE_SCALAR';
    public const E_ORPHANED_REFERENCE      = 'ORPHANED_REFERENCE';

    // Pass 5b — Loop Builder settings and dynamic-content tokens (live-proven on Divi 5.15.1, docs/loop-builder-evidence-5.15.1.md)
    public const E_INVALID_LOOP_SETTING     = 'INVALID_LOOP_SETTING';
    public const E_MALFORMED_DYNAMIC_TOKEN  = 'MALFORMED_DYNAMIC_TOKEN';

    // Pass 6 — SEO / accessibility
    public const E_MULTIPLE_H1             = 'MULTIPLE_H1';

    private SchemaRules $schema;
    private BlockParser $parser;

    public function __construct(?SchemaRules $schema = null, ?BlockParser $parser = null)
    {
        $this->schema = $schema ?? new SchemaRules();
        $this->parser = $parser ?? new BlockParser();
    }

    public function validateContent(string $postContent): ValidationResult
    {
        $json = json_encode(['post_content' => $postContent]);
        if ($json === false) {
            return new ValidationResult([new Violation(
                self::E_INVALID_JSON,
                'post_content contains unencodable bytes: ' . json_last_error_msg(),
                '$.post_content'
            )]);
        }
        return $this->validate($json);
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

        // Pass 5b — Loop Builder settings and dynamic-content tokens
        $this->passLoops($tree, $violations);

        // Pass 6 — SEO / accessibility (at most one h1 per page)
        $this->passSingleH1($tree, $violations);

        return new ValidationResult($violations);
    }

    // ---------------------------------------------------------------
    // Pass 6 — Single H1 (SEO / accessibility)
    // ---------------------------------------------------------------

    /**
     * A page must have at most one <h1>. divi/heading blocks render at the level
     * in title…headingLevel ("h1".."h6"); an omitted level is not h1. Each h1
     * beyond the first is flagged.
     *
     * @param Violation[] $violations
     */
    private function passSingleH1(Block $root, array &$violations): void
    {
        $seen = 0;
        $root->walk(function (Block $block, string $path) use (&$violations, &$seen): void {
            if ($block->name() !== 'divi/heading') {
                return;
            }
            if ($block->attr('title.decoration.font.font.desktop.value.headingLevel') !== 'h1') {
                return;
            }
            $seen++;
            if ($seen > 1) {
                $violations[] = new Violation(
                    self::E_MULTIPLE_H1,
                    'A page must have only one <h1>. Use h2–h6 for additional headings (set title.decoration.font.font.desktop.value.headingLevel).',
                    $path . '.headingLevel'
                );
            }
        });
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
            // and divi/global-layout — real exports show both carry no builderVersion
            // (global-layout is a Theme Builder reference with globalModule/localAttrs).
            if ($block->name() === 'divi/placeholder' || $block->name() === 'divi/global-layout') {
                return;
            }

            // A Slot is matched to the component's own Slot by its slotId; Divi 5.15 ignores one without it
            // (GlobalSlotUtils skips an empty id), so its content would silently never show.
            if ($block->name() === 'divi/global-slot') {
                $slotId = $block->attr('slotId');
                if (!is_string($slotId) || $slotId === '') {
                    $violations[] = new Violation(
                        self::E_MISSING_REQUIRED_FIELD,
                        "Block 'divi/global-slot' is missing required attribute 'slotId' (a non-empty string).",
                        $path . '.slotId'
                    );
                }
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
        // Top-level blocks: real Divi 5 pages either wrap everything in a
        // divi/placeholder, or place divi/section blocks directly at the top
        // (optionally with divi/global-layout Theme Builder references). All
        // three forms are valid roots — confirmed across real site exports.
        $allowedTop = ['divi/placeholder', 'divi/section', 'divi/global-layout'];
        foreach ($root->children() as $i => $child) {
            if (!in_array($child->name(), $allowedTop, true)) {
                $violations[] = new Violation(
                    self::E_WRONG_NESTING,
                    "Top-level block must be one of [divi/placeholder, divi/section, divi/global-layout], got '{$child->name()}'.",
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

            // Note: we deliberately do NOT flag "leaf module has children". Real
            // Divi 5 saves many modules as paired blocks wrapping inner content
            // (text, number-counter, image, tab, contact-field, … — confirmed
            // across two production sites). Children are still validated as blocks
            // below and by the other passes; the important structural hierarchy
            // (root forms, section/row/column, compound parent→child) is enforced
            // via allowedChildrenOf, which is empty (no restriction) for leaves.

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
        $allRules = $this->schema->contentKeyRules();
        $fatal    = $this->schema->renderFatalKeys();

        $root->walk(function (Block $block, string $path) use (&$violations, $allRules, $fatal): void {
            $type = $block->name();
            // Paths the hand-written table already reported, so the live-proven table never reports them twice.
            $reported = [];

            foreach ($allRules[$type] ?? [] as [$contentKey, $required, $mustBeObject]) {
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
                    $reported[$contentKey] = true;
                }
            }

            if (!$this->schema->isKnownType($type)) {
                return;
            }

            // Live-proven render fatals (Divi answered HTTP 500 for the whole page): src/VerifiedContentTypes.php.
            foreach ($fatal['object'][$type] ?? [] as $contentKey) {
                $value = $block->attr("$contentKey.innerContent.desktop.value");
                if ($value !== null && !is_array($value) && !isset($reported[$contentKey])) {
                    $violations[] = new Violation(
                        self::E_SCALAR_WHERE_OBJECT,
                        "Block '$type': '$contentKey.innerContent.desktop.value' must be an object, got "
                            . gettype($value) . '. This causes a PHP fatal on render (Divi answers HTTP 500 for the whole page).',
                        "$path.$contentKey.innerContent.desktop.value"
                    );
                }
            }
            foreach ($fatal['scalar'][$type] ?? [] as $contentKey) {
                $value = $block->attr("$contentKey.innerContent.desktop.value");
                if (is_array($value)) {
                    $violations[] = new Violation(
                        self::E_OBJECT_WHERE_SCALAR,
                        "Block '$type': '$contentKey.innerContent.desktop.value' must be a plain string or HTML, got an "
                            . 'object/array; this causes a PHP fatal on render (Divi answers HTTP 500 for the whole page).',
                        "$path.$contentKey.innerContent.desktop.value"
                    );
                }
            }
        });
    }

    // ---------------------------------------------------------------
    // Pass 5b — Loop Builder (module.advanced.loop) and dynamic tokens
    // ---------------------------------------------------------------

    /**
     * @return string[] Query types the Loop Builder offers (Divi 5.15.1 QueryTypeController), plus "terms" (Divi reads it as
     * post_taxonomies; live-rendered) and "repeater_<acf field name>" (matched by isLoopQueryType()).
     */
    private function loopQueryTypes(): array
    {
        return ['post_types', 'post_taxonomies', 'terms', 'user_roles', 'menus', 'current_page'];
    }

    /**
     * An ACF repeater loop is any queryType "repeater_" plus the field name (DynamicContentACFUtils::is_repeater_query). Divi runs
     * the type through sanitize_key(), so only lowercase letters, digits, "_" and "-" survive. Source-grounded and rendered on
     * Divi 5.15.1 without ACF (nothing listed, nothing thrown); not rendered with a real ACF Pro repeater.
     */
    private function isLoopQueryType(mixed $type): bool
    {
        return is_string($type)
            && (in_array($type, $this->loopQueryTypes(), true) || preg_match('/^repeater_[a-z0-9_-]+$/', $type) === 1);
    }

    /**
     * Every shape below was rendered on the real Divi 5.15.1 (docs/loop-builder-evidence-5.15.1.md):
     * some answer HTTP 500 for the whole page (a non-list metaQuery, an array order or loopId), the others make the loop
     * silently switch off or query every post type, which is what a saved layout must never do. The builder itself only
     * writes the shapes accepted here (fixtures/valid/loop-builder-5.3.json), including "" for an empty include/exclude list.
     *
     * @param Violation[] $violations
     */
    private function passLoops(Block $root, array &$violations): void
    {
        $root->walk(function (Block $block, string $path) use (&$violations): void {
            $type = $block->name();
            $loop = $block->attr('module.advanced.loop');
            $base = "$path.module.advanced.loop";

            if ($loop !== null && $loop !== []) {
                $fail = static function (string $what) use (&$violations, $type, $base): void {
                    $violations[] = new Violation(
                        self::E_INVALID_LOOP_SETTING,
                        "Block '$type': loop setting $what",
                        $base
                    );
                };
                $value = is_array($loop) ? ($loop['desktop']['value'] ?? null) : null;
                if (!is_array($loop) || !is_array($loop['desktop'] ?? null) || !is_array($value)) {
                    $fail("must be an object {\"desktop\":{\"value\":{...}}} (anything else switches the loop off without a message).");
                } else {
                    $this->checkLoopValue($value, $fail);
                }
            }

            // A Post Navigation module connected to a loop: an array or object here is a TypeError for the whole page
            // (LoopQueryRegistry::get_query() wants a string; docs/loop-builder-evidence-5.15.1.md, probe 2026-11-08).
            if ($type === 'divi/post-nav' && is_array($block->attr('module.advanced.targetLoop.desktop.value'))) {
                $violations[] = new Violation(
                    self::E_INVALID_LOOP_SETTING,
                    "Block '$type': 'module.advanced.targetLoop.desktop.value' must be the loopId string of the loop it pages "
                        . '(an array or object makes Divi answer HTTP 500 for the whole page).',
                    "$path.module.advanced.targetLoop.desktop.value"
                );
            }

            $this->checkTokens($block->attrs(), $path, $type, $violations);
        });
    }

    /** @param callable(string):void $fail */
    private function checkLoopValue(array $v, callable $fail): void
    {
        $isDigits = static fn($x, int $min): bool => (is_int($x) && $x >= $min)
            || (is_string($x) && preg_match('/^\d+$/', $x) === 1 && (int) $x >= $min);

        if (array_key_exists('enable', $v) && !in_array($v['enable'], ['on', 'off'], true)) {
            $fail("'enable' must be the string \"on\" or \"off\" (true, \"yes\" or a missing value leave the loop off).");
        }
        if (array_key_exists('loopId', $v) && !is_string($v['loopId'])) {
            $fail("'loopId' must be a string such as \"loop-ab12cd34ef\" (an array makes Divi answer HTTP 500 for the whole page).");
        }
        if (array_key_exists('queryType', $v) && !$this->isLoopQueryType($v['queryType'])) {
            $fail("'queryType' must be one of " . implode(', ', $this->loopQueryTypes()) . ', or repeater_<lowercase ACF field name>.');
        }
        if (array_key_exists('order', $v) && !in_array($v['order'], ['ascending', 'descending'], true)) {
            $fail("'order' must be the string \"ascending\" or \"descending\" (an array makes Divi answer HTTP 500, \"desc\" is read as ascending).");
        }
        if (array_key_exists('orderBy', $v) && !is_string($v['orderBy'])) {
            $fail("'orderBy' must be a string such as \"date\", \"name\" or \"title\".");
        }
        if (array_key_exists('postPerPage', $v) && !$isDigits($v['postPerPage'], 1)) {
            $fail("'postPerPage' must be a whole number of 1 or more, written as a string such as \"6\" (\"abc\" and \"0\" load every item).");
        }
        if (array_key_exists('postOffset', $v) && !$isDigits($v['postOffset'], 0)) {
            $fail("'postOffset' must be a whole number written as a string such as \"0\".");
        }
        foreach (['excludeCurrentPost', 'ignoreStickysPost'] as $key) {
            if (array_key_exists($key, $v) && !in_array($v[$key], ['on', 'off'], true)) {
                $fail("'$key' must be the string \"on\" or \"off\".");
            }
        }
        if (array_key_exists('subTypes', $v)) {
            $ok = is_array($v['subTypes']) && $this->isList($v['subTypes']);
            foreach ($ok ? $v['subTypes'] : [] as $item) {
                if (!is_array($item) || !is_string($item['value'] ?? null) || $item['value'] === '') {
                    $ok = false;
                }
            }
            if (!$ok) {
                $fail("'subTypes' must be a list of {\"value\":\"<slug>\",\"label\":\"<name>\"} objects, for example [{\"value\":\"post\",\"label\":\"Posts\"}] (any other shape makes the loop read EVERY post type, media files included).");
            }
        }
        if (array_key_exists('metaQuery', $v) && !(is_array($v['metaQuery']) && $this->isList($v['metaQuery']))) {
            $fail("'metaQuery' must be a list of {\"id\",\"metaKey\",\"metaValue\",\"compare\",\"type\"} objects, or left out (a string, even an empty one, makes Divi answer HTTP 500 for the whole page).");
        }
    }

    /** A JSON array (not an object): keys 0..n-1 (array_is_list() needs WordPress 6.5). */
    private function isList(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    /**
     * A dynamic-content token is written $variable({...})$. Without the closing "$" Divi prints the raw text on the page.
     *
     * @param Violation[] $violations
     */
    private function checkTokens(mixed $node, string $path, string $type, array &$violations): void
    {
        if (is_array($node)) {
            foreach ($node as $key => $child) {
                $this->checkTokens($child, $path . '.' . $key, $type, $violations);
            }
            return;
        }
        if (!is_string($node) || !str_contains($node, '$variable(')) {
            return;
        }
        $offset = 0;
        while (($at = strpos($node, '$variable(', $offset)) !== false) {
            if (preg_match('/\G\$variable\(\{.*?\}\)\$/s', $node, $m, 0, $at) !== 1) {
                $violations[] = new Violation(
                    self::E_MALFORMED_DYNAMIC_TOKEN,
                    "Block '$type': a dynamic-content token must read \$variable({...})\$ with both the opening \$variable( and the closing )\$; "
                        . 'otherwise Divi prints the raw text on the page.',
                    $path
                );
                return;
            }
            $offset = $at + strlen($m[0]);
        }
    }
}
