<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Service\MemberLifecycleService;
use OCP\Server;

/**
 * Was zurueckbleibt, wenn eine Person geht.
 *
 * §29: Beim Loeschen eines Kontos werden dessen private Vorgaenge entfernt und
 * offene Zuweisungen aufgehoben — „sonst blieben unsichtbare Tickets und ein
 * ewiges ‚wartet auf Kunde' stehen, die sich **mangels Admin-Ausnahme nicht
 * aufraeumen liessen**".
 *
 * Dieser letzte Halbsatz ist der Grund fuer den Test: In jeder anderen App
 * koennte ein Administrator hinterherraeumen. Hier waere ein uebersehener
 * privater Vorgang fuer immer da.
 */
class MemberLifecycleTest extends IntegrationTestCase {

	private LeakMatrixFixture $fixture;
	private MemberLifecycleService $lifecycle;

	protected function setUp(): void {
		parent::setUp();

		$this->fixture = new LeakMatrixFixture();
		$this->lifecycle = Server::get(MemberLifecycleService::class);
	}

	/**
	 * **Der private Vorgang verschwindet, der oeffentliche bleibt.**
	 *
	 * Das ist der Unterschied zwischen Aufraeumen und Arbeit vernichten: Ein
	 * oeffentlicher Vorgang gehoert dem Projekt, nicht der Person.
	 */
	public function testOnlyThePrivateTicketsOfThatPersonAreRemoved(): void {
		$tickets = Server::get(TicketMapper::class);
		$anna = $this->fixture->contextFor(LeakMatrixFixture::ANNA);

		$privat = $this->fixture->ticketIds['private/anna'];
		$oeffentlich = $this->fixture->ticketIds['public/anna'];

		$this->assertSame(
			TicketScope::VISIBILITY_PRIVATE,
			$tickets->findVisible($anna, $privat)->getVisibility(),
			'Der Aufbau stimmt nicht — ohne privaten Vorgang misst der Test nichts.',
		);

		$this->lifecycle->forget(LeakMatrixFixture::ANNA);

		// **Direkt in der Tabelle nachsehen, nicht ueber einen Betrachter.**
		//
		// Der erste Anlauf dieses Tests fragte `findVisibleInBoard` fuer Bert —
		// und war gruen, auch als das Loeschen ausgebaut war. Kein Wunder:
		// Annas privater Vorgang war fuer Bert nie sichtbar, `assertNotContains`
		// traf also immer zu. Ein Test, der die Mutation ueberlebt, prueft
		// nichts.
		//
		// Ueber einen Betrachter geht es hier grundsaetzlich nicht: Die einzige
		// Person, die den Vorgang je sehen konnte, ist geloescht. Genau das ist
		// ja der Grund, warum aufgeraeumt werden muss.
		$this->assertSame(0, $this->zeilenMitId('pwerk_tickets', $privat), 'Der private Vorgang muss weg sein.');
		$this->assertSame(
			1,
			$this->zeilenMitId('pwerk_tickets', $oeffentlich),
			'Der oeffentliche gehoert dem Projekt und bleibt.',
		);

		// Und seine Kinder gehen mit — sonst blieben Zeilen ohne Vorgang.
		$this->assertSame(0, $this->kinderVon('pwerk_steps', $privat));
		$this->assertSame(0, $this->kinderVon('pwerk_comments', $privat));
	}

