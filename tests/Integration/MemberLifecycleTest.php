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
}
