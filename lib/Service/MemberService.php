<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\Member;
use OCA\Projektwerk\Db\MemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\IUserManager;

/**
 * Mitglieder eines Projekts pflegen.
 *
 * Drei Regeln aus §8, alle wörtlich und keine davon erfunden:
 *
 * 1. Pflegen darf nur ein **internes Mitglied mit Verwaltungsrecht**.
 * 2. Das Verwaltungsrecht ist **nur an interne Mitglieder vergebbar**.
 * 3. Der **Board-Eigentümer behält es immer**.
 *
 * **Personenweise, keine Gruppen** (§8). Eine Gruppenmitgliedschaft würde die
 * Rolle an den Nextcloud-Account binden — und genau das trennt das Produkt
 * ausdrücklich: Die Rolle hängt an der Mitgliedschaft, ein Gastzugang ist nicht
 * automatisch extern und ein Vollkonto nicht automatisch intern.
 *
 * **Entfernen fehlt hier bewusst.** §5.29 verlangt dabei, die `private`-Tickets
 * der Person nach bezifferter Bestätigung zu löschen und offene Zuweisungen
 * aufzuheben. Zuweisungen an Arbeitsschritten gibt es erst ab Phase 3, und der
 * Plan legt den `MemberLifecycleService` in Phase 6. Ein Entfernen ohne diesen
 * Teil hinterließe unsichtbare Tickets, die sich mangels Admin-Ausnahme nie
 * wieder aufräumen ließen — also lieber noch gar nicht.
 */
class MemberService {

	public function __construct(
		private MemberMapper $members,
		private BoardMapper $boards,
		private IUserManager $users,
		private MemberLifecycleService $lifecycle,
	) {
	}