	/**
	 * @param string $tabelle Tabellenname ohne Praefix.
	 * @param int $id Primaerschluessel.
	 */
	private function zeilenMitId(string $tabelle, int $id): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($tabelle)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * @param string $tabelle Tabellenname ohne Praefix.
	 * @param int $ticketId Kennung des Vorgangs.
	 */
	private function kinderVon(string $tabelle, int $ticketId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($tabelle)
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId)));

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * **Offene Zuweisungen werden geloest** — sonst wartet das Board ewig.
	 *
	 * Der Wartezustand wird aus offenen Schritten gerechnet; ein Schritt, der
	 * einer geloeschten Person gehoert, wird nie erledigt.
	 */
	public function testOpenAssignmentsAreReleased(): void {
		$this->lifecycle->forget(LeakMatrixFixture::ANNA);

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'anzahl'))
			->from('pwerk_steps')
			->where($qb->expr()->eq('assigned_user_id', $qb->createNamedParameter(LeakMatrixFixture::ANNA)));

		$this->assertSame(
			0,
			(int)$qb->executeQuery()->fetchOne(),
			'Kein Schritt darf noch der geloeschten Person gehoeren.',
		);

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'anzahl'))
			->from('pwerk_tickets')
			->where($qb->expr()->eq('responsible_user_id', $qb->createNamedParameter(LeakMatrixFixture::ANNA)));

		$this->assertSame(0, (int)$qb->executeQuery()->fetchOne());
	}

	/**
	 * Die Mitgliedschaft geht mit — und mit ihr der Zugang.
	 */
	public function testTheMembershipIsGoneToo(): void {
		$this->lifecycle->forget(LeakMatrixFixture::ANNA);

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'anzahl'))
			->from('pwerk_members')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(LeakMatrixFixture::ANNA)));

		$this->assertSame(0, (int)$qb->executeQuery()->fetchOne());
	}

	/**
	 * **Das board-begrenzte Entfernen (§5.29, manuell) räumt dieselben Spuren
	 * auf wie das Kontolöschen** — nur eben auf genau diesem Projekt.
	 *
	 * Geprüft an demselben Aufbau: Annas privater Vorgang verschwindet, ihr
	 * öffentlicher bleibt dem Projekt, ihre Zuweisungen sind gelöst, ihre
	 * Mitgliedschaft ist weg.
	 */
	public function testRemoveFromBoardClearsThePersonOnThatBoard(): void {
		$privat = $this->fixture->ticketIds['private/anna'];
		$oeffentlich = $this->fixture->ticketIds['public/anna'];

		$this->lifecycle->removeFromBoard(LeakMatrixFixture::ANNA, $this->fixture->boardId);

		$this->assertSame(0, $this->zeilenMitId('pwerk_tickets', $privat), 'Der private Vorgang muss weg sein.');
		$this->assertSame(1, $this->zeilenMitId('pwerk_tickets', $oeffentlich), 'Der öffentliche gehört dem Projekt und bleibt.');
		$this->assertSame(0, $this->kinderVon('pwerk_steps', $privat));
		$this->assertSame(0, $this->kinderVon('pwerk_comments', $privat));

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'anzahl'))
			->from('pwerk_members')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(LeakMatrixFixture::ANNA)))
			->andWhere($qb->expr()->eq('board_id', $qb->createNamedParameter($this->fixture->boardId)));
		$this->assertSame(0, (int)$qb->executeQuery()->fetchOne(), 'Die Mitgliedschaft auf diesem Projekt muss weg sein.');

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'anzahl'))
			->from('pwerk_tickets')
			->where($qb->expr()->eq('responsible_user_id', $qb->createNamedParameter(LeakMatrixFixture::ANNA)))
			->andWhere($qb->expr()->eq('board_id', $qb->createNamedParameter($this->fixture->boardId)));
		$this->assertSame(0, (int)$qb->executeQuery()->fetchOne(), 'Keine Zuständigkeit dieser Person darf bleiben.');
	}

	/**
	 * Die bezifferte Vorschau zählt nur die privaten Vorgänge **dieser** Person
	 * auf **diesem** Projekt — je Person und board-begrenzt.
	 *
	 * Anna hat auf dem anderen Projekt keinen privaten Vorgang: Dort zählt für
	 * sie null, obwohl sie auf dem ersten einen hat. Das ist die Gegenprobe auf
	 * den `board_id`-Filter.
	 */
	public function testCountPrivateOnBoardIsScopedToTheBoard(): void {
		$this->assertSame(
			1,
			$this->lifecycle->countPrivateOnBoard(LeakMatrixFixture::ANNA, $this->fixture->boardId),
			'Anna hat genau einen privaten Vorgang auf diesem Projekt.',
		);

		$this->assertSame(
			0,
			$this->lifecycle->countPrivateOnBoard(LeakMatrixFixture::ANNA, $this->fixture->otherBoardId),
			'Auf dem anderen Projekt hat Anna keinen privaten Vorgang.',
		);

		// Bert hat seinen eigenen privaten Vorgang auf diesem Projekt — die
		// Zählung ist je Person, Annas Zahl schließt ihn nicht ein und umgekehrt.
		$this->assertSame(
			1,
			$this->lifecycle->countPrivateOnBoard(LeakMatrixFixture::BERT, $this->fixture->boardId),
			'Bert hat seinen eigenen privaten Vorgang.',
		);
	}

	/**
	 * **Board-begrenzt heißt board-begrenzt.** Bert aus dem ersten Projekt zu
	 * entfernen lässt seine Spuren im zweiten unangetastet — Mitgliedschaft und
	 * Vorgänge dort bleiben.
	 *
	 * Ohne den `board_id`-Filter wäre `removeFromBoard` still ein `forget`: Es
	 * risse einer Person überall den Boden weg, obwohl sie nur aus einem Projekt
	 * gehen soll.
	 */
	public function testRemoveFromBoardDoesNotTouchOtherBoards(): void {
		$privatHier = $this->fixture->ticketIds['private/bert'];
		$vorgangDort = $this->fixture->ticketIds['b:internal/bert'];

		$this->lifecycle->removeFromBoard(LeakMatrixFixture::BERT, $this->fixture->boardId);

		// Hier weg …
		$this->assertSame(0, $this->zeilenMitId('pwerk_tickets', $privatHier), 'Berts privater Vorgang hier muss weg sein.');

		// … dort unangetastet.
		$this->assertSame(1, $this->zeilenMitId('pwerk_tickets', $vorgangDort), 'Berts Vorgang im anderen Projekt bleibt.');

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'anzahl'))
			->from('pwerk_members')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(LeakMatrixFixture::BERT)))
			->andWhere($qb->expr()->eq('board_id', $qb->createNamedParameter($this->fixture->otherBoardId)));
		$this->assertSame(1, (int)$qb->executeQuery()->fetchOne(), 'Berts Mitgliedschaft im anderen Projekt bleibt.');

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'anzahl'))
			->from('pwerk_members')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(LeakMatrixFixture::BERT)))
			->andWhere($qb->expr()->eq('board_id', $qb->createNamedParameter($this->fixture->boardId)));
		$this->assertSame(0, (int)$qb->executeQuery()->fetchOne(), 'Hier ist Bert kein Mitglied mehr.');
	}
}
