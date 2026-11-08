<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Reference;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\NotAMemberException;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\ProjectMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Search\TicketSearchProvider;
use OCA\Projektwerk\Service\MemberService;
use OCA\Projektwerk\Service\TicketLink;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Vorschaukarte für Links auf einen Vorgang, etwa in Talk oder Text.
 */
class TicketReferenceProvider extends ADiscoverableReferenceProvider implements ISearchableReferenceProvider {

	public const RICH_OBJECT_TYPE = 'projektwerk-ticket';

	public function __construct(
		private IURLGenerator $urls,
		private TicketLink $links,
		private IL10N $l10n,
		private TicketMapper $tickets,
		private BoardAccess $access,
		private BoardMapper $boards,
		private ProjectMapper $projects,
		private ColumnMapper $columns,
		private MemberService $members,
		private IUserSession $session,
	) {
	}

	public function getId(): string {
		return self::RICH_OBJECT_TYPE;
	}

	public function getTitle(): string {
		return $this->l10n->t('ProjektWerk-Vorgänge');
	}

	public function getOrder(): int {
		return 50;
	}

	public function getIconUrl(): string {
		return $this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function getSupportedSearchProviderIds(): array {
		return [TicketSearchProvider::ID];
	}

	public function matchReference(string $referenceText): bool {
		return $this->ticketIdFrom($referenceText) !== null;
	}

	public function resolveReference(string $referenceText): ?IReference {
		$ticketId = $this->ticketIdFrom($referenceText);
		$userId = $this->currentUserId();
		if ($ticketId === null || $userId === null) {
			return null;
		}

		// Unbekannt, verborgen und fremd sehen gleich aus: Talk zeigt dann nur die URL.
		try {
			$ticket = $this->tickets->findVisibleAnywhere($userId, $ticketId);
			$viewer = $this->access->contextFor($userId, (int)$ticket->getBoardId());
		} catch (DoesNotExistException|NotAMemberException) {
			return null;
		}

		$project = (string)$this->projects->findForViewer($viewer)->getTitle();
		$board = (string)$this->boards->findForViewer($viewer)->getTitle();
		$link = $this->links->forTicket($ticketId);
		$number = '#' . str_pad((string)$ticket->getNumber(), 4, '0', STR_PAD_LEFT);

		$reference = new Reference($referenceText);
		$reference->setTitle($number . ' ' . $ticket->getTitle());
		$reference->setDescription($board !== '' && $board !== $project ? $project . ' · ' . $board : $project);
		$reference->setImageUrl($this->getIconUrl());
		$reference->setUrl($link);
		$reference->setRichObject(self::RICH_OBJECT_TYPE, [
			'id' => $ticketId,
			'number' => $number,
			'title' => (string)$ticket->getTitle(),
			'project' => $project,
			'board' => $board !== $project ? $board : '',
			'column' => $this->columnTitle($viewer, $ticket),
			'closedOutcome' => $ticket->getClosedOutcome(),
			'responsible' => $this->responsible($viewer, $ticket),
			'dueDate' => $ticket->getDueDate()?->format('Y-m-d'),
			'link' => $link,
		]);

		return $reference;
	}

	public function getCachePrefix(string $referenceId): string {
		return (string)$this->ticketIdFrom($referenceId);
	}

	/** Je Nutzer: Sonst bekäme ein Kunde aus dem Cache die Karte, die ein Interner aufgelöst hat. */
	public function getCacheKey(string $referenceId): ?string {
		return $this->currentUserId() ?? '';
	}

	/** Aus der Sitzung zur Aufrufzeit: Der Server-Container injiziert Providern kein `userId`. */
	private function currentUserId(): ?string {
		return $this->session->getUser()?->getUID();
	}

	/**
	 * Die Kennung aus einem Link dieser Instanz: `/t/{id}` oder `#/boards/{board}?ticket={id}`.
	 */
	public function ticketIdFrom(string $referenceText): ?int {
		$base = preg_quote(rtrim($this->urls->getAbsoluteURL('/'), '/'), '#');
		$pattern = '#^' . $base . '/(?:index\.php/)?apps/projektwerk/(?:t/(\d+)|\#/boards/\d+\?ticket=(\d+))/?$#';
		if (preg_match($pattern, trim($referenceText), $m) !== 1) {
			return null;
		}

		$id = (int)($m[1] !== '' ? $m[1] : $m[2]);

		return $id > 0 ? $id : null;
	}

	private function columnTitle(ViewerContext $viewer, Ticket $ticket): string {
		foreach ($this->columns->findForBoard($viewer) as $column) {
			if ((int)$column->getId() === (int)$ticket->getColumnId()) {
				return (string)$column->getTitle();
			}
		}

		return '';
	}

	/**
	 * @return array{id: string, name: string}|null
	 */
	private function responsible(ViewerContext $viewer, Ticket $ticket): ?array {
		$userId = $ticket->getResponsibleUserId();
		if ($userId === null) {
			return null;
		}
		foreach ($this->members->listForBoard($viewer) as $member) {
			if ($member['userId'] === $userId) {
				return ['id' => $userId, 'name' => (string)$member['resolvedName']];
			}
		}

		return ['id' => $userId, 'name' => $userId];
	}
}
