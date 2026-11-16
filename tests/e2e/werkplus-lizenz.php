<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Nur für die Wegwerf-Instanz der CI: ergänzt einen Prüfschlüssel in der ausgecheckten App und gibt ein dazu signiertes Token aus.

$appDir = $argv[1] ?? '';
$keysFile = $appDir . '/lib/License/EntitlementKeys.php';
$serviceFile = $appDir . '/lib/License/LicenseService.php';
if (!is_file($keysFile) || !is_file($serviceFile)) {
	fwrite(STDERR, "Aufruf: php werkplus-lizenz.php <App-Verzeichnis>\n");
	exit(1);
}

$uuid = '0b6f4c1e-7d2a-4f3b-9e8c-5a1d2b3c4d5e';
$kid = 'e2e-ci';

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$pem = openssl_pkey_get_details($key)['key'];

$source = file_get_contents($keysFile);
$end = strrpos($source, '];');
if ($end === false) {
	fwrite(STDERR, "PUBLIC_KEYS nicht gefunden\n");
	exit(1);
}
file_put_contents($keysFile, substr($source, 0, $end) . var_export($kid, true) . ' => ' . var_export($pem, true) . ",\n\t" . substr($source, $end));

preg_match('/const FLAGS = \[(.*?)\]/s', file_get_contents($serviceFile), $match);
preg_match_all("/'([a-z_]+)'/", $match[1] ?? '', $flags);
if ($flags[1] === []) {
	fwrite(STDERR, "FLAGS nicht gefunden\n");
	exit(1);
}

$b64 = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
$now = time();
$input = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid])) . '.' . $b64(json_encode([
	'iss' => 'https://api.werkwolke.de',
	'aud' => 'werkplus',
	'sub' => $uuid,
	'iat' => $now,
	'nbf' => $now - 60,
	'exp' => $now + 86400,
	'graceUntil' => $now + 2 * 86400,
	'tier' => 'werkplus',
	'features' => $flags[1],
]));
openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256);

echo $uuid . "\n" . $input . '.' . $b64($signature) . "\n";
