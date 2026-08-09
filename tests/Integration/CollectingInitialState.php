<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCP\AppFramework\Services\IInitialState;

/**
 * Ein `IInitialState`, der behaelt statt auszuliefern.
 *
 * Der Deep-Link antwortet immer mit derselben Huelle und immer mit 200 — sein
 * ganzer Inhalt steckt im Initial State. Ohne diesen Doppelgaenger koennte die
 * Leak-Matrix an dieser Route nur pruefen, dass eine Seite zurueckkommt, und
 * das ist genau die Aussage, die nichts wert ist.
 *
 * Bewusst kein Mock-Framework: Ein `createMock()` mit `willReturnCallback()`
 * waere hier mehr Gerueust als Aussage, und der Test soll lesbar bleiben.
 */
final class CollectingInitialState implements IInitialState {

	/** @var array<string, mixed> */
	private array $states = [];

	private mixed $lastValue = null;

	public function provideInitialState(string $key, mixed $data): void {
		$this->states[$key] = $data;
		$this->lastValue = $data;
	}

	public function provideLazyInitialState(string $key, \Closure $closure): void {
		$this->provideInitialState($key, $closure());
	}

	/**
	 * Der zuletzt hinterlegte Wert.
	 *
	 * Die Tests rufen den Controller in einer Schleife; `last()` liest damit
	 * immer die Antwort auf die gerade gestellte Frage, ohne dass der Test den
	 * Schluessel kennen muss.
	 */
	public function last(): mixed {
		return $this->lastValue;
	}

	/**
	 * @param string $key Schluessel, unter dem der Wert hinterlegt wurde.
	 */
	public function get(string $key): mixed {
		return $this->states[$key] ?? null;
	}
}
