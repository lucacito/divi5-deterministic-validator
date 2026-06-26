<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\Licensing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/Licensing.php';

/**
 * Tests the offline Ed25519 license verification and premium gating.
 *
 * Fixtures are real license tokens signed once with the vendor secret key; they
 * verify against the public key embedded in Licensing.php, so no secret is
 * needed at test time. Regenerate via scripts/make-license.php if the keypair
 * ever rotates.
 */
class LicensingTest extends TestCase
{
    // Signed premium, no domain claim, never expires.
    private const PERPETUAL_ANY = 'eyJlbWFpbCI6ImFAeC5jb20iLCJwbGFuIjoicHJlbWl1bSIsImV4cCI6MH0.-0-FX3N2CiiLM9vvZo8-w67CMF3Ixp2Zc37J1PpUsFN9S3-YtKRXGrr3TnO23ly6pZqqjprBGqOcOlxrZFC6DA';
    // Signed premium, bound to example.com.
    private const DOMAIN_EXAMPLE = 'eyJlbWFpbCI6ImFAeC5jb20iLCJwbGFuIjoicHJlbWl1bSIsImRvbWFpbiI6ImV4YW1wbGUuY29tIiwiZXhwIjowfQ.64kF22x6H-miJgk4Xrn_G784P8f5pky7BNHjXrxGxa8is_itIg6MEdX_XbuUsur9G-2wGtcZbiKdyQuXwVt_Bw';
    // Signed premium, bound to other.com.
    private const DOMAIN_OTHER = 'eyJlbWFpbCI6ImFAeC5jb20iLCJwbGFuIjoicHJlbWl1bSIsImRvbWFpbiI6Im90aGVyLmNvbSIsImV4cCI6MH0.L2Z7WKbS6oIznCTvO4JO47znf5LOw6IEF4ah9NzJYsmjvlp1hOtzs3bzI1F_9-ySOuQpcEsPs8XBRGpboU2pBw';
    // Signed premium, already expired.
    private const EXPIRED = 'eyJlbWFpbCI6ImFAeC5jb20iLCJwbGFuIjoicHJlbWl1bSIsImV4cCI6MTc4MjUwNzM5M30.SMyc6jp7zI65r3VRf9tB7dOIpJMWiBYicFGuufOOJMpLBz-_I3hOR4yefrIv2RQuzn3aGZtepInt7qJeVIRGBQ';
    // Signed premium, far-future expiry.
    private const FUTURE = 'eyJlbWFpbCI6ImFAeC5jb20iLCJwbGFuIjoicHJlbWl1bSIsImV4cCI6MTgxNDA0MzQ5M30.aBX-08AowUf-g3BR_WqlCwutmCOfAoPtK5NvEOO_WIcCZeeqF_LcWMDgrRo7L6kAA20-C0zDjRVZioVExcvMBw';
    // Signed but plan is not premium.
    private const WRONG_PLAN = 'eyJlbWFpbCI6ImFAeC5jb20iLCJwbGFuIjoiYmFzaWMiLCJleHAiOjB9.HdWGBRM7NF9rm58AkBI7twkd4BeysaIJj3HfFxzhG_4wtfAibXK48suMN7UqQ9XC954bVnxK1BFlFwAGTmL9Bw';

    protected function setUp(): void
    {
        $GLOBALS['__wp_options']  = [];
        $GLOBALS['__wp_home_url'] = 'https://example.com';
    }

    // --- verify() ---------------------------------------------------

    public function testVerifyReturnsPayloadForValidKey(): void
    {
        $payload = Licensing::verify(self::PERPETUAL_ANY);
        $this->assertIsArray($payload);
        $this->assertSame('premium', $payload['plan']);
        $this->assertSame('a@x.com', $payload['email']);
    }

    public function testVerifyRejectsTamperedSignature(): void
    {
        // Decode the signature, flip one real byte, re-encode — guarantees a
        // genuine 64-byte signature that no longer matches the payload.
        [$payload, $sig] = explode('.', self::PERPETUAL_ANY);
        $bytes    = base64_decode(strtr($sig, '-_', '+/'), true);
        $bytes[0] = $bytes[0] ^ "\x01";
        $tampered = $payload . '.' . rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        $this->assertNotSame(self::PERPETUAL_ANY, $tampered, 'tamper must change the key');
        $this->assertNull(Licensing::verify($tampered));
    }

    public function testVerifyRejectsTamperedPayload(): void
    {
        // Re-encode a different payload but keep the original signature.
        [$_, $sig] = explode('.', self::PERPETUAL_ANY);
        $forged = rtrim(strtr(base64_encode('{"email":"hacker@x.com","plan":"premium","exp":0}'), '+/', '-_'), '=') . '.' . $sig;
        $this->assertNull(Licensing::verify($forged));
    }

    #[DataProvider('malformedKeys')]
    public function testVerifyRejectsMalformedKeys(string $key): void
    {
        $this->assertNull(Licensing::verify($key));
    }

    public static function malformedKeys(): array
    {
        return [
            'empty'        => [''],
            'no dot'       => ['notadotseparatedstring'],
            'three parts'  => ['a.b.c'],
            'not base64'   => ['!!!.???'],
            'short sig'    => [rtrim(strtr(base64_encode('{"plan":"premium"}'), '+/', '-_'), '=') . '.QUJD'],
        ];
    }

    // --- status() / isPremium() -------------------------------------

    public function testNoKeyIsNotPremium(): void
    {
        $this->assertFalse(Licensing::isPremium());
        $this->assertSame('no_key', Licensing::status()['reason']);
    }

    public function testPerpetualKeyWithoutDomainIsPremiumOnAnySite(): void
    {
        $GLOBALS['__wp_home_url'] = 'https://anything-at-all.net';
        Licensing::setKey(self::PERPETUAL_ANY);
        $this->assertTrue(Licensing::isPremium());
    }

    public function testDomainBoundKeyMatchesItsSite(): void
    {
        $GLOBALS['__wp_home_url'] = 'https://www.example.com';   // www stripped on compare
        Licensing::setKey(self::DOMAIN_EXAMPLE);
        $this->assertTrue(Licensing::isPremium());
    }

    public function testDomainBoundKeyRejectedOnDifferentSite(): void
    {
        Licensing::setKey(self::DOMAIN_OTHER);   // bound to other.com, site is example.com
        $status = Licensing::status();
        $this->assertFalse($status['valid']);
        $this->assertSame('domain_mismatch', $status['reason']);
    }

    public function testExpiredKeyIsNotPremium(): void
    {
        Licensing::setKey(self::EXPIRED);
        $status = Licensing::status();
        $this->assertFalse($status['valid']);
        $this->assertSame('expired', $status['reason']);
    }

    public function testFutureExpiryIsPremium(): void
    {
        Licensing::setKey(self::FUTURE);
        $this->assertTrue(Licensing::isPremium());
    }

    public function testWrongPlanIsNotPremium(): void
    {
        Licensing::setKey(self::WRONG_PLAN);
        $status = Licensing::status();
        $this->assertFalse($status['valid']);
        $this->assertSame('wrong_plan', $status['reason']);
    }

    public function testGarbageStoredKeyIsInvalidSignature(): void
    {
        Licensing::setKey('this-is-not-a-license');
        $this->assertSame('invalid_signature', Licensing::status()['reason']);
    }

    public function testClearRemovesPremium(): void
    {
        Licensing::setKey(self::PERPETUAL_ANY);
        $this->assertTrue(Licensing::isPremium());
        Licensing::clear();
        $this->assertFalse(Licensing::isPremium());
    }
}
