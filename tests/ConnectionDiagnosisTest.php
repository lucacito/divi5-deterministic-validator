<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ConnectionDiagnosis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';
require_once __DIR__ . '/../wp-plugin/src/ConnectionDiagnosis.php';

/**
 * The pure diagnosis behind the Settings screen's "Test connection" button: facts in, plain English out.
 * The rule that matters most: "Connected" needs positive evidence from all three probes.
 */
final class ConnectionDiagnosisTest extends TestCase
{
    /** A healthy site: 401 without the key, 200 JSON-RPC with it, a tool list. */
    private function healthy(array $overrides = []): array
    {
        $base = [
            'unauth' => ['status' => 401, 'contentType' => 'application/json', 'jsonCode' => 'unauthorized', 'jsonRpc' => false, 'toolCount' => null, 'headerHint' => false, 'redirected' => false],
            'auth'   => ['status' => 200, 'contentType' => 'application/json', 'jsonCode' => '', 'jsonRpc' => true, 'toolCount' => null, 'headerHint' => false, 'redirected' => false],
            'tools'  => ['status' => 200, 'contentType' => 'application/json', 'jsonCode' => '', 'jsonRpc' => true, 'toolCount' => 21, 'headerHint' => false, 'redirected' => false],
        ];
        foreach ($overrides as $name => $fields) {
            $base[$name] = array_merge($base[$name], $fields);
        }

        return $base;
    }

    private function site(array $overrides = []): array
    {
        return array_merge([
            'permalinks_plain' => false,
            'is_local'         => false,
            'scheme'           => 'https',
            'key_owner_ok'     => true,
            'divi'             => '5.14.0',
        ], $overrides);
    }

    /** @return list<string> */
    private function codes(array $probes, array $site): array
    {
        return array_column(ConnectionDiagnosis::explain($probes, $site), 'code');
    }

    public function testHealthySiteIsConnectedAndNothingElse(): void
    {
        $f = ConnectionDiagnosis::explain($this->healthy(), $this->site());
        $this->assertSame(['ALL_OK'], array_column($f, 'code'));
        $this->assertSame('ok', $f[0]['level']);
        $this->assertStringContainsString('21 tools', $f[0]['title']);
        $this->assertStringContainsString('from your browser', $f[0]['fix']);
    }

    public function testOneToolIsSingular(): void
    {
        $f = ConnectionDiagnosis::explain($this->healthy(['tools' => ['toolCount' => 1]]), $this->site());
        $this->assertStringContainsString('1 tool available', $f[0]['title']);
    }

