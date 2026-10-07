<?php

declare(strict_types=1);

namespace Divi5Validator\Tools;

use Divi5Validator\SchemaRules;
use Divi5Validator\VerifiedModules;

/** Compares Divi's declared modules with what the validator knows. */
final class SchemaGap
{
    /**
     * @param list<array{name:string}> $proposals
     * @return list<array{name:string}> proposals the validator does not recognise
     */
    public static function missing(array $proposals, SchemaRules $rules): array
    {
        return array_values(array_filter($proposals, fn (array $p): bool => !$rules->isKnownType($p['name'])));
    }

    /**
     * Proposals the render harness should probe: placeable on their own (not child
     * items; a module or fullwidth-module) and either unknown to the validator or
     * currently promoted via the generated VerifiedModules. Promoted modules stay
     * candidates so re-verification (e.g. after a Divi update) re-proves them;
     * hand-written known modules (heading, blurb, ...) are never candidates.
     *
     * @param list<array{name:string,category:?string,isChild:bool}> $proposals
     * @return list<array{name:string,category:?string,isChild:bool}>
     */
    public static function candidates(array $proposals, SchemaRules $rules): array
    {
        $promoted = array_merge(VerifiedModules::COLUMN_CHILDREN, VerifiedModules::SECTION_CHILDREN);

        return array_values(array_filter(
            $proposals,
            static fn (array $p): bool => !$p['isChild']
                && in_array($p['category'], ['module', 'fullwidth-module'], true)
                && (!$rules->isKnownType($p['name']) || in_array($p['name'], $promoted, true))
        ));
    }

    /**
     * Child items of a parent the validator ALREADY knows (hand-written, e.g. divi/signup) whose own
     * name it does not: the parent is never a candidate, so `candidates()` never reaches them, and a
     * page holding such a child is refused as an unknown block type (divi/signup-custom-field). They
     * are probed inside their parent. Parents promoted by VerifiedModules::STRUCTURAL are skipped
     * (their parent probe already renders their first child). A child currently promoted through
     * KNOWN_PARENT_CHILDREN stays a candidate so a Divi update re-proves it.
     *
     * @param list<array{name:string,category:?string,isChild:bool,children?:list<string>}> $proposals
     * @return list<array{name:string,category:?string,isChild:bool,children:list<string>,parent:string}>
     */
    public static function childCandidates(array $proposals, SchemaRules $rules): array
    {
        $parentOf = [];
        foreach ($proposals as $p) {
            if ($p['isChild'] || !$rules->isKnownType($p['name']) || in_array($p['name'], VerifiedModules::STRUCTURAL, true)) {
                continue;
            }
            foreach ($p['children'] ?? [] as $kid) {
                $parentOf[$kid] ??= $p['name'];
            }
        }

        $out = [];
        foreach ($proposals as $p) {
            $parent = $parentOf[$p['name']] ?? null;
            if ($parent === null || !$p['isChild'] || $p['category'] !== 'child-module') {
                continue;
            }
            $promoted = in_array($p['name'], VerifiedModules::KNOWN_PARENT_CHILDREN[$parent] ?? [], true);
            if (!$rules->isKnownType($p['name']) || $promoted) {
                $out[] = ['name' => $p['name'], 'category' => $p['category'], 'isChild' => true, 'children' => [], 'parent' => $parent];
            }
        }

        return $out;
    }

    /**
     * @param list<array{name:string}> $proposals
     * @return list<string> types the validator knows that no module.json declares
     */
    public static function unmatched(array $proposals, SchemaRules $rules): array
    {
        $declared = array_column($proposals, 'name');
        $known    = array_merge(SchemaRules::STRUCTURAL_BLOCKS, SchemaRules::LEAF_MODULES);
        $out      = array_values(array_diff($known, $declared));
        sort($out);

        return $out;
    }
}
