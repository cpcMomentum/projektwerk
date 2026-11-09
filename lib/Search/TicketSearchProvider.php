<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Search;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Service\TicketLink;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Vorgänge in der Nextcloud-Suche und im Smart Picker, nur aus der sichtbaren Menge.
 */
class TicketSearchProvider implements IProvider {

	public const ID = 'projektwerk-tickets';

	private const MAX_LIMIT = 25;

	public function __construct(
		private IURLGenerator $urls,
		private TicketLink $links,
		private IL10N $l10n,
		private TicketMapper $tickets,
		private BoardMapper $boards,
	) {
	}

	public function getId(): string {
		return self::ID;
	}

	public function getName(): string {
		return $this->l10n->t('ProjektWerk-Vorgänge');
	}

	public function getOrder(string $route, array $routeParameters): ?int {
		return str_starts_with($route, Application::APP_ID . '.') ? -1 : 40;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = trim($query->getTerm());
		if ($term === '') {
			return SearchResult::complete($this->getName(), []);
		}

		$limit = max(1, min($query->getLimit(), self::MAX_LIMIT));
		$found = $this->tickets->findVisibleMatching($user->getUID(), $term, $limit);

		$titles = [];
		foreach ($this->boards->findAllForUser($user->getUID()) as $board) {
			$titles[(int)$board->getId()] = (string)$board->getTitle();
		}

		$icon = $this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg'));
		$entries = array_map(
			fn (Ticket $ticket): SearchResultEntry => new SearchResultEntry(
				$icon,
				'#' . str_pad((string)$ticket->getNumber(), 4, '0', STR_PAD_LEFT) . ' ' . $ticket->getTitle(),
				$titles[(int)$ticket->getBoardId()] ?? '',
				$this->links->forTicket((int)$ticket->getId()),
			),
			$found,
		);

		return SearchResult::complete($this->getName(), $entries);
	}
}
