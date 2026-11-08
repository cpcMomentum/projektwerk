<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCP\IDBConnection;

/**
 * Zweite Prüfung je Eintrag in TicketLogService::listFor().
 *
 * @template-extends TicketChildMapper<TicketLog>
 */
class TicketLogMapper extends TicketChildMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_ticket_log', TicketLog::class);
	}

	#[\Override]
	protected function sortProperty(): string {
		return 'createdAt';
	}
}
