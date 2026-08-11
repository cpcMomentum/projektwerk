<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Attachment;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\Folder;
use OCP\Files\NotPermittedException;

/**
 * Anhänge am Vorgang — als Datei im Projektordner, nicht als Kopie in der App.
 *
 * **Der Ablageort IST die Sichtbarkeit** (§5.18). Ein Anhang landet in dem
 * Ordner, der zur Sichtbarkeit seines Vorgangs gehört; wer den Ordner sehen
 * darf, entscheidet Nextcloud, nicht diese App. Damit ist der Zugriff auf die
 * Datei genau dort geregelt, wo Dateizugriff geregelt gehört — und nicht ein
 * zweites Mal hier.
 *
 * Daraus folgt die unbequeme Seite: **Für Vorgänge ohne Ordner gibt es keine
 * Anhänge.** Ein interner Vorgang der Kundenseite und ein „Nur ich"-Vorgang
 * haben keinen Ablageort, und einen der beiden vorhandenen zu nehmen hieße,
 * die Datei jemandem hinzulegen, der den Vorgang nicht sehen darf. Die App
 * lehnt dann ab, statt einen Ort zu raten.
 *
 * **Die Sichtbarkeit eines Vorgangs mit Anhängen lässt sich nicht ändern**
 * (§3.10 Stufe 1). Ein Umzug der Dateien wäre nicht transaktional zur
 * Datenbank: Bräche er in der Mitte ab, läge die Datei im falschen Ordner,
 * während das Ticket schon umgestellt ist — ein Leck, das **physisch** ist und
 * das keine spätere Codekorrektur heilt. Solange Spike S2 nicht beantwortet
 * ist, wird deshalb gar nicht erst verschoben.
 *
 * **Löschen löst nur die Verknüpfung.** Die Datei bleibt liegen, wo sie liegt
 * — die App löscht nie (§5.18). Das ist im Rückfragedialog benannt, damit
 * niemand „entfernen" für „wegräumen" hält.
 */
class AttachmentService {

	/**
	 * Wie viele Namen bei einer Kollision durchprobiert werden.
	 *
	 * Eine Obergrenze und keine Schleife bis zum Erfolg: Wäre der Ordner aus
	 * einem anderen Grund unschreibbar, liefe sie ewig und der Aufruf hinge,
	 * statt zu antworten.
	 */
	private const MAX_ATTEMPTS = 50;

	public function __construct(
		private AttachmentMapper $attachments,
		private TicketMapper $tickets,
		private BoardMapper $boards,
		private ProjectFolderService $folders,
	) {
	}

	/**
	 * Eine hochgeladene Datei an einen Vorgang hängen.
	 *
	 * @param resource|string $content Der Inhalt, wie ihn Nextcloud liefert.
	 *
	 * @throws DoesNotExistException  Vorgang nicht sichtbar
	 * @throws NoFolderException      Für diesen Vorgang gibt es keinen Ablageort
	 * @throws NotPermittedException  Ordner nicht erreichbar oder nicht beschreibbar
	 */
	public function create(ViewerContext $viewer, int $ticketId, string $name, $content): Attachment {
		// Erst die Sichtbarkeit, dann alles andere — wie überall: Sonst
		// unterschiede sich die Antwort auf einen verborgenen Vorgang je
		// nachdem, ob der Ordner steht, und daran ließe sich seine Existenz
		// ablesen.
		$ticket = $this->tickets->findVisible($viewer, $ticketId);
		$folder = $this->folderFor($viewer, $ticket);

		$file = $folder->newFile($this->freeName($folder, $ticket, $name), $content);

		$attachment = new Attachment();
		$attachment->setTicketId($ticketId);
		$attachment->setFileId($file->getId());
		$attachment->setFilePath($this->folders->displayPath($viewer->userId, $folder) . '/' . $file->getName());
		$attachment->setFileName($file->getName());
		$attachment->setLocation((string)$this->folders->locationFor($ticket));
		$attachment->setUploadedBy($viewer->userId);
		$attachment->setCreatedAt(new \DateTime());

		return $this->attachments->insert($attachment);
	}

	/**
	 * Die Verknüpfung lösen — **die Datei bleibt liegen** (§5.18).
	 *
	 * Anders als beim Kommentar gibt es hier keine Autorenschranke: Wer den
	 * Vorgang sieht, darf seine Anhänge lösen. Ein Anhang gehört dem Vorgang,
	 * nicht der Person, die ihn hochgeladen hat — und ein Vorgang, dessen
	 * Sichtbarkeit sich nur ändern lässt, wenn eine bestimmte Person greifbar
	 * ist, wäre für den Rest des Teams blockiert.
	 *
	 * @throws DoesNotExistException Anhang nicht sichtbar
	 */
	public function delete(ViewerContext $viewer, int $attachmentId): Attachment {
		return $this->attachments->delete($this->findVisibleAttachment($viewer, $attachmentId));
	}

