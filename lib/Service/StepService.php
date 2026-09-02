<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\MailOutbox;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\Step;
use OCA\Projektwerk\Db\StepMapper;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Db\TicketMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Arbeitsschritte anlegen, zuweisen, erledigen.
 *
 * **Die Zuweisung ist die einzige Regel hier, die nicht aus §7 folgt** — und
 * sie folgt trotzdem daraus: Wem ein Schritt haengt, der muss das Ticket
 * oeffnen koennen. Sonst stuende in „Meine Aufgaben" eine Zeile, deren Ziel
 * beim Anklicken 404 liefert.
 *
 * Deshalb wird die Zuweisung gegen dieselbe Sichtbarkeitsregel geprueft, mit
 * der auch gelesen wird — ueber {@see TicketScope::wouldSee()}, das Praedikat
 * neben dem JOIN. Praktisch heisst das:
 *
 * | Sichtbarkeit | zuweisbar an |
 * |---|---|
 * | `public`   | alle Mitglieder, intern und extern **ohne Trennung** |
 * | `internal` | nur die Seite, der das Ticket gehoert |
 * | `private`  | nur die anlegende Person |
 *
 * **Kein automatisches Hochstufen als Nebenwirkung.** Wer einem Externen einen
 * Schritt an einem internen Ticket geben will, muss das Ticket erst selbst
 * oeffentlich machen — mit der Rueckfrage, die dazugehoert. Eine Zuweisung, die
 * still die Sichtbarkeit aendert, waere genau die Hintertuer, die §7 ausschliesst.
 */
class StepService {

	public function __construct(
		private StepMapper $steps,
		private TicketMapper $tickets,
		private MemberMapper $members,
		private TicketScope $scope,
		private NotificationService $notifications,
	) {
	}

	/**
	 * Ein neuer Schritt am Ende der Liste.
	 *
	 * @throws DoesNotExistException      Ticket nicht sichtbar
	 * @throws \InvalidArgumentException  Titel leer oder Zuweisung unzulaessig
	 */
	public function create(
		ViewerContext $viewer,
		int $ticketId,
		string $title,
		?string $assignedUserId = null,
		?string $dueDate = null,
		?string $description = null,
	): Step {
		$ticket = $this->tickets->findVisible($viewer, $ticketId);
		$trimmed = trim($title);

		if ($trimmed === '') {
			throw new \InvalidArgumentException('Ein Arbeitsschritt braucht einen Titel.');
		}

		$step = new Step();
		$step->setTicketId($ticketId);
		$step->setTitle($trimmed);
		// Beschreibung und Ergebnis erben die Sichtbarkeit des Vorgangs; ein
		// leerer Wert ist „keiner" (null), nicht die leere Zeichenkette.
		$step->setDescription($this->leerAlsNull($description));
		$step->setDone(0);
		$step->setDueDate($this->parseDueDate($dueDate));
		$step->setPosition($this->nextPosition($ticketId));
		$step->setCreatedAt(new \DateTime());

		$neu = $this->applyAssignment($step, $ticket, $viewer, $assignedUserId);
		$gespeichert = $this->steps->insert($step);

		$this->ankuendigen($ticket, $neu, $viewer, $step->getTitle());

		return $gespeichert;
	}

	/**
	 * Titel, Zuweisung, Faelligkeit, erledigt.
	 *
	 * @param array{title?: string, description?: ?string, result?: ?string, assignedUserId?: ?string, dueDate?: ?string, done?: bool} $changes
	 * @throws DoesNotExistException      Ticket oder Schritt nicht sichtbar
	 * @throws \InvalidArgumentException  Zuweisung unzulaessig
	 */
	public function update(ViewerContext $viewer, int $stepId, array $changes): Step {
		$step = $this->findVisibleStep($viewer, $stepId);
		$ticket = $this->tickets->findVisible($viewer, (int)$step->getTicketId());

		if (array_key_exists('title', $changes)) {
			$title = trim($changes['title']);
			if ($title === '') {
				throw new \InvalidArgumentException('Ein Arbeitsschritt braucht einen Titel.');
			}
			$step->setTitle($title);
		}

		if (array_key_exists('description', $changes)) {
			$step->setDescription($this->leerAlsNull($changes['description']));
		}

		if (array_key_exists('result', $changes)) {
			$step->setResult($this->leerAlsNull($changes['result']));
		}

		if (array_key_exists('dueDate', $changes)) {
			$step->setDueDate($this->parseDueDate($changes['dueDate']));
		}

		if (array_key_exists('done', $changes)) {
			// `done_at` haengt an `done` und wird nie getrennt gesetzt — sonst
			// gaebe es erledigte Schritte ohne Zeitpunkt und umgekehrt.
			$step->setDone($changes['done'] ? 1 : 0);
			$step->setDoneAt($changes['done'] ? new \DateTime() : null);
		}

		$neu = null;
		if (array_key_exists('assignedUserId', $changes)) {
			$neu = $this->applyAssignment($step, $ticket, $viewer, $changes['assignedUserId']);
		}

		$gespeichert = $this->steps->update($step);
		$this->ankuendigen($ticket, $neu, $viewer, $step->getTitle());

		return $gespeichert;
	}

