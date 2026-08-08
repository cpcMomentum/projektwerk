<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * PHPUnit bootstrap for ProjektWerk.
 *
 * Expected to run against a Nextcloud installation, which loads NC's runtime via
 * lib/base.php and makes OCP\* available. Our own classes come from the app's
 * composer autoloader.
 *
 * NEXTCLOUD_ROOT points at that installation. Default /var/www/html is the dev
 * container; the CI integration job checks out nextcloud/server elsewhere and
 * sets the variable. Hard-coding the path would have meant a second bootstrap
 * that drifts from this one.
 *
 * A missing base.php is NOT fatal here: without it OCP\* is absent, and
 * IntegrationTestCase turns that into either a skip (local) or a failure with a
 * useful message (CI, PWERK_REQUIRE_DB=1). Dying in the bootstrap would produce
 * a PHPUnit error that says nothing about what to do.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$ncRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
$base = rtrim($ncRoot, '/') . '/lib/base.php';

if (is_file($base)) {
	require_once $base;
}