	/**
	 * Der Ordner, in den ein Anhang dieses Vorgangs gehört.
	 *
	 * @throws NoFolderException
	 * @throws NotPermittedException
	 */
	private function folderFor(ViewerContext $viewer, Ticket $ticket): Folder {
		$location = $this->folders->locationFor($ticket);

		if ($location === null) {
			// Zwei verschiedene Gründe, **eine** Meldung — und beide sind keine
			// Störung, sondern die Zusage: Ein Vorgang, den nur eine Seite oder
			// nur eine Person sieht, hat keinen Ordner, in dem die Datei
			// genauso eng läge.
			throw new NoFolderException(
				'An Vorgängen, die nur die eigene Seite oder nur Sie selbst sehen, sind keine Anhänge möglich.',
			);
		}

		$folderId = $this->folders->folderIdFor($this->boards->findForViewer($viewer), $location);

		if ($folderId === null) {
			throw new NoFolderException(
				'Für dieses Projekt ist noch kein Ordner hinterlegt. Die Projektverwaltung trägt ihn in den Einstellungen ein.',
			);
		}

		return $this->folders->resolve($viewer->userId, $folderId);
	}

	/**
	 * Ein freier Dateiname mit Vorgangsnummer davor — `0042_angebot.pdf`.
	 *
	 * **Flache Ablage mit Präfix statt eines Unterordners je Vorgang.** Der
	 * Ordner ist derselbe, den beide Seiten ohnehin in ihren Dateien öffnen;
	 * ein Baum aus vierstelligen Zahlen wäre dort für Menschen unbrauchbar.
	 * Die Nummer vorn hält die Anhänge eines Vorgangs trotzdem beieinander,
	 * weil die Dateiliste alphabetisch sortiert.
	 *
	 * Bei Namensgleichheit wird gezählt (`0042_angebot_2.pdf`) und **nicht**
	 * überschrieben: Zwei Personen, die am selben Tag „scan.pdf" anhängen,
	 * dürfen einander nicht die Datei wegnehmen.
	 *
	 * @throws NotPermittedException
	 */
	private function freeName(Folder $folder, Ticket $ticket, string $name): string {
		$prefix = str_pad((string)$ticket->getNumber(), 4, '0', STR_PAD_LEFT) . '_';
		$sauber = $this->cleanName($name);

		$punkt = strrpos($sauber, '.');
		// Der Punkt an Position 0 ist kein Trenner, sondern der Anfang eines
		// Namens wie `.gitignore` — dort gehört die Zählung ans Ende.
		$stamm = $punkt === false || $punkt === 0 ? $sauber : substr($sauber, 0, $punkt);
		$endung = $punkt === false || $punkt === 0 ? '' : substr($sauber, $punkt);

		for ($n = 1; $n <= self::MAX_ATTEMPTS; $n++) {
			$kandidat = $n === 1
				? $prefix . $sauber
				: $prefix . $stamm . '_' . $n . $endung;

			if (!$folder->nodeExists($kandidat)) {
				return $kandidat;
			}
		}

		throw new NotPermittedException('Es gibt bereits zu viele Dateien mit diesem Namen.');
	}

	/**
	 * Einen Dateinamen so weit entschärfen, dass er ein Name bleibt.
	 *
	 * Es geht **nicht** darum, Nextclouds eigene Prüfung zu ersetzen — die
	 * greift beim Anlegen ohnehin. Es geht um die Fälle, in denen ein Name
	 * sonst etwas anderes bedeutete als einen Namen: Ein Schrägstrich wäre ein
	 * Pfad, ein führender Punkt eine versteckte Datei, und ein leerer Name gar
	 * keiner.
	 */
	private function cleanName(string $name): string {
		// `basename` wirft alles vor dem letzten Schrägstrich weg — damit ist
		// `../../geheim.txt` sicher `geheim.txt` und kein Ausbruch.
		$sauber = basename(str_replace('\\', '/', trim($name)));
		$sauber = preg_replace('/[\x00-\x1F\x7F]/u', '', $sauber) ?? '';
		$sauber = ltrim($sauber, '.');

		return $sauber === '' ? 'anhang' : mb_substr($sauber, 0, 200);
	}

	/**
	 * Ein Anhang, aber nur über die gefilterte Ticketmenge.
	 *
	 * Derselbe Umweg wie bei {@see CommentService} und aus demselben Grund: Es
	 * gibt keine Methode, die „Anhang 42" lädt — nur „die Anhänge zu den
	 * Vorgängen, die dieser Betrachter sehen darf" (§5.8).
	 *
	 * @throws DoesNotExistException
	 */
	private function findVisibleAttachment(ViewerContext $viewer, int $attachmentId): Attachment {
		$ticketIds = array_map(
			static fn (Ticket $ticket): int => (int)$ticket->getId(),
			$this->tickets->findVisibleInBoard($viewer),
		);

		foreach ($this->attachments->findForTickets($ticketIds) as $attachment) {
			if ((int)$attachment->getId() === $attachmentId) {
				return $attachment;
			}
		}

		throw new DoesNotExistException('Anhang nicht gefunden.');
	}
}
