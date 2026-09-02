<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * #246 PR 3 — Mitgliedschaft strukturell aufs Projekt heben.
 *
 * Seit PR 2 verbindet {@see \OCA\Projektwerk\Access\TicketScope} über
 * `m.project_id`. Damit ist die **Identität** einer Mitgliedschaft `(project_id,
 * user_id)`, nicht mehr `(board_id, user_id)`: Eine Person hat **genau eine**
 * Rolle je Projekt, die für alle Boards des Projekts gilt. Diese Migration macht
 * das zur Datenbank-Invariante, statt sie nur im Dienst zu versprechen —
 * dieselbe Linie wie „Sichtbarkeit ist EINE Bedingung an EINER Stelle": Eine
 * per-Board widersprüchliche Rolle wird **strukturell unmöglich**, nicht bloß
 * unwahrscheinlich.
 *
 * Drei Schritte, rein am Schema:
 *
 * 1. **Neu:** eindeutiger Index `(project_id, user_id)`. Er ist zugleich der
 *    Verbundschlüssel der Sichtbarkeitsregel (`m.project_id = t.project_id AND
 *    m.user_id = :uid`) und ersetzt darin den nicht-eindeutigen aus PR 2.
 * 2. **Weg:** der nicht-eindeutige `pwerk_members_project_idx` aus PR 2 — der
 *    eindeutige deckt denselben Verbund, zwei Indizes auf denselben Spalten
 *    wären Ballast.
 * 3. **Weg:** der alte eindeutige `(board_id, user_id)` — `board_id` bleibt als
 *    Heimat-Board erhalten, ist aber nicht länger Teil der Identität.
 *
 * **Kein Backfill, keine Dedup-Runde nötig.** Bis PR 5 hat jedes Projekt genau
 * **ein** Board; mit dem bisherigen `(board_id, user_id)`-Unique und dem 1:1
 * Board↔Projekt ist `(project_id, user_id)` bereits eindeutig. Der neue Index
 * greift also in Bestandsdaten ohne Konflikt.
 */
class Version000016Date20260902040000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'members identity to project (#246 PR 3)';
	}

	#[\Override]
	public function description(): string {
		return 'Make (project_id, user_id) the unique identity of a membership; drop the old (board_id, user_id) unique and the redundant non-unique project index (#246).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_members')) {
			return null;
		}

		$members = $schema->getTable('pwerk_members');

		if (!$members->hasIndex('pwerk_members_pu_uidx')) {
			$members->addUniqueIndex(['project_id', 'user_id'], 'pwerk_members_pu_uidx');
		}

		if ($members->hasIndex('pwerk_members_project_idx')) {
			$members->dropIndex('pwerk_members_project_idx');
		}

		if ($members->hasIndex('pwerk_members_bu_uidx')) {
			$members->dropIndex('pwerk_members_bu_uidx');
		}

		return $schema;
	}
}
