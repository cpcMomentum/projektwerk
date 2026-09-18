<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Migration\Version000021Date20260918000000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Migration\IOutput;

/**
 * Der additive Backfill aus #309 Phase 1: Firma je Mitglied, Kunde je Projekt,
 * Kunden-Kopie am Board — alle Werte aus dem Projekt (Autorität).
 *
 * Prüft die **Anzeige-Parität**: `members.company` trägt danach genau den Wert,
 * den die heutige rollenabgeleitete Anzeige zeigt (`external → org_external`,
 * sonst `org_internal`), und `projects.customer`/`boards.customer` den Kunden.
 */
class CompanyBackfillTest extends IntegrationTestCase {

	public function testBackfillSetztFirmaProPersonUndKunde(): void {
		[$projectId, $boardId] = $this->projektMitBoard('cpcMomentum', 'Kunde MI');
		$internId = $this->mitgliedEinfuegen($boardId, $projectId, 'lm-anna', 'internal');
		$externId = $this->mitgliedEinfuegen($boardId, $projectId, 'lm-timo', 'external');

		$this->backfillAusloesen();

		$this->assertSame('Kunde MI', $this->zeile('pwerk_projects', $projectId)['customer'],
			'Der Kunde des Projekts ist die externe Firma.');
		$this->assertSame('Kunde MI', $this->zeile('pwerk_boards', $boardId)['customer'],
			'Die Board-Anzeige-Kopie folgt dem Projekt-Kunden.');
		$this->assertSame('cpcMomentum', $this->zeile('pwerk_members', $internId)['company'],
			'Ein internes Mitglied trägt die eigene Firma.');
		$this->assertSame('Kunde MI', $this->zeile('pwerk_members', $externId)['company'],
			'Ein externes Mitglied trägt die Kundenfirma.');
	}

	public function testMitgliedOhneProjectIdUeberDasBoardHergeleitet(): void {
		[$projectId, $boardId] = $this->projektMitBoard('cpcMomentum', 'Kunde MI');
		// project_id am Mitglied bewusst null — der Backfill muss das Projekt
		// über board_id → board.project_id finden.
		$externId = $this->mitgliedEinfuegen($boardId, null, 'lm-timo', 'external');

		$this->backfillAusloesen();

		$this->assertSame('Kunde MI', $this->zeile('pwerk_members', $externId)['company']);
	}

	public function testBereitsGesetzteFirmaBleibtUnberuehrt(): void {
		[$projectId, $boardId] = $this->projektMitBoard('cpcMomentum', 'Kunde MI');
		// Timo kommt von einer dritten Firma (Nect) — der Sonderfall, den der
		// Backfill nicht plattbügeln darf.
		$externId = $this->mitgliedEinfuegen($boardId, $projectId, 'lm-timo', 'external', 'Nect');

		$this->backfillAusloesen();

		$this->assertSame('Nect', $this->zeile('pwerk_members', $externId)['company'],
			'Eine bereits gesetzte Firma darf der Backfill nicht überschreiben.');
	}

	public function testBackfillIstIdempotent(): void {
		[$projectId, $boardId] = $this->projektMitBoard('cpcMomentum', 'Kunde MI');
		$externId = $this->mitgliedEinfuegen($boardId, $projectId, 'lm-timo', 'external');

		$this->backfillAusloesen();
		$this->backfillAusloesen();

		$this->assertSame('Kunde MI', $this->zeile('pwerk_members', $externId)['company'],
			'Ein zweiter Lauf ändert nichts.');
	}

	private function backfillAusloesen(): void {
		$migration = new Version000021Date20260918000000($this->db);
		$migration->postSchemaChange($this->nullAusgabe(), $this->schemaVorhanden(), []);
	}

	/**
	 * Ein Projekt (mit Firmen) und ein damit verknüpftes Board anlegen.
	 *
	 * @param string $orgIntern Eigene Firma.
	 * @param string $orgExtern Kundenfirma.
	 * @return array{0: int, 1: int} [projectId, boardId]
	 */
	private function projektMitBoard(string $orgIntern, string $orgExtern): array {
		$now = (new \DateTime())->format('Y-m-d H:i:s');

		$board = new Board();
		$board->setTitle('Firmenmodell-' . uniqid('', true));
		$board->setOwnerUserId('lm-anna');
		$board->setOrgInternal($orgIntern);
		$board->setOrgExternal($orgExtern);
		$projectId = $this->projektFuerBoard($board);

		$qb = $this->db->getQueryBuilder();
		$qb->insert('pwerk_boards')->values([
			'title' => $qb->createNamedParameter($board->getTitle()),
			'owner_user_id' => $qb->createNamedParameter('lm-anna'),
			'org_internal' => $qb->createNamedParameter($orgIntern),
			'org_external' => $qb->createNamedParameter($orgExtern),
			'project_id' => $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT),
			'ticket_counter' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($now),
			'updated_at' => $qb->createNamedParameter($now),
		]);
		$qb->executeStatement();

		return [$projectId, $qb->getLastInsertId()];
	}

	/**
	 * @param int $boardId Board der Mitgliedschaft.
	 * @param int|null $projectId Projekt der Mitgliedschaft (null testet den Board-Weg).
	 * @param string $userId Konto-Kennung.
	 * @param string $role `internal` oder `external`.
	 * @param string|null $company Vorbelegte Firma (null = Backfill soll setzen).
	 */
	private function mitgliedEinfuegen(int $boardId, ?int $projectId, string $userId, string $role, ?string $company = null): int {
		$now = (new \DateTime())->format('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('pwerk_members')->values([
			'board_id' => $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT),
			'project_id' => $projectId === null
				? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
				: $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT),
			'user_id' => $qb->createNamedParameter($userId),
			'role' => $qb->createNamedParameter($role),
			'is_manager' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'company' => $company === null
				? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
				: $qb->createNamedParameter($company),
			'added_by' => $qb->createNamedParameter('lm-anna'),
			'added_at' => $qb->createNamedParameter($now),
		]);
		$qb->executeStatement();

		return $qb->getLastInsertId();
	}

	/**
	 * @param string $tabelle Tabellenname.
	 * @param int $id Primärschlüssel.
	 * @return array<string, mixed>
	 */
	private function zeile(string $tabelle, int $id): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($tabelle)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$zeile = $qb->executeQuery()->fetch();

		return $zeile === false ? [] : $zeile;
	}

	private function nullAusgabe(): IOutput {
		return $this->createMock(IOutput::class);
	}

	/**
	 * Schema-Closure, dessen Prüfungen (Tabelle/Spalte vorhanden) alle zutreffen
	 * — die echten Tabellen existieren nach dem Setup ohnehin.
	 */
	private function schemaVorhanden(): \Closure {
		$tabelle = $this->createMock(\Doctrine\DBAL\Schema\Table::class);
		$tabelle->method('hasColumn')->willReturn(true);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($tabelle);

		return static fn (): ISchemaWrapper => $schema;
	}
}
