<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Licensing\LicenseExtras;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Licensing/LicenseExtras.php';

class LicenseExtrasTest extends TestCase
{
    public function testEmptyReturnsDefaults(): void
    {
        $extras = LicenseExtras::empty();
        $this->assertSame(
            [
                'tier'          => null,
                'tier_label'    => null,
                'sites_used'    => null,
                'sites_allowed' => null,
                'founding'      => false,
                'lifetime'      => false,
                'upgrade_url'   => null,
                'manage_url'    => null,
            ],
            $extras
        );
    }

    public function testFromBodyWithCompleteData(): void
    {
        $body = [
            'tier'          => 'agency',
            'tier_label'    => 'Agency',
            'sites_used'    => 3,
            'sites_allowed' => null,
            'founding'      => true,
            'lifetime'      => false,
            'upgrade_url'   => 'https://divi5lab.com/pricing',
            'manage_url'    => 'https://divi5lab.com/account/licenses',
        ];
        $extras = LicenseExtras::fromBody( $body, 'https://divi5lab.com' );
        $this->assertSame( $body, $extras );
    }

    public function testFromBodyWithMissingFields(): void
    {
        $body = [
            'tier'  => 'personal',
            'founding' => false,
        ];
        $extras = LicenseExtras::fromBody( $body, 'https://divi5lab.com' );
        $this->assertSame( 'personal', $extras['tier'] );
        $this->assertNull( $extras['tier_label'] );
        $this->assertNull( $extras['sites_used'] );
        $this->assertNull( $extras['sites_allowed'] );
        $this->assertFalse( $extras['founding'] );
        $this->assertFalse( $extras['lifetime'] );
    }

    public function testFromBodyIgnoresNonArrayInput(): void
    {
        $extras = LicenseExtras::fromBody( 'not an array', 'https://divi5lab.com' );
        $this->assertSame( LicenseExtras::empty(), $extras );
    }

    public function testFromBodyIgnoresNullInput(): void
    {
        $extras = LicenseExtras::fromBody( null, 'https://divi5lab.com' );
        $this->assertSame( LicenseExtras::empty(), $extras );
    }

    public function testFromBodySanitizesTierAsString(): void
    {
        $body = [
            'tier' => 123, // wrong type
            'founding' => false,
        ];
        $extras = LicenseExtras::fromBody( $body, 'https://divi5lab.com' );
        $this->assertNull( $extras['tier'] );
    }

    public function testFromBodySanitizesSitesAsInteger(): void
    {
        $body = [
            'sites_used'    => '5',
            'sites_allowed' => '10.5',
            'founding'      => false,
        ];
        $extras = LicenseExtras::fromBody( $body, 'https://divi5lab.com' );
        // Should accept integers but reject non-numeric strings
        $this->assertNull( $extras['sites_used'] );
        $this->assertNull( $extras['sites_allowed'] );
    }

    public function testFromBodySanitizesBooleanFields(): void
    {
        $body = [
            'founding' => 'true', // string instead of boolean
            'lifetime' => 1,      // integer instead of boolean
        ];
        $extras = LicenseExtras::fromBody( $body, 'https://divi5lab.com' );
        $this->assertFalse( $extras['founding'] );
        $this->assertFalse( $extras['lifetime'] );
    }

    public function testFromBodyKeepsOnlyHttpsUrls(): void
    {
        $body = [
            'upgrade_url' => 'http://divi5lab.com/pricing', // http not https
            'manage_url'  => 'https://divi5lab.com/account',
            'founding'    => false,
        ];
        $extras = LicenseExtras::fromBody( $body, 'https://divi5lab.com' );
        $this->assertNull( $extras['upgrade_url'] );
        $this->assertSame( 'https://divi5lab.com/account', $extras['manage_url'] );
    }

    public function testFromBodyKeepsOnlyLicenseHostUrls(): void
    {
        $body = [
            'upgrade_url' => 'https://malicious.com/pricing',
            'manage_url'  => 'https://divi5lab.com/account',
            'founding'    => false,
        ];
        $extras = LicenseExtras::fromBody( $body, 'https://divi5lab.com' );
        $this->assertNull( $extras['upgrade_url'] );
        $this->assertSame( 'https://divi5lab.com/account', $extras['manage_url'] );
    }

    public function testAWwwPrefixOnEitherSideIsTheSameHost(): void
    {
        $body = [ 'upgrade_url' => 'https://www.divi5lab.com/pricing', 'manage_url' => 'https://divi5lab.com/account/licenses' ];
        $this->assertSame( 'https://www.divi5lab.com/pricing', LicenseExtras::fromBody( $body, 'https://divi5lab.com' )['upgrade_url'] );
        $this->assertSame( 'https://divi5lab.com/account/licenses', LicenseExtras::fromBody( $body, 'https://www.divi5lab.com' )['manage_url'] );
        $this->assertNull( LicenseExtras::fromBody( [ 'upgrade_url' => 'https://www.divi5lab.com.evil.test/pricing' ], 'https://divi5lab.com' )['upgrade_url'] );
    }

    public function testFromBodyHandlesDifferentApiBase(): void
    {
        $body = [
            'upgrade_url' => 'https://localhost:8080/pricing',
            'manage_url'  => 'https://localhost:8080/account',
            'founding'    => false,
        ];
        $extras = LicenseExtras::fromBody( $body, 'https://localhost:8080' );
        $this->assertSame( 'https://localhost:8080/pricing', $extras['upgrade_url'] );
        $this->assertSame( 'https://localhost:8080/account', $extras['manage_url'] );
    }

    public function testFromBodyIgnoresExtraFields(): void
    {
        $body = [
            'tier'          => 'personal',
            'founding'      => false,
            'extra_field'   => 'should be ignored',
            'another_extra' => 123,
        ];
        $extras = LicenseExtras::fromBody( $body, 'https://divi5lab.com' );
        $this->assertArrayNotHasKey( 'extra_field', $extras );
        $this->assertArrayNotHasKey( 'another_extra', $extras );
        $this->assertCount( 8, $extras ); // only the 8 expected keys
    }
}
