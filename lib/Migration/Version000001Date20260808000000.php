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
 * Schema v0.1.0 — alle zehn Tabellen auf einen Schlag.
 *
 * Vollstaendig, obwohl Anhaenge erst in Phase 5 und die Outbox erst in Phase 6
 * ausgeliefert werden: Released Migrationen werden nie editiert, und
 * `visibility`, `creator_role`, `assigned_role` und `version` nachzuruesten
 * kostet fuer immer Zusatzmigrationen.
 *
 * Drei Festlegungen gelten durchgaengig und stehen in docs/nextcloud-fallstricke.md:
 *
 * - Praefix `pwerk_`, nicht `projektwerk_`. NC begrenzt Tabellen mit
 *   Auto-Increment-Schluessel praktisch auf 22 Zeichen; `projektwerk_ticket_steps`
 *   waere zu lang und wuerde erst auf einer fremden Installation krachen.
 * - Alle Benutzerkennungen `varchar(64)`. Gast-UIDs sind Hashes mit **exakt** 64
 *   Zeichen — in S1 am 07.08.2026 nachgemessen — und wuerden bei 32 still
 *   abgeschnitten.
 * - Boolesche Felder als SMALLINT 0/1, nie `Types::BOOLEAN` mit `notnull`:
 *   das erzeugt Schema-Fehler, und `PARAM_BOOL` schreibt auf PostgreSQL 'f'
 *   statt 0.
 *
 * Indexnamen sind in PostgreSQL schema-global, deshalb alle mit Tabellenpraefix
 * qualifiziert und alle <= 30 Zeichen.
 */
