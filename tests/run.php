<?php

define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-plan-version.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(Plan_Version::is_update_available('1.2.0', '1.3.0') === true, '1.3.0 powinno aktualizować 1.2.0');
$assert(Plan_Version::is_update_available('1.2.0', '1.2.0') === false, 'Ta sama wersja nie może powodować aktualizacji');
$assert(Plan_Version::is_update_available('2.0.0', '1.9.0') === false, 'Starszy release nie może powodować aktualizacji');
$assert(Plan_Version::normalize_tag('v2.0.0-rc1') === null, 'Prerelease nie może być stabilnym SemVer');
$assert(Plan_Version::normalize_tag('v2.0.0-rc1', true) === '2.0.0-rc1', 'Kanał beta powinien akceptować RC');
$assert(Plan_Version::normalize_tag('v2.0.0-beta2', true) === '2.0.0-beta2', 'Kanał beta powinien akceptować beta');

$releases = [
    ['tag_name' => 'v2.0.0-rc1', 'draft' => false, 'prerelease' => true, 'assets' => [['name' => 'plan-integration.zip']]],
    ['tag_name' => 'v1.3.0', 'draft' => false, 'prerelease' => false, 'assets' => [['name' => 'plan-integration.zip', 'browser_download_url' => 'https://example.test/1.3.0.zip']]],
    ['tag_name' => 'v1.4.0', 'draft' => true, 'prerelease' => false, 'assets' => [['name' => 'plan-integration.zip']]],
];

$stable = Plan_Version::latest_release($releases, 'plan-integration.zip', 'stable');
$assert(is_array($stable), 'Powinien zostać znaleziony stabilny release');
$assert(($stable['_normalized_version'] ?? null) === '1.3.0', 'Stable musi ignorować draft i prerelease');
$assert(is_array($stable['_asset'] ?? null), 'Wymagany asset powinien zostać znaleziony');

$beta = Plan_Version::latest_release($releases, 'plan-integration.zip', 'beta');
$assert(is_array($beta), 'Kanał beta powinien znaleźć prerelease');
$assert(($beta['_normalized_version'] ?? null) === '2.0.0-rc1', 'Kanał beta powinien wybrać najwyższą wersję włącznie z RC');

$missingAsset = Plan_Version::latest_stable_release([
    ['tag_name' => 'v1.5.0', 'draft' => false, 'prerelease' => false, 'assets' => [['name' => 'source.zip']]],
], 'plan-integration.zip');
$assert(is_array($missingAsset) && ($missingAsset['_asset'] ?? null) === null, 'Nie wolno używać losowego archiwum zamiast plan-integration.zip');

if ($failures !== []) {
    fwrite(STDERR, "FAILED\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "OK: wszystkie testy updatera przeszły.\n";
