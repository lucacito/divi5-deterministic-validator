<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OAuthAdmin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/OAuthAdmin.php';

final class OAuthAdminTest extends TestCase
{
    public function testRowsJoinTheAppAndTheUser(): void
    {
        $rows = OAuthAdmin::rows(
            [
                ['family' => 'f1', 'client_id' => 'c1', 'user_id' => 7, 'created_at' => 100],
                ['family' => 'f2', 'client_id' => 'gone', 'user_id' => 9, 'created_at' => 50],
            ],
            static fn(string $id): string => 'c1' === $id ? 'Claude' : '',
            static fn(int $id): string => 'User ' . $id
        );

        $this->assertSame([
            ['family' => 'f1', 'app' => 'Claude', 'user' => 'User 7', 'since' => 100],
            ['family' => 'f2', 'app' => 'Unknown app', 'user' => 'User 9', 'since' => 50],
        ], $rows);
    }

    public function testNoGrantsNoRows(): void
    {
        $this->assertSame([], OAuthAdmin::rows([], static fn() => '', static fn() => ''));
    }

    public function testTheHandlersAreProtectedByCapabilityAndNonce(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../wp-plugin/src/OAuthAdmin.php');
        $this->assertStringContainsString("current_user_can( 'manage_options' )", $src);
        $this->assertSame(2, substr_count($src, 'self::guard('), 'both handlers call guard()');
        $this->assertStringContainsString('check_admin_referer( $nonce )', $src);
        $this->assertStringContainsString("preg_match( '/^[0-9a-f]{32}\$/', \$family )", $src, 'the family id is validated before it reaches a query');
    }
}
