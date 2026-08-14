<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\TicketScope;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\AttachmentMapper;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Service\AttachmentsPresentException;
use OCA\Projektwerk\Service\ConflictException;
use OCA\Projektwerk\Service\NotOwningSideException;
use OCA\Projektwerk\Service\PositionService;
use OCA\Projektwerk\Service\TicketService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Server;

/**
 * Der Schreibpfad gegen eine echte Datenbank.
 *
 * Was die Unit-Suite nicht kann: Die atomare Nummer ist eine Eigenschaft der
 * Transaktion und des eindeutigen Index, nicht des PHP-Codes — sie lässt sich
 * nur gegen ein echtes Schema prüfen. Dasselbe gilt für das Neunummerieren
 * einer Spalte.
 */
class TicketWritePathTest extends IntegrationTestCase {

	private LeakMatrixFixture $fixture;
	private TicketService $service;
	private TicketMapper $tickets;

	protected function setUp(): void {
		parent::setUp();

		$this->fixture = new LeakMatrixFixture();
		$this->service = Server::get(TicketService::class);
		$this->tickets = Server::get(TicketMapper::class);
	}

	/**
	 * Ein neues Ticket bekommt die nächste Nummer und landet hinten.
	 */
	public function testCreateAssignsTheNextNumberAndAppends(): void {
		$viewer = $this->viewer(LeakMatrixFixture::ANNA);
		$columnId = $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A];

		$before = $this->tickets->findLastPositionInColumn($viewer, $columnId);

		$ticket = $this->service->create(
			$viewer,
			'Neues Ticket',
			null,
			TicketScope::VISIBILITY_PUBLIC,
			$columnId,
		);

