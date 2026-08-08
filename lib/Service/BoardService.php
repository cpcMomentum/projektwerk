<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCP\IDBConnection;

/**
 * Projekte anlegen und ihre Einstellungen pflegen.
 *
 * **Die Einstellungen gehören internen Mitgliedern mit Verwaltungsrecht** (§8).
 * Die Prüfung steht in {@see assertManager()} und in keiner zweiten Methode —
 * ein zweiter Ort wäre der, an dem sie beim nächsten Feld vergessen wird.
 */
class BoardService {

	public function __construct(
		private IDBConnection $db,
		private BoardMapper $boards,
		private MemberMapper $members,
		private BoardAccess $access,
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

			$this->db->commit();

			return $board;
		} catch (\Throwable $e) {
			$this->db->rollBack();

			throw $e;
		}
	}

	/**
	 * Titel, Beschreibung, die beiden Firmennamen und die Chat-Adresse.
	 *
	 * Der Dateiablage-Teil der Einstellungen fehlt hier bewusst — er kommt mit
	 * Phase 5, wo Anhänge zum ersten Mal einen Ordner brauchen.
	 *
	 * @param array{title?: string, description?: ?string, orgInternal?: ?string, orgExternal?: ?string, chatUrl?: ?string} $changes
	 * @throws NotManagerException
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