class Version000001Date20260808000000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Schema v0.1.0 (ProjektWerk Fundament)';
	}

	#[\Override]
	public function description(): string {
		return 'Create initial schema: boards, members, columns, tickets, ticket_users, steps, comments, attachments, notify_prefs, mail_outbox.';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->createBoardsTable($schema);
		$this->createMembersTable($schema);
		$this->createColumnsTable($schema);
		$this->createTicketsTable($schema);
		$this->createTicketUsersTable($schema);
		$this->createStepsTable($schema);
		$this->createCommentsTable($schema);
		$this->createAttachmentsTable($schema);
		$this->createNotifyPrefsTable($schema);
		$this->createMailOutboxTable($schema);

		return $schema;
	}

	private function createBoardsTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable('pwerk_boards')) {
			return;
		}
		$table = $schema->createTable('pwerk_boards');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('description', Types::TEXT, ['notnull' => false]);
		$table->addColumn('owner_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		// Die Namen der beiden Seiten. Am Board und nicht am Mitglied, weil ein
		// Board genau zwei Parteien kennt — mehr ist ausdruecklich nicht
		// abgedeckt. Ein Feld je Mitglied koennte auseinanderlaufen (zwei
		// Schreibweisen derselben Firma), zwei Felder am Board koennen es nicht.
		//
		// Sie stehen unter JEDEM Namen, auch unter den internen: In der
		// Personenauswahl eines oeffentlichen Tickets erscheinen beide Seiten
		// gemeinsam und ohne Trennung (§9). Traege nur die Kundenseite eine
		// Firma, waere die interne stumm "der Normalfall" — die Trennung waere
		// durch die Hintertuer zurueck.
		$table->addColumn('org_internal', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('org_external', Types::STRING, ['notnull' => false, 'length' => 128]);
		// Hinterlegt wird die Datei-ID; der Pfad dient nur der Anzeige und darf
		// veralten. In S2 (07.08.2026) bestaetigt: Die Datei-ID ueberlebt einen
		// Umzug innerhalb des Team-Ordners, der Pfad nicht.
		$table->addColumn('folder_public_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		$table->addColumn('folder_public_path', Types::STRING, ['notnull' => false, 'length' => 4000]);
		$table->addColumn('folder_internal_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		$table->addColumn('folder_internal_path', Types::STRING, ['notnull' => false, 'length' => 4000]);
		// Reine Adresse fuer den Knopf "Zum Projektchat" — kein Secret, keine
		// Schnittstelle, kein Bezug zum Benachrichtigungswesen.
		$table->addColumn('chat_url', Types::STRING, ['notnull' => false, 'length' => 4000]);
		$table->addColumn('ticket_counter', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
		$table->addColumn('github_enabled', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('archived', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		// Zaehlt bei jedem Schreibvorgang im Board hoch. Im MVP geschrieben,
		// aber nicht gelesen: Der Delta-Poll ist die Rueckfallebene, die
		// notify_push spaeter ohnehin braucht, und die Spalte kostet heute
		// nichts. Preis: Die Board-Zeile wird zum Serialisierungspunkt jedes
		// Schreibvorgangs — bei <= 20 Mitgliedern tragbar.
		$table->addColumn('change_seq', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['owner_user_id'], 'pwerk_boards_owner_idx');
	}

	private function createMembersTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable('pwerk_members')) {
			return;
		}
		$table = $schema->createTable('pwerk_members');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('board_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		// Enum-Werte ASCII und englisch ('internal'/'external'); die deutschen
		// Bezeichnungen sind reine Anzeigetexte aus der Uebersetzungsdatei.
		$table->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('is_manager', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		// Vor- und Nachname fuer dieses Board. NULL heisst: Anzeigename aus
		// Nextcloud verwenden.
		//
		// Nextclouds Anzeigename ist oft ein Kuerzel — intern gleichgueltig,
		// gegenueber dem Kunden nicht. Verschaerfend der Befund aus S1: Ohne
		// gepflegten Namen steht die E-Mail-Adresse eines Gastes als Klartext
		// auf jeder Ticketkarte, auch fuer die uebrigen Mitarbeiter der
		// Kundenseite. Ein Feld an der Mitgliedschaft macht das behebbar, ohne
		// fremde Konten anzufassen — und passt zur Hausregel, dass die Rolle an
		// der Mitgliedschaft haengt und nicht am Konto.
		$table->addColumn('display_name', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('added_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('added_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		// Die Eindeutigkeit ist nicht Kosmetik: BoardAccess liest genau eine
		// Zeile je (Board, Person) und leitet daraus die Rolle ab, an der die
		// gesamte Sichtbarkeitsregel haengt.
		$table->addUniqueIndex(['board_id', 'user_id'], 'pwerk_members_bu_uidx');
		$table->addIndex(['user_id'], 'pwerk_members_user_idx');
	}

	private function createColumnsTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable('pwerk_columns')) {
			return;
		}
		$table = $schema->createTable('pwerk_columns');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('board_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('position', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
		$table->addColumn('color', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['board_id', 'position'], 'pwerk_columns_board_idx');
	}

	private function createTicketsTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable('pwerk_tickets')) {
			return;
		}
		$table = $schema->createTable('pwerk_tickets');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('board_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('column_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('number', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('description', Types::TEXT, ['notnull' => false]);
		// 'public' | 'internal' | 'private'. Vorgabe public: Wer nichts
		// entscheidet, arbeitet sichtbar — das ist der Zweck des Produkts.
		$table->addColumn('visibility', Types::STRING, ['notnull' => true, 'default' => 'public', 'length' => 16]);
		$table->addColumn('creator_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		// Am Ticket eingefroren, nicht zur Laufzeit ermittelt. Ohne dieses Feld
		// waere die Symmetrie von 'internal' nicht stabil, sobald jemand die
		// Rolle wechselt oder das Board verlaesst — und die Pruefung nicht in
		// einer einzigen Abfrage moeglich.
		$table->addColumn('creator_role', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('responsible_user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		// BIGINT mit Schrittweite 65536, Einfuegen per Mittelwert in PHP
		// (intdiv). Nicht in SQL: Integer-Division verhaelt sich ueber SQLite,
		// MySQL und PostgreSQL nicht gleich. Float-Halbierung waere nach ~50
		// Einfuegungen praezisionsabhaengig.
		$table->addColumn('position', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
		$table->addColumn('closed_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('version', Types::BIGINT, ['notnull' => true, 'default' => 1, 'length' => 20]);
		// Wer den aktuellen Stand von `version` verursacht hat. NULL heisst:
		// seit dem Anlegen unveraendert — der Ersteller steht in
		// creator_user_id und wird hier nicht wiederholt.
		//
		// Steht ab Migration 1 hier, obwohl die Anzeige dazu erst spaeter
		// kommt. Der Grund ist derselbe wie bei visibility und creator_role
		// (siehe Kopf dieser Datei): Solange es kein Release gibt, kostet die
		// Spalte eine Zeile; danach kostet sie fuer immer eine
		// Zusatzmigration. varchar(64), weil Gast-Kennungen Hashes mit exakt
		// 64 Zeichen sind.
		$table->addColumn('last_editor_user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('github_issue_number', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		$table->addColumn('github_issue_url', Types::STRING, ['notnull' => false, 'length' => 4000]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		// Die eigentliche Garantie gegen doppelte Ticketnummern. Der atomare
		// UPDATE auf ticket_counter ist der Normalweg, dieser Index faengt das
		// Rennen ab — der Aufrufer wiederholt bei REASON_UNIQUE_CONSTRAINT_VIOLATION.
		$table->addUniqueIndex(['board_id', 'number'], 'pwerk_tickets_bn_uidx');
		// Die Sichtbarkeitsbedingung steht in JEDER Ticket-Abfrage; dieser
		// Index traegt sie.
		$table->addIndex(['board_id', 'visibility', 'creator_role'], 'pwerk_tickets_vis_idx');
		$table->addIndex(['column_id', 'position'], 'pwerk_tickets_col_idx');
		$table->addIndex(['responsible_user_id'], 'pwerk_tickets_resp_idx');
	}

	private function createTicketUsersTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable('pwerk_ticket_users')) {
			return;
		}
		$table = $schema->createTable('pwerk_ticket_users');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('ticket_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('added_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['ticket_id', 'user_id'], 'pwerk_tuser_tu_uidx');
	}

	private function createStepsTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable('pwerk_steps')) {
			return;
		}
		$table = $schema->createTable('pwerk_steps');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('ticket_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('assigned_user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		// Bei der Zuweisung kopiert, damit "wartet auf Kunde" ohne Verbund
		// stabil bleibt. Der Zustand wird nie gespeichert, sondern je Ticket
		// aus offenen Schritten mit assigned_role = 'external' berechnet.
		$table->addColumn('assigned_role', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->addColumn('assigned_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('done', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('done_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('due_date', Types::DATE, ['notnull' => false]);
		$table->addColumn('position', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['ticket_id', 'position'], 'pwerk_steps_ticket_idx');
		$table->addIndex(['assigned_user_id', 'done'], 'pwerk_steps_assigned_idx');
	}

	private function createCommentsTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable('pwerk_comments')) {
			return;
		}
		$table = $schema->createTable('pwerk_comments');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('ticket_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('author_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('body', Types::TEXT, ['notnull' => true]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['ticket_id', 'created_at'], 'pwerk_comments_ticket_idx');
	}

	private function createAttachmentsTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable('pwerk_attachments')) {
			return;
		}
		$table = $schema->createTable('pwerk_attachments');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('ticket_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		// Fuehrendes Feld. file_path und location werden beim
		// Sichtbarkeitswechsel nachgezogen, die Datei-ID bleibt.
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('file_path', Types::STRING, ['notnull' => false, 'length' => 4000]);
		$table->addColumn('file_name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('location', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('uploaded_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['ticket_id'], 'pwerk_attach_ticket_idx');
		$table->addIndex(['file_id'], 'pwerk_attach_file_idx');
	}

	private function createNotifyPrefsTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable('pwerk_notify_prefs')) {
			return;
		}
		$table = $schema->createTable('pwerk_notify_prefs');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		// Textfeld statt Zweierschalter, damit ein dritter Kanal spaeter ohne
		// Migration dazukommt. Fehlende Zeile = Vorgabe (Kanal an).
		$table->addColumn('channel', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('enabled', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['user_id', 'channel'], 'pwerk_notify_uc_uidx');
	}

	private function createMailOutboxTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable('pwerk_mail_outbox')) {
			return;
		}
		$table = $schema->createTable('pwerk_mail_outbox');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('recipient_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('ticket_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		$table->addColumn('event', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('lang', Types::STRING, ['notnull' => false, 'length' => 8]);
		// 'pending' | 'sent' | 'failed' | 'skipped_no_address'. Der letzte Wert
		// traegt die Zusage aus §5.24, dass "keine Adresse" von "abgeschaltet"
		// unterscheidbar bleibt.
		$table->addColumn('status', Types::STRING, ['notnull' => true, 'default' => 'pending', 'length' => 24]);
		$table->addColumn('attempts', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		// In S4 (07.08.2026) gemessen: IMailer::send() wirft eine
		// TransportException, und wer nicht faengt, merkt nichts — occ
		// user:welcome liefert bei totem SMTP Exitcode 0 ohne Ausgabe und ohne
		// Mail. Hier landet der Ausnahmetext, sonst ist er verloren.
		$table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('sent_at', Types::DATETIME, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['status', 'attempts'], 'pwerk_outbox_status_idx');
	}
}
