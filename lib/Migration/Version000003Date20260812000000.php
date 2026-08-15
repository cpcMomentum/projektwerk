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
 * Schritt 1 von 2: `pref_key` anlegen und `channel` hinueberkopieren.
 *
 * **Warum die Spalte umbenannt wird.** Sie traegt seit Phase 6 zweierlei: die
 * beiden Kanaele (`bell`, `mail`) **und** die Anlaesse (`ticket_assigned`, …).
 * Mit den beiden neuen Anlaessen aus #98 stuenden fuenf Anlaesse gegen zwei
 * Kanaele in einer Spalte namens „Kanal" — ein Name, der dann mehr verdeckt
 * als benennt.
 *
 * **Warum jetzt.** Die App ist unveroeffentlicht. Nach dem ersten Release waere
 * dieselbe Aenderung eine Migration auf **fremden** Installationen. Vermerkt
 * war das im Docblock von {@see \OCA\Projektwerk\Db\NotifyPref} seit dem
 * 2026-08-11.
 *
 * **Warum nicht `renameColumn()`.** Doctrines Umbenennung wird von Nextclouds
 * Schema-Wrapper nicht durchgereicht und faellt je nach Datenbank auf
 * „fallen lassen und neu anlegen" zurueck — dabei ginge der Inhalt verloren.
 *
 * **Warum zwei Migrationen.** Ein Schemaschritt laeuft **vor** dem Kopieren;
 * wer die Quellspalte im selben Zug abwirft, kopiert aus einer Spalte, die es
 * nicht mehr gibt. Der Abwurf und der neue Schluessel stehen deshalb in
 * {@see Version000004Date20260812000001} — dem Standardweg fuer Umbenennungen
 * in Nextcloud.
 */
class Version000003Date20260812000000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	#[\Override]
	public function name(): string {
		return 'Schalterspalte umbenennen (1/2): pref_key anlegen';
	}

	#[\Override]
	public function description(): string {
		return 'Add notify_prefs.pref_key and copy channel into it — the column carries channels and events alike.';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_notify_prefs')) {
			return null;
		}

		$table = $schema->getTable('pwerk_notify_prefs');

		if (!$table->hasColumn('pref_key')) {
			// **32 statt der 16 von `channel`** — und das ist der einzige
			// Unterschied zur alten Spalte.
			//
			// 16 reichte fuer die bisherigen Werte gerade so: `ticket_assigned`
			// hat 15 Zeichen. Der naechste Anlass sprengt es lautlos —
			// `attachment_added` sind genau 16, `visibility_changed` schon 18.
			// Was dann passiert, haengt an der Datenbank: stille Abschneidung
			// oder ein Fehler auf einer fremden Installation. Dieselbe
			// Fehlerklasse wie die zu langen Tabellennamen, und dieselbe
			// Antwort: vorher Luft lassen statt hinterher suchen.
			$table->addColumn('pref_key', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => '',
			]);
		}

		return $schema;
	}

	/**
	 * Den Inhalt hinueberkopieren.
	 *
	 * @param IOutput $output Fortschrittsausgabe der Migration.
	 * @param Closure $schemaClosure Liefert den Schema-Wrapper.
	 * @param array<string, mixed> $options Optionen des Migrationslaufs.
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_notify_prefs')) {
			return;
		}

		if (!$schema->getTable('pwerk_notify_prefs')->hasColumn('channel')) {
			return;
		}

		// **Nur wo noch nichts steht.** Ein zweiter Lauf darf nichts
		// ueberschreiben, was inzwischen ueber die Oberflaeche gesetzt wurde.
		$kopie = $this->connection->getQueryBuilder();
		$kopie->update('pwerk_notify_prefs')
			->set('pref_key', 'channel')
			->where($kopie->expr()->eq('pref_key', $kopie->createNamedParameter('', IQueryBuilder::PARAM_STR)));

		$output->info('notify_prefs: ' . $kopie->executeStatement() . ' Zeilen von channel nach pref_key kopiert');
	}
}
