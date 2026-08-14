<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Die Rolle des Verantwortlichen ans Ticket, eingefroren wie `assigned_role`
 * am Schritt (#114).
 *
 * **Warum.** „Wartet auf Kunde" entsteht heute allein aus offenen Schritten mit
 * `assigned_role = 'external'`. Ein Vorgang ganz ohne Schritte kann diese
 * Bedingung nie erfuellen, auch dann nicht, wenn er seit Wochen bei der
 * Kundenseite liegt. Seit die Zustaendigkeit setzbar ist (#99) ist genau diese
 * Lage herstellbar und erreichbar. `WaitStateCalculator` braucht deshalb eine
 * zweite Quelle: die Rolle des Verantwortlichen.
 *
 * **Warum eingefroren, nicht zur Laufzeit.** Dieselbe Begruendung wie bei
 * `assigned_role` am Schritt und `creator_role` am Ticket: Wuerde die Rolle zur
 * Laufzeit aus `pwerk_members` ermittelt, kippte der Wartezustand rueckwirkend,
 * sobald jemand die Rolle wechselt oder das Board verlaesst, an Vorgaengen, die
 * seit Wochen unveraendert sind.
 *
 * **`responsible_since`** traegt die Wartezeit, wie `assigned_at` am Schritt.
 * Sie wird beim Eintragen des Verantwortlichen gesetzt und beim Entfernen
 * geleert.
 *
 * **Warum jetzt.** Die App ist unveroeffentlicht (kein Tag, kein Tarball). Nach
 * dem ersten Release waere dieselbe Spalte eine Migration auf fremden
 * Installationen. `responsible_role` und `due_date` (#72) haengen beide an
 * `pwerk_tickets`; sie liegen bewusst in **zwei** Migrationen, damit je Issue
 * eine steht und die Hausregel „released Migrationen nie editieren" nicht am
 * Sonderfall aufweicht.
 */
class Version000005Date20260814000000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	#[\Override]
	public function name(): string {
		return 'Rolle und Zeitpunkt des Verantwortlichen am Ticket';
	}

	#[\Override]
	public function description(): string {
		return 'Add tickets.responsible_role and responsible_since so a ticket without steps can still wait on the customer (#114).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_tickets')) {
			return null;
		}

		$table = $schema->getTable('pwerk_tickets');

		// Laenge 16 wie `creator_role` und `assigned_role`. Die Werte sind
		// 'internal'/'external', nullbar wie am Schritt: kein Verantwortlicher,
		// keine Rolle.
		if (!$table->hasColumn('responsible_role')) {
			$table->addColumn('responsible_role', Types::STRING, ['notnull' => false, 'length' => 16]);
		}

		if (!$table->hasColumn('responsible_since')) {
			$table->addColumn('responsible_since', Types::DATETIME, ['notnull' => false]);
		}

		return $schema;
	}

	/**
	 * Bestandszeilen: die Rolle aus `pwerk_members` nachziehen.
	 *
	 * Sie ist dort zum Migrationszeitpunkt korrekt. Das ist derselbe Wert, den
	 * das kuenftige Setzen einfriert, nur nachtraeglich fuer die Vorgaenge, die
	 * schon einen Verantwortlichen tragen.
	 *
	 * **`responsible_since` bleibt fuer Bestandszeilen leer.** Wann die
	 * Zustaendigkeit gesetzt wurde, ist nicht rekonstruierbar; ein erfundenes
	 * Datum waere schlechter als keins. Die Marke steht dann ohne Datum da, wie
	 * ein Schritt mit fehlendem `assigned_at`. Das eigentliche Dringlichkeits-
	 * signal ist ohnehin die gerissene Faelligkeit (#72), nicht das Wartealter.
	 *
	 * @param IOutput $output Fortschrittsausgabe der Migration.
	 * @param Closure $schemaClosure Liefert den Schema-Wrapper.
	 * @param array<string, mixed> $options Optionen des Migrationslaufs.
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_tickets') || !$schema->hasTable('pwerk_members')) {
			return;
		}

		// Nur Zeilen mit Verantwortlichem und noch leerer Rolle: Ein zweiter Lauf
		// ueberschreibt nichts, was inzwischen ueber die Oberflaeche gesetzt wurde.
		$select = $this->connection->getQueryBuilder();
		$select->select('t.id', 't.board_id', 't.responsible_user_id')
			->from('pwerk_tickets', 't')
			->where($select->expr()->isNotNull('t.responsible_user_id'))
			->andWhere($select->expr()->neq('t.responsible_user_id', $select->createNamedParameter('')))
			->andWhere($select->expr()->isNull('t.responsible_role'));

		$result = $select->executeQuery();
		$gezogen = 0;
		while ($row = $result->fetch()) {
			$rolle = $this->roleOf((int)$row['board_id'], (string)$row['responsible_user_id']);
			if ($rolle === null) {
				// Der Verantwortliche ist kein Mitglied mehr. Ohne Rolle keine
				// Marke, und das ist ehrlich: Der Wartebegriff braucht die Rolle.
				continue;
			}

			$update = $this->connection->getQueryBuilder();
			$update->update('pwerk_tickets')
				->set('responsible_role', $update->createNamedParameter($rolle))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
			$gezogen += $update->executeStatement();
		}
		$result->closeCursor();

		$output->info('tickets: ' . $gezogen . ' Verantwortlichen-Rollen aus pwerk_members nachgezogen');
	}

	/**
	 * Die Rolle einer Person auf einem Board, oder `null`, wenn kein Mitglied.
	 */
	private function roleOf(int $boardId, string $userId): ?string {
		$qb = $this->connection->getQueryBuilder();
		$qb->select('role')
			->from('pwerk_members')
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$rolle = $result->fetchOne();
		$result->closeCursor();

		return $rolle === false ? null : (string)$rolle;
	}
}