	/**
	 * Einen Arbeitsschritt löschen (#203).
	 *
	 * Wie beim Ändern trägt die **Sichtbarkeit des Vorgangs** die Berechtigung:
	 * `findVisibleStep()` wirft, wenn der Betrachter den Vorgang nicht sehen
	 * darf — dann gibt es den Schritt für ihn nicht. Kein Autor-Check wie beim
	 * Kommentar: Ein Arbeitsschritt ist ein Werkzeug des Vorgangs, keine Äußerung
	 * einer Person. Hart gelöscht; einen Papierkorb gibt es nicht.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException Schritt (bzw. Vorgang) nicht sichtbar
	 */
	public function delete(ViewerContext $viewer, int $stepId): Step {
		$step = $this->findVisibleStep($viewer, $stepId);

		return $this->steps->delete($step);
	}

	/**
	 * Die Zuweisung eines Schritts ankuendigen — **nach** dem Schreiben.
	 *
	 * Steht als eigene Methode da, weil `create()` und `update()` sie beide
	 * brauchen und die Reihenfolge (erst speichern, dann senden) an beiden
	 * Stellen dieselbe sein muss.
	 *
	 * @param Ticket $ticket Der Vorgang, an dem der Schritt haengt.
	 * @param string|null $recipientUid Wem neu zugewiesen wurde, sonst null.
	 * @param ViewerContext $viewer Wer die Zuweisung vorgenommen hat.
	 * @param string $stepTitle Titel des zugewiesenen Schritts — steht dann in der Mail.
	 */
	private function ankuendigen(Ticket $ticket, ?string $recipientUid, ViewerContext $viewer, string $stepTitle): void {
		if ($recipientUid === null) {
			return;
		}

		$vorgemerkt = $this->notifications->announce(
			$ticket,
			$recipientUid,
			$viewer->userId,
			MailOutbox::EVENT_STEP_ASSIGNED,
			$stepTitle,
		);
		$this->notifications->deliver($vorgemerkt, $ticket);
	}

	/**
	 * Wer an diesem Ticket einen Schritt bekommen darf.
	 *
	 * Fuer den Personen-Picker. Die Liste entsteht aus **derselben** Regel, die
	 * die Zuweisung prueft — nicht aus einer zweiten Bedingung im Frontend.
	 * Steht sie hier, kann die Oberflaeche gar nicht erst anbieten, was der
	 * Server danach ablehnen wuerde.
	 *
	 * @return string[] Benutzerkennungen
	 * @throws DoesNotExistException Ticket nicht sichtbar
	 */
	public function assignableFor(ViewerContext $viewer, int $ticketId): array {
		$ticket = $this->tickets->findVisible($viewer, $ticketId);

		$allowed = [];
		foreach ($this->members->findForBoard($viewer) as $member) {
			if ($this->maySee($ticket, (string)$member->getUserId(), (string)$member->getRole())) {
				$allowed[] = (string)$member->getUserId();
			}
		}

		return $allowed;
	}

	/**
	 * Wer an einem **noch nicht angelegten** Vorgang zustaendig sein duerfte (#146).
	 *
	 * Der Anlege-Dialog braucht den Picker, bevor es eine Ticket-ID gibt — und
	 * damit bevor {@see assignableFor()} greift, das ein sichtbares Ticket laedt.
	 * Die Regel bleibt dieselbe: {@see TicketScope::wouldSee()} entscheidet, nur
	 * gegen einen **gedachten** Vorgang, dessen Ersteller der Betrachter selbst
	 * ist. Genau so legt er ihn gleich an; die anlegende Rolle wird am Ticket
	 * eingefroren (§8), und dieselbe Rolle steht hier im Vergleich.
	 *
	 * So bietet der Dialog nie jemanden an, den der Schreibpfad danach ablehnte —
	 * dieselbe Zusicherung wie beim Detail-Picker, ohne eine zweite Fassung der
	 * Regel im Frontend.
	 *
	 * @param string $visibility Die im Dialog gewaehlte Stufe.
	 * @return string[] Benutzerkennungen
	 * @throws \InvalidArgumentException Unbekannte Sichtbarkeit.
	 */
	public function assignableForNew(ViewerContext $viewer, string $visibility): array {
		if (!in_array($visibility, [
			TicketScope::VISIBILITY_PUBLIC,
			TicketScope::VISIBILITY_INTERNAL,
			TicketScope::VISIBILITY_PRIVATE,
		], true)) {
			throw new \InvalidArgumentException('Unbekannte Sichtbarkeit: ' . $visibility);
		}

		$allowed = [];
		foreach ($this->members->findForBoard($viewer) as $member) {
			$sieht = $this->scope->wouldSee(
				$visibility,
				$viewer->userId,
				$viewer->role,
				(string)$member->getUserId(),
				(string)$member->getRole(),
			);
			if ($sieht) {
				$allowed[] = (string)$member->getUserId();
			}
		}

		return $allowed;
	}