    /** @return array<string,array{0:array,1:array,2:string}> */
    public static function errorCases(): array
    {
        $h = static fn(array $o = []): array => (new self('x'))->healthy($o);

        return [
            'network on the first probe'  => [$h(['unauth' => ['status' => 0]]), [], 'NETWORK'],
            'network on the second probe' => [$h(['auth' => ['status' => 0]]), [], 'NETWORK'],
            'network on the tools probe'  => [$h(['tools' => ['status' => 0]]), [], 'NETWORK'],
            'plain permalinks'            => [$h(['unauth' => ['status' => 404, 'jsonCode' => '']]), ['permalinks_plain' => true], 'PERMALINKS_PLAIN'],
            'not found'                   => [$h(['unauth' => ['status' => 404, 'jsonCode' => '']]), [], 'NOT_FOUND'],
            'rest closed (json)'          => [$h(['unauth' => ['status' => 401, 'jsonCode' => 'rest_login_required']]), [], 'REST_BLOCKED_JSON'],
            'rest closed (cannot access)' => [$h(['unauth' => ['status' => 403, 'jsonCode' => 'rest_cannot_access']]), [], 'REST_BLOCKED_JSON'],
            'firewall html 403'           => [$h(['auth' => ['status' => 403, 'jsonCode' => '', 'jsonRpc' => false, 'headerHint' => true]]), [], 'REST_BLOCKED_HTML'],
            'firewall html 429'           => [$h(['unauth' => ['status' => 429, 'jsonCode' => '', 'headerHint' => true]]), [], 'REST_BLOCKED_HTML'],
            'server error with json'      => [$h(['auth' => ['status' => 500, 'jsonCode' => 'internal_error', 'jsonRpc' => false]]), [], 'SERVER_ERROR'],
            'server error with html'      => [$h(['auth' => ['status' => 500, 'jsonCode' => '', 'jsonRpc' => false, 'headerHint' => true]]), [], 'SERVER_ERROR'],
            'bad gateway'                 => [$h(['tools' => ['status' => 502, 'jsonCode' => '', 'toolCount' => null]]), [], 'SERVER_ERROR'],
            'redirected'                  => [$h(['auth' => ['redirected' => true]]), [], 'REDIRECTED'],
            'key owner cannot edit'       => [$h(['auth' => ['status' => 403, 'jsonCode' => 'forbidden', 'jsonRpc' => false]]), ['key_owner_ok' => false], 'KEY_OWNER_CANNOT_EDIT'],
            'key rejected 401'            => [$h(['auth' => ['status' => 401, 'jsonCode' => 'unauthorized', 'jsonRpc' => false]]), [], 'KEY_REJECTED'],
            'key rejected 403'            => [$h(['auth' => ['status' => 403, 'jsonCode' => 'forbidden', 'jsonRpc' => false]]), [], 'KEY_REJECTED'],
            'cached page answers 200'     => [$h(['auth' => ['status' => 200, 'jsonRpc' => false, 'contentType' => 'text/html']]), [], 'BAD_REPLY'],
            'unauth answered 200 as html' => [$h(['unauth' => ['status' => 200, 'jsonRpc' => false, 'jsonCode' => '']]), [], 'BAD_REPLY'],
            'no tools'                    => [$h(['tools' => ['toolCount' => 0]]), [], 'NO_TOOLS'],
            'tools probe refused'         => [$h(['tools' => ['status' => 401, 'jsonCode' => 'unauthorized', 'toolCount' => null]]), [], 'UNEXPECTED'],
            'tools without a count'       => [$h(['tools' => ['toolCount' => null]]), [], 'UNEXPECTED'],
            'unauth open (200 json-rpc)'  => [$h(['unauth' => ['status' => 200, 'jsonRpc' => true, 'jsonCode' => '']]), [], 'UNEXPECTED'],
            'auth answered 204'           => [$h(['auth' => ['status' => 204, 'jsonRpc' => false]]), [], 'UNEXPECTED'],
        ];
    }

    #[DataProvider('errorCases')]
    public function testEachFailureHasItsOwnSingleFinding(array $probes, array $siteOverrides, string $expected): void
    {
        $f = ConnectionDiagnosis::explain($probes, $this->site($siteOverrides));
        $this->assertSame([$expected], array_column($f, 'code'), 'one error, never a success line next to it');
        $this->assertSame('error', $f[0]['level']);
        $this->assertNotSame('', $f[0]['title']);
        $this->assertNotSame('', $f[0]['fix']);
    }

    public function testPrecedenceNetworkBeatsEverything(): void
    {
        $probes = $this->healthy(['unauth' => ['status' => 0], 'auth' => ['status' => 500]]);
        $this->assertSame(['NETWORK'], $this->codes($probes, $this->site(['key_owner_ok' => false])));
    }

    public function testPlainPermalinksBeatTheGenericNotFound(): void
    {
        $probes = $this->healthy(['unauth' => ['status' => 404, 'jsonCode' => '']]);
        $this->assertSame(['PERMALINKS_PLAIN'], $this->codes($probes, $this->site(['permalinks_plain' => true])));
    }

