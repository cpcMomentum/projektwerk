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
 * #285 — Antwort-Anker auf der Mail-Outbox.
 *
 * Zwei Spalten, rein additiv, damit „Antworten per E-Mail" (Serie #287) eine
 * eingehende Antwort wieder ihrer ausgegangenen Mail zuordnen kann:
 *
 * - `reply_token` — pro Zeile ein `bin2hex(random_bytes(16))`, erzeugt schon
 *   beim Vormerken in {@see \OCA\Projektwerk\Service\MailDispatcher::queue()}
 *   (nicht beim Senden, damit der Nachversand keinen zweiten Token vergibt). Er
 *   reist im Betreff (`[PW-{token}]`, Serie #287) und ist der verlässliche
 *   Anker. `string(32)`, nullable (Altbestand hat keinen), mit **Unique-Index**
 *   — der Token ist eine Fähigkeit, Dubletten wären ein Loch.
 * - `sent_message_id` — die Message-ID der versendeten Mail, für das
 *   `In-Reply-To`-Matching. `string(255)`, nullable. Wird derzeit **nicht**
 *   befüllt (die Spalte ist Vorrat): `OCP\Mail\IMessage` gibt auf NC 33/34
 *   keinen Zugriff auf die darunterliegende Symfony-Mail her, ohne den die
 *   Message-ID nicht zu setzen ist — Begründung in `MailDispatcher::flush()`.
 *
 * Beide nullable + Unique auf einer nullable-Spalte: sqlite/mysql/pgsql lassen
 * mehrere NULLs im Unique-Index zu, der Altbestand (alles NULL) bleibt also
 * gültig.
 */
class Version000020Date20260914130000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'mail outbox reply anchors (#285)';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_mail_outbox.reply_token (unique) and sent_message_id for email-reply matching (#285).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_mail_outbox')) {
			return null;
		}

		$table = $schema->getTable('pwerk_mail_outbox');
		$changed = false;

		if (!$table->hasColumn('reply_token')) {
			$table->addColumn('reply_token', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('sent_message_id')) {
			$table->addColumn('sent_message_id', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$changed = true;
		}

		// Index-Name unter dem Oracle-30-Zeichen-Limit (kein `<database>` in
		// info.xml → Oracle-Namensregeln gelten fleetweit).
		if (!$table->hasIndex('pwerk_mo_reply_token_idx')) {
			$table->addUniqueIndex(['reply_token'], 'pwerk_mo_reply_token_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
