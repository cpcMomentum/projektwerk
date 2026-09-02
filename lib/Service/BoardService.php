<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Attachment;
use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Column;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\Project;
use OCA\Projektwerk\Db\ProjectMapper;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Projekte anlegen und ihre Einstellungen pflegen.
 *
 * **Die Einstellungen gehören internen Mitgliedern mit Verwaltungsrecht** (§8).
 * Die Prüfung steht in {@see assertManager()} und in keiner zweiten Methode —
 * ein zweiter Ort wäre der, an dem sie beim nächsten Feld vergessen wird.
 */
class BoardService {

	/**
	 * Die Spalten, die ein neues Projekt mitbringt.
	 *
	 * **Die ersten drei Spalten sind eine Leiter der Verbindlichkeit:**
	 * „wir haben es" (Eingegangen), „wir machen es" (Bestätigt), „wir wissen
	 * wann" (Eingeplant). Auf einem geteilten Board meldet der Kunde etwas, und
	 * jede dieser Stufen ist ein Vorgang, den jemand auslöst — fielen sie
	 * zusammen, sagte schon das Anlegen eines Tickets eine Zusage zu, die
	 * niemand gegeben hat.
	 *
	 * **„Bestätigt" heißt nicht „Backlog".** Der Begriff bezeichnet
	 * üblicherweise den unsortierten Eingangsstapel — also die *erste* Spalte.
	 * An zweiter Stelle läse ihn falsch herum, wer ihn kennt, und gar nicht,
	 * wer ihn nicht kennt.
	 *
	 * Alle sechs sind deutsch und ohne Fachjargon: Der Kunde sieht dieselben
	 * Spalten (§8, keine kundenspezifischen Spaltennamen), und §7 benennt
	 * „nach dem Publikum, nicht nach der Technik".
	 *
	 * Keine Spalte heißt „Wartet auf Kunde". Der Wartezustand liegt laut §9
	 * **quer zu den Spalten** und ist ein Filterschalter, kein Ort.
	 *
	 * @var string[]
	 */
	public const DEFAULT_COLUMNS = ['Eingegangen', 'Bestätigt', 'Eingeplant', 'In Arbeit', 'Erledigt', 'Verworfen'];

	public function __construct(
		private IDBConnection $db,
		private BoardMapper $boards,
		private ProjectMapper $projects,
		private MemberMapper $members,
		private ColumnMapper $columns,
		private BoardAccess $access,
		private IL10N $l10n,
		private ProjectFolderService $folders,
	) {
	}

