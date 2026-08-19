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
			$board = new Board();
			$board->setTitle(trim($title));
			$board->setDescription($description);
			$board->setOwnerUserId($userId);
			$board->setOrgInternal($this->trimOrNull($orgInternal));
			$board->setOrgExternal($this->trimOrNull($orgExternal));
			$board->setArchived(0);
			$board->setCreatedAt($now);
			$board->setUpdatedAt($now);
			$board = $this->boards->insert($board);

			$member = new Member();
			$member->setBoardId((int)$board->getId());
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
			// Reine Adresse für den Knopf „Zum Projektchat". Leer heißt: Knopf
			// entfällt ersatzlos — kein Hinweis, keine Einrichtungsaufforderung.
			$board->setChatUrl($this->trimOrNull($changes['chatUrl']));
		}
		if (array_key_exists('folderPublicPath', $changes)) {
			$this->setFolder($viewer, $board, Attachment::LOCATION_PUBLIC, $changes['folderPublicPath']);
		}
		if (array_key_exists('folderInternalPath', $changes)) {
			$this->setFolder($viewer, $board, Attachment::LOCATION_INTERNAL, $changes['folderInternalPath']);
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

		return $this->boards->update($board);
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
	private function setFolder(ViewerContext $viewer, Board $board, string $location, ?string $path): void {
		$intern = $location === Attachment::LOCATION_INTERNAL;

		if ($path === null || trim($path) === '') {
			$intern ? $board->setFolderInternalId(null) : $board->setFolderPublicId(null);
			$intern ? $board->setFolderInternalPath(null) : $board->setFolderPublicPath(null);

			return;
		}

		$folder = $this->folders->resolvePath($viewer->userId, $path);
		// **Nicht der eingetippte Pfad, sondern der aufgelöste.** Wer
		// `/Projekte//Kunde A/` einträgt, soll danach `Projekte/Kunde A` lesen —
		// sonst steht in den Einstellungen eine Schreibweise, die es so im
		// Dateibaum nicht gibt, und beim nächsten Vergleich stimmt nichts.
		$clean = $this->folders->displayPath($viewer->userId, $folder);

		$intern ? $board->setFolderInternalId($folder->getId()) : $board->setFolderPublicId($folder->getId());
		$intern ? $board->setFolderInternalPath($clean) : $board->setFolderPublicPath($clean);
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
