<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\TaskFilter;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * „Meine Aufgaben" — projektübergreifend, in einer Antwort.
 *
 * **Kein `ViewerContext`, und das ist hier richtig.** Jeder andere Controller
 * beginnt mit `BoardAccess::contextFor()`, weil er zu genau einem Board
 * gehört. Diese Ansicht gehört zu allen — der Kontext wäre die falsche Frage.
 * An seine Stelle tritt der JOIN auf `pwerk_members` in `TicketScope`, der die
 * Rolle **je Board** bildet: Dieselbe Person kann in einem Projekt intern und
 * in einem anderen extern sein, und genau das fällt hier nicht als Sonderfall
 * an, sondern ergibt sich aus dem Verbund.
 *
 * **Zwei Abschnitte, zwei Mengen.** „Meine Arbeitsschritte" und „Meine
 * Tickets" überschneiden sich, sind aber verschieden: Ein Schritt kann mir an
 * einem Vorgang zugewiesen sein, für den ich weder verantwortlich noch
 * mitarbeitend bin. Sie deshalb aus einer Abfrage zu ziehen hieße, eine der
 * beiden falsch zu beantworten.
 *
 * **Die Sortierung fehlt hier bewusst.** §9 will „nach Fälligkeit, dann Alter,
 * Überfälliges oben" — das entsteht aus Ticket **und** Schritt zusammen und
 * gehört damit in die Ansicht, die beides nebeneinander hat. Ein Mapper, der
 * nach einem Feld der Kinder sortiert, bräuchte sie im JOIN und wäre der
 * zweite Ort, an dem die Sichtbarkeit stimmen müsste.
 */
class TaskController extends Controller {

	public function __construct(
		IRequest $request,
		private TicketMapper $tickets,
		private StepMapper $steps,
		private BoardMapper $boards,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Beide Abschnitte plus die Boards als Herkunftszeile.
	 *
	 * Die Schritte kommen über `findForTickets()` aus der **gefilterten**
	 * Ticketmenge — Kinder werden nie eigenständig abgefragt (§5.8). Danach
	 * bleiben nur die eigenen offenen übrig; das ist eine Anzeigefrage, keine
	 * Sichtbarkeitsfrage, und darf deshalb in PHP passieren.
	 *
	 * Die Boards liefert `findAllForUser()`, ein bestehender und registrierter
	 * Lesepfad. Ein eigener Weg zu denselben Daten wäre der, gegen den die
	 * ganze Bauform gerichtet ist.
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		// §5.3: Geschlossene Vorgänge verschwinden aus „Meine Aufgaben".
		$filter = TaskFilter::openOnly();

		$withMySteps = $this->tickets->findVisibleWithMyOpenSteps($this->userId, $filter);
		$mine = $this->tickets->findVisibleAcrossBoards($this->userId, $filter);

		return new JSONResponse([
			// Abschnitt 1: die Vorgänge, an denen mir etwas offen zugewiesen
			// ist — mit genau diesen Schritten.
			'stepTickets' => $withMySteps,
			'steps' => $this->myOpenSteps($withMySteps),
			// Abschnitt 2: verantwortlich oder mitarbeitend.
			'tickets' => $mine,
			// Die Herkunftszeile. Ein Board kann in beiden Abschnitten
			// vorkommen und wird deshalb einmal geliefert, nicht je Vorgang.
			'boards' => $this->boardLine(),
		]);
	}

	/**
	 * Meine offenen Schritte an dieser Ticketmenge.
	 *
	 * @param Ticket[] $tickets die bereits gefilterte Menge
	 * @return \OCA\Projektwerk\Db\Step[]
	 */
	private function myOpenSteps(array $tickets): array {
		$ids = array_map(static fn (Ticket $ticket): int => (int)$ticket->getId(), $tickets);

		return array_values(array_filter(
			$this->steps->findForTickets($ids),
			fn ($step): bool => (string)$step->getAssignedUserId() === $this->userId && !$step->isDone(),
		));
	}

	/**
	 * Board-Kennung => Titel und beide Firmennamen.
	 *
	 * Beide Seiten, nicht nur eine: Trüge nur die Kundenseite eine Firma, wäre
	 * die interne stumm „der Normalfall" (§8).
	 *
	 * **Einschließlich archivierter Projekte**, und das ist kein Versehen: Die
	 * Sichtbarkeitsregel kennt kein Archiv, die beiden Ticketabfragen liefern
	 * also weiterhin Vorgänge aus archivierten Projekten. Käme die
	 * Herkunftszeile ohne sie, widersprächen sich die beiden Hälften **einer**
	 * Antwort — die Zeile stünde ohne Projektnamen da, und die Ansicht liefe
	 * auf einen fehlenden Eintrag.
	 *
	 * Ob ein archiviertes Projekt überhaupt noch Aufgaben beisteuern soll, ist
	 * eine Produktfrage und hier bewusst **nicht** entschieden: Sie zu
	 * beantworten hieße, die Ticketabfragen einzuschränken, und das ist eine
	 * andere Änderung als diese.
	 *
	 * @return array<int, array{title: string, orgInternal: ?string, orgExternal: ?string}>
	 */
	private function boardLine(): array {
		$line = [];

		foreach ($this->boards->findAllForUser((string)$this->userId, true) as $board) {
			$line[(int)$board->getId()] = [
				'title' => (string)$board->getTitle(),
				'orgInternal' => $board->getOrgInternal(),
				'orgExternal' => $board->getOrgExternal(),
			];
		}

		return $line;
	}
}
