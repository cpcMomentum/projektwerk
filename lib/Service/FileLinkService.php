<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\FileLink;
use OCA\Projektwerk\Db\FileLinkMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketLog;
use OCA\Projektwerk\Db\TicketMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Verknüpfte Dateien und Ordner: Das Ziel bleibt liegen, wer es öffnen darf, entscheidet allein Nextcloud.
 */
class FileLinkService {

	public function __construct(
		private FileLinkMapper $links,
		private TicketMapper $tickets,
		private IRootFolder $root,
		private IURLGenerator $urls,
		private TicketLogService $log,
		private IDBConnection $db,
		private IL10N $l10n,
	) {
	}

	/**
	 * @throws DoesNotExistException Vorgang nicht sichtbar
	 * @throws NotPermittedException Pfad nicht erreichbar
	 * @return array<string, mixed>
	 */
	public function link(ViewerContext $viewer, int $ticketId, string $path): array {
		$ticket = $this->tickets->findVisible($viewer, $ticketId);
		$node = $this->nodeAt($viewer->userId, $path);

		foreach ($this->linksOf($ticket) as $existing) {
			if ((int)$existing->getFileId() === $node->getId()) {
				return $this->present($viewer, $existing, $node);
			}
		}

		$link = new FileLink();
		$link->setTicketId((int)$ticket->getId());
		$link->setFileId($node->getId());
		$link->setFileName($node->getName());
		$link->setCreatedBy($viewer->userId);
		$link->setCreatedVia($viewer->via);
		$link->setCreatedAt(new \DateTime());

		try {
			$saved = $this->withLog($viewer, $ticket, TicketLog::FILE_LINKED, fn (): FileLink => $this->links->insert($link));
		} catch (Exception $e) {
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// Gleichzeitig dasselbe Ziel verknüpft: die bestehende Verknüpfung ist die Antwort.
			return $this->link($viewer, $ticketId, $path);
		}

		return $this->present($viewer, $saved, $node);
	}

	/** @throws DoesNotExistException Verknüpfung für diese Person nicht sichtbar */
	public function delete(ViewerContext $viewer, int $linkId): void {
		$ticketIds = array_map(
			static fn (Ticket $ticket): int => (int)$ticket->getId(),
			$this->tickets->findVisibleInBoard($viewer),
		);

		foreach ($this->links->findForTickets($ticketIds) as $link) {
			if ((int)$link->getId() === $linkId && $this->visibleEntry($viewer, $link) !== null) {
				$ticket = $this->tickets->findVisible($viewer, (int)$link->getTicketId());
				$this->withLog($viewer, $ticket, TicketLog::FILE_UNLINKED, fn (): FileLink => $this->links->delete($link));

				return;
			}
		}

		throw new DoesNotExistException($this->l10n->t('Verknüpfung nicht gefunden.'));
	}

	/**
	 * Nur was diese Person in Nextcloud erreicht; ein gelöschtes Ziel nur für die verknüpfende Seite.
	 *
	 * @param FileLink[] $links aus der bereits gefilterten Ticketmenge
	 * @return list<array<string, mixed>>
	 */
	public function forViewer(ViewerContext $viewer, array $links): array {
		return array_values(array_filter(array_map(
			fn (FileLink $link): ?array => $this->visibleEntry($viewer, $link),
			$links,
		)));
	}

	/** @return array<string, mixed>|null */
	private function visibleEntry(ViewerContext $viewer, FileLink $link): ?array {
		try {
			$node = $this->root->getUserFolder($viewer->userId)->getFirstNodeById((int)$link->getFileId());
		} catch (\Throwable) {
			// Ohne greifbaren Dateibaum lässt sich der Zugriff nicht prüfen, also nichts zeigen.
			return null;
		}
		if ($node !== null) {
			return $this->present($viewer, $link, $node);
		}
		// Ob das Ziel gelöscht ist oder nur fremd, lässt sich nicht sicher sagen; den Namen kennt nur, wer verknüpft hat.
		if ($link->getCreatedBy() !== $viewer->userId) {
			return null;
		}

		return $this->present($viewer, $link, null);
	}

	/** @return array<string, mixed> */
	private function present(ViewerContext $viewer, FileLink $link, ?Node $node): array {
		return [
			'id' => (int)$link->getId(),
			'ticketId' => (int)$link->getTicketId(),
			'fileId' => (int)$link->getFileId(),
			'name' => $node?->getName() ?? (string)$link->getFileName(),
			'path' => $node === null ? null : $this->displayPath($viewer->userId, $node),
			'type' => $node === null ? null : ($node instanceof Folder ? 'folder' : 'file'),
			'url' => $node === null ? null : $this->urls->linkToRouteAbsolute('files.View.showFile', ['fileid' => (int)$link->getFileId()]),
			'missing' => $node === null,
			'createdBy' => $link->getCreatedBy(),
			'createdVia' => $link->getCreatedVia(),
			'createdAt' => $link->getCreatedAt()?->format(\DateTime::ATOM),
		];
	}

	/** @throws NotPermittedException */
	private function nodeAt(string $userId, string $path): Node {
		$clean = trim($path, " \t\n\r\0\x0B/");
		if ($clean === '') {
			throw new NotPermittedException($this->l10n->t('Bitte eine Datei oder einen Ordner angeben.'));
		}

		try {
			$node = $this->root->getUserFolder($userId)->get($clean);
		} catch (NotFoundException) {
			throw new NotPermittedException($this->l10n->t('Diese Datei oder dieser Ordner ist nicht erreichbar.'));
		}
		if (!$node->isReadable()) {
			throw new NotPermittedException($this->l10n->t('Diese Datei oder dieser Ordner ist nicht erreichbar.'));
		}

		return $node;
	}

	private function displayPath(string $userId, Node $node): string {
		$base = $this->root->getUserFolder($userId)->getPath();
		$path = $node->getPath();

		return str_starts_with($path, $base . '/') ? substr($path, strlen($base) + 1) : ltrim($path, '/');
	}

	/** Ohne Dateinamen im Verlauf: den lesen auch Personen ohne Zugriff auf das Ziel. */
	private function withLog(ViewerContext $viewer, Ticket $ticket, string $action, callable $write): FileLink {
		$this->db->beginTransaction();
		try {
			$result = $write();
			$this->log->record($viewer, $ticket, $action);
			$this->db->commit();

			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();

			throw $e;
		}
	}

	/** @return FileLink[] */
	private function linksOf(Ticket $ticket): array {
		return $this->links->findForTickets([(int)$ticket->getId()]);
	}
}
