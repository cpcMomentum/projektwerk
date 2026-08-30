<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\Access\WaitStateCalculator;
use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\Step;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\TaskFilter;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Service\MemberService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Der Überblick — der Einstieg in die App (#76, entschieden am 2026-08-13).
 *
 * **Die Frage ist eine andere als bei „Meine Aufgaben".** Jene Seite beantwortet
 * *was liegt bei mir*; diese *wo hakt es* — über alle Projekte, auch dort, wo
 * gerade nichts bei mir liegt. Ein Vorgang, der seit zwölf Tagen bei der
 * Kundenseite liegt und einem Kollegen gehört, ist genau der Fall, für den die
 * Seite existiert, und fällt bei `task#index` heraus.
 *
 * Das ist der Grund, warum es diesen Lesepfad überhaupt gibt, und er war teuer:
 * Jeder Lesepfad ist eine Stelle mehr, an der die Sichtbarkeitsregel stimmen
 * muss. Er steht deshalb in {@see \OCA\Projektwerk\Tests\ReadPathRegistry} und
 * wird von der Leak-Matrix gefahren wie jeder andere.
 *
 * **Kein `ViewerContext`, wie bei {@see TaskController} und aus demselben
 * Grund:** Diese Ansicht gehört zu keinem Board, sondern zu allen. An seine
 * Stelle tritt der JOIN auf `pwerk_members` in `TicketScope`, der die Rolle **je
 * Board** bildet — dieselbe Person kann in einem Projekt intern und in einem
 * anderen extern sein.
 *
 * **Der Controller rechnet nichts aus, was die Ansicht ausrechnen kann.** Er
 * liefert die sichtbare Menge, den Wartezustand und die Namen; welche Vorgänge
 * in welchem Abschnitt stehen und wie sie sortiert sind, entscheidet die
 * Ansicht. Eine zweite Sortierung im Server wäre dieselbe Regel an zwei Orten —
 * und die Zahlen je Projekt entstehen aus derselben Menge, aus der auch die
 * Wartezeilen kommen. Zwei Abfragen dafür hießen, dass sich die beiden
 * Abschnitte einer Seite widersprechen können.
 */
class OverviewController extends Controller {

	public function __construct(
		IRequest $request,
		private TicketMapper $tickets,
		private StepMapper $steps,
		private BoardMapper $boards,
		private ColumnMapper $columns,
		private WaitStateCalculator $waitState,
		private MemberService $memberService,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Alles Sichtbare über alle Projekte, mit Wartezustand und Namen.
	 *
	 * **Geschlossene Vorgänge bleiben draußen.** Der Überblick zeigt, wo etwas
	 * hakt; ein erledigter Vorgang hakt nicht mehr. Dieselbe Entscheidung wie
	 * bei „Meine Aufgaben" (§5.3).
	 *
	 * Die Schritte kommen über `findForTickets()` aus der **gefilterten**
	 * Ticketmenge — Kinder werden nie eigenständig abgefragt (§5.8). Sie werden
	 * nicht mitgeliefert: Die Seite zeigt keine Schritte, sie braucht sie nur,
	 * damit der Wartezustand berechnet werden kann.
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		$aktiv = $this->boardLine(false);

		$tickets = array_values(array_filter(
			$this->tickets->findVisibleAcrossBoardsAll($this->userId, TaskFilter::openOnly()),
			// **Archivierte Projekte bleiben draußen** — und das ist eine
			// Produktentscheidung, keine Sichtbarkeitsfrage.
			//
			// Die Sichtbarkeitsregel kennt kein Archiv, die Abfrage liefert
			// also weiterhin Vorgänge aus archivierten Projekten. Für „Meine
			// Aufgaben" ist das bewusst offengelassen; für den **Einstieg**
			// nicht: Er beantwortet „wo hakt es gerade", und ein archiviertes
			// Projekt hakt nicht mehr. Auf der Dev-Instanz standen sonst
			// Dutzende Altlasten unter „Projekte mit Bewegung" — auf einer
			// echten Instanz wäre es dasselbe mit jedem abgeschlossenen
			// Kundenprojekt.
			//
			// **Gefiltert wird hier und nicht im Mapper.** Die Regel dort ist
			// die Sichtbarkeit und sonst nichts; eine zweite Bedingung
			// daneben wäre der Anfang einer zweiten Fassung. Was die Seite
			// zeigt, entscheidet die Seite.
			static fn (Ticket $ticket): bool => isset($aktiv[(int)$ticket->getBoardId()]),
		));
		$ids = array_map(static fn (Ticket $ticket): int => (int)$ticket->getId(), $tickets);

		// Einmal geladen, zweifach gebraucht: der Wartezustand und die Frage,
		// welche Vorgänge überhaupt einen offenen Schritt tragen (#119). Kinder
		// werden nie eigenständig abgefragt — beides kommt aus dieser Menge.
		$steps = $this->steps->findForTickets($ids);

		// Vorgänge mit mindestens einem offenen Schritt. Grundlage für „liegt
		// bei niemandem" (#119): ohne Verantwortlichen **und** ohne offenen
		// Schritt liegt ein Vorgang bei niemandem, er ist unbearbeitet.
		$withOpenSteps = array_values(array_unique(array_map(
			static fn (Step $step): int => (int)$step->getTicketId(),
			array_filter($steps, static fn (Step $step): bool => !$step->isDone()),
		)));

		return new JSONResponse([
			'tickets' => $tickets,
			// Kennung => {since, userIds}. Nur für Vorgänge, die wirklich
			// warten — der Rechner lässt die übrigen weg, und eine Zuordnung
			// mit lauter `null` wäre Ballast auf einer Startseite.
			'waiting' => $this->waitState->forTickets($tickets, $steps),
			'boards' => $aktiv,
			// Projekt => Kennung => Name. Ohne sie stünde auf der Startseite
			// „wartet auf pw-carla" — der Fehler aus #104, hier von vornherein
			// vermieden.
			'names' => $this->memberService->namesForUserBoards($this->userId),
			// Die eigene Kennung — für „Meine Vorgänge" (#120): die Vorgänge, für
			// die ich verantwortlich bin. Vom Server, damit der Browser nicht
			// raten muss, wer „ich" ist.
			'me' => $this->userId,
			// Vorgänge mit offenem Schritt (#119).
			'withOpenSteps' => $withOpenSteps,
			// **Erledigte je Projekt** (#226) — für die Status-Zahl „Erledigt"
			// und den Fortschritt im Dashboard. Die offenen Zahlen (Neu/Offen/
			// Wartet) entstehen aus `tickets`+`waiting` oben; nur die
			// geschlossenen fehlen dort, weil der Überblick sie bewusst weglässt.
			// Sichtbarkeits-gefiltert **und** auf die aktiven Boards beschränkt
			// (`array_keys($aktiv)`), damit dieser Zähler dieselbe Projektmenge
			// nennt wie `boards` und `firstColumn` — archivierte bleiben draußen.
			'closedCounts' => $this->tickets->countClosedByBoard($this->userId, array_keys($aktiv)),
			// **Erste Spalte je Projekt** (#226) — ein offener Vorgang darin gilt
			// als „Neu" (noch in der Eingangsspalte, nicht aufgegriffen). Nur für
			// die aktiven Boards, die die Seite ohnehin kennt.
			'firstColumn' => $this->columns->findFirstColumnByBoard(array_keys($aktiv)),
			// **Durchsatz** (#226/#232) — neu und erledigt in den letzten sieben
			// Tagen mit Veränderung zur Vorwoche, dazu die Tages-Zeitreihe der
			// letzten 30 Tage für die Verlaufs-Kurven.
			'durchsatz' => $this->durchsatz(array_keys($aktiv)),
			// **Neu je Projekt in den letzten sieben Tagen** (#232) — die Marke
			// „N diese Woche" an der Kachel. Sichtbarkeits-sicher und auf die
			// aktiven Boards beschränkt, dieselbe Projektmenge wie `boards`.
			'neuDieseWoche' => $this->tickets->countNewByBoard(
				$this->userId,
				array_keys($aktiv),
				(new \DateTime('now', new \DateTimeZone('UTC')))->modify('-7 days')->format('Y-m-d H:i:s'),
			),
		]);
	}

	/**
	 * Der Durchsatz je Zeitfenster (#226): neu und erledigt in den letzten
	 * sieben Tagen, mit der Veränderung zur Vorwoche.
	 *
	 * Rollende Sieben-Tage-Fenster, in **UTC** gerechnet — so speichert die DB
	 * die Zeitstempel, und der Vergleich kippt nicht mit der lokalen Zeitzone.
	 * Jeder Zähler ist sichtbarkeits-sicher (siehe {@see TicketMapper::countInWindow()}).
	 *
	 * @param int[] $boardIds Die aktiven Boards.
	 * @return array{neu: int, neuDelta: int, erledigt: int, erledigtDelta: int}
	 */
	private function durchsatz(array $boardIds): array {
		$utc = new \DateTimeZone('UTC');
		$w1 = (new \DateTime('now', $utc))->modify('-7 days')->format('Y-m-d H:i:s');
		$w2 = (new \DateTime('now', $utc))->modify('-14 days')->format('Y-m-d H:i:s');
		$uid = (string)$this->userId;

		$neuThis = $this->tickets->countInWindow($uid, $boardIds, 'created_at', $w1, null);
		$neuLast = $this->tickets->countInWindow($uid, $boardIds, 'created_at', $w2, $w1);
		$erlThis = $this->tickets->countInWindow($uid, $boardIds, 'closed_at', $w1, null);
		$erlLast = $this->tickets->countInWindow($uid, $boardIds, 'closed_at', $w2, $w1);

		return [
			'neu' => $neuThis,
			'neuDelta' => $neuThis - $neuLast,
			'erledigt' => $erlThis,
			'erledigtDelta' => $erlThis - $erlLast,
			// Die Verlaufs-Kurven (#232): ein Zähler je Tag über die letzten
			// {@see REIHE_TAGE} Tage, älteste zuerst. Die Zahlen oben nennen die
			// Woche, die Kurve zeigt den Weg dahin.
			'neuReihe' => $this->reihe($boardIds, 'created_at'),
			'erledigtReihe' => $this->reihe($boardIds, 'closed_at'),
		];
	}

	/**
	 * Wie viele Tage die Verlaufs-Kurve umfasst (#232). Ein Monat: genug, um
	 * einen Trend zu sehen, ohne dass die Kurve bei wenigen Vorgängen je Tag ins
	 * Rauschen kippt. Reine Anzeige — nichts wird gespeichert.
	 */
	private const REIHE_TAGE = 30;

	/**
	 * Die Tages-Zeitreihe eines Zählers (#232): für jeden der letzten
	 * {@see REIHE_TAGE} Tage die Zahl sichtbarer Vorgänge, älteste zuerst.
	 *
	 * **In UTC gebündelt**, wie der Durchsatz daneben: Die DB speichert die
	 * Zeitstempel so, und ein `substr($ts, 0, 10)` schneidet den UTC-Tag heraus
	 * — portabel über SQLite und Postgres, ohne Datumsfunktion der Datenbank
	 * (siehe {@see TicketMapper::findTimestampsInWindow()}).
	 *
	 * @param int[] $boardIds Die aktiven Boards.
	 * @param string $column `created_at` oder `closed_at`.
	 * @return int[] Ein Zähler je Tag, Länge {@see REIHE_TAGE}, älteste zuerst.
	 */
	private function reihe(array $boardIds, string $column): array {
		$utc = new \DateTimeZone('UTC');
		$tage = self::REIHE_TAGE;
		$start = (new \DateTime('now', $utc))->setTime(0, 0, 0)->modify('-' . ($tage - 1) . ' days');
		$ab = $start->format('Y-m-d H:i:s');
		$bis = (new \DateTime('now', $utc))->format('Y-m-d H:i:s');

		// Tag-Kennung (Y-m-d) => Index in der Reihe. So braucht das Bündeln nur
		// einen Nachschlag je Zeitstempel, keine Datumsrechnung.
		$index = [];
		$tag = clone $start;
		for ($i = 0; $i < $tage; $i++) {
			$index[$tag->format('Y-m-d')] = $i;
			$tag = $tag->modify('+1 day');
		}

		$reihe = array_fill(0, $tage, 0);
		foreach ($this->tickets->findTimestampsInWindow((string)$this->userId, $boardIds, $column, $ab, $bis) as $ts) {
			$key = substr($ts, 0, 10);
			if (isset($index[$key])) {
				$reihe[$index[$key]]++;
			}
		}

		return $reihe;
	}

	/**
	 * Board-Kennung => Titel und beide Firmennamen.
	 *
	 * Nah an {@see TaskController::boardLine()} und bewusst nicht geteilt: Ein
	 * gemeinsamer Helfer müsste in einer dritten Klasse wohnen, und beide
	 * Controller hingen dann an ihr, obwohl die Frage sich hier gerade
	 * unterscheidet — dort **mit** archivierten Projekten, hier ohne.
	 *
	 * **Und die Zeile ist zugleich der Filter.** Sie entscheidet, welche
	 * Projekte die Seite überhaupt kennt; die Ticketmenge richtet sich danach.
	 * Zwei getrennte Wege dafür hießen, dass ein Vorgang ohne seine
	 * Herkunftszeile ankommen kann — und die Ansicht liefe auf einen fehlenden
	 * Eintrag.
	 *
	 * @param bool $mitArchivierten Ob archivierte Projekte dazugehören.
	 * @return array<int, array{title: string, orgInternal: ?string, orgExternal: ?string}>
	 */
	private function boardLine(bool $mitArchivierten): array {
		$line = [];

		foreach ($this->boards->findAllForUser((string)$this->userId, $mitArchivierten) as $board) {
			$line[(int)$board->getId()] = [
				'title' => (string)$board->getTitle(),
				'orgInternal' => $board->getOrgInternal(),
				'orgExternal' => $board->getOrgExternal(),
			];
		}

		return $line;
	}
}
