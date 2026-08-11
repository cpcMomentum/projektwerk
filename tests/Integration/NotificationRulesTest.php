<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Db\MailOutbox;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Service\NotificationService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use OCP\Server;
use Psr\Log\NullLogger;

/**
 * Wer wovon erfaehrt — und wer ausdruecklich nicht.
 *
 * Die drei Regeln aus §5.21, jede einzeln geprueft. **Die erste ist ein
 * Akzeptanzkriterium aus #10** und der Grund, warum dieser Test existiert: Zu
 * einem `private`-Vorgang darf nachweislich nichts versendet werden.
 *
 * Der Benachrichtigungsmanager ist ein Doppel — was hier geprueft wird, ist die
 * Entscheidung darueber, ob ueberhaupt etwas entsteht, nicht Nextclouds
 * Zustellung.
 */
class NotificationRulesTest extends IntegrationTestCase {

	/**
	 * @param int $erwarteteGlocken Wie viele Glockeneintraege entstehen duerfen.
	 */
	private function service(int $erwarteteGlocken): NotificationService {
		$eintrag = $this->createStub(INotification::class);
		// Die fluente Kette muss sich selbst zurueckgeben, sonst laeuft der
		// Aufbau des Eintrags gegen null.
		$eintrag->method('setApp')->willReturnSelf();
		$eintrag->method('setUser')->willReturnSelf();
		$eintrag->method('setDateTime')->willReturnSelf();
		$eintrag->method('setObject')->willReturnSelf();
		$eintrag->method('setSubject')->willReturnSelf();

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($eintrag);
		$manager->expects($this->exactly($erwarteteGlocken))->method('notify');

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$factory = $this->createStub(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$factory->method('getUserLanguage')->willReturn('de');
		$factory->method('findGenericLanguage')->willReturn('en');

		$urls = $this->createStub(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('http://example.invalid/t/1');
		$urls->method('getAbsoluteURL')->willReturn('http://example.invalid/t/1');

		return new NotificationService(
			Server::get(\OCA\Projektwerk\Service\MailDispatcher::class),
			Server::get(\OCA\Projektwerk\Db\NotifyPrefMapper::class),
			$manager,
			$factory,
			$urls,
			new NullLogger(),
		);
	}

	/**
	 * @param string $visibility Sichtbarkeit des Vorgangs.
	 */
	private function ticket(string $visibility): Ticket {
		$ticket = new Ticket();
		$ticket->setId($this->fixtureTicketId());
		$ticket->setNumber(42);
		$ticket->setTitle('Ein Vorgang');
		$ticket->setVisibility($visibility);

		return $ticket;
	}

	private function fixtureTicketId(): int {
		return (new LeakMatrixFixture())->ticketIds['public/anna'];
	}

	/**
	 * **Akzeptanzkriterium aus #10: zu `private` wird nachweislich nichts
	 * versendet.**
	 *
	 * Ein privater Vorgang ist fuer genau eine Person sichtbar, und die loest
	 * ihn selbst aus. Es gibt keinen gueltigen Empfaenger — weder fuer die
	 * Glocke noch fuer die Mail.
	 */
	public function testNothingIsSentAboutAPrivateTicket(): void {
		$service = $this->service(0);

		$vorgemerkt = $service->announce(
			$this->ticket(TicketScope::VISIBILITY_PRIVATE),
			LeakMatrixFixture::BERT,
			LeakMatrixFixture::ANNA,
			MailOutbox::EVENT_TICKET_ASSIGNED,
		);

		$this->assertSame([], $vorgemerkt, 'Zu einem privaten Vorgang darf keine Zeile entstehen.');
	}

	/**
	 * Niemand wird ueber die eigene Handlung benachrichtigt.
	 */
	public function testNobodyIsNotifiedAboutTheirOwnAction(): void {
		$service = $this->service(0);

		$vorgemerkt = $service->announce(
			$this->ticket(TicketScope::VISIBILITY_PUBLIC),
			LeakMatrixFixture::ANNA,
			LeakMatrixFixture::ANNA,
			MailOutbox::EVENT_TICKET_ASSIGNED,
		);

		$this->assertSame([], $vorgemerkt);
	}

	/**
	 * Der Normalfall: Glocke **und** Mailzeile entstehen.
	 */
	public function testAnAssignmentProducesBothChannels(): void {
		$service = $this->service(1);

		$vorgemerkt = $service->announce(
			$this->ticket(TicketScope::VISIBILITY_PUBLIC),
			LeakMatrixFixture::BERT,
			LeakMatrixFixture::ANNA,
			MailOutbox::EVENT_TICKET_ASSIGNED,
		);

		$this->assertCount(1, $vorgemerkt);
		$this->assertSame(MailOutbox::STATUS_PENDING, $vorgemerkt[0]->getStatus());
		$this->assertSame(LeakMatrixFixture::BERT, $vorgemerkt[0]->getRecipientUid());
	}
}
