<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Attachment;
use OCA\Projektwerk\Db\Project;
use OCA\Projektwerk\Db\Ticket;
use OCP\Config\IUserConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
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

	/** Einstellungs-Schlüssel des persönlichen Ordners je Person (#184). */
	private const PRIVATE_FOLDER_KEY = 'private_attachment_folder';

	/**
	 * Der Ordner, in dem private Anhänge landen, solange die Person keinen
	 * eigenen gewählt hat — ein Ordner in **ihren** Files, kein Team-Ordner.
	 * Wird beim ersten privaten Anhang angelegt, falls er fehlt.
	 */
	public const DEFAULT_PRIVATE_FOLDER = 'ProjektWerk';

	public function __construct(
		private IRootFolder $root,
		private IUserConfig $userConfig,
	) {
	}

	/**
	 * Der zuständige Ordner für einen Vorgang — oder `null`.
	 *
	 * `null` heißt **nicht** „Fehler", sondern „für diesen Vorgang gibt es
	 * keinen Ablageort": Ein interner Vorgang der Kundenseite hat keinen, und
	 * das ist keine Lücke, sondern die Zusage. Für ihn gibt es folgerichtig
	 * auch keine Anhänge (§3.10). Ein `private`-Vorgang hat seit #184 (Phase B)
	 * einen eigenen — den persönlichen Ordner der anlegenden Person.
	 */
	public function locationFor(Ticket $ticket): ?string {
		return $this->locationForVisibility(
			(string)$ticket->getVisibility(),
			(string)$ticket->getCreatorRole(),
		);
	}

	/**
	 * Derselbe Ablageort, aber für eine **gedachte** Sichtbarkeit.
	 *
	 * Beim Umzug (#185) braucht die App den Zielordner **vor** dem Wechsel — also
	 * für die Sichtbarkeit, die der Vorgang gleich haben wird, nicht für die, die
	 * er hat. Die Erzeugerrolle bleibt dabei dieselbe: Ein Sichtbarkeitswechsel
	 * ändert nicht, wer den Vorgang angelegt hat.
	 *
	 * `null` heißt wie bei {@see locationFor()} „für diese Sichtbarkeit gibt es
	 * keinen Ablageort" — die Kundenseite hat keinen internen Ordner, und
	 * `private` hat (bis Phase B, #184) gar keinen.
	 */
	public function locationForVisibility(string $visibility, string $creatorRole): ?string {
		return match ($visibility) {
			TicketScope::VISIBILITY_PUBLIC => Attachment::LOCATION_PUBLIC,
			// Nur die Dienstleisterseite hat einen internen Ordner. Für die
			// Kundenseite wäre `91_Tickets_intern` genau der Ordner, den sie
			// nicht sehen darf — ein Anhang dort wäre für sie selbst unlesbar.
			TicketScope::VISIBILITY_INTERNAL => $creatorRole === ViewerContext::ROLE_INTERNAL
				? Attachment::LOCATION_INTERNAL
				: null,
			// Der private Ablageort (#184, Phase B): kein Team-Ordner, sondern
			// der persönliche Ordner der anlegenden Person — aufgelöst über
			// {@see privateFolderFor()}, nicht über eine Board-Ordner-ID.
			TicketScope::VISIBILITY_PRIVATE => Attachment::LOCATION_PRIVATE,
			default => null,
		};
	}

	/**
	 * Die am **Projekt** hinterlegte Ordner-ID für einen Ablageort (#246 PR 5).
	 *
	 * Der Ablageort gehört seit #246 dem Projekt, nicht dem einzelnen Board:
	 * Alle Boards eines Projekts legen in denselben Austausch- und Intern-Ordner
	 * ab. Der Aufrufer besorgt das Projekt über
	 * {@see \OCA\Projektwerk\Db\ProjectMapper::findForViewer()}.
	 */
	public function folderIdFor(Project $project, string $location): ?int {
		$id = $location === Attachment::LOCATION_INTERNAL
			? $project->getFolderInternalId()
			: $project->getFolderPublicId();

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
	 * Die **Datei** eines Anhangs im Baum dieser Person auflösen.
	 *
	 * Wie {@see resolve()}, nur für eine Datei statt eines Ordners — gebraucht
	 * beim Umzug (#185), wo die Datei-ID aus der Datenbank kommt. Dieselbe
	 * Sichtweise: Was diese Person nicht erreicht, existiert für sie nicht, und
	 * ein Treffer über eine fremde Freigabe hülfe ihr nicht.
	 *
	 * @throws NotPermittedException Keine Datei oder nicht erreichbar
	 */
	public function resolveFile(string $userId, int $fileId): File {
		$node = $this->root->getUserFolder($userId)->getFirstNodeById($fileId);

		if (!$node instanceof File) {
			throw new NotPermittedException('Diese Datei ist nicht erreichbar.');
		}

		return $node;
	}

	/**
	 * Der persönliche Ordner einer Person für ihre privaten Anhänge (#184).
	 *
	 * Kein Team-Ordner: ein Ordner in **ihren eigenen** Files, dessen Reichweite
	 * exakt „nur diese Person" ist — so wie es die Sichtbarkeit `private`
	 * verlangt (§5.18). Der Pfad steht in einer Nutzereinstellung; ohne Wahl gilt
	 * {@see DEFAULT_PRIVATE_FOLDER}. Fehlt der Ordner, wird er angelegt: Ein
	 * privater Anhang soll ohne Vorabkonfiguration funktionieren — anders als der
	 * Team-Ordner, den die Projektverwaltung eintragen muss.
	 *
	 * @throws NotPermittedException Kein Ordner, nicht erreichbar oder nicht beschreibbar.
	 */
	public function privateFolderFor(string $userId): Folder {
		return $this->resolveOrCreatePrivate($userId, $this->privatePath($userId));
	}

	/**
	 * Der eingestellte Pfad des persönlichen Ordners — oder die Vorgabe.
	 *
	 * @return string bereinigt, nie leer.
	 */
	public function privatePath(string $userId): string {
		$raw = trim(
			$this->userConfig->getValueString($userId, Application::APP_ID, self::PRIVATE_FOLDER_KEY, ''),
			" \t\n\r\0\x0B/",
		);

		return $raw === '' ? self::DEFAULT_PRIVATE_FOLDER : $raw;
	}

	/**
	 * Den persönlichen Ordner wählen — geprüft, bei Bedarf angelegt, dann gemerkt.
	 *
	 * Wie beim Team-Ordner ist der Pfad der Eingabeweg. Er wird sofort aufgelöst:
	 * Ein unbeschreibbarer oder unmöglicher Ordner fällt hier auf, bei der
	 * Person, die ihn wählt — nicht erst beim nächsten privaten Anhang.
	 *
	 * @throws NotPermittedException Kein Ordner, nicht erreichbar oder nicht beschreibbar.
	 */
	public function setPrivatePath(string $userId, string $path): void {
		$clean = trim($path, " \t\n\r\0\x0B/");
		if ($clean === '') {
			$clean = self::DEFAULT_PRIVATE_FOLDER;
		}

		$this->resolveOrCreatePrivate($userId, $clean);
		$this->userConfig->setValueString($userId, Application::APP_ID, self::PRIVATE_FOLDER_KEY, $clean);
	}

	/**
	 * Den persönlichen Ordner unter `$clean` auflösen — oder anlegen.
	 *
	 * @throws NotPermittedException
	 */
	private function resolveOrCreatePrivate(string $userId, string $clean): Folder {
		$home = $this->root->getUserFolder($userId);

		try {
			$node = $home->get($clean);
		} catch (NotFoundException) {
			$node = $home->newFolder($clean);
		}

		return $this->assertUsable($node);
	}

	/**
	 * Gibt es die Datei mit dieser Kennung im Baum dieser Person noch? (#9)
	 *
	 * **Nicht werfend, anders als {@see resolve()}.** Hier ist das Fehlen die
	 * Antwort, nicht der Fehler: Ein Anhang, dessen Datei jemand im Dateibaum
	 * gelöscht oder weggeschoben hat, soll in der Liste als „nicht mehr
	 * vorhanden" stehen, statt jede weitere Dateioperation zu blockieren.
	 *
	 * Dieselbe Sichtweise wie beim Öffnen — der Baum **dieser** Person: Was sie
	 * nicht erreichen kann, ist für sie so gut wie weg, und ein Treffer über
	 * eine fremde Freigabe hülfe ihr nicht.
	 */
	public function exists(string $userId, int $fileId): bool {
		try {
			return $this->root->getUserFolder($userId)->getFirstNodeById($fileId) !== null;
		} catch (\Throwable) {
			// Lässt sich der Dateibaum dieser Person gerade nicht auflösen, ist
			// das **kein** Beleg, dass die Datei fehlt. Ein echtes Fehlen ist der
			// `null`-Treffer oben; hier ist der Baum selbst nicht greifbar. Dann
			// wie bisher den Link zeigen, statt fälschlich „nicht mehr vorhanden"
			// zu behaupten — die unangenehmere Falschaussage von beiden.
			return true;
		}
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