    public function testForeignRestBlockBeatsKeyRejected(): void
    {
        $probes = $this->healthy(['auth' => ['status' => 401, 'jsonCode' => 'rest_forbidden', 'jsonRpc' => false]]);
        $this->assertSame(['REST_BLOCKED_JSON'], $this->codes($probes, $this->site()));
    }

    public function testOurOwnErrorCodesAreNeverReadAsAForeignBlock(): void
    {
        $probes = $this->healthy(['auth' => ['status' => 401, 'jsonCode' => 'unauthorized', 'jsonRpc' => false]]);
        $this->assertSame(['KEY_REJECTED'], $this->codes($probes, $this->site()));
    }

    public function testKeyOwnerProblemBeatsKeyRejected(): void
    {
        $probes = $this->healthy(['auth' => ['status' => 403, 'jsonCode' => 'forbidden', 'jsonRpc' => false]]);
        $this->assertSame(['KEY_OWNER_CANNOT_EDIT'], $this->codes($probes, $this->site(['key_owner_ok' => false])));
    }

    public function testKeyRejectedFixShowsTheHtaccessLines(): void
    {
        $probes = $this->healthy(['auth' => ['status' => 401, 'jsonCode' => 'unauthorized', 'jsonRpc' => false]]);
        $fix = ConnectionDiagnosis::explain($probes, $this->site())[0]['fix'];
        $this->assertStringContainsString('RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]', $fix);
    }

    public function testWarningsSitNextToTheSuccessLine(): void
    {
        $codes = $this->codes($this->healthy(), $this->site(['divi' => null, 'is_local' => true, 'scheme' => 'http']));
        $this->assertSame(['DIVI_MISSING', 'LOCAL_SITE', 'ALL_OK'], $codes);
    }

    public function testHttpOnlyWarningOnARealSite(): void
    {
        $codes = $this->codes($this->healthy(), $this->site(['scheme' => 'http']));
        $this->assertSame(['HTTP_ONLY', 'ALL_OK'], $codes);
    }

    public function testNewerDiviWarns(): void
    {
        $codes = $this->codes($this->healthy(), $this->site(['divi' => '9.0.0']));
        $this->assertSame(['DIVI_NEWER', 'ALL_OK'], $codes);
    }

    public function testAnErrorHidesTheWarnings(): void
    {
        $probes = $this->healthy(['tools' => ['toolCount' => 0]]);
        $this->assertSame(['NO_TOOLS'], $this->codes($probes, $this->site(['divi' => null, 'is_local' => true])));
    }

    public function testNoProbeAtAllIsANetworkProblemNotSuccess(): void
    {
        $this->assertSame(['NETWORK'], $this->codes(ConnectionDiagnosis::sanitizeProbes(null), $this->site()));
        $this->assertSame(['NETWORK'], $this->codes(ConnectionDiagnosis::sanitizeProbes('garbage'), $this->site()));
        $this->assertSame(['NETWORK'], $this->codes(ConnectionDiagnosis::sanitizeProbes([]), $this->site()));
    }

    public function testSanitizeKeepsOnlyKnownProbesAndFields(): void
    {
        $out = ConnectionDiagnosis::sanitizeProbes([
            'unauth' => ['status' => 401, 'evil' => 'x', 'jsonCode' => 'unauthorized'],
            'bogus'  => ['status' => 200],
        ]);
        $this->assertSame(['unauth', 'auth', 'tools'], array_keys($out));
        $this->assertSame(['status', 'contentType', 'jsonCode', 'jsonRpc', 'toolCount', 'headerHint', 'redirected', 'hasChallenge', 'docIssuer', 'docResource'], array_keys($out['unauth']));
        $this->assertSame(401, $out['unauth']['status']);
    }