	/**
	 * Die Zuweisung setzen oder loeschen — mit der Rollenkopie.
	 *
	 * `assigned_role` wird **kopiert**, nicht verwiesen. Sonst kippte der
	 * Wartezustand rueckwirkend, sobald jemand die Rolle wechselt oder das Board
	 * verlaesst; §5.29 friert sie deshalb ausdruecklich ein. Dasselbe Prinzip
	 * wie `creator_role` am Ticket.
	 *
	 * @throws \InvalidArgumentException die Person darf dieses Ticket nicht sehen
	 */
	private function applyAssignment(Step $step, Ticket $ticket, ViewerContext $viewer, ?string $userId): ?string {
		if ($userId === null || trim($userId) === '') {
			$step->setAssignedUserId(null);
			$step->setAssignedRole(null);
			$step->setAssignedAt(null);

			return null;
		}

		$role = null;
		foreach ($this->members->findForBoard($viewer) as $member) {
			if ((string)$member->getUserId() === $userId) {
				$role = (string)$member->getRole();
				break;
			}
		}

		if ($role === null) {
			// Dieselbe Meldung wie fuer „darf nicht": Ob jemand ueberhaupt
			// Mitglied ist, beantwortet board#show fuer Mitglieder ohnehin —
			// aber unterscheiden muss diese Stelle es nicht.
			throw new \InvalidArgumentException('Diese Person kann diesen Vorgang nicht sehen.');
		}

		if (!$this->maySee($ticket, $userId, $role)) {
			throw new \InvalidArgumentException('Diese Person kann diesen Vorgang nicht sehen.');
		}

		// Nur bei einem Wechsel neu stempeln: Sonst spraenge die Wartezeit bei
		// jeder Titelaenderung auf heute, und die Marke verloere ihren Sinn.
		// **Derselbe Vergleich entscheidet ueber die Benachrichtigung** — wer
		// denselben Namen noch einmal speichert, bekommt keine zweite Mail.
		$wechsel = $step->getAssignedUserId() !== $userId;
		if ($wechsel) {
			$step->setAssignedAt(new \DateTime());
		}

		$step->setAssignedUserId($userId);
		$step->setAssignedRole($role);

		return $wechsel ? $userId : null;
	}

	/**
	 * Dieselbe Frage wie beim Lesen, nur fuer eine andere Person.
	 */
	private function maySee(Ticket $ticket, string $userId, string $role): bool {
		return $this->scope->wouldSee(
			(string)$ticket->getVisibility(),
			(string)$ticket->getCreatorUserId(),
			(string)$ticket->getCreatorRole(),
			$userId,
			$role,
		);
	}

	/**
	 * Ein Schritt, aber nur ueber die gefilterte Ticketmenge.
	 *
	 * Es gibt keine Methode, die „Schritt 42" laedt — nur „die Schritte zu den
	 * Tickets, die dieser Betrachter sehen darf" (§5.8). Der Umweg ist der
	 * Zweck.
	 *
	 * @throws DoesNotExistException
	 */
	private function findVisibleStep(ViewerContext $viewer, int $stepId): Step {
		$ticketIds = array_map(
			static fn (Ticket $ticket): int => (int)$ticket->getId(),
			$this->tickets->findVisibleInBoard($viewer),
		);

		foreach ($this->steps->findForTickets($ticketIds) as $step) {
			if ((int)$step->getId() === $stepId) {
				return $step;
			}
		}

		throw new DoesNotExistException('Arbeitsschritt nicht gefunden.');
	}

	private function nextPosition(int $ticketId): int {
		$positions = array_map(
			static fn (Step $step): int => (int)$step->getPosition(),
			$this->steps->findForTickets([$ticketId]),
		);

		return $positions === [] ? 0 : max($positions) + 1;
	}

	/**
	 * Ein Freitextfeld normalisieren: getrimmt, und leer heißt „keiner" (null).
	 *
	 * So trägt eine geleerte Beschreibung oder ein geleertes Ergebnis dasselbe
	 * `null` wie ein nie gefülltes Feld — die Anzeige muss nicht zwischen „leer"
	 * und „nie gesetzt" unterscheiden.
	 *
	 * @param string|null $wert Der rohe Wert aus der Anfrage.
	 */
	private function leerAlsNull(?string $wert): ?string {
		if ($wert === null) {
			return null;
		}

		$getrimmt = trim($wert);

		return $getrimmt === '' ? null : $getrimmt;
	}

	/**
	 * @throws \InvalidArgumentException kein Datum im Format JJJJ-MM-TT
	 */
	private function parseDueDate(?string $value): ?\DateTime {
		if ($value === null || trim($value) === '') {
			return null;
		}

		$date = \DateTime::createFromFormat('!Y-m-d', trim($value));
		if ($date === false) {
			throw new \InvalidArgumentException('Die Fälligkeit braucht das Format JJJJ-MM-TT.');
		}

		return $date;
	}
}
