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
 * Fehlplatzierte Anhänge **melden**, nicht bewegen (#11, Phase 7b).
 *
 * Der lesende Zwilling zu {@see AttachmentsRelocate}: zeigt, welche Anhänge an
 * einem Ort liegen, der nicht zur Sichtbarkeit ihres Vorgangs passt — also was
 * ein {@see AttachmentsRelocate} nachzöge —, ohne eine Datei anzufassen und ohne
 * in die DB zu schreiben. Der Blick vor dem Eingriff.
 *
 * **Exit-Code als Signal für Health-Checks:** 0, wenn alles am richtigen Ort
 * liegt; 1, wenn mindestens ein Anhang fehlplatziert ist. So lässt sich
 * `verify && echo ok` verketten, ohne die Ausgabe zu parsen. Die Arbeit macht
 * {@see RelocateAttachments::preview()}; hier stehen nur Aufruf und Ausgabe.
 */
class AttachmentsVerify extends Command {

	public function __construct(
		private RelocateAttachments $repair,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('projektwerk:attachments:verify')
			->setDescription('Anhaenge melden, deren Ablageort nicht zur Sichtbarkeit ihres Vorgangs passt — nur pruefen, nichts bewegen (#11)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$ergebnis = $this->repair->preview();

		if ($ergebnis['mismatched'] === 0) {
			$output->writeln('<info>Alle Anhaenge liegen am richtigen Ort.</info>');

			return 0;
		}

		$output->writeln(sprintf(
			'<comment>%d Anhang/Anhaenge liegen falsch</comment> (projektwerk:attachments:relocate zieht sie nach):',
			$ergebnis['mismatched'],
		));
		foreach ($ergebnis['items'] as $item) {
			$output->writeln(sprintf(
				'  Anhang %d (Vorgang %d): %s -> %s',
				$item['attachmentId'],
				$item['ticketId'],
				$item['ist'],
				$item['soll'],
			));
		}

		return 1;
	}
}