		// Die Fixture hat neun Tickets vergeben, also ist die zehnte an der Reihe.
		$this->assertSame(10, (int)$ticket->getNumber());
		$this->assertGreaterThan((int)$before, (int)$ticket->getPosition());
		$this->assertSame(1, (int)$ticket->getVersion());
		$this->assertSame(LeakMatrixFixture::ANNA, $ticket->getCreatorUserId());
	}

	/**
	 * **Ein frisch angelegtes Ticket hat keinen letzten Bearbeiter.**
	 *
	 * `null` heißt „seit dem Anlegen unverändert". Wer es angelegt hat, steht
	 * in `creator_user_id` und wird hier nicht wiederholt — sonst gäbe es zwei
	 * Felder mit derselben Aussage, die auseinanderlaufen können.
	 */
	public function testCreateLeavesTheLastEditorEmpty(): void {
		$ticket = $this->service->create(
			$this->viewer(LeakMatrixFixture::ANNA),
			'Frisch',
			null,
			TicketScope::VISIBILITY_PUBLIC,
			$this->fixture->columnIds[LeakMatrixFixture::COLUMN_A],
		);

		$this->assertNull($ticket->getLastEditorUserId());
		$this->assertSame(1, (int)$ticket->getVersion());
	}

	/**
	 * **Jeder Schreibweg vermerkt den Urheber — auch Verschieben und
	 * Sichtbarkeitswechsel.**
	 *
	 * `last_editor_user_id` benennt, wer den aktuellen `version`-Stand
	 * verursacht hat. Stünde `version` auf 3 und der Urheber noch bei dem von
	 * Version 2, wäre das Paar eine Lüge — und jede Anzeige, die darauf baut,
	 * ebenfalls.
	 */
	public function testEveryWritePathRecordsTheEditor(): void {
		$anna = $this->viewer(LeakMatrixFixture::ANNA);
		$bert = $this->viewer(LeakMatrixFixture::BERT);
		$ticketId = $this->fixture->ticketIds['public/anna'];

		$updated = $this->service->update($bert, $ticketId, 1, ['title' => 'Von Bert']);
		$this->assertSame(LeakMatrixFixture::BERT, $updated->getLastEditorUserId());

		$moved = $this->service->move(
			$anna,
			$ticketId,
			(int)$updated->getVersion(),
			$this->fixture->columnIds[LeakMatrixFixture::COLUMN_B],
			null,
			null,
		);
		$this->assertSame(
			LeakMatrixFixture::ANNA,
			$moved->getLastEditorUserId(),
			'Verschieben erhöht die Version, vermerkt aber niemanden.',
		);

		// Vorbedingung seit §3.10 Stufe 1 — siehe `ohneAnhaenge()`.
		$this->ohneAnhaenge($ticketId);

		$hidden = $this->service->changeVisibility(
			$bert,
			$ticketId,
			(int)$moved->getVersion(),
			TicketScope::VISIBILITY_INTERNAL,
		);
		$this->assertSame(
			LeakMatrixFixture::BERT,
			$hidden->getLastEditorUserId(),
			'Sichtbarkeitswechsel erhöht die Version, vermerkt aber niemanden.',
		);
	}

	/**
	 * **`creator_role` wird eingefroren, nicht zur Laufzeit ermittelt.**
	 *
	 * Sonst bräche die Symmetrie von `internal`, sobald jemand die Rolle
	 * wechselt oder das Board verlässt — das interne Ticket würde plötzlich für
	 * die andere Seite sichtbar.
	 */
	public function testCreateFreezesTheCreatorRole(): void {
		$internal = $this->service->create(
			$this->viewer(LeakMatrixFixture::ANNA),
			'Von intern',
			null,
			TicketScope::VISIBILITY_INTERNAL,
			$this->fixture->columnIds[LeakMatrixFixture::COLUMN_A],
		);
		$external = $this->service->create(
			$this->viewer(LeakMatrixFixture::CARLA),
			'Von extern',
			null,
			TicketScope::VISIBILITY_INTERNAL,
			$this->fixture->columnIds[LeakMatrixFixture::COLUMN_A],
		);

		$this->assertSame(ViewerContext::ROLE_INTERNAL, $internal->getCreatorRole());
		$this->assertSame(ViewerContext::ROLE_EXTERNAL, $external->getCreatorRole());
	}

	/**
	 * Nummern laufen fortlaufend und ohne Doppelung weiter.
	 *
	 * Nacheinander, nicht gleichzeitig — echte Parallelität lässt sich in einer
	 * Transaktion nicht herstellen. Was der Test prüft, ist das Zusammenspiel
	 * von Zähler und eindeutigem Index; das Wettrennen selbst deckt der Index
	 * ab, nicht dieser Test.
	 */
	public function testNumbersStayUniqueAndConsecutive(): void {
		$viewer = $this->viewer(LeakMatrixFixture::ANNA);
		$columnId = $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A];

		$numbers = [];
		for ($i = 0; $i < 5; $i++) {
			$numbers[] = (int)$this->service
				->create($viewer, 'Nr ' . $i, null, TicketScope::VISIBILITY_PUBLIC, $columnId)
				->getNumber();
		}

		$this->assertSame([10, 11, 12, 13, 14], $numbers);
		$this->assertSame($numbers, array_unique($numbers));
	}

	public function testUnknownVisibilityIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service->create(
			$this->viewer(LeakMatrixFixture::ANNA),
			'Kaputt',
			null,
			'oeffentlich',
			$this->fixture->columnIds[LeakMatrixFixture::COLUMN_A],
		);
	}

	/**
	 * Verschieben zwischen zwei Nachbarn — der Aufrufer nennt IDs, keine Zahl.
	 */
	public function testMoveBetweenNeighbours(): void {
		$viewer = $this->viewer(LeakMatrixFixture::ANNA);
		$columnA = $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A];

		$inColumn = $this->tickets->findVisibleInBoard($viewer, $columnA);
		$this->assertGreaterThanOrEqual(3, count($inColumn));

		$moved = $this->fixture->ticketIds['public/bert'];
		$before = (int)$inColumn[0]->getId();
		$after = (int)$inColumn[1]->getId();

		$result = $this->service->move(
			$viewer,
			$moved,
			(int)$this->tickets->findVisible($viewer, $moved)->getVersion(),
			$columnA,
			$before,
			$after,
		);

		$this->assertSame($columnA, (int)$result->getColumnId());

		$order = array_map(
			static fn ($t): int => (int)$t->getId(),
			$this->tickets->findVisibleInBoard($viewer, $columnA),
		);
		$this->assertSame([$before, $moved, $after], array_slice($order, 0, 3));
	}

	/**
	 * Ganz nach oben und ganz nach unten — beide Ränder.
	 */
	public function testMoveToTheEdges(): void {
		$viewer = $this->viewer(LeakMatrixFixture::ANNA);
		$columnA = $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A];
		$moved = $this->fixture->ticketIds['private/anna'];

		$first = (int)$this->tickets->findVisibleInBoard($viewer, $columnA)[0]->getId();
		$version = (int)$this->tickets->findVisible($viewer, $moved)->getVersion();

		$this->service->move($viewer, $moved, $version, $columnA, null, $first);
		$order = array_map(
			static fn ($t): int => (int)$t->getId(),
			$this->tickets->findVisibleInBoard($viewer, $columnA),
		);
		$this->assertSame($moved, $order[0], 'Nicht ganz nach oben gewandert.');

		$version = (int)$this->tickets->findVisible($viewer, $moved)->getVersion();
		$last = (int)$order[count($order) - 1];
		$this->service->move($viewer, $moved, $version, $columnA, $last, null);
		$order = array_map(
			static fn ($t): int => (int)$t->getId(),
			$this->tickets->findVisibleInBoard($viewer, $columnA),
		);
		$this->assertSame($moved, $order[count($order) - 1], 'Nicht ganz nach unten gewandert.');
	}

	/**
	 * **Ein Nachbar, den der Aufrufer nicht sehen darf, ist kein Nachbar.**
	 *
	 * Carla kennt `private/anna` nicht. Nennt sie dessen ID als Nachbarn,
	 * bekommt sie dieselbe Ausnahme wie für eine ID, die es nicht gibt — die
	 * Fehlerform darf nicht verraten, was die Abfrage nicht verrät.
	 */
	public function testInvisibleNeighbourIsRefusedLikeAMissingOne(): void {
		$viewer = $this->viewer(LeakMatrixFixture::CARLA);
		$columnA = $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A];
		$own = $this->fixture->ticketIds['private/carla'];
		$version = (int)$this->tickets->findVisible($viewer, $own)->getVersion();

		$this->expectException(DoesNotExistException::class);

		$this->service->move(
			$viewer,
			$own,
			$version,
			$columnA,
			$this->fixture->ticketIds['private/anna'],
			null,
		);
	}

	/**
	 * **Ein Nachbar aus einer anderen Spalte ist kein Nachbar.**
	 *
	 * Ohne diese Prüfung würde zwischen Positionen aus zwei Spalten gerechnet.
	 * Das Ergebnis wäre eine gültige Zahl — und das Ticket landete an einer
	 * Stelle, die niemand gemeint hat, ohne dass irgendwo ein Fehler auftaucht.
	 *
	 * Der Test entstand aus einer Gegenprobe: Die Prüfung abzuschalten ließ
	 * zunächst *keinen* Test fallen.
	 */
	public function testNeighbourFromAnotherColumnIsRejected(): void {
		$viewer = $this->viewer(LeakMatrixFixture::ANNA);
		$columnA = $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A];

		$moved = $this->fixture->ticketIds['private/anna'];
		$version = (int)$this->tickets->findVisible($viewer, $moved)->getVersion();

		$this->expectException(\InvalidArgumentException::class);

		$this->service->move(
			$viewer,
			$moved,
			$version,
			$columnA,
			// `internal/anna` steht in Spalte B, nicht in der Zielspalte.
			$this->fixture->ticketIds['internal/anna'],
			null,
		);
	}

	/**
	 * **In eine Spalte, die es nicht gibt, wandert nichts** — weder beim
	 * Verschieben noch beim Anlegen.
	 *
	 * Ohne diese Sperre wäre der Vorgang danach für **niemanden** mehr
	 * erreichbar, auch nicht für den, der ihn sehen darf: Die Board-Ansicht
	 * ordnet Tickets ihren Spalten zu und verwirft stillschweigend, was zu
	 * keiner passt.
	 *
	 * Der Weg dorthin ist keine Bosheit, sondern der Normalfall zweier
	 * geöffneter Browser: Wird eine Spalte entfernt (#60), bietet jeder andere
	 * Client sie weiter an. Die optimistische Sperre fängt das **nicht** — das
	 * Verschieben ganzer Spalten ist keine inhaltliche Änderung am Vorgang und
	 * zählt die Version bewusst nicht hoch. Deshalb muss die Zielspalte selbst
	 * geprüft werden.
	 */
	public function testATicketNeverLandsInAColumnThatDoesNotExist(): void {
		$viewer = $this->viewer(LeakMatrixFixture::ANNA);
		$ticketId = $this->fixture->ticketIds['public/anna'];
		$version = (int)$this->tickets->findVisible($viewer, $ticketId)->getVersion();
		$gone = 999999;

		try {
			$this->service->move($viewer, $ticketId, $version, $gone, null, null);
			$this->fail('Ein Vorgang liess sich in eine Spalte verschieben, die es nicht gibt.');
		} catch (DoesNotExistException) {
			$this->addToAssertionCount(1);
		}

		try {
			$this->service->create($viewer, 'Ins Nichts', null, TicketScope::VISIBILITY_PUBLIC, $gone);
			$this->fail('Ein Vorgang liess sich in einer Spalte anlegen, die es nicht gibt.');
		} catch (DoesNotExistException) {
			$this->addToAssertionCount(1);
		}

		$this->assertSame(
			$this->fixture->columnIds[LeakMatrixFixture::COLUMN_A],
			(int)$this->tickets->findVisible($viewer, $ticketId)->getColumnId(),
			'Der fehlgeschlagene Versuch hat den Vorgang trotzdem bewegt.',
		);
	}

	/**
	 * Und ebenso wenig in eine Spalte eines **fremden** Projekts.
	 *
	 * Das wäre der schwerste Ausgang: Der Vorgang landete in einem Board,
	 * dessen Mitglieder ihn nie sehen durften.
	 */
	public function testATicketNeverLandsInAnotherBoardsColumn(): void {
		$viewer = $this->viewer(LeakMatrixFixture::ANNA);
		$other = Server::get(\OCA\Projektwerk\Service\BoardService::class)->create('lm-neu', 'Fremdes Projekt');
		$otherViewer = Server::get(\OCA\Projektwerk\Access\BoardAccess::class)
			->contextFor('lm-neu', (int)$other->getId());
		$foreign = (int)Server::get(\OCA\Projektwerk\Db\ColumnMapper::class)->findForBoard($otherViewer)[0]->getId();

		$ticketId = $this->fixture->ticketIds['public/anna'];
		$version = (int)$this->tickets->findVisible($viewer, $ticketId)->getVersion();

		$this->expectException(DoesNotExistException::class);

		$this->service->move($viewer, $ticketId, $version, $foreign, null, null);
	}

	/**
	 * Ist die Lücke aufgebraucht, nummeriert der Dienst die Spalte neu — und
	 * das Ticket landet trotzdem dort, wo es hin soll.
	 */
	public function testMoveRebalancesWhenTheGapIsUsedUp(): void {
		$viewer = $this->viewer(LeakMatrixFixture::ANNA);
		$columnA = $this->fixture->columnIds[LeakMatrixFixture::COLUMN_A];

		$inColumn = $this->tickets->findVisibleInBoard($viewer, $columnA);
		$first = $inColumn[0];
		$second = $inColumn[1];

		// Nachbarn kuenstlich auf Abstand 1 setzen — der Zustand, den sonst
		// erst sechzehn Einfuegungen an derselben Stelle herstellen.
		$first->setPosition(1000);
		$second->setPosition(1001);
		$this->tickets->update($first);
		$this->tickets->update($second);

		$moved = $this->fixture->ticketIds['private/anna'];
		$version = (int)$this->tickets->findVisible($viewer, $moved)->getVersion();

		$this->service->move(
			$viewer,
			$moved,
			$version,
			$columnA,
			(int)$first->getId(),
			(int)$second->getId(),
		);

		$order = array_map(
			static fn ($t): int => (int)$t->getId(),
			$this->tickets->findVisibleInBoard($viewer, $columnA),
		);
		$this->assertSame(
			[(int)$first->getId(), $moved, (int)$second->getId()],
			array_slice($order, 0, 3),
		);

		// Und danach ist wieder Platz.
		$positions = new PositionService();
		$reloaded = $this->tickets->findVisibleInBoard($viewer, $columnA);
		for ($i = 1; $i < count($reloaded); $i++) {
			$this->assertFalse(
				$positions->needsRebalance(
					(int)$reloaded[$i - 1]->getPosition(),
					(int)$reloaded[$i]->getPosition(),
				),
				'Nach dem Neunummerieren ist immer noch kein Platz.',
			);
		}
	}

	/**
	 * Ein veralteter `version`-Wert ergibt 409 mit dem aktuellen Stand.
	 */
	public function testStaleVersionConflicts(): void {
		$viewer = $this->viewer(LeakMatrixFixture::ANNA);
		$ticketId = $this->fixture->ticketIds['public/anna'];

		$this->service->update($viewer, $ticketId, 1, ['title' => 'Erste Änderung']);

		try {
			$this->service->update($viewer, $ticketId, 1, ['title' => 'Zweite Änderung']);
			$this->fail('Der veraltete Stand wurde angenommen.');
		} catch (ConflictException $e) {
			$this->assertSame(2, (int)$e->current->getVersion());
			$this->assertSame('Erste Änderung', $e->current->getTitle(), 'Der aktuelle Stand fehlt in der Ausnahme.');
		}
	}

	public function testUpdateRaisesTheVersion(): void {
		$viewer = $this->viewer(LeakMatrixFixture::ANNA);
		$ticketId = $this->fixture->ticketIds['public/anna'];

		$updated = $this->service->update($viewer, $ticketId, 1, ['title' => 'Neuer Titel']);

		$this->assertSame(2, (int)$updated->getVersion());
		$this->assertSame('Neuer Titel', $updated->getTitle());
	}

	/**
	 * Die Sichtbarkeit ändert nur die Seite, der das Ticket gehört (§7).
	 */
	public function testOnlyTheOwningSideChangesVisibility(): void {
		$anna = $this->viewer(LeakMatrixFixture::ANNA);
		$ticketId = $this->fixture->ticketIds['public/anna'];
		$this->ohneAnhaenge($ticketId);

		// Bert ist intern wie Anna — dieselbe Seite, also erlaubt.
		$bert = $this->viewer(LeakMatrixFixture::BERT);
		$changed = $this->service->changeVisibility($bert, $ticketId, 1, TicketScope::VISIBILITY_INTERNAL);
		$this->assertSame(TicketScope::VISIBILITY_INTERNAL, $changed->getVisibility());

		// Und Anna selbst natuerlich auch.
		$changed = $this->service->changeVisibility($anna, $ticketId, 2, TicketScope::VISIBILITY_PUBLIC);
		$this->assertSame(TicketScope::VISIBILITY_PUBLIC, $changed->getVisibility());
	}

	/**
	 * **Ein Vorgang mit Anhängen lässt sich nicht umstellen** (§3.10 Stufe 1).
	 *
	 * Das ist der einzige Punkt, an dem ein Leck physisch würde: Läge die Datei
	 * erst in `90_Austausch`, hätte die Kundenseite sie gesehen, und keine
	 * spätere Codekorrektur nähme das zurück. Solange der Umzug nicht
	 * transaktional zur Datenbank ist (§11.3, Spike S2 offen), wird deshalb gar
	 * nicht erst verschoben.
	 */
	public function testATicketWithAttachmentsKeepsItsVisibility(): void {
		$anna = $this->viewer(LeakMatrixFixture::ANNA);
		$ticketId = $this->fixture->ticketIds['public/anna'];

		// Die Fixture hat einen Anhang daran — hier ausdrücklich **nicht**
		// gelöst.
		try {
			$this->service->changeVisibility($anna, $ticketId, 1, TicketScope::VISIBILITY_INTERNAL);
			$this->fail('Die Sichtbarkeit ließ sich trotz Anhang ändern.');
		} catch (AttachmentsPresentException $e) {
			$this->assertSame(1, $e->count, 'Die Meldung nennt die Zahl, weil sie die Handlung bestimmt.');
		}

		// Und der Vorgang steht unverändert da: Ein abgewiesener Versuch darf
		// nichts halb erledigt hinterlassen.
		$ticket = $this->tickets->findVisible($anna, $ticketId);
		$this->assertSame(TicketScope::VISIBILITY_PUBLIC, $ticket->getVisibility());
		$this->assertSame(1, (int)$ticket->getVersion());
	}

	/**
	 * **Dieselbe Stufe noch einmal zu wählen geht auch mit Anhängen.**
	 *
	 * Es bewegt keine Datei, also gibt es nichts zu verweigern. Ohne diese
	 * Unterscheidung wäre ein Vorgang mit Anhang gegen einen Klick gesperrt,
	 * der gar nichts tut — und die Meldung dazu wäre schlicht unverständlich.
	 */
	public function testChoosingTheSameVisibilityAgainIsNotBlocked(): void {
		$anna = $this->viewer(LeakMatrixFixture::ANNA);
		$ticketId = $this->fixture->ticketIds['public/anna'];

		$unchanged = $this->service->changeVisibility($anna, $ticketId, 1, TicketScope::VISIBILITY_PUBLIC);

		$this->assertSame(TicketScope::VISIBILITY_PUBLIC, $unchanged->getVisibility());
	}

	/**
	 * **„Darf ich" steht vor „geht es".**
	 *
	 * Die andere Seite bekommt die Eigentumsmeldung und **nicht** die Zahl der
	 * Anhänge. Andersherum wäre der Riegel ein Zählwerk für Leute, die den
	 * Vorgang ohnehin nicht umstellen dürfen.
	 */
	public function testTheOwnershipRuleAnswersBeforeTheAttachmentRule(): void {
		$carla = $this->viewer(LeakMatrixFixture::CARLA);
		$ticketId = $this->fixture->ticketIds['public/anna'];

		$this->expectException(NotOwningSideException::class);

		$this->service->changeVisibility($carla, $ticketId, 1, TicketScope::VISIBILITY_INTERNAL);
	}

	/**
	 * Die andere Seite bekommt 403 — nicht 404.
	 *
	 * Der Betrachter sieht das Ticket ja; zu verbergen gibt es nichts mehr.
	 * Genau dieser Fall ist die Begründung der Regel: Sonst könnte ein interner
	 * Mitarbeiter ein Kundenticket so herunterstufen, dass er selbst den
	 * Zugriff verliert.
	 */
	public function testTheOtherSideCannotChangeVisibility(): void {
		$carla = $this->viewer(LeakMatrixFixture::CARLA);
		$ticketId = $this->fixture->ticketIds['public/anna'];

		$this->expectException(NotOwningSideException::class);

		$this->service->changeVisibility($carla, $ticketId, 1, TicketScope::VISIBILITY_INTERNAL);
	}

	/**
	 * Auf `private` herunterstufen kann nur die anlegende Person selbst.
	 */
	public function testOnlyTheCreatorDowngradesToPrivate(): void {
		$bert = $this->viewer(LeakMatrixFixture::BERT);
		$annasTicket = $this->fixture->ticketIds['public/anna'];

		$this->expectException(NotOwningSideException::class);

		// Bert ist dieselbe Seite und dürfte auf `internal` — aber nicht auf
		// `private`, denn das Ticket ist nicht seines.
		$this->service->changeVisibility($bert, $annasTicket, 1, TicketScope::VISIBILITY_PRIVATE);
	}

	public function testTheCreatorMayDowngradeToPrivate(): void {
		$anna = $this->viewer(LeakMatrixFixture::ANNA);
		$ticketId = $this->fixture->ticketIds['public/anna'];
		$this->ohneAnhaenge($ticketId);

		$changed = $this->service->changeVisibility($anna, $ticketId, 1, TicketScope::VISIBILITY_PRIVATE);

		$this->assertSame(TicketScope::VISIBILITY_PRIVATE, $changed->getVisibility());
	}

	/**
	 * Ein Ticket, das der Betrachter nicht sieht, lässt sich auch nicht ändern.
	 */
	public function testInvisibleTicketsCannotBeWritten(): void {
		$carla = $this->viewer(LeakMatrixFixture::CARLA);

		$this->expectException(DoesNotExistException::class);

		$this->service->update(
			$carla,
			$this->fixture->ticketIds['private/anna'],
			1,
			['title' => 'Fremdes Ticket'],
		);
	}

	/**
	 * §7 gilt auch fuer die Zustaendigkeit: An einem internen Ticket der
	 * eigenen Seite hat die Kundenseite nichts zu suchen — sonst bekaeme sie
	 * per Mail Kenntnis von einem Vorgang, den sie nicht sehen darf.
	 */
	public function testResponsibleUserMustBeAbleToSeeTheTicket(): void {
		$anna = $this->viewer(LeakMatrixFixture::ANNA);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->update(
			$anna,
			$this->fixture->ticketIds['internal/anna'],
			1,
			['responsibleUserId' => LeakMatrixFixture::CARLA],
		);
	}

	/**
	 * Ein Nichtmitglied darf nicht zustaendig werden — es koennte den Vorgang,
	 * ueber den es dann per Mail informiert wuerde, gar nicht sehen.
	 */
	public function testResponsibleUserMustBeABoardMember(): void {
		$anna = $this->viewer(LeakMatrixFixture::ANNA);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->update(
			$anna,
			$this->fixture->ticketIds['public/anna'],
			1,
			['responsibleUserId' => LeakMatrixFixture::FREMD],
		);
	}

	/**
	 * Die Gegenprobe: Wer das Ticket sehen wuerde, darf auch zustaendig
	 * werden.
	 */
	public function testAVisibleMemberCanBecomeResponsible(): void {
		$anna = $this->viewer(LeakMatrixFixture::ANNA);

		$updated = $this->service->update(
			$anna,
			$this->fixture->ticketIds['internal/anna'],
			1,
			['responsibleUserId' => LeakMatrixFixture::BERT],
		);

		$this->assertSame(LeakMatrixFixture::BERT, $updated->getResponsibleUserId());
	}

	/**
	 * Der Kern von #114 am Schreibpfad: Wer einen Verantwortlichen der
	 * Kundenseite eintraegt, friert dessen Rolle ein und stellt die Uhr — und
	 * der Vorgang wartet danach auf die Kundenseite, **ganz ohne einen
	 * Arbeitsschritt**.
	 */
	public function testAnExternalResponsibleMakesASteplessTicketWaitOnTheCustomer(): void {
		$anna = $this->viewer(LeakMatrixFixture::ANNA);
		$ticketId = $this->fixture->ticketIds['public/anna'];

		$this->service->update($anna, $ticketId, 1, ['responsibleUserId' => LeakMatrixFixture::CARLA]);

		// Frisch aus der Datenbank, nicht die Rueckgabe: geprueft wird, was
		// tatsaechlich geschrieben wurde.
		$reloaded = $this->tickets->findVisible($anna, $ticketId);

		$this->assertSame(LeakMatrixFixture::CARLA, $reloaded->getResponsibleUserId());
		$this->assertSame(ViewerContext::ROLE_EXTERNAL, $reloaded->getResponsibleRole());
		$this->assertNotNull($reloaded->getResponsibleSince());
		$this->assertTrue($reloaded->waitsOnExternal());
	}

	/**
	 * Die Gegenprobe: Wird der Verantwortliche entfernt, gehen die eingefrorene
	 * Rolle und der Zeitpunkt mit — ueber diese Quelle wartet der Vorgang dann
	 * auf niemanden mehr.
	 */
	public function testRemovingTheResponsibleClearsTheFrozenRoleAndClock(): void {
		$anna = $this->viewer(LeakMatrixFixture::ANNA);
		$ticketId = $this->fixture->ticketIds['public/anna'];

		$this->service->update($anna, $ticketId, 1, ['responsibleUserId' => LeakMatrixFixture::CARLA]);
		$mitVerantwortlichem = $this->tickets->findVisible($anna, $ticketId);

		$this->service->update($anna, $ticketId, $mitVerantwortlichem->getVersion(), ['responsibleUserId' => null]);
		$reloaded = $this->tickets->findVisible($anna, $ticketId);

		$this->assertNull($reloaded->getResponsibleUserId());
		$this->assertNull($reloaded->getResponsibleRole());
		$this->assertNull($reloaded->getResponsibleSince());
		$this->assertFalse($reloaded->waitsOnExternal());
	}

	/**
	 * **Dieselbe Regel beim Anlegen** — die zweite Haelfte des Befunds vom
	 * 2026-08-11.
	 *
	 * `create()` benachrichtigt heute nicht, hier kann also keine Mail lecken.
	 * Ohne die Pruefung entstuende aber eine zustaendige Person, die ihren
	 * eigenen Vorgang nicht sehen kann — und sobald das Anlegen spaeter
	 * ebenfalls benachrichtigt, waere es genau die Luecke, die im Aendern
	 * gerade geschlossen wurde.
	 */
	public function testANewTicketCannotBeAssignedToSomeoneWhoCannotSeeIt(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service->create(
			$this->viewer(LeakMatrixFixture::ANNA),
			'Intern, aber der Kundenseite zugewiesen',
			null,
			TicketScope::VISIBILITY_INTERNAL,
			$this->fixture->columnIds[LeakMatrixFixture::COLUMN_A],
			LeakMatrixFixture::CARLA,
		);
	}

	/**
	 * Gegenprobe: Wer den neuen Vorgang sehen wuerde, darf zustaendig sein.
	 */
	public function testANewTicketCanBeAssignedToSomeoneWhoSeesIt(): void {
		$ticket = $this->service->create(
			$this->viewer(LeakMatrixFixture::ANNA),
			'Intern, an die eigene Seite',
			null,
			TicketScope::VISIBILITY_INTERNAL,
			$this->fixture->columnIds[LeakMatrixFixture::COLUMN_A],
			LeakMatrixFixture::BERT,
		);

		$this->assertSame(LeakMatrixFixture::BERT, $ticket->getResponsibleUserId());
	}

	private function viewer(string $userId): ViewerContext {
		return $this->fixture->contextFor($userId);
	}

	/**
	 * Die Anhänge eines Vorgangs lösen — die **Vorbedingung** jeder
	 * Sichtbarkeitsänderung (§3.10 Stufe 1).
	 *
	 * Die Fixture hängt an jeden ihrer Vorgänge genau einen Anhang, damit die
	 * Leak-Matrix auch diesen Lesepfad abdeckt. Seit der Riegel steht, heißt
	 * das: Wer hier umstellen will, muss vorher lösen — genau wie die Person
	 * vor dem Bildschirm.
	 *
	 * Direkt über den Mapper und nicht über den Dienst: Der greift auf den
	 * Dateibaum zu, und diese Tests haben keinen. Was hier geprüft wird, ist
	 * die Sichtbarkeitsregel, nicht das Anhängen.
	 *
	 * @param int $ticketId Der Vorgang.
	 */
	private function ohneAnhaenge(int $ticketId): void {
		$attachments = Server::get(AttachmentMapper::class);

		foreach ($attachments->findForTickets([$ticketId]) as $attachment) {
			$attachments->delete($attachment);
		}
	}

	/**
	 * **Nur der Uebergang benachrichtigt, nicht der Zustand** (#98).
	 *
	 * Ein zweites `closed: true` an einem bereits geschlossenen Vorgang darf
	 * keine zweite Runde ausloesen. Der naheliegende Fehler waere gewesen, auf
	 * `$changes['closed']` zu schauen statt auf den Stand davor — dann schickte
	 * jeder Speichervorgang an einem geschlossenen Vorgang erneut „Eure Sache
	 * ist durch" an alle Beteiligten.
	 *
	 * Gezaehlt wird im Ausgangskorb und nicht an der Glocke: Die Mail ist der
	 * Weg, auf den sich ein Gast verlaesst, und sie ist nachzaehlbar.
	 */
	public function testClosingNotifiesOnceNotOnEverySave(): void {
		$bert = $this->viewer(LeakMatrixFixture::BERT);
		$ticketId = $this->fixture->ticketIds['public/anna'];

		$vorher = $this->geschlossenZeilen($ticketId);

		$zu = $this->service->update($bert, $ticketId, 1, ['closed' => true]);
		$einmal = $this->geschlossenZeilen($ticketId);
		$this->assertGreaterThan($vorher, $einmal, 'Das Schliessen muss ankuendigen.');

		// Noch einmal dasselbe — der Vorgang ist bereits geschlossen.
		$this->service->update($bert, $ticketId, (int)$zu->getVersion(), ['closed' => true]);

		$this->assertSame(
			$einmal,
			$this->geschlossenZeilen($ticketId),
			'Ein zweites „closed: true" ist kein Uebergang und darf nicht erneut ausloesen.',
		);
	}

	/**
	 * Zeilen im Ausgangskorb, die zum Schliessen dieses Vorgangs gehoeren.
	 *
	 * **Direkt auf der Tabelle und nicht ueber den Mapper.** Der Mapper hat
	 * keine Methode „alle Zeilen" — und er bekommt hier auch keine: Eine
	 * Produktionsmethode, die es nur gibt, weil ein Test sie braucht, ist eine
	 * Lesemoeglichkeit mehr, als das Produkt kennt.
	 *
	 * @param int $ticketId Der Vorgang.
	 */
	private function geschlossenZeilen(int $ticketId): int {
		$db = Server::get(\OCP\IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'anzahl'))
			->from('pwerk_mail_outbox')
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('event', $qb->createNamedParameter(\OCA\Projektwerk\Db\MailOutbox::EVENT_TICKET_CLOSED)));

		$ergebnis = $qb->executeQuery();
		$anzahl = (int)$ergebnis->fetchOne();
		$ergebnis->closeCursor();

		return $anzahl;
	}
}
