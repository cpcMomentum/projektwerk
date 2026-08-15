<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\AppInfo\Application;
use OCP\Config\IUserConfig;

/**
 * Welche Projekte eine Person in die Seitenleiste gepinnt hat (#115).
 *
 * **Eine Nutzer-Einstellung, keine Tabelle.** Der Pin ist rein persönlich und
 * trägt keine Beziehung, die eine eigene Zeile verdient — eine Liste von
 * Board-IDs je Person genügt. Ein User-Value heißt: keine Migration, kein
 * Schema, kein Aufräumen.
 *
 * **`IUserConfig`, nicht `IConfig`.** `IConfig::getUserValue`/`setUserValue`
 * sind seit NC 33 deprecated; ProjektWerk zielt auf 33–34, also die
 * Nachfolge-API mit `getValueString`/`setValueString`.
 *
 * **Die Sichtbarkeit steht nicht hier.** Dieser Dienst weiß nur, was jemand
 * gepinnt hat, nicht was er sehen darf. Die Schnittmenge bildet der Aufrufer aus
 * `findAllForUser()` — dem einen Lesepfad, der die Sichtbarkeit ohnehin
 * durchläuft. Ein gepinntes Projekt, aus dem jemand herausfällt, verschwindet
 * damit von selbst aus der Anzeige, ohne dass hier etwas aufgeräumt werden muss.
 */
class BoardPinService {

	private const KEY = 'pinned_boards';

	public function __construct(
		private IUserConfig $config,
	) {
	}

	/**
	 * Die gepinnten Board-IDs einer Person — roh, ohne Sichtbarkeitsfilter.
	 *
	 * @return int[]
	 */
	public function pinnedIds(string $userId): array {
		$raw = $this->config->getValueString($userId, Application::APP_ID, self::KEY, '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}

		// Auf Ganzzahlen normieren und Doppelte entfernen: Der Wert ist zwar
		// nur von der eigenen App geschrieben, aber eine Einstellung ist ein
		// Speicher wie jeder andere, und eine kaputte Zeile darf die Liste nicht
		// umwerfen.
		return array_values(array_unique(array_map('intval', $decoded)));
	}

	/**
	 * Ein Projekt an- oder abpinnen. Idempotent: doppelt anpinnen ändert nichts.
	 */
	public function setPin(string $userId, int $boardId, bool $pinned): void {
		$ids = $this->pinnedIds($userId);
		$has = in_array($boardId, $ids, true);

		if ($pinned === $has) {
			return;
		}

		if ($pinned) {
			$ids[] = $boardId;
		} else {
			$ids = array_values(array_filter($ids, static fn (int $id): bool => $id !== $boardId));
		}

		$this->config->setValueString($userId, Application::APP_ID, self::KEY, json_encode(array_values($ids)));
	}
}
