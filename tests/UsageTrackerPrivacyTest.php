<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

/** The activity log stores no raw IP and no unsalted (rainbow-table-reversible) IP hash. */
class UsageTrackerPrivacyTest extends TestCase
{
    public function testIpIsHashedWithTheSiteSalt(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../wp-plugin/src/UsageTracker.php');
        $this->assertStringContainsString("'ip_hash'    => \$ip ? wp_hash(\$ip) : null,", $src);
        $this->assertStringNotContainsString("hash('sha256', \$ip)", $src);
        $this->assertDoesNotMatchRegularExpression("/'ip(_address)?'\s*=>\s*\\\$ip\b/", $src, 'the raw IP is never stored');
    }

    public function testReadmeDescribesWhatTheLogStores(): void
    {
        $r = (string) file_get_contents(__DIR__ . '/../wp-plugin/readme.txt');
        $this->assertStringContainsString('the first 80 characters of its user-agent string', $r);
        $this->assertStringContainsString('hashed IP address', $r);
    }
}
