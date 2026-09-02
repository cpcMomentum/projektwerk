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
use OCA\Projektwerk\Db\ProjectMapper;
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
 * Anhänge.** Ein interner Vorgang der Kundenseite hat keinen Ablageort, und
 * einen der beiden Team-Ordner zu nehmen hieße, die Datei jemandem
 * hinzulegen, der den Vorgang nicht sehen darf. Die App lehnt dann ab, statt
 * einen Ort zu raten. Ein „Nur ich"-Vorgang hat seit #184 (Phase B) einen
 * eigenen Ablageort — den persönlichen Ordner der anlegenden Person.
 *
 * **Anhänge ziehen mit der Sichtbarkeit um** (#185, {@see relocate()}). Der
 * Ablageort IST die Sichtbarkeit, also wandert die Datei in den Ordner der
 * Ziel-Sichtbarkeit, statt den Wechsel zu blockieren. Der Umzug ist nicht
 * transaktional zur Datenbank — deshalb bestimmt {@see TicketService} die
 * **Reihenfolge** so, dass die Datei nie offener liegt als der Vorgang: Ein
 * abgebrochener Umzug degradiert zu „Anhang fehlt" ({@see withPresence()}),
 * nie zu einem Leck. Die Datei-ID wird nach dem Umzug am Zielknoten neu gelesen
 * (die offene Frage aus §11.3 / Spike S2 ist damit gegenstandslos, statt
 * beantwortet werden zu müssen).
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
		private ProjectMapper $projects,
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
	 * Kann der Vorgang mit seinen Anhängen auf `$targetVisibility` wechseln?
	 *
	 * **Vorabprüfung ohne Nebenwirkung** (#185). Der Aufrufer
	 * ({@see TicketService::changeVisibility()}) braucht die Antwort, **bevor**
	 * er die Datenbank ändert — beim Hochstufen wird erst die Sichtbarkeit
	 * gesetzt und dann die Datei verschoben, und ein fehlender Zielordner darf
	 * nicht erst auffallen, wenn der Vorgang schon offen ist.
	 *
	 * Ohne Anhänge ist nichts zu prüfen: Der Wechsel bewegt dann keine Datei.
	 * Mit Anhängen muss der Zielordner stehen — sonst gibt es keinen Ort, der
	 * so eng wäre wie der Vorgang, und der Wechsel wird abgelehnt (in Phase A
	 * ist das der Fall „nach privat" und „Kundenseite nach intern").
	 *
	 * @throws NoFolderException      Zielsichtbarkeit hat keinen Ablageort
	 * @throws NotPermittedException  Zielordner nicht erreichbar/beschreibbar
	 */
	public function assertRelocatable(ViewerContext $viewer, Ticket $ticket, string $targetVisibility): void {
		if ($this->attachmentsOf($ticket) === []) {
			return;
		}

		$this->folderForVisibility($viewer, $ticket, $targetVisibility);
	}

	/**
	 * Die Anhänge eines Vorgangs in den Ordner der Ziel-Sichtbarkeit ziehen (#185).
	 *
	 * **Der Ablageort IST die Sichtbarkeit** (§5.18) — also zieht die Datei mit,
	 * statt den Wechsel zu blockieren. Aufgerufen aus
	 * {@see TicketService::changeVisibility()}, das die **Reihenfolge** relativ
	 * zum Datenbank-Schreiben bestimmt, damit die Datei nie offener liegt als der
	 * Vorgang.
	 *
	 * **Die Datei-ID wird nach dem Umzug am Zielknoten neu gelesen** und
	 * gespeichert. Ein Verschieben innerhalb derselben Storage erhält die ID
	 * (dann ist das ein No-op); ein Verschieben über Storage-Grenzen ist intern
	 * ein Kopieren-und-Löschen und vergibt eine neue ID. Beide Fälle sind damit
	 * abgedeckt, ohne sich auf das eine oder andere zu verlassen (die offene
	 * Frage aus §11.3 / Spike S2). Anzeigepfad und Name folgen dem neuen Ort.
	 *
	 * @throws NoFolderException      Zielsichtbarkeit hat keinen Ablageort
	 * @throws NotPermittedException  Datei oder Zielordner nicht erreichbar
	 */
	public function relocate(ViewerContext $viewer, Ticket $ticket, string $targetVisibility): void {
		$attachments = $this->attachmentsOf($ticket);

		if ($attachments === []) {
			return;
		}

		$target = $this->folderForVisibility($viewer, $ticket, $targetVisibility);
		$location = (string)$this->folders->locationForVisibility($targetVisibility, (string)$ticket->getCreatorRole());

		foreach ($attachments as $attachment) {
			$this->moveAttachmentTo($viewer, $attachment, $target, $location);
		}
	}

	/**
	 * Einen einzelnen Anhang an den Ort ziehen, den die **aktuelle** Sichtbarkeit
	 * seines Vorgangs vorschreibt — der Reparaturweg zu #185 (#188).
	 *
	 * Selbstheilung, kein Sichtbarkeitswechsel: Der Vorgang steht bereits richtig,
	 * nur Datei und `location` hängen hinterher (ein zwischen Datei-Move und
	 * DB-Schreiben abgebrochener Umzug). Zielort ist deshalb
	 * `ticket->getVisibility()`, nicht eine gedachte Ziel-Sichtbarkeit. Ob ein
	 * Anhang überhaupt fehlplatziert ist, entscheidet der aufrufende
	 * Reparaturschritt ({@see \OCA\Projektwerk\Repair\RelocateAttachments}); hier
	 * wird nur gezogen.
	 *
	 * @throws NoFolderException      Der Vorgang hat keinen Ablageort
	 * @throws NotPermittedException  Datei oder Zielordner nicht erreichbar
	 */
	public function reconcileOne(ViewerContext $viewer, Ticket $ticket, Attachment $attachment): void {
		$visibility = (string)$ticket->getVisibility();
		$target = $this->folderForVisibility($viewer, $ticket, $visibility);
		$location = (string)$this->folders->locationForVisibility($visibility, (string)$ticket->getCreatorRole());

		$this->moveAttachmentTo($viewer, $attachment, $target, $location);
	}

	/**
	 * Eine Datei in den Zielordner ziehen und den Anhang nachführen.
	 *
	 * Die **eine** Move-Implementierung hinter {@see relocate()} (Sichtbarkeits-
	 * wechsel) und {@see reconcileOne()} (Reparatur).
	 *
	 * **Die Datei-ID wird nach dem Umzug am Zielknoten neu gelesen.** Ein
	 * Verschieben innerhalb derselben Storage erhält die ID; über Storage-Grenzen
	 * ist es intern ein Kopieren-und-Löschen und vergibt eine neue. Beide Fälle
	 * sind so abgedeckt, ohne sich auf einen zu verlassen. Anzeigepfad und Name
	 * folgen dem neuen Ort.
	 *
	 * @throws NotPermittedException  Datei oder Zielordner nicht erreichbar
	 */
	private function moveAttachmentTo(ViewerContext $viewer, Attachment $attachment, Folder $target, string $location): void {
		$file = $this->folders->resolveFile($viewer->userId, (int)$attachment->getFileId());
		$name = $this->freeMoveName($target, $attachment->getFileName());

		$file->move($target->getPath() . '/' . $name);

		$moved = $target->get($name);
		$attachment->setFileId($moved->getId());
		$attachment->setFileName($name);
		$attachment->setFilePath($this->folders->displayPath($viewer->userId, $target) . '/' . $name);
		$attachment->setLocation($location);
		$this->attachments->update($attachment);
	}

	/**
	 * Die Anhänge eines Vorgangs — als flache Liste.
	 *
	 * `findForTickets()` liefert bereits eine flache `Attachment[]` (nicht nach
	 * Ticket-ID indiziert, anders als `countForTickets()`) — für eine
	 * Einermenge sind das genau die Anhänge dieses Vorgangs.
	 *
	 * @return Attachment[]
	 */
	private function attachmentsOf(Ticket $ticket): array {
		return $this->attachments->findForTickets([$ticket->getId()]);
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
	 * Die Anhänge eines Vorgangs, jeder mit der Angabe, ob seine Datei noch da
	 * ist (#9).
	 *
	 * **Anzeigen statt blockieren.** Ein Anhang, dessen Datei jemand im
	 * Dateibaum gelöscht oder weggeschoben hat, taucht mit `missing = true` auf
	 * und wird in der Liste als „nicht mehr vorhanden" gezeigt; die übrigen
	 * Dateioperationen bleiben unberührt (§3.10). Bis hierher fragte
	 * `nodeExists()` nur beim Anlegen nach dem freien Namen — für die Anzeige
	 * geschah die Prüfung nie.
	 *
	 * Die Anhänge kommen bereits über die **gefilterte** Ticketmenge herein
	 * (`findForTickets()`); hier wird nichts eigenständig abgefragt, nur der
	 * Dateizustand je Anhang nachgeschlagen.
	 *
	 * @param Attachment[] $attachments Die Anhänge des sichtbaren Vorgangs.
	 * @return array<int, array<string, mixed>>
	 */
	public function withPresence(ViewerContext $viewer, array $attachments): array {
		return array_map(
			fn (Attachment $attachment): array => $attachment->jsonSerialize() + [
				'missing' => !$this->folders->exists($viewer->userId, (int)$attachment->getFileId()),
			],
			$attachments,
		);
	}

	/**
	 * Der Ordner, in den ein Anhang dieses Vorgangs gehört.
	 *
	 * @throws NoFolderException
	 * @throws NotPermittedException
	 */
	private function folderFor(ViewerContext $viewer, Ticket $ticket): Folder {
		return $this->folderForVisibility($viewer, $ticket, (string)$ticket->getVisibility());
	}

	/**
	 * Der Zielordner für eine **gedachte** Sichtbarkeit des Vorgangs (#185).
	 *
	 * Beim Umzug wird der Ordner der Ziel-Sichtbarkeit gebraucht, nicht der der
	 * aktuellen. Sonst identisch zu {@see folderFor()}: dieselben zwei Gründe für
	 * „kein Ordner", dieselbe Auflösung über den Baum der handelnden Person.
	 *
	 * @throws NoFolderException      Für diese Sichtbarkeit gibt es keinen Ablageort
	 * @throws NotPermittedException  Ordner nicht erreichbar oder nicht beschreibbar
	 */
	private function folderForVisibility(ViewerContext $viewer, Ticket $ticket, string $visibility): Folder {
		$location = $this->folders->locationForVisibility($visibility, (string)$ticket->getCreatorRole());

		if ($location === null) {
			// Bleibt seit #184 nur noch ein Fall: der **interne** Vorgang der
			// Kundenseite. `91_Tickets_intern` wäre genau der Ordner, den sie
			// nicht sehen darf — ein Anhang dort wäre für sie selbst unlesbar.
			// (Der private Vorgang hat jetzt einen Ablageort, siehe unten.)
			throw new NoFolderException(
				'An internen Vorgängen der Kundenseite sind keine Anhänge möglich.',
			);
		}

		// **Der private Ablageort ist kein Board-Ordner** (#184, Phase B): Ein
		// „Nur ich"-Vorgang gehört einer Person, nicht dem Board. Seine Datei
		// liegt im persönlichen Ordner dieser Person, aufgelöst (und bei Bedarf
		// angelegt) in ihrem eigenen Files-Bereich — nicht über eine
		// Board-Ordner-ID.
		if ($location === Attachment::LOCATION_PRIVATE) {
			return $this->folders->privateFolderFor($viewer->userId);
		}

		$folderId = $this->folders->folderIdFor($this->projects->findForViewer($viewer), $location);

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
	 * Ein freier Name im Zielordner beim **Umzug** (#185).
	 *
	 * Anders als {@see freeName()} setzt diese Fassung **keinen** Präfix davor:
	 * Der Name trägt die Vorgangsnummer schon (er wurde beim Anhängen so
	 * gebildet). Sie sorgt nur dafür, dass er im Zielordner nicht mit einer
	 * bereits dort liegenden Datei kollidiert — dann wird wie beim Anhängen
	 * gezählt (`0042_scan_2.pdf`) statt überschrieben.
	 *
	 * @throws NotPermittedException
	 */
	private function freeMoveName(Folder $target, string $name): string {
		if (!$target->nodeExists($name)) {
			return $name;
		}

		$punkt = strrpos($name, '.');
		$stamm = $punkt === false || $punkt === 0 ? $name : substr($name, 0, $punkt);
		$endung = $punkt === false || $punkt === 0 ? '' : substr($name, $punkt);

		for ($n = 2; $n <= self::MAX_ATTEMPTS; $n++) {
			$kandidat = $stamm . '_' . $n . $endung;

			if (!$target->nodeExists($kandidat)) {
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
