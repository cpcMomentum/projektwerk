<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Access;

/**
 * Der Nachweis, dass eine bestimmte Person Mitglied eines bestimmten Boards ist
 * — und mit welcher Rolle.
 *
 * Der Sinn dieses Typs ist nicht, Daten zu buendeln. Er ist der **Beweis, dass
 * die Mitgliedschaft geprueft wurde**: Jede Lesemethode des `TicketMapper`
 * beginnt mit einem `ViewerContext`, es gibt dort kein `findAll()` und kein
 * `find(int $id)`. Wer den Sichtbarkeitsfilter vergisst, bekommt damit einen
 * **Typfehler statt eines Review-Versaeumnisses**.
 *
 * Deshalb ist der Konstruktor privat: `new ViewerContext(...)` ist unmoeglich,
 * es gibt genau eine Tuer. Dass durch diese Tuer nur `BoardAccess` geht, kann
 * PHP allerdings **nicht** ausdruecken — eine statische Fabrik ist
 * zwangslaeufig oeffentlich. Diese Luecke schliesst der Architekturtest in
 * `tests/Unit/Access/ArchitectureTest.php`, der `forMember(` ausserhalb von
 * `BoardAccess.php` verbietet. Die Konvention ist damit nicht wegargumentiert,
 * sondern maschinell bewacht.
 */
final readonly class ViewerContext {

	public const ROLE_INTERNAL = 'internal';
	public const ROLE_EXTERNAL = 'external';

	private function __construct(
		public string $userId,
		public int $boardId,
		public string $role,
		public bool $isManager,
	) {
	}

	/**
	 * Einzige Tuer. Nur `BoardAccess` darf sie benutzen — durchgesetzt vom
	 * Architekturtest, nicht von der Sprache.
	 *
	 * @internal
	 */
	public static function forMember(string $userId, int $boardId, string $role, bool $isManager): self {
		if ($role !== self::ROLE_INTERNAL && $role !== self::ROLE_EXTERNAL) {
			// Ein unbekannter Rollenwert darf nicht als "irgendwas" durchgehen:
			// Die Sichtbarkeitsregel vergleicht creator_role mit genau diesem
			// Wert, ein Tippfehler wuerde still zu weniger oder mehr Sicht
			// fuehren.
			throw new \InvalidArgumentException('Unbekannte Rolle: ' . $role);
		}
		// is_manager gilt laut §8 nur fuer interne Mitglieder. Ein externes
		// Mitglied mit gesetztem Flag waere ein Datenfehler; hier wird er
		// entschaerft statt weitergereicht.
		return new self($userId, $boardId, $role, $isManager && $role === self::ROLE_INTERNAL);
	}

	public function isInternal(): bool {
		return $this->role === self::ROLE_INTERNAL;
	}
}