	/**
	 * Ein neues Projekt.
	 *
	 * **Board und erste Mitgliedschaft entstehen in einer Transaktion.** Ohne
	 * sie könnte ein Board ohne Mitglied zurückbleiben — und weil es keine
	 * Admin-Ausnahme gibt, käme danach niemand mehr heran. Es wäre für immer
	 * unerreichbar und ließe sich nicht einmal löschen.
	 *
	 * Wer anlegt, wird Eigentümer und internes Mitglied mit Verwaltungsrecht.
	 * Eine Rechteprüfung gibt es davor nicht: Ein eigenes Projekt anzulegen
	 * setzt nichts voraus.
	 *
	 * Die Spalten aus {@see DEFAULT_COLUMNS} entstehen gleich mit — **einmalig
	 * übersetzt in der Sprache der anlegenden Person**, danach sind es normale
	 * Daten und jederzeit umbenennbar. Bei jeder Anzeige zu übersetzen wäre
	 * falsch: Dann sähe ein englischsprachiger Kunde andere Spalten als die
	 * interne Seite, und §8 verlangt ausdrücklich, dass beide Seiten dieselben
	 * sehen.
	 */
	public function create(
		string $userId,
		string $title,
		?string $description = null,
		?string $orgInternal = null,
		?string $orgExternal = null,
	): Board {
		$this->assertTitle($title);
		$now = new \DateTime();

		$this->db->beginTransaction();

		try {
			// #246: Das Projekt-Dach zuerst — ein Board ohne Projekt hätte
			// Tickets mit `project_id = NULL`, die der (jetzt projekt-scoped)
			// Sichtbarkeitsverbund nicht mehr fände. Vorerst ein Projekt je Board
			// (Engagement-Felder kopiert); mehrere Boards unter einem Projekt legt
			// erst die eigentliche Projekt-Anlage in einem späteren PR an.
			$project = new Project();
			$project->setTitle(trim($title));
			$project->setDescription($description);
			$project->setOwnerUserId($userId);
			$project->setOrgInternal($this->trimOrNull($orgInternal));
			$project->setOrgExternal($this->trimOrNull($orgExternal));
			$project->setTicketCounter(0);
			$project->setArchived(0);
			$project->setCreatedAt($now);
			$project->setUpdatedAt($now);
			$project = $this->projects->insert($project);
			$projectId = (int)$project->getId();

			$board = new Board();
			$board->setTitle(trim($title));
			$board->setDescription($description);
			$board->setOwnerUserId($userId);
			$board->setOrgInternal($this->trimOrNull($orgInternal));
			$board->setOrgExternal($this->trimOrNull($orgExternal));
			$board->setArchived(0);
			$board->setProjectId($projectId);
			$board->setCreatedAt($now);
			$board->setUpdatedAt($now);
			$board = $this->boards->insert($board);

			$member = new Member();
			$member->setBoardId((int)$board->getId());
			$member->setProjectId($projectId);
			$member->setUserId($userId);
			$member->setRole(ViewerContext::ROLE_INTERNAL);
			$member->setIsManager(1);
			$member->setAddedBy($userId);
			$member->setAddedAt($now);
			$this->members->insert($member);

			foreach (self::DEFAULT_COLUMNS as $position => $columnTitle) {
				$column = new Column();
				$column->setBoardId((int)$board->getId());
				$column->setTitle($this->l10n->t($columnTitle));
				$column->setPosition($position);
				$this->columns->insert($column);
			}

			$this->db->commit();

			return $board;
		} catch (\Throwable $e) {
			$this->db->rollBack();

			throw $e;
		}
	}

	/**
	 * Ein **weiteres** Board in einem bestehenden Projekt anlegen (#246 PR 5).
	 *
	 * Der Unterschied zu {@see create()} ist der Kern von #246: Es entsteht
	 * **kein** neues Projekt und **keine** neue Mitgliedszeile. Das Board hängt
	 * am schon bestehenden Projekt des Betrachters; Mitglieder, Rollen, Ordner,
	 * Chat und der Nummernkreis teilen sich alle Boards des Projekts über
	 * `project_id` — genau deshalb ist die Mitgliedschaft seit PR 3 projekt-
	 * scoped. Nur die Kanban-Hülle (Board-Zeile + Standardspalten) kommt hinzu.
	 *
	 * Anlegen darf, wer das Projekt verwaltet — dieselbe Regel wie bei jeder
	 * Mitglieder- und Board-Pflege (§8), „so wie bisher".
	 *
	 * @throws NotManagerException
	 * @throws \InvalidArgumentException Titel leer
	 */
	public function createInProject(ViewerContext $viewer, string $title): Board {
		$this->assertManager($viewer);
		$this->assertTitle($title);
		$now = new \DateTime();

		$this->db->beginTransaction();

		try {
			// Org und Eigentümer stammen aus dem Projekt: Sie gelten projektweit,
			// ein zweites Board erbt sie, statt sie neu zu erfragen.
			$project = $this->projects->findForViewer($viewer);

			$board = new Board();
			$board->setTitle(trim($title));
			$board->setOwnerUserId((string)$project->getOwnerUserId());
			$board->setOrgInternal($project->getOrgInternal());
			$board->setOrgExternal($project->getOrgExternal());
			$board->setArchived(0);
			$board->setProjectId($viewer->projectId);
			$board->setCreatedAt($now);
			$board->setUpdatedAt($now);
			$board = $this->boards->insert($board);

			foreach (self::DEFAULT_COLUMNS as $position => $columnTitle) {
				$column = new Column();
				$column->setBoardId((int)$board->getId());
				$column->setTitle($this->l10n->t($columnTitle));
				$column->setPosition($position);
				$this->columns->insert($column);
			}

			$this->db->commit();

			return $board;
		} catch (\Throwable $e) {
			$this->db->rollBack();

			throw $e;
		}
	}