    public function testSanitizeNeverCastsWrongTypes(): void
    {
        $out = ConnectionDiagnosis::sanitizeProbes([
            'unauth' => ['status' => '200', 'jsonRpc' => 'true', 'headerHint' => 1, 'redirected' => 'yes', 'toolCount' => '5', 'jsonCode' => ['x'], 'contentType' => 7],
            'auth'   => ['status' => 200.0, 'toolCount' => -1],
            'tools'  => ['status' => 99999, 'toolCount' => 100000],
        ]);
        $this->assertSame(0, $out['unauth']['status']);
        $this->assertFalse($out['unauth']['jsonRpc']);
        $this->assertFalse($out['unauth']['headerHint']);
        $this->assertFalse($out['unauth']['redirected']);
        $this->assertNull($out['unauth']['toolCount']);
        $this->assertSame('', $out['unauth']['jsonCode']);
        $this->assertSame('', $out['unauth']['contentType']);
        $this->assertSame(0, $out['auth']['status']);
        $this->assertNull($out['auth']['toolCount']);
        $this->assertSame(0, $out['tools']['status']);
        $this->assertNull($out['tools']['toolCount']);
    }

    public function testSanitizeCapsStringLengths(): void
    {
        $out = ConnectionDiagnosis::sanitizeProbes(['unauth' => ['jsonCode' => str_repeat('a', 5000), 'contentType' => str_repeat('b', 5000)]]);
        $this->assertSame(100, strlen($out['unauth']['jsonCode']));
        $this->assertSame(100, strlen($out['unauth']['contentType']));
    }

    public function testNothingTheBrowserSendsIsEchoedBack(): void
    {
        $probes = ConnectionDiagnosis::sanitizeProbes([
            'unauth' => ['status' => 401, 'jsonCode' => 'SECRET-aied-key-123'],
            'auth'   => ['status' => 401, 'jsonCode' => 'SECRET-aied-key-123'],
            'tools'  => ['status' => 200, 'contentType' => 'SECRET-aied-key-123'],
        ]);
        $json = json_encode(ConnectionDiagnosis::explain($probes, $this->site()));
        $this->assertStringNotContainsString('SECRET-aied-key-123', (string) $json);
    }

    public function testEveryFindingHasAllFieldsAndAKnownLevel(): void
    {
        foreach (self::errorCases() as [$probes, $siteOverrides]) {
            foreach (ConnectionDiagnosis::explain($probes, $this->site($siteOverrides)) as $finding) {
                $this->assertSame(['level', 'code', 'title', 'fix'], array_keys($finding));
                $this->assertContains($finding['level'], ['error', 'warning', 'ok']);
                $this->assertNotSame('', $finding['title']);
            }
        }
    }

    // ── OAuth sign-in probes (only while the owner has switched it on) ─────────────────────

    private function oauthProbes(array $as = [], array $prm = [], array $unauth = []): array
    {
        $doc = ['status' => 200, 'contentType' => 'application/json', 'jsonCode' => '', 'jsonRpc' => false, 'toolCount' => null, 'headerHint' => false, 'redirected' => false, 'hasChallenge' => false, 'docIssuer' => '', 'docResource' => ''];

        return $this->healthy(['unauth' => array_merge(['hasChallenge' => true], $unauth)]) + [
            'oauth_as'  => array_merge($doc, ['docIssuer' => 'https://example.com'], $as),
            'oauth_prm' => array_merge($doc, ['docResource' => 'https://example.com/wp-json/ai-editor-divi5/v1/mcp'], $prm),
        ];
    }

    private function oauthSite(array $o = []): array
    {
        return $this->site(array_merge(['oauth' => true, 'issuer' => 'https://example.com', 'resource' => 'https://example.com/wp-json/ai-editor-divi5/v1/mcp'], $o));
    }

    public function testOauthOffMeansNoOauthFindingsEvenIfProbesArrive(): void
    {
        $this->assertSame(['ALL_OK'], $this->codes($this->oauthProbes(['status' => 404]), $this->site()));
    }

