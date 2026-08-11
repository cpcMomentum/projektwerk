<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Attachment;
use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\Ticket;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;

/**
 * Die beiden Projektordner eines Boards — auflösen, prüfen, zuordnen.
 *
 * **Der Ablageort IST die Sichtbarkeit** (§5.18). `90_Austausch` sieht die
 * Kundenseite, `91_Tickets_intern` nicht; welcher der beiden Ordner für einen
 * Vorgang zuständig ist, folgt damit unmittelbar aus seiner Sichtbarkeit und
 * darf nirgends zweitgeprüft werden. Genau diese Zuordnung steht in
 * {@see locationFor()} — an **einer** Stelle, wie die Sichtbarkeitsregel
 * selbst.
 *
 * **Die Ordner-ID kommt vom Browser und wird nicht geglaubt.** Sie wird über
 * den Dateibaum der handelnden Person aufgelöst; was dort nicht auftaucht,
 * existiert für sie nicht. Damit kann niemand eine fremde Ordner-ID an einem
 * Board hinterlegen — die Prüfung ist dieselbe, die Nextcloud beim Öffnen
 * anwendet, nicht eine zweite eigene.
 *
 * Kein `IAppData`, kein eigener Share-Provider, **keine von der App angelegten
 * Freigaben**: Die Ordner gehören dem Team-Ordner des Projekts, und wer sie
 * sehen darf, entscheidet Nextcloud.
 */
class ProjectFolderService {

	public function __construct(
		private IRootFolder $root,
	) {
	}

	/**
	 * Der zuständige Ordner für einen Vorgang — oder `null`.
	 *
	 * `null` heißt **nicht** „Fehler", sondern „für diesen Vorgang gibt es
	 * keinen Ablageort": Ein interner Vorgang der Kundenseite und ein
	 * `private`-Vorgang haben keinen, und das ist keine Lücke, sondern die
	 * Zusage. Für sie gibt es folgerichtig auch keine Anhänge (§3.10).
	 */
	public function locationFor(Ticket $ticket): ?string {
		return match ((string)$ticket->getVisibility()) {
			TicketScope::VISIBILITY_PUBLIC => Attachment::LOCATION_PUBLIC,
			// Nur die Dienstleisterseite hat einen internen Ordner. Für die
			// Kundenseite wäre `91_Tickets_intern` genau der Ordner, den sie
			// nicht sehen darf — ein Anhang dort wäre für sie selbst unlesbar.
			TicketScope::VISIBILITY_INTERNAL => (string)$ticket->getCreatorRole() === ViewerContext::ROLE_INTERNAL
				? Attachment::LOCATION_INTERNAL
				: null,
			default => null,
		};
	}

	/**
	 * Die am Board hinterlegte Ordner-ID für einen Ablageort.
	 */
	public function folderIdFor(Board $board, string $location): ?int {
		$id = $location === Attachment::LOCATION_INTERNAL
			? $board->getFolderInternalId()
			: $board->getFolderPublicId();

		return $id === null ? null : (int)$id;
	}

	/**
	 * Eine Ordner-ID im Dateibaum dieser Person auflösen.
	 *
	 * Wird beim Anhängen gebraucht, wo die ID aus der Datenbank kommt.
	 *
	 * @throws NotPermittedException Kein Ordner, nicht erreichbar oder nicht beschreibbar
	 */
	public function resolve(string $userId, int $fileId): Folder {
		// `getFirstNodeById` statt `getById`: Dieselbe Datei kann über mehrere
		// Freigaben mehrfach im Baum hängen. Für die Frage „darf diese Person
		// dorthin schreiben" ist ein Treffer genau so gut wie alle.
		return $this->assertUsable($this->root->getUserFolder($userId)->getFirstNodeById($fileId));
	}

	/**
	 * Einen Ordner über seinen Pfad auflösen.
	 *
	 * **Der Pfad ist der Eingabeweg, die ID bleibt der Speicherweg.** Gespeichert
	 * wird, was {@see Folder::getId()} liefert; der Pfad dient nur dazu, den
	 * Ordner überhaupt zu benennen. Wer ihn danach umbenennt, lässt eine
	 * Beschriftung veralten, keine Verknüpfung reißen (§5.18).
	 *
	 * Dass hier ein Pfad steht und nicht Nextclouds Dateiwähler, ist eine
	 * Einschränkung des Werkzeugs, nicht der Bauform: `@nextcloud/dialogs` lädt
	 * die Auswahl über `import()` nach, und ein IIFE-Bundle verträgt keine
	 * Codeaufteilung (Vite 8/Rolldown, 11.08.2026). Ein Wähler lässt sich später
	 * davorsetzen, ohne ein einziges gespeichertes Feld anzufassen.
	 *
	 * @throws NotPermittedException Kein Ordner, nicht erreichbar oder nicht beschreibbar
	 */
	public function resolvePath(string $userId, string $path): Folder {
		$clean = trim($path, " \t\n\r\0\x0B/");

		if ($clean === '') {
			throw new NotPermittedException('Bitte einen Ordner angeben.');
		}

		try {
			$node = $this->root->getUserFolder($userId)->get($clean);
		} catch (\OCP\Files\NotFoundException) {
			// Dieselbe Meldung wie für „ist kein Ordner": Ob es den Pfad
			// anderswo gibt, geht die fragende Person nichts an.
			$node = null;
		}

		return $this->assertUsable($node);
	}

	/**
	 * @throws NotPermittedException
	 */
	private function assertUsable(?\OCP\Files\Node $node): Folder {
		if (!$node instanceof Folder) {
			// Eine Datei statt eines Ordners landet hier ebenso wie ein Pfad, den
			// es nicht gibt oder den diese Person nicht erreicht — **dieselbe**
			// Meldung für alle drei. Eine genauere Auskunft verriete, ob es den
			// Ordner anderswo gibt.
			throw new NotPermittedException('Dieser Ordner ist nicht erreichbar.');
		}

		if (!$node->isCreatable()) {
			// Ein Ordner ohne Schreibrecht wäre erst beim ersten Anhang
			// aufgefallen — und dann bei der Person, die anhängt, nicht bei der,
			// die ihn eingetragen hat.
			throw new NotPermittedException('In diesen Ordner darf nicht geschrieben werden.');
		}

		return $node;
	}

	/**
	 * Der Anzeigepfad eines Ordners, ohne den Präfix des Dateibaums.
	 *
	 * Nur zur Anzeige — führend ist die ID (§5.18). Wer den Ordner umbenennt
	 * oder verschiebt, lässt diesen Pfad veralten, und das ist eingeplant: Die
	 * Verknüpfung hängt nicht daran.
	 */
	public function displayPath(string $userId, Folder $folder): string {
		$base = $this->root->getUserFolder($userId)->getPath();
		$path = $folder->getPath();

		return str_starts_with($path, $base . '/')
			? substr($path, strlen($base) + 1)
			: ltrim($path, '/');
	}
}