	/**
	 * Titel, Beschreibung, die beiden Firmennamen, die Chat-Adresse und die
	 * beiden Projektordner.
	 *
	 * Die Ordner sind seit Migration 1 als Spalten da, aber bis hierher hat sie
	 * nichts gesetzt — die Anhänge aus Phase 5 sind der erste Anlass. Ohne sie
	 * hätte ein Anhang keinen Ort, an den er gehört.
	 *
	 * @param array{title?: string, description?: ?string, orgInternal?: ?string, orgExternal?: ?string, chatUrl?: ?string, folderPublicPath?: ?string, folderInternalPath?: ?string, githubEnabled?: bool, githubRepo?: ?string} $changes
	 * @throws NotManagerException
	 * @throws \OCP\Files\NotPermittedException Ordner nicht erreichbar oder nicht beschreibbar
	 */
	public function update(ViewerContext $viewer, array $changes): Board {
		$this->assertManager($viewer);

		$board = $this->boards->findForViewer($viewer);
		// Ordner und Chat gehören seit #246 dem PROJEKT — eine Quelle, geteilt
		// über alle Boards des Projekts. Board-eigene Felder (Titel, Org,
		// GitHub) bleiben am Board.
		$project = $this->projects->findForViewer($viewer);
		$projectChanged = false;

		if (array_key_exists('title', $changes)) {
			$this->assertTitle($changes['title']);
			$board->setTitle(trim($changes['title']));
		}
		if (array_key_exists('description', $changes)) {
			$board->setDescription($changes['description']);
		}
		if (array_key_exists('orgInternal', $changes)) {
			$board->setOrgInternal($this->trimOrNull($changes['orgInternal']));
		}
		if (array_key_exists('orgExternal', $changes)) {
			$board->setOrgExternal($this->trimOrNull($changes['orgExternal']));
		}
		if (array_key_exists('chatUrl', $changes)) {
			// Reine Adresse für den Knopf „Zum Projektchat", am Projekt (#246).
			// Leer heißt: Knopf entfällt ersatzlos.
			$project->setChatUrl($this->trimOrNull($changes['chatUrl']));
			$projectChanged = true;
		}
		if (array_key_exists('folderPublicPath', $changes)) {
			$this->setFolder($viewer, $project, Attachment::LOCATION_PUBLIC, $changes['folderPublicPath']);
			$projectChanged = true;
		}
		if (array_key_exists('folderInternalPath', $changes)) {
			$this->setFolder($viewer, $project, Attachment::LOCATION_INTERNAL, $changes['folderInternalPath']);
			$projectChanged = true;
		}
		if (array_key_exists('githubEnabled', $changes)) {
			// SMALLINT 0/1, nie Types::BOOLEAN — siehe {@see Board}.
			$board->setGithubEnabled($changes['githubEnabled'] ? 1 : 0);
		}
		if (array_key_exists('githubRepo', $changes)) {
			// Reine Adresse „owner/repo" (#12). Leer heißt: kein Ziel — die
			// Überführungs-Aktion bleibt dann aus. Die Formatprüfung sitzt bewusst
			// erst beim Überführen ({@see GithubService}), damit ein aktiviertes
			// Board ohne fertig eingetragenes Repo ein zulässiger Zwischenzustand
			// bleibt.
			$board->setGithubRepo($this->trimOrNull($changes['githubRepo']));
		}

		$board->setUpdatedAt(new \DateTime());

		$this->db->beginTransaction();
		try {
			$saved = $this->boards->update($board);

			if ($projectChanged) {
				$project->setUpdatedAt(new \DateTime());
				$this->projects->update($project);
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();

			throw $e;
		}

		// Read-through: Die Antwort trägt Ordner und Chat aus dem Projekt. Das
		// Board serialisiert sie, die Quelle bleibt aber das Projekt (#246) —
		// nur in-memory, nicht persistiert.
		$this->hydrateProjectFields($saved, $project);

		return $saved;
	}

	/**
	 * Das Board für die Anzeige — mit Ordner und Chat aus dem Projekt (#246).
	 *
	 * Der eine Lesepfad für `board#show`: Er liefert das Board membership-gated
	 * ({@see BoardMapper::findForViewer()}) und spiegelt die fünf Projekt-Felder
	 * hinein, sodass die Einstellungen die projektweiten Werte sehen. So bleibt
	 * die Autorität für Ordner und Chat an EINER Stelle (dem Projekt), auch wenn
	 * das Board sie in der API-Antwort trägt.
	 */
	public function forViewerWithProjectFields(ViewerContext $viewer): Board {
		$board = $this->boards->findForViewer($viewer);
		$this->hydrateProjectFields($board, $this->projects->findForViewer($viewer));

		return $board;
	}

	/**
	 * Ordner und Chat aus dem Projekt in das Board-Objekt spiegeln — allein für
	 * die Serialisierung (#246). Nicht persistiert — die Board-Spalten bleiben
	 * unberührt.
	 */
	private function hydrateProjectFields(Board $board, Project $project): void {
		$board->setChatUrl($project->getChatUrl());
		$board->setFolderPublicId($project->getFolderPublicId());
		$board->setFolderPublicPath($project->getFolderPublicPath());
		$board->setFolderInternalId($project->getFolderInternalId());
		$board->setFolderInternalPath($project->getFolderInternalPath());
	}

	/**
	 * Archivieren und zurückholen.
	 *
	 * Archiviert heißt: aus der Startseite verschwunden, nicht gelöscht. Die
	 * App löscht auch hier nicht — dieselbe Zusage wie bei den Anhängen.
	 *
	 * @throws NotManagerException
	 */
	public function setArchived(ViewerContext $viewer, bool $archived): Board {
		$this->assertManager($viewer);

		$board = $this->boards->findForViewer($viewer);
		$board->setArchived($archived ? 1 : 0);
		$board->setUpdatedAt(new \DateTime());

		return $this->boards->update($board);
	}

	/**
	 * @throws NotManagerException
	 */
	private function assertManager(ViewerContext $viewer): void {
		if (!$viewer->isManager) {
			throw new NotManagerException(
				'Die Board-Einstellungen dürfen nur interne Mitglieder mit Verwaltungsrecht ändern.',
			);
		}
	}

	private function assertTitle(string $title): void {
		if (trim($title) === '') {
			throw new \InvalidArgumentException('Ein Projekt braucht einen Titel.');
		}
	}

	/**
	 * Einen der beiden Projektordner setzen oder entfernen.
	 *
	 * **Der Pfad wird mitgeschrieben, aber nie gelesen, um irgendwohin zu
	 * gelangen** — dafür ist die ID da (§5.18). Er steht in den Einstellungen,
	 * damit dort „90_Austausch" steht und nicht eine Zahl; veraltet er nach
	 * einem Umbenennen, ist das eine falsche Beschriftung und kein
	 * gerissener Verweis.
	 *
	 * `null` entfernt die Zuordnung. Ein Board ohne Ordner ist ein
	 * gültiger Zustand, kein halb eingerichtetes — es hat dann schlicht keine
	 * Anhänge (§3.10).
	 *
	 * @throws \OCP\Files\NotPermittedException Ordner nicht erreichbar oder nicht beschreibbar
	 */
	private function setFolder(ViewerContext $viewer, Project $project, string $location, ?string $path): void {
		$intern = $location === Attachment::LOCATION_INTERNAL;

		if ($path === null || trim($path) === '') {
			$intern ? $project->setFolderInternalId(null) : $project->setFolderPublicId(null);
			$intern ? $project->setFolderInternalPath(null) : $project->setFolderPublicPath(null);

			return;
		}

		$folder = $this->folders->resolvePath($viewer->userId, $path);
		// **Nicht der eingetippte Pfad, sondern der aufgelöste.** Wer
		// `/Projekte//Kunde A/` einträgt, soll danach `Projekte/Kunde A` lesen —
		// sonst steht in den Einstellungen eine Schreibweise, die es so im
		// Dateibaum nicht gibt, und beim nächsten Vergleich stimmt nichts.
		$clean = $this->folders->displayPath($viewer->userId, $folder);

		$intern ? $project->setFolderInternalId($folder->getId()) : $project->setFolderPublicId($folder->getId());
		$intern ? $project->setFolderInternalPath($clean) : $project->setFolderPublicPath($clean);
	}

	private function trimOrNull(?string $value): ?string {
		if ($value === null) {
			return null;
		}

		$trimmed = trim($value);

		// Ein leeres Feld ist kein leerer String, sondern „nicht gesetzt".
		// Sonst müsste jede Anzeige zwei Formen von „nichts" unterscheiden.
		return $trimmed === '' ? null : $trimmed;
	}
}