    public function testOauthWorksAddsAFriendlyLine(): void
    {
        $this->assertSame(['OAUTH_OK', 'ALL_OK'], $this->codes($this->oauthProbes(), $this->oauthSite()));
    }

    public function testBlockedWellKnownIsAWarningNotAnError(): void
    {
        foreach ([['status' => 404, 'docIssuer' => ''], ['status' => 403, 'headerHint' => true, 'docIssuer' => ''], ['status' => 200, 'docIssuer' => ''], ['status' => 0, 'docIssuer' => '']] as $as) {
            $f = ConnectionDiagnosis::explain($this->oauthProbes($as), $this->oauthSite());
            $this->assertSame(['OAUTH_WELLKNOWN_BLOCKED', 'ALL_OK'], array_column($f, 'code'), json_encode($as));
            $this->assertSame('warning', $f[0]['level'], 'the API key connection still works');
        }
    }

    public function testAMissingResourceDocumentCountsAsBlocked(): void
    {
        $this->assertSame(['OAUTH_WELLKNOWN_BLOCKED', 'ALL_OK'], $this->codes($this->oauthProbes([], ['status' => 404, 'docResource' => '']), $this->oauthSite()));
    }

    public function testADifferentSiteAddressIsReported(): void
    {
        $this->assertSame(['OAUTH_METADATA_MISMATCH', 'ALL_OK'], $this->codes($this->oauthProbes(['docIssuer' => 'https://www.example.com']), $this->oauthSite()));
        $this->assertSame(['OAUTH_METADATA_MISMATCH', 'ALL_OK'], $this->codes($this->oauthProbes([], ['docResource' => 'http://example.com/wp-json/ai-editor-divi5/v1/mcp']), $this->oauthSite()));
    }

    public function testAStrippedChallengeHeaderIsReported(): void
    {
        $this->assertSame(['OAUTH_NO_CHALLENGE', 'ALL_OK'], $this->codes($this->oauthProbes([], [], ['hasChallenge' => false]), $this->oauthSite()));
    }

    public function testAnApiKeyProblemStillWinsOverOauthWarnings(): void
    {
        $probes = $this->oauthProbes(['status' => 404, 'docIssuer' => '']);
        $probes['auth'] = array_merge($probes['auth'], ['status' => 401, 'jsonCode' => 'unauthorized', 'jsonRpc' => false]);
        $this->assertSame(['KEY_REJECTED'], $this->codes($probes, $this->oauthSite()));
    }

    public function testOauthProbesAreOptionalInSanitize(): void
    {
        $this->assertSame(['unauth', 'auth', 'tools'], array_keys(ConnectionDiagnosis::sanitizeProbes([])));
        $out = ConnectionDiagnosis::sanitizeProbes(['oauth_as' => ['status' => 200, 'docIssuer' => 'https://example.com'], 'oauth_prm' => 'garbage', 'oauth_other' => ['status' => 200]]);
        $this->assertSame(['unauth', 'auth', 'tools', 'oauth_as', 'oauth_prm'], array_keys($out));
        $this->assertSame('https://example.com', $out['oauth_as']['docIssuer']);
        $this->assertSame(0, $out['oauth_prm']['status']);
    }

    public function testTheDocumentFieldsAreCappedAndTyped(): void
    {
        $out = ConnectionDiagnosis::sanitizeProbes(['oauth_as' => ['docIssuer' => str_repeat('a', 5000), 'docResource' => ['x'], 'hasChallenge' => 'yes']]);
        $this->assertSame(300, strlen($out['oauth_as']['docIssuer']));
        $this->assertSame('', $out['oauth_as']['docResource']);
        $this->assertFalse($out['oauth_as']['hasChallenge']);
    }

    public function testTheSourceHasNoRemoteCalls(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../wp-plugin/src/ConnectionDiagnosis.php');
        $this->assertDoesNotMatchRegularExpression('/wp_remote_|curl_|file_get_contents|fsockopen/', $src);
    }
}
