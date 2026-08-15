<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Der Lesestand je Person und Vorgang (#79).
 *
 * **Warum eine eigene Tabelle, nicht ein Feld an `pwerk_ticket_users`.** Der
 * Lesestand ist je **Betrachter** und je Vorgang — nicht jeder, der einen
 * Vorgang sieht, ist dort Mitarbeiter. Ein Zeitstempel an der Zuarbeit-Zeile
 * träfe die falsche Menge.
 *
 * **Was das trägt.** „Hat sich etwas getan, seit *du* zuletzt draufgeschaut
 * hast" — der Blick, den man morgens aufs Board wirft. Der frühere blaue Punkt
 * hing an `last_editor_user_id` und ging nie wieder aus; dieser Stand ist je
 * Person und verschwindet, sobald sie den Vorgang geöffnet hat.
 *
 * Kein `notnull`-`default` auf `seen_at`: Jede Zeile wird beim Anlegen gesetzt,
 * es gibt keinen Zwischenzustand „gelesen, aber wann unbekannt".
 *
 * Indexname mit Präfix und ≤ 30 Zeichen (PostgreSQL: schema-global).
 */
class Version000007Date20260815000000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Lesestand je Person und Vorgang';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_reads so a card can show "changed since you last looked" per user (#79).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('pwerk_reads')) {
			return $schema;
		}

		$table = $schema->createTable('pwerk_reads');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('ticket_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('seen_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		// Je Person und Vorgang genau eine Zeile — der Speicherpunkt, den das
		// „gelesen"-Setzen aktualisiert statt zu vervielfachen.
		$table->addUniqueIndex(['user_id', 'ticket_id'], 'pwerk_reads_ut_uidx');
		// Der Lesepfad fragt „meine Stände zu diesen Vorgängen": nach user_id
		// gefiltert, ticket_id in einer Menge.
		$table->addIndex(['user_id'], 'pwerk_reads_u_idx');

		return $schema;
	}
}
