<?php

declare(strict_types=1);

namespace Divi5Validator\Tools;

/**
 * Turns Divi's shipped module.json definitions into schema proposals.
 * Pure: takes already-decoded arrays, performs no I/O. Fails loudly on a shape
 * it does not understand rather than guessing.
 */
final class ModuleDeriver
{
    /**
     * @param array<string, mixed> $defs relative path => decoded module.json
     * @return list<array{name:string, category:?string, children:list<string>, isChild:bool}>
     */
    public static function derive(array $defs): array
    {
        $proposals = [];
        foreach ($defs as $path => $def) {
            if (!is_array($def) || !isset($def['name']) || !is_string($def['name']) || !str_starts_with($def['name'], 'divi/')) {
                throw new \UnexpectedValueException("module.json at {$path} has no valid divi/* name");
            }

            $children = [];
            if (isset($def['childrenName'])) {
                if (!is_array($def['childrenName'])) {
                    throw new \UnexpectedValueException("module.json at {$path} has a non-array childrenName");
                }
                foreach ($def['childrenName'] as $child) {
                    if (is_string($child)) {
                        $children[] = $child;
                    }
                }
            }
            if (isset($def['childModuleName']) && is_string($def['childModuleName'])) {
                $children[] = $def['childModuleName'];
            }

            $category = isset($def['category']) && is_string($def['category']) ? $def['category'] : null;

            $proposals[$def['name']] = [
                'name'     => $def['name'],
                'category' => $category,
                'children' => array_values(array_unique($children)),
                'isChild'  => $category === 'child-module',
            ];
        }

        ksort($proposals);

        return array_values($proposals);
    }
}