	/**
	 * Eine Person zum Projekt hinzufügen.
	 *
	 * @throws NotManagerException
	 * @throws \InvalidArgumentException Konto unbekannt, Rolle ungültig oder bereits Mitglied
	 */
	public function add(
		ViewerContext $viewer,
		string $userId,
		string $role,
		bool $isManager = false,
		?string $displayName = null,
	): Member {
		$this->assertManager($viewer);
		$this->assertKnownRole($role);

		if (!$this->users->userExists($userId)) {
			// Früh und deutlich: Eine Mitgliedschaft auf eine nicht existierende
			// Kennung wäre eine Zeile, die niemand je einlöst — und sie fiele
			// erst auf, wenn sich jemand wundert, warum der Kunde nichts sieht.
			throw new \InvalidArgumentException('Unbekanntes Konto: ' . $userId);
		}

		$member = new Member();
		$member->setBoardId($viewer->boardId);
		// #246: Die Mitgliedschaft gilt projektweit — der Sichtbarkeitsverbund
		// hängt an `project_id`, nicht mehr am Board.
		$member->setProjectId($viewer->projectId);
		$member->setUserId($userId);
		$member->setRole($role);
		$member->setIsManager($this->manageableFlag($role, $isManager));
		$member->setDisplayName($this->trimOrNull($displayName));
		$member->setAddedBy($viewer->userId);
		$member->setAddedAt(new \DateTime());

		try {
			return $this->members->insert($member);
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new \InvalidArgumentException('Diese Person ist bereits Mitglied.');
			}

			throw $e;
		}
	}

	/**
	 * Die Mitglieder eines Projekts, jedes mit dem Namen, der anzuzeigen ist.
	 *
	 * `display_name` an der Mitgliedschaft ist ein **Übersteuern**, kein
	 * Pflichtfeld: `null` heißt laut Datenmodell „den Anzeigenamen aus
	 * Nextcloud verwenden". Genau das ist bisher nirgends passiert — das
	 * Frontend fiel auf die Benutzerkennung zurück, und die steht bei einem
	 * Gastkonto als 64-stelliger Hash auf der Karte.
	 *
	 * Die Auflösung gehört auf den Server, weil nur er sie hat: Nextclouds
	 * Personensuche liefert in einer Gast-Sitzung prinzipbedingt eine leere
	 * Liste, ein Nachschlagen im Browser bliebe also ausgerechnet beim Kunden
	 * stumm.
	 *
	 * Der aufgelöste Name steht **neben** `displayName`, nicht an dessen
	 * Stelle: Die Mitgliederverwaltung bearbeitet das Übersteuern, und ein
	 * vorbelegtes Feld würde den Nextcloud-Namen beim nächsten Speichern
	 * versehentlich einfrieren.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listForBoard(ViewerContext $viewer): array {
		return array_map(
			fn (Member $member): array => $member->jsonSerialize() + [
				'resolvedName' => $this->nameFor($member),
			],
			$this->members->findForBoard($viewer),
		);
	}

	/**
	 * Die Anzeigenamen aller Projekte dieser Person — je Projekt eine Zuordnung
	 * von Kennung auf Namen (#76).
	 *
	 * **Nach Projekt geschachtelt und nicht flach.** `display_name` an der
	 * Mitgliedschaft ist ein Übersteuern **je Projekt**: Dieselbe Person kann in
	 * einem Projekt unter ihrem Firmennamen stehen und in einem anderen unter
	 * ihrem eigenen. Eine flache Zuordnung müsste eines der beiden gewinnen
	 * lassen — und welches, wäre Zufall der Reihenfolge.
	 *
	 * Ein Vorgang gehört zu genau einem Projekt, die Ansicht schlägt also
	 * `namen[boardId][userId]` nach und bekommt den Namen, der dort gilt.
	 *
	 * @return array<int, array<string, string>> Projekt => Kennung => Name
	 */
	public function namesForUserBoards(string $userId): array {
		$namen = [];

		foreach ($this->members->findForUserBoards($userId) as $member) {
			$namen[(int)$member->getBoardId()][(string)$member->getUserId()] = $this->nameFor($member);
		}

		return $namen;
	}

	/**
	 * Die eigene Rolle je Board — fürs Gäste-Gate (#234).
	 *
	 * Reine Weitergabe an {@see MemberMapper::rolesForUser()}: Anders als bei
	 * den Namen ist hier nichts aufzulösen, die Rolle steht roh an der
	 * Mitgliedschaft. Der Umweg über den Dienst besteht dennoch, weil die
	 * Controller die Mitgliederdaten grundsätzlich über diese Grenze beziehen
	 * und nicht am Dienst vorbei auf den Mapper greifen.
	 *
	 * @return array<int, string> Board-Kennung => Rolle.
	 */
	public function rolesForUserBoards(string $userId): array {
		return $this->members->rolesForUser($userId);
	}

	/**
	 * Rolle, Verwaltungsrecht und Name einer Mitgliedschaft ändern.
	 *
	 * **Zum Rollenwechsel gibt es keine Datenbewegung.** §8 friert
	 * `creator_role` am Ticket ein, und das bleibt so: Von dieser Person
	 * angelegte interne Tickets verbleiben bei der bisherigen Seite. §5.29
	 * verlangt dafür einen **Hinweis** im Dialog, keine Umbuchung — sonst
	 * bräche die Symmetrie von `internal` rückwirkend.
	 *
	 * @param array{role?: string, isManager?: bool, displayName?: ?string} $changes
	 * @throws NotManagerException
	 * @throws DoesNotExistException das Mitglied gehört nicht zu diesem Board
	 */
	public function update(ViewerContext $viewer, string $userId, array $changes): Member {
		$this->assertManager($viewer);

		$member = $this->findInBoard($viewer, $userId);
		$role = $changes['role'] ?? (string)$member->getRole();

		if (array_key_exists('role', $changes)) {
			$this->assertKnownRole($changes['role']);
			$this->assertNotDemotingTheOwner($viewer, $userId, $changes['role']);
			$member->setRole($changes['role']);
		}

		if (array_key_exists('isManager', $changes)) {
			$this->assertOwnerKeepsTheRight($viewer, $userId, $changes['isManager']);
			$member->setIsManager($this->manageableFlag($role, $changes['isManager']));
		} elseif (array_key_exists('role', $changes)) {
			// Wer extern wird, verliert das Verwaltungsrecht — sonst stünde in
			// der Datenbank ein externes Mitglied mit gesetztem Flag, und
			// ViewerContext müsste es bei jedem Aufruf stillschweigend
			// entschärfen. Einmal richtig schreiben ist besser als überall
			// entschärfen.
			$member->setIsManager($this->manageableFlag($role, $member->getIsManager() === 1));
		}

		if (array_key_exists('displayName', $changes)) {
			$member->setDisplayName($this->trimOrNull($changes['displayName']));
		}

		return $this->members->update($member);
	}

	/**
	 * Wie viele private Vorgänge das Entfernen dieser Person löschen würde (§5.29).
	 *
	 * Grundlage der bezifferten Rückfrage. Das ist **kein** Leck wie die bewusst
	 * fehlende Zählung beim Rollenwechsel weiter unten: Gezählt werden nur die
	 * `private`-Vorgänge **dieser** Person — die sieht ohnehin niemand sonst, und
	 * dass sie mit ihr verschwinden, ist genau die Zusage. Interne Vorgänge der
	 * Person zählt die Methode nicht.
	 *
	 * @throws NotManagerException
	 * @throws DoesNotExistException das Mitglied gehört nicht zu diesem Board
	 * @throws \InvalidArgumentException der Eigentümer lässt sich nicht entfernen
	 */
	public function removalImpact(ViewerContext $viewer, string $userId): int {
		$this->assertManager($viewer);
		$this->findInBoard($viewer, $userId);
		$this->assertNotOwner($viewer, $userId);

		return $this->lifecycle->countPrivateOnBoard($userId, $viewer->boardId);
	}

	/**
	 * Eine Person aus dem Projekt entfernen (§5.29).
	 *
	 * Ihre `private`-Vorgänge in diesem Projekt werden gelöscht, ihre offenen
	 * Zuweisungen aufgehoben, ihre Mitgliedschaft entfernt — die eigentliche
	 * Arbeit macht {@see MemberLifecycleService::removeFromBoard()}, an genau der
	 * Stelle, die als einzige ohne Betrachter aufräumen darf. Die bezifferte
	 * Rückfrage steht vorher im Frontend über {@see removalImpact()}.
	 *
	 * Der Eigentümer lässt sich nicht entfernen — es gäbe niemanden, der das
	 * Verwaltungsrecht sicher behält (§8).
	 *
	 * @throws NotManagerException
	 * @throws DoesNotExistException das Mitglied gehört nicht zu diesem Board
	 * @throws \InvalidArgumentException der Eigentümer lässt sich nicht entfernen
	 */
	public function remove(ViewerContext $viewer, string $userId): void {
		$this->assertManager($viewer);
		$this->findInBoard($viewer, $userId);
		$this->assertNotOwner($viewer, $userId);

		$this->lifecycle->removeFromBoard($userId, $viewer->boardId);
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	private function assertNotOwner(ViewerContext $viewer, string $userId): void {
		if ($this->isOwner($viewer, $userId)) {
			throw new \InvalidArgumentException('Der Eigentümer des Projekts kann nicht entfernt werden.');
		}
	}

	/*
	 * BEWUSST NICHT VORHANDEN: eine Zählung „wie viele Tickets bleiben bei der
	 * alten Seite zurück".
	 *
	 * Naheliegend wäre sie — der Herunterstufen-Dialog nennt schließlich
	 * konkrete Zahlen und Namen. Für den Rollenwechsel verlangt §5.29 aber
	 * ausdrücklich nur einen **Hinweis**, und das ist kein Versehen der
	 * Spezifikation: Wechselt jemand von extern nach intern, sind seine
	 * bisherigen `internal`-Tickets genau die, die die interne Seite **nicht
	 * sehen darf**. Eine Zahl darüber wäre die Auskunft, wie viele interne
	 * Vorgänge die Kundenseite führt — abrufbar über eine Vorschau, die niemand
	 * für eine Abfrage hält.
	 *
	 * Der Hinweistext im Dialog bleibt deshalb ohne Zahl.
	 */

	/**
	 * @throws DoesNotExistException
	 */
	private function findInBoard(ViewerContext $viewer, string $userId): Member {
		foreach ($this->members->findForBoard($viewer) as $member) {
			if ((string)$member->getUserId() === $userId) {
				return $member;
			}
		}

		throw new DoesNotExistException('Kein Mitglied dieses Boards: ' . $userId);
	}

	/**
	 * Der Eigentümer behält das Verwaltungsrecht — immer (§8).
	 *
	 * @throws \InvalidArgumentException
	 */
	private function assertOwnerKeepsTheRight(ViewerContext $viewer, string $userId, bool $isManager): void {
		if ($isManager || !$this->isOwner($viewer, $userId)) {
			return;
		}

		throw new \InvalidArgumentException(
			'Dem Eigentümer des Projekts lässt sich das Verwaltungsrecht nicht entziehen.',
		);
	}

	/**
	 * Und er kann auch nicht extern werden — das nähme ihm dasselbe Recht auf
	 * dem Umweg über die Rolle.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function assertNotDemotingTheOwner(ViewerContext $viewer, string $userId, string $role): void {
		if ($role === ViewerContext::ROLE_INTERNAL || !$this->isOwner($viewer, $userId)) {
			return;
		}

		throw new \InvalidArgumentException(
			'Der Eigentümer des Projekts bleibt intern; sonst verlöre er das Verwaltungsrecht.',
		);
	}

	private function isOwner(ViewerContext $viewer, string $userId): bool {
		return (string)$this->board($viewer)->getOwnerUserId() === $userId;
	}

	private function board(ViewerContext $viewer): Board {
		return $this->boards->findForViewer($viewer);
	}

	/**
	 * Das Verwaltungsrecht ist nur an interne Mitglieder vergebbar (§8).
	 */
	private function manageableFlag(string $role, bool $wanted): int {
		return $wanted && $role === ViewerContext::ROLE_INTERNAL ? 1 : 0;
	}

	private function assertKnownRole(string $role): void {
		if ($role !== ViewerContext::ROLE_INTERNAL && $role !== ViewerContext::ROLE_EXTERNAL) {
			// Ein unbekannter Wert dürfte nicht durchrutschen: Die
			// Sichtbarkeitsregel vergleicht creator_role exakt gegen diese
			// beiden Zeichenketten.
			throw new \InvalidArgumentException('Unbekannte Rolle: ' . $role);
		}
	}

	/**
	 * @throws NotManagerException
	 */
	private function assertManager(ViewerContext $viewer): void {
		if (!$viewer->isManager) {
			throw new NotManagerException(
				'Mitglieder dürfen nur interne Mitglieder mit Verwaltungsrecht pflegen.',
			);
		}
	}

	/**
	 * Der Name, der anzuzeigen ist: Übersteuern, sonst Nextcloud, sonst Kennung.
	 *
	 * Die Kennung bleibt als letzte Stufe stehen, weil eine leere Zeile
	 * schlimmer ist als ein Hash: Eine Zeile ohne Namen sähe aus wie ein
	 * Ladefehler, und in der Rückfrage beim Herunterstufen stünde dann eine
	 * Person weniger auf der Liste, als tatsächlich den Zugriff verliert.
	 *
	 * Im CLI liefert `IUserManager` für Gastkonten nichts — dort ist nur das
	 * Datenbank-Backend geladen. Das ist verkraftbar, weil es nur den
	 * Anzeigenamen betrifft und über HTTP funktioniert; kein Test darf sich
	 * jedoch darauf verlassen, dass ein Gast hier seinen Namen bekommt.
	 */
	private function nameFor(Member $member): string {
		$stored = $member->getDisplayName();
		if ($stored !== null) {
			return $stored;
		}

		$fromNextcloud = $this->users->get((string)$member->getUserId())?->getDisplayName();

		return ($fromNextcloud === null || $fromNextcloud === '')
			? (string)$member->getUserId()
			: $fromNextcloud;
	}

	private function trimOrNull(?string $value): ?string {
		if ($value === null) {
			return null;
		}

		$trimmed = trim($value);

		// Leer heißt „Anzeigename aus Nextcloud", nicht „leerer Name".
		return $trimmed === '' ? null : $trimmed;
	}
}
