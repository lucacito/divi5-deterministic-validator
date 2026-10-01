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
