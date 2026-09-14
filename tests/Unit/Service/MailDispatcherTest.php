<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Service\MailDispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Absender-Anzeigename und Betreff-Präfix (#284) — die reinen Entscheidungen.
 *
 * Der eigentliche Versand ({@see MailDispatcher::flush()}) hängt an Mailer,
 * Nutzerverwaltung und — über `Util::getDefaultEmailAddress()` — am laufenden
 * Server; er gehört in den Integrations-/Rauchtest. Prüfbar ohne all das ist
 * die eine Sache, um die es hier geht: dass mit Projektname „ProjektWerk –
 * {Projekt}" und `[{Projekt}]` entstehen und ohne Projektname sauber auf
 * „ProjektWerk" ohne Präfix zurückfällt. Beide Regeln sind bewusst als reine
 * statische Methoden gekapselt, damit genau dieser Fallback eine Maschine hütet.
 */
class MailDispatcherTest extends TestCase {

	private function call(string $method, mixed ...$args): string {
		$ref = new ReflectionMethod(MailDispatcher::class, $method);

		return (string)$ref->invoke(null, ...$args);
	}

	public function testAbsenderNameWithProject(): void {
		$this->assertSame('ProjektWerk – Relaunch Website', $this->call('absenderName', 'Relaunch Website'));
	}

	public function testAbsenderNameFallsBackWithoutProject(): void {
		$this->assertSame('ProjektWerk', $this->call('absenderName', null));
	}

	public function testSubjectGetsProjectPrefix(): void {
		$this->assertSame(
			'[Relaunch Website] Neuer Kommentar zu Vorgang #0007',
			$this->call('betreffMitProjekt', 'Neuer Kommentar zu Vorgang #0007', 'Relaunch Website'),
		);
	}

	public function testSubjectUnchangedWithoutProject(): void {
		$this->assertSame(
			'Neuer Kommentar zu Vorgang #0007',
			$this->call('betreffMitProjekt', 'Neuer Kommentar zu Vorgang #0007', null),
		);
	}
}
