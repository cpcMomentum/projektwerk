<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Repair;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\NotAMemberException;
use OCA\Projektwerk\Db\Attachment;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Service\AttachmentService;
use OCA\Projektwerk\Service\NoFolderException;
use OCA\Projektwerk\Service\ProjectFolderService;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * **Das Netz für einen abgebrochenen Anhang-Umzug** (#188, Folge zu #185).
 *
 * Beim Sichtbarkeitswechsel zieht die Datei mit ({@see AttachmentService::relocate()}).
 * Bricht der Vorgang **zwischen Datei-Move und DB-Schreiben** ab, bleibt der
 * Anhang an einem Ort, der nicht mehr zur Sichtbarkeit seines Vorgangs passt: Er
 * zeigt „fehlt", bis jemand nachzieht. Das ist fail-closed — die Datei liegt nie
 * offener als der Vorgang, es entsteht kein Leck — aber es heilt nicht von selbst.
 * Dieser Schritt zieht es nach.
 *
 * Läuft **beim App-Upgrade** (post-migration) und ist über den occ-Befehl
 * `projektwerk:attachments:relocate` auch von Hand anstoßbar. **Idempotent**:
 * Nach dem Heilen passt jeder Ort zu seiner Sichtbarkeit, ein weiterer Lauf ist
 * ein No-op.
 *
 * **Diese Klasse greift — wie {@see \OCA\Projektwerk\Command\TicketRestore} — als
 * eine von wenigen ausserhalb von `TicketMapper`/`TicketScope` direkt auf
 * `pwerk_tickets` zu.** Sie muss es: Ein gestrandeter **interner** Anhang gehört
 * geheilt, obwohl ihn kein Betrachter sähe — die Reparatur läuft ohne
 * Betrachterkontext und an der Sichtbarkeitsregel vorbei. Der Zugriff beschränkt
 * sich auf die eine lesende Abfrage in {@see mismatched()}; das Verschieben und
 * jeder Schreibzugriff auf `pwerk_attachments` laufen über den Mapper bzw.
 * {@see AttachmentService}. Der Architekturtest kennt die Ausnahme namentlich.
 */
class RelocateAttachments implements IRepairStep {

	public function __construct(
		private IDBConnection $db,
		private AttachmentMapper $attachments,
		private AttachmentService $attachmentService,
		private ProjectFolderService $folders,
		private BoardAccess $access,
		private LoggerInterface $logger,
	) {
	}

	public function getName(): string {
		return 'ProjektWerk: fehlplatzierte Anhänge an ihren Sichtbarkeits-Ordner nachziehen';
	}

	public function run(IOutput $output): void {
		$ergebnis = $this->reconcile();

		$output->info(sprintf(
			'ProjektWerk: %d Anhang/Anhänge nachgezogen, %d übersprungen (%d geprüft).',
			$ergebnis['healed'],
			$ergebnis['skipped'],
			$ergebnis['checked'],
		));
	}

	/**
	 * Alle fehlplatzierten Anhänge an ihren korrekten Ort ziehen.
	 *
	 * Ein Fehler an einem Anhang (Uploader hat das Board verlassen, Datei nicht
	 * mehr erreichbar, Zielordner fehlt) **überspringt genau diesen einen** und
	 * hält ihn im Log fest — der Lauf geht weiter. So heilt ein Durchgang, was er
	 * kann, statt am ersten Sonderfall stehen zu bleiben.
	 *
	 * @return array{checked: int, healed: int, skipped: int}
	 */
	public function reconcile(): array {
		$checked = 0;
		$healed = 0;
		$skipped = 0;

		foreach ($this->mismatched() as $row) {
			$checked++;

			$ticket = $this->ticketFrom($row);
			$attachment = $this->attachmentIn((int)$row['ticket_id'], (int)$row['att_id']);

			if ($attachment === null) {
				// Zwischen Abfrage und Nachladen entfernt — kein Fehler, nichts
				// zu tun.
				$skipped++;
				continue;
			}

			try {
				$viewer = $this->access->contextFor((string)$row['uploaded_by'], (int)$row['board_id']);
				$this->attachmentService->reconcileOne($viewer, $ticket, $attachment);
				$healed++;
			} catch (NotAMemberException | NoFolderException | NotPermittedException $e) {
				$this->logger->warning(
					'ProjektWerk: Anhang {att} an Vorgang {ticket} liess sich nicht nachziehen — bitte von Hand pruefen.',
					[
						'att' => (int)$row['att_id'],
						'ticket' => (int)$row['ticket_id'],
						'exception' => $e,
					],
				);
				$skipped++;
			}
		}

		return ['checked' => $checked, 'healed' => $healed, 'skipped' => $skipped];
	}

	/**
	 * Die Zeilen, deren gespeicherter Ort nicht zur Sichtbarkeit ihres Vorgangs
	 * passt — board-übergreifend und an der Sichtbarkeitsregel vorbei.
	 *
	 * Der bewusste Direktzugriff auf `pwerk_tickets` (siehe Klassenkommentar):
	 * ein interner Anhang muss auch dann gefunden werden, wenn ihn kein
	 * Betrachter sähe. Der Soll-Ort kommt aus derselben Quelle wie beim
	 * Anlegen/Umzug — {@see ProjectFolderService::locationForVisibility()} — und
	 * wird gegen den gespeicherten `location` gehalten.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function mismatched(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias('a.id', 'att_id')
			->selectAlias('a.location', 'att_location')
			->selectAlias('a.uploaded_by', 'uploaded_by')
			->selectAlias('a.ticket_id', 'ticket_id')
			->selectAlias('t.board_id', 'board_id')
			->selectAlias('t.visibility', 'visibility')
			->selectAlias('t.creator_role', 'creator_role')
			->from('pwerk_attachments', 'a')
			->innerJoin('a', 'pwerk_tickets', 't', $qb->expr()->eq('a.ticket_id', 't.id'));

		$rows = $qb->executeQuery()->fetchAll();

		return array_values(array_filter($rows, function (array $row): bool {
			$soll = $this->folders->locationForVisibility(
				(string)$row['visibility'],
				(string)$row['creator_role'],
			);

			// Kein Ablageort (der interne Vorgang der Kundenseite): dort kann es
			// regulär keinen Anhang geben. Taucht doch einer auf, ist das ein
			// Fall für die Hand, nicht fürs blinde Verschieben — hier übergangen.
			return $soll !== null && $soll !== (string)$row['att_location'];
		}));
	}

	/**
	 * Ein Ticket-Objekt allein aus der Reparatur-Abfrage — nur mit den Feldern,
	 * die {@see AttachmentService::reconcileOne()} braucht (Sichtbarkeit und
	 * Ersteller-Rolle bestimmen den Zielordner). Keine DB-Last, kein zweiter
	 * Lesepfad auf `pwerk_tickets`.
	 *
	 * @param array<string, mixed> $row
	 */
	private function ticketFrom(array $row): Ticket {
		$ticket = new Ticket();
		$ticket->setId((int)$row['ticket_id']);
		$ticket->setBoardId((int)$row['board_id']);
		$ticket->setVisibility((string)$row['visibility']);
		$ticket->setCreatorRole((string)$row['creator_role']);

		return $ticket;
	}

	/**
	 * Den Anhang frisch als Entity laden — nur so ist er über den Mapper
	 * aktualisierbar. Über die (erlaubte) Kinder-Lesemethode `findForTickets()`,
	 * nicht über einen zweiten Direktzugriff.
	 */
	private function attachmentIn(int $ticketId, int $attachmentId): ?Attachment {
		foreach ($this->attachments->findForTickets([$ticketId]) as $attachment) {
			if ((int)$attachment->getId() === $attachmentId) {
				return $attachment;
			}
		}

		return null;
	}
}
