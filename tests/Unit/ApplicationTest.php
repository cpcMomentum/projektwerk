<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit;

use OCA\Projektwerk\AppInfo\Application;
use PHPUnit\Framework\TestCase;

/**
 * Container-freie Zusicherungen: laufen auch in der CI gegen die
 * nextcloud/ocp-Stubs, ohne laufende Nextcloud. Damit ist die Suite nie
 * vollstaendig uebersprungen — ein komplett geskippter Lauf wuerde ein
 * kaputtes Test-Setup als "gruen" durchgehen lassen.
 */
class ApplicationTest extends TestCase {

	public function testAppIdMatchesInfoXml(): void {
		$info = simplexml_load_file(__DIR__ . '/../../appinfo/info.xml');

		$this->assertNotFalse($info, 'appinfo/info.xml ist nicht lesbar');
		$this->assertSame(Application::APP_ID, (string)$info->id);
	}

	public function testAppIdIsProjektwerk(): void {
		$this->assertSame('projektwerk', Application::APP_ID);
	}

	/**
	 * Nextcloud begrenzt Tabellennamen mit Auto-Increment-Schluessel praktisch
	 * auf 22 Zeichen (ohne oc_-Praefix). Deshalb `pwerk_` statt `projektwerk_`.
	 * Der Test haelt die Entscheidung fest, bevor die erste Migration sie
	 * zementiert — `projektwerk_ticket_steps` waere bereits zu lang gewesen
	 * und erst auf einer fremden Installation gekracht.
	 */
	public function testTablePrefixLeavesRoomForLongestPlannedTable(): void {
		$prefix = 'pwerk_';
		$longestSuffix = 'ticket_users';

		$this->assertLessThanOrEqual(22, strlen($prefix . $longestSuffix));
	}
}
