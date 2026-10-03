<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\ValueKind;

require_once __DIR__ . '/../src/autoload.php';

/** A kind whose "site" is an array in $GLOBALS, with switches for permission and write failure. */
final class FakeKind extends ValueKind
{
    public function name(): string
    {
        return 'fake';
    }

    public function current( array $item ): mixed
    {
        return $GLOBALS['__fake_store'][ $item['ref'] ] ?? null;
    }

    public function allowed( array $item ): bool
    {
        return (bool) ( $GLOBALS['__fake_allowed'] ?? true );
    }

    protected function write( array $item, mixed $value ): bool
    {
        if ( $GLOBALS['__fake_fail'] ?? false ) {
            return false;
        }
        $GLOBALS['__fake_store'][ $item['ref'] ] = $value;
        $GLOBALS['__fake_writes'][]               = $item['ref'];

        return true;
    }
}
