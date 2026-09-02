<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Das Projekt-Dach (#246).
 *
 * Bewusst schmal: Ein Projekt trägt keine Sichtbarkeit — seine Zeile ist für
 * alle Mitglieder dieselbe. Es gibt hier **kein** `find(int $id)`; wo der
 * Projektname gebraucht wird, kommt er über die schon gesperrte
 * {@see BoardMapper::findAllForUser()} aus Sicht des Betrachters. Angelegt wird
 * ein Projekt einzig beim Anlegen eines Boards (dort besitzt die aufrufende
 * Person das Board ohnehin).
 *
 * @template-extends QBMapper<Project>
 */
class ProjectMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'pwerk_projects', Project::class);
	}
}
