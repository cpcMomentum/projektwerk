<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Command;

use OCA\Projektwerk\Repair\RelocateAttachments;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fehlplatzierte Anhänge nachziehen — das Netz zu #185 von Hand (#188).
 *
 * Derselbe Reparaturlauf, der beim App-Upgrade automatisch greift
 * ({@see RelocateAttachments}), auf Zuruf: für den seltenen Fall, dass ein
 * zwischen Datei-Move und DB-Schreiben abgebrochener Sichtbarkeitswechsel einen
 * Anhang als „fehlt" stehen lässt und man ihn nicht bis zum nächsten Update
 * warten lassen will. Der Zustand ist fail-closed (nie zu offen), nur nicht
 * selbstheilend — deshalb ein Werkzeug und kein Alarm.
 *
 * Die Arbeit macht {@see RelocateAttachments}; hier stehen nur Aufruf und Ausgabe.
 */
class AttachmentsRelocate extends Command {

	public function __construct(
		private RelocateAttachments $repair,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('projektwerk:attachments:relocate')
			->setDescription('Anhaenge nachziehen, deren Ablageort nicht zur Sichtbarkeit ihres Vorgangs passt (#188)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$ergebnis = $this->repair->reconcile();

		$output->writeln(sprintf(
			'<info>%d Anhang/Anhaenge nachgezogen</info>, %d uebersprungen (%d geprueft).',
			$ergebnis['healed'],
			$ergebnis['skipped'],
			$ergebnis['checked'],
		));

		return 0;
	}
}
