<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCP\IDBConnection;

/**
 * Verknüpfte Dateien und Ordner — ausschliesslich ueber die bereits gefilterte Ticket-Menge.
 *
 * @template-extends TicketChildMapper<FileLink>
 */
class FileLinkMapper extends TicketChildMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_file_links', FileLink::class);
	}

	#[\Override]
	protected function sortProperty(): string {
		return 'createdAt';
	}
}
