<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Access;

use OCA\Projektwerk\Access\ViewerContext;
use PHPUnit\Framework\TestCase;

/**
 * Der Betrachter-Kontext (#281): das board-scopes Ersteller-Flag.
 *
 * `forMember` darf laut Architektur-Test nur `BoardAccess` aus `lib/` aufrufen;
 * Testdateien sind davon ausgenommen (der Scan prüft nur `lib/`).
 */
class ViewerContextTest extends TestCase {

	public function testBoardCreatorFlagFlowsThrough(): void {
		$ersteller = ViewerContext::forMember('u', 1, 9, ViewerContext::ROLE_INTERNAL, false, true);
		$this->assertTrue($ersteller->isBoardCreator);

		$fremd = ViewerContext::forMember('u', 1, 9, ViewerContext::ROLE_INTERNAL, false, false);
		$this->assertFalse($fremd->isBoardCreator);
	}

	public function testDefaultsToNotCreator(): void {
		$viewer = ViewerContext::forMember('u', 1, 9, ViewerContext::ROLE_INTERNAL, true);
		$this->assertFalse($viewer->isBoardCreator, 'Ohne Angabe ist niemand Ersteller');
	}

	/**
	 * Ein **externer** Ersteller behält das Ersteller-Recht, obwohl das
	 * Manager-Flag für Externe erzwungen auf false fällt (#281): Genau das ist
	 * der Sinn — die externe Arbeitsgruppe richtet ihr Board selbst ein.
	 */
	public function testExternalCreatorKeepsCreatorRightButNeverManager(): void {
		$viewer = ViewerContext::forMember('u', 1, 9, ViewerContext::ROLE_EXTERNAL, true, true);

		$this->assertTrue($viewer->isBoardCreator, 'Externer Ersteller behält das Recht');
		$this->assertFalse($viewer->isManager, 'Extern ist nie Manager (§8)');
	}
}
