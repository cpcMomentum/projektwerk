<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Db\MailOutbox;
use OCA\Projektwerk\Db\MailOutboxMapper;
use OCA\Projektwerk\Db\NotifyPref;
use OCA\Projektwerk\Db\NotifyPrefMapper;
use OCA\Projektwerk\Service\MailDispatcher;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Absender-Anzeigename und Betreff-Präfix (#284) und der Antwort-Token (#285) —
 * die reinen Entscheidungen.
 *
 * Der eigentliche Versand ({@see MailDispatcher::flush()}) hängt an Mailer,
 * Nutzerverwaltung und — über `Util::getDefaultEmailAddress()` — am laufenden
 * Server; er gehört in den Integrations-/Rauchtest. Prüfbar ohne all das sind
 * die gekapselten Entscheidungen: der Absendername samt Fallback, der
 * Betreff-Präfix, der Header-Injection-Schutz und die Token-Vergabe beim
 * Vormerken.
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

	// --- #284: Header-Injection-Schutz --------------------------------------

	public function testSenderNameStripsControlChars(): void {
		// Header-Injection-Schutz (PR #291): ein Zeilenumbruch im Projektnamen
		// darf nicht in die From-Kopfzeile durchschlagen.
		$this->assertSame(
			'ProjektWerk – Relaunch Website',
			$this->call('absenderName', "Relaunch\r\nWebsite"),
		);
	}

	public function testSubjectPrefixStripsControlChars(): void {
		$this->assertSame(
			'[Relaunch Website] Neuer Kommentar zu Vorgang #0007',
			$this->call('betreffMitProjekt', 'Neuer Kommentar zu Vorgang #0007', "Relaunch\nWebsite"),
		);
	}

	public function testAbsenderNameFallsBackWhenProjectIsOnlyControlChars(): void {
		// Ein Board-Titel wird nur mit trim() auf Nicht-Leerheit geprueft, das
		// erfasst nicht jedes Steuerzeichen (z. B. \x01). Bleibt nach dem
		// Glaetten nichts uebrig, zaehlt das wie „kein Projekt".
		$this->assertSame('ProjektWerk', $this->call('absenderName', "\x01\x01\x01"));
	}

	public function testSubjectUnchangedWhenProjectIsOnlyControlChars(): void {
		$this->assertSame(
			'Neuer Kommentar zu Vorgang #0007',
			$this->call('betreffMitProjekt', 'Neuer Kommentar zu Vorgang #0007', "\x01\x01\x01"),
		);
	}

	// --- #285: Antwort-Token beim Vormerken ---------------------------------

	public function testReplyTokenIsThirtyTwoHexChars(): void {
		$token = (string)(new ReflectionMethod(MailDispatcher::class, 'neuerReplyToken'))->invoke(null);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
	}

	public function testQueueAssignsReplyTokenToNewRow(): void {
		$inserted = null;
		$dispatcher = $this->dispatcher(
			throttled: false,
			capture: function (MailOutbox $row) use (&$inserted): void {
				$inserted = $row;
			},
		);

		// Zuweisung ist nie gedrosselt → der reguläre pending-Pfad.
		$dispatcher->queue('carla', 42, MailOutbox::EVENT_TICKET_ASSIGNED, 7, 'anna');

		$this->assertInstanceOf(MailOutbox::class, $inserted);
		$this->assertSame(MailOutbox::STATUS_PENDING, $inserted->getStatus());
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string)$inserted->getReplyToken());
	}

	public function testSuppressedRowAlsoGetsReplyToken(): void {
		$inserted = null;
		$dispatcher = $this->dispatcher(
			throttled: true,
			capture: function (MailOutbox $row) use (&$inserted): void {
				$inserted = $row;
			},
		);

		// Kommentar innerhalb des Fensters → unterdrückte Zeile.
		$result = $dispatcher->queue('carla', 42, MailOutbox::EVENT_COMMENT_ADDED, 7, 'anna');

		$this->assertNull($result, 'Eine unterdrückte Mail gibt null zurück.');
		$this->assertInstanceOf(MailOutbox::class, $inserted);
		$this->assertSame(MailOutbox::STATUS_SUPPRESSED, $inserted->getStatus());
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string)$inserted->getReplyToken());
	}

	/**
	 * Ein MailDispatcher, dessen Outbox jede eingefügte Zeile an `$capture`
	 * durchreicht. `$throttled` steuert, ob eine Kommentar-Mail als „im Fenster"
	 * gilt (existsSince), der Mailkanal ist immer an.
	 *
	 * @param callable(MailOutbox): void $capture
	 */
	private function dispatcher(bool $throttled, callable $capture): MailDispatcher {
		$outbox = $this->createMock(MailOutboxMapper::class);
		$outbox->method('existsSince')->willReturn($throttled);
		$outbox->method('insert')->willReturnCallback(static function (MailOutbox $row) use ($capture): MailOutbox {
			$capture($row);

			return $row;
		});

		$prefs = $this->createMock(NotifyPrefMapper::class);
		$prefs->method('isEnabled')->willReturnCallback(
			static fn (string $uid, string $channel): bool => $channel === NotifyPref::CHANNEL_MAIL,
		);

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('findGenericLanguage')->willReturn('en');

		return new MailDispatcher(
			$outbox,
			$prefs,
			$this->createMock(IMailer::class),
			$this->createMock(IUserManager::class),
			$l10nFactory,
			$this->createMock(LoggerInterface::class),
		);
	}
}
