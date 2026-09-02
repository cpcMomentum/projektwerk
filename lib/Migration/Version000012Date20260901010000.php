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
 * Auslöser und Schritt-Titel an der Mail-Zeile (#248, Teil 2).
 *
 * Damit die Benachrichtigungs-Mail sagen kann, **wer** etwas getan hat und
 * **welcher** Schritt gemeint war — und zwar identisch im Erst- und im
 * Nachversand. `actor_uid` trägt die Kennung der auslösenden Person (der Name
 * wird beim Senden aufgelöst, so bleibt er aktuell). `step_title` friert den
 * Titel des zugewiesenen Schritts ein: Der Nachversand darf keinen Schritt
 * nachladen — das verböte die Regel, dass Schritte nie eigenständig abgefragt
 * werden —, also steht der Titel schon beim Vormerken auf der Zeile.
 *
 * Beide nullable: Alte Zeilen und Anlässe ohne Schritt (Kommentar, Abschluss)
 * tragen sie nicht, und der Text fällt dann auf die Form ohne Auslöser/Schritt
 * zurück.
 */
class Version000012Date20260901010000 extends SimpleMigrationStep {

	#[\Override]
	public function name(): string {
		return 'Mail: Auslöser und Schritt-Titel';
	}

	#[\Override]
	public function description(): string {
		return 'Add pwerk_mail_outbox.actor_uid and step_title so notification mails name the actor and the assigned step, identically on first send and retry (#248).';
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pwerk_mail_outbox')) {
			return null;
		}

		$table = $schema->getTable('pwerk_mail_outbox');

		if (!$table->hasColumn('actor_uid')) {
			$table->addColumn('actor_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
		}

		if (!$table->hasColumn('step_title')) {
			$table->addColumn('step_title', Types::TEXT, ['notnull' => false]);
		}

		return $schema;
	}
}
