<?php

declare(strict_types=1);

namespace Divi5Validator\Tools;

use Divi5Validator\SchemaRules;

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
