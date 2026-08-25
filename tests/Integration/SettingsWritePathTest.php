<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Integration;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Controller\SettingsController;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\ColumnMapper;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Db\TicketMapper;
use OCA\Projektwerk\Service\BoardService;
use OCA\Projektwerk\Service\ColumnService;
use OCA\Projektwerk\Service\MemberService;
use OCA\Projektwerk\Service\NotManagerException;
use OCA\Projektwerk\Service\NotOwnerException;
use OCA\Projektwerk\Service\TicketService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IRequest;
use OCP\Server;

/**
 * Die Verwaltung: Projekt, Spalten, Mitglieder.
 *
 * Der rote Faden ist eine einzige Regel aus §8 — pflegen darf nur ein
 * **internes Mitglied mit Verwaltungsrecht** — und drei Stellen, an denen sie
 * gilt. Jede wird einzeln geprüft: Eine Sperre, die nur an zwei von drei
 * Stellen greift, ist keine.
 */
class SettingsWritePathTest extends IntegrationTestCase {

	private LeakMatrixFixture $fixture;
	private BoardService $boardService;
	private ColumnService $columnService;
	private MemberService $memberService;

	protected function setUp(): void {
		parent::setUp();

		$this->fixture = new LeakMatrixFixture();
		$this->boardService = Server::get(BoardService::class);
		$this->columnService = Server::get(ColumnService::class);
		$this->memberService = Server::get(MemberService::class);
	}

	/**
	 * **Anlegen erzeugt Board und Mitgliedschaft zusammen.**
	 *
	 * Bliebe ein Board ohne Mitglied zurück, käme mangels Admin-Ausnahme
	 * niemand mehr heran — es wäre für immer unerreichbar und ließe sich nicht
	 * einmal löschen.
	 */
	public function testCreatingABoardMakesTheCreatorAnInternalManager(): void {
		$board = $this->boardService->create('lm-neu', 'Neues Projekt', null, 'cpcMomentum', 'Kunde GmbH');

		$viewer = Server::get(BoardAccess::class)->contextFor('lm-neu', (int)$board->getId());

		$this->assertSame('lm-neu', $board->getOwnerUserId());
		$this->assertSame(ViewerContext::ROLE_INTERNAL, $viewer->role);
		$this->assertTrue($viewer->isManager, 'Wer anlegt, muss verwalten dürfen.');
		$this->assertSame('cpcMomentum', $board->getOrgInternal());
		$this->assertSame('Kunde GmbH', $board->getOrgExternal());
	}

	public function testABoardNeedsATitle(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->boardService->create('lm-neu', '   ');
	}

	/**
	 * Leere Felder werden zu `null`, nicht zu leeren Zeichenketten.
	 *
	 * Sonst müsste jede Anzeige zwei Formen von „nichts" unterscheiden — und
	 * der Knopf „Zum Projektchat" entfällt laut §9 genau dann, wenn keine
	 * Adresse hinterlegt ist.
	 */
	public function testEmptyFieldsBecomeNull(): void {
		$board = $this->boardService->update($this->manager(), ['chatUrl' => '   ', 'orgExternal' => '']);

		$this->assertNull($board->getChatUrl());
		$this->assertNull($board->getOrgExternal());
	}

	public function testUpdatingTheBoardKeepsUntouchedFields(): void {
		$before = Server::get(BoardMapper::class)->findForViewer($this->manager());

		$board = $this->boardService->update($this->manager(), ['title' => 'Umbenannt']);

		$this->assertSame('Umbenannt', $board->getTitle());
		$this->assertSame($before->getOrgInternal(), $board->getOrgInternal(), 'Nicht genanntes Feld verändert.');
	}

	public function testArchivingAndBack(): void {
		$this->assertSame(1, (int)$this->boardService->setArchived($this->manager(), true)->getArchived());
		$this->assertSame(0, (int)$this->boardService->setArchived($this->manager(), false)->getArchived());
	}

	/**
	 * Bert ist intern, aber ohne Verwaltungsrecht — an allen drei Stellen
	 * abgewiesen.
	 */
	public function testWithoutTheManagementRightEverythingIsRefused(): void {
		$bert = $this->fixture->contextFor(LeakMatrixFixture::BERT);
		$this->assertFalse($bert->isManager);

		$refused = 0;

		foreach ([
			fn () => $this->boardService->update($bert, ['title' => 'Fremd']),
			fn () => $this->boardService->setArchived($bert, true),
			fn () => $this->columnService->create($bert, 'Neue Spalte'),
			fn () => $this->columnService->rename($bert, $this->columnId(LeakMatrixFixture::COLUMN_A), 'Anders'),
			fn () => $this->columnService->setFinalOutcome($bert, $this->columnId(LeakMatrixFixture::COLUMN_A), 'done'),
			fn () => $this->columnService->reorder($bert, []),
			// Das Entfernen sitzt sogar enger — nur der Eigentuemer. Hier steht
			// es trotzdem, weil die Verwaltungssperre die erste ist, die greift.
			fn () => $this->columnService->delete(
				$bert,
				$this->columnId(LeakMatrixFixture::COLUMN_A),
				$this->columnId(LeakMatrixFixture::COLUMN_B),
			),
			fn () => $this->memberService->add($bert, 'lm-neu', ViewerContext::ROLE_INTERNAL),
			fn () => $this->memberService->update($bert, LeakMatrixFixture::CARLA, ['role' => ViewerContext::ROLE_INTERNAL]),
			// **Auch die Dateiablage.** Sie ist der Ort, an dem die Sichtbarkeit
			// physisch wird (§3.10) — wer den Austauschordner umhängen könnte,
			// könnte damit den Ablageort jedes künftigen Anhangs bestimmen.
			// Ein leerer Pfad, damit hier keine Datei gebraucht wird: Die
			// Verwaltungssperre greift vor jeder Auflösung, und genau das ist
			// die Behauptung.
			fn () => $this->boardService->update($bert, ['folderPublicPath' => '']),
			fn () => $this->boardService->update($bert, ['folderInternalPath' => '']),
		] as $attempt) {
			try {
				$attempt();
				$this->fail('Ein Schreibvorgang ohne Verwaltungsrecht ging durch.');
			} catch (NotManagerException) {
				$refused++;
			}
		}

		$this->assertSame(11, $refused);
	}

	/**
	 * Ein leerer Pfad entfernt die Zuordnung — ohne den Dateibaum anzufassen.
	 *
	 * Das ist mehr als eine Randbedingung: `null` ist der Zustand eines frisch
	 * angelegten Projekts, und ein Board ohne Ordner muss ein **gültiger**
	 * Zustand sein und keiner, der halb eingerichtet aussieht. An solchen
	 * Vorgängen gibt es dann schlicht keine Anhänge (§3.10).
	 */
	public function testAnEmptyPathClearsTheFolderAssignment(): void {
		$board = $this->boardService->update($this->manager(), [
			'folderPublicPath' => '',
			'folderInternalPath' => '',
		]);

		$this->assertNull($board->getFolderPublicId());
		$this->assertNull($board->getFolderPublicPath());
		$this->assertNull($board->getFolderInternalId());
		$this->assertNull($board->getFolderInternalPath());
	}

	/**
	 * **Ein externes Mitglied kann kein Verwalter sein — auch nicht auf dem
	 * Umweg über einen Rollenwechsel.**
	 *
	 * §8: Das Recht ist nur an interne Mitglieder vergebbar. Der Kontext
	 * entschärft ein falsch gesetztes Flag bereits beim Bauen; hier wird es gar
	 * nicht erst geschrieben. Einmal richtig schreiben ist besser als überall
	 * entschärfen.
	 */
	public function testTheManagementRightNeverSticksToAnExternalMember(): void {
		$manager = $this->manager();

		$carla = $this->memberService->update($manager, LeakMatrixFixture::CARLA, ['isManager' => true]);
		$this->assertSame(0, (int)$carla->getIsManager(), 'Extern mit Verwaltungsrecht in der Datenbank.');

		// Und der umgekehrte Weg: erst intern und Verwalter, dann extern.
		$this->memberService->update($manager, LeakMatrixFixture::CARLA, [
			'role' => ViewerContext::ROLE_INTERNAL,
			'isManager' => true,
		]);
		$demoted = $this->memberService->update($manager, LeakMatrixFixture::CARLA, [
			'role' => ViewerContext::ROLE_EXTERNAL,
		]);

		$this->assertSame(ViewerContext::ROLE_EXTERNAL, $demoted->getRole());
		$this->assertSame(0, (int)$demoted->getIsManager(), 'Das Recht hat den Rollenwechsel überlebt.');
	}

	/**
	 * Der Eigentümer behält das Verwaltungsrecht — auf beiden Wegen (§8).
	 */
	public function testTheOwnerKeepsTheManagementRight(): void {
		$manager = $this->manager();

		try {
			$this->memberService->update($manager, LeakMatrixFixture::ANNA, ['isManager' => false]);
			$this->fail('Dem Eigentümer wurde das Verwaltungsrecht entzogen.');
		} catch (\InvalidArgumentException) {
			$this->addToAssertionCount(1);
		}

		try {
			$this->memberService->update($manager, LeakMatrixFixture::ANNA, ['role' => ViewerContext::ROLE_EXTERNAL]);
			$this->fail('Der Eigentümer wurde extern — und verlöre damit dasselbe Recht.');
		} catch (\InvalidArgumentException) {
			$this->addToAssertionCount(1);
		}
	}

	public function testAddingSomebodyTwiceIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->memberService->add($this->manager(), LeakMatrixFixture::BERT, ViewerContext::ROLE_INTERNAL);
	}

	public function testUnknownRoleIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->memberService->update($this->manager(), LeakMatrixFixture::BERT, ['role' => 'kunde']);
	}

	/**
	 * Der Name an der Mitgliedschaft lässt sich setzen und wieder leeren.
	 *
	 * Leer heißt „Anzeigename aus Nextcloud", nicht „leerer Name" — sonst stünde
	 * auf der Karte gar nichts.
	 */
	public function testTheMembershipNameCanBeSetAndCleared(): void {
		$manager = $this->manager();

		$named = $this->memberService->update($manager, LeakMatrixFixture::BERT, ['displayName' => ' Bert König ']);
		$this->assertSame('Bert König', $named->getDisplayName());

		$cleared = $this->memberService->update($manager, LeakMatrixFixture::BERT, ['displayName' => '']);
		$this->assertNull($cleared->getDisplayName());
	}

	/**
	 * Ohne Übersteuern steht nie eine leere Zeile da.
	 *
	 * `display_name` ist ein Übersteuern, kein Pflichtfeld. Fehlt es, muss der
	 * Server den Anzeigenamen aus Nextcloud einsetzen und notfalls die Kennung —
	 * das Frontend kann es nicht: Nextclouds Personensuche liefert in einer
	 * Gast-Sitzung prinzipbedingt eine leere Liste.
	 *
	 * Geprüft wird deshalb, dass `resolvedName` **immer gefüllt** ist und dem
	 * Übersteuern folgt, wo eines steht. Der mittlere Fall — Name aus Nextcloud
	 * — steht bewusst nicht als Erwartung drin: Im CLI ist nur das
	 * Datenbank-Backend geladen, ein Gastkonto hätte dort keinen Namen, und ein
	 * Test, der das behauptet, prüfte die Testumgebung statt den Code.
	 */
	public function testEveryMemberCarriesANameToShow(): void {
		$manager = $this->manager();

		$this->memberService->update($manager, LeakMatrixFixture::BERT, ['displayName' => 'Bert König']);
		$this->memberService->update($manager, LeakMatrixFixture::CARLA, ['displayName' => '']);

		$byUser = [];
		foreach ($this->memberService->listForBoard($manager) as $member) {
			$byUser[$member['userId']] = $member;
		}

		$this->assertSame('Bert König', $byUser[LeakMatrixFixture::BERT]['resolvedName']);

		foreach ($byUser as $userId => $member) {
			$this->assertNotSame('', $member['resolvedName'], $userId . ': keine Zeile darf namenlos bleiben.');
			$this->assertArrayHasKey(
				'displayName',
				$member,
				$userId . ': Das Übersteuern muss daneben stehen bleiben — die Verwaltung bearbeitet es.',
			);
		}

		$this->assertNull(
			$byUser[LeakMatrixFixture::CARLA]['displayName'],
			'Ein geleertes Übersteuern darf nicht durch den aufgelösten Namen ersetzt werden — '
			. 'sonst friert das nächste Speichern den Nextcloud-Namen versehentlich ein.',
		);
	}

	public function testColumnsAreAppendedAndRenamed(): void {
		$manager = $this->manager();

		$created = $this->columnService->create($manager, ' Wartet auf Kunde ');
		$this->assertSame('Wartet auf Kunde', $created->getTitle());
		$this->assertSame(2, (int)$created->getPosition(), 'Die neue Spalte gehört ans Ende.');

		$renamed = $this->columnService->rename($manager, (int)$created->getId(), 'Abgestimmt');
		$this->assertSame('Abgestimmt', $renamed->getTitle());
	}

	/**
	 * **Eine Spalte lässt sich als Endspalte markieren** (#172) — mit Ergebnis,
	 * und die Markierung lässt sich wieder nehmen (`null`). Ein unbekannter Wert
	 * wird abgewiesen, nicht still gedeutet: An der Spalte ist es eine
	 * ausdrückliche Einstellung, kein Nebenprodukt einer Handlung.
	 */
	public function testAColumnCanBeMarkedAsFinalAndCleared(): void {
		$manager = $this->manager();
		$columnId = (int)$this->columnService->create($manager, 'Verworfen')->getId();

		$this->assertSame('discarded', $this->columnService->setFinalOutcome($manager, $columnId, 'discarded')->getFinalOutcome());
		$this->assertSame('done', $this->columnService->setFinalOutcome($manager, $columnId, 'done')->getFinalOutcome());
		$this->assertNull($this->columnService->setFinalOutcome($manager, $columnId, null)->getFinalOutcome(), 'null nimmt die Markierung.');

		$this->expectException(\InvalidArgumentException::class);
		$this->columnService->setFinalOutcome($manager, $columnId, 'bogus');
	}

	/**
	 * **Eine unvollständige Reihenfolge wird abgewiesen, nicht still ergänzt.**
	 *
	 * Sonst entschiede über die nicht genannten Spalten der Zufall, und niemand
	 * könnte erklären, warum eine Spalte gewandert ist, die niemand angefasst
	 * hat.
	 */
	public function testReorderingDemandsEveryColumn(): void {
		$manager = $this->manager();
		$a = $this->columnId(LeakMatrixFixture::COLUMN_A);
		$b = $this->columnId(LeakMatrixFixture::COLUMN_B);

		$ordered = $this->columnService->reorder($manager, [$b, $a]);
		$this->assertSame([$b, $a], array_map(static fn ($c): int => (int)$c->getId(), $ordered));
		$this->assertSame(
			[$b, $a],
			array_map(
				static fn ($c): int => (int)$c->getId(),
				Server::get(ColumnMapper::class)->findForBoard($manager),
			),
			'Die gespeicherte Reihenfolge weicht ab.',
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->columnService->reorder($manager, [$a]);
	}

	/**
	 * **Ein neues Board bringt sechs Spalten mit — und die erste ist der
	 * Eingang, nicht die Zusage.**
	 *
	 * Auf einem geteilten Board meldet der Kunde etwas. Ohne erste Spalte fiele
	 * „wir haben es" mit „wir machen es" zusammen, weil jedes neue Ticket sofort
	 * unter „Eingeplant" stünde.
	 *
	 * Keine Spalte heißt „Wartet auf Kunde": Der Wartezustand liegt laut §9 quer
	 * zu den Spalten und ist ein Filterschalter, kein Ort. Der Test hält das
	 * fest, weil es beim nächsten Nachdenken über die Vorgabe die naheliegendste
	 * falsche Ergänzung wäre.
	 */
	public function testANewBoardStartsWithTheDefaultColumns(): void {
		$board = $this->boardService->create('lm-neu', 'Mit Vorgabe');
		$viewer = Server::get(BoardAccess::class)->contextFor('lm-neu', (int)$board->getId());

		$columns = Server::get(ColumnMapper::class)->findForBoard($viewer);
		$titles = array_map(static fn ($c): string => (string)$c->getTitle(), $columns);

		// Verglichen wird gegen die uebersetzte Vorgabe, nicht gegen deutsche
		// Woerter: Die Spalten entstehen in der Sprache der anlegenden Person,
		// und die Testumgebung laeuft auf Englisch. Welche Woerter in der
		// Vorgabe stehen, prueft DefaultColumnsTest containerfrei.
		$l10n = Server::get(\OCP\L10N\IFactory::class)->get('projektwerk');
		$expected = array_map(
			static fn (string $title): string => $l10n->t($title),
			BoardService::DEFAULT_COLUMNS,
		);

		$this->assertSame($expected, $titles);
		$this->assertSame($l10n->t('Eingegangen'), $titles[0], 'Die erste Spalte ist der Eingang.');
		$this->assertNotContains($l10n->t('Wartet auf Kunde'), $titles);
		$this->assertCount(1, Server::get(MemberMapper::class)->findForBoard($viewer));
	}

	/**
	 * Die Vorgabe ist eine Vorgabe, kein Gesetz: Sie lässt sich sofort
	 * umbenennen und umsortieren.
	 */
	public function testTheDefaultColumnsAreOrdinaryData(): void {
		$board = $this->boardService->create('lm-neu', 'Mit Vorgabe');
		$viewer = Server::get(BoardAccess::class)->contextFor('lm-neu', (int)$board->getId());

		$columns = Server::get(ColumnMapper::class)->findForBoard($viewer);
		$renamed = $this->columnService->rename($viewer, (int)$columns[0]->getId(), 'Posteingang');

		$this->assertSame('Posteingang', $renamed->getTitle());
	}

	/**
	 * **Das Kernversprechen von #60: Es geht nichts verloren.**
	 *
	 * Geprüft wird nicht „die Spalte ist weg", sondern die Ticketzahl **je
	 * Betrachter einzeln** — fünf Zahlen vorher, dieselben fünf nachher. Eine
	 * Gesamtzahl reichte nicht: Sie bliebe auch dann gleich, wenn genau die
	 * Vorgänge verschwänden, die niemand ausser Anna sieht, und ein anderer
	 * Fehler sie zugleich sichtbar machte. Fünf getrennte Zahlen können das
	 * nicht zugleich erfüllen.
	 *
	 * Der Fremde ist mit drin, obwohl er nichts sieht: Seine Null muss eine
	 * Null bleiben.
	 */
	public function testRemovingAColumnLeavesEveryViewersTicketCountUntouched(): void {
		$tickets = Server::get(TicketMapper::class);

		$before = [];
		foreach ($this->allViewers() as $userId => $viewer) {
			$before[$userId] = $tickets->countVisibleInBoard($viewer);
		}

		$this->columnService->delete(
			$this->manager(),
			$this->columnId(LeakMatrixFixture::COLUMN_A),
			$this->columnId(LeakMatrixFixture::COLUMN_B),
		);

		foreach ($this->allViewers() as $userId => $viewer) {
			$this->assertSame(
				$before[$userId],
				$tickets->countVisibleInBoard($viewer),
				$userId . ' sieht nach dem Entfernen der Spalte eine andere Zahl von Vorgängen.',
			);
		}

		$remaining = Server::get(ColumnMapper::class)->findForBoard($this->manager());
		$this->assertCount(1, $remaining, 'Die Spalte ist nicht weggefallen.');
		$this->assertSame(LeakMatrixFixture::COLUMN_B, (string)$remaining[0]->getTitle());
	}

	/**
	 * Auch die **verborgenen** Vorgänge sind mitgewandert.
	 *
	 * Der Test davor kann das nicht zeigen: Er zählt je Betrachter, und was
	 * niemand zählt, könnte in einer Spalte zurückbleiben, die es nicht mehr
	 * gibt. Deshalb hier einmal ungefiltert direkt in der Tabelle — im Test ist
	 * das erlaubt, der Architekturwächter durchsucht `lib/`.
	 */
	public function testEveryTicketMovedAlongEvenTheHiddenOnes(): void {
		$source = $this->columnId(LeakMatrixFixture::COLUMN_A);
		$target = $this->columnId(LeakMatrixFixture::COLUMN_B);

		$this->columnService->delete($this->manager(), $source, $target);

		$this->assertSame(0, $this->rawCountInColumn($source), 'In der entfernten Spalte stehen noch Vorgänge.');
		$this->assertSame(
			count(LeakMatrixFixture::TICKETS),
			$this->rawCountInColumn($target),
			'Nicht alle Vorgänge sind in der Zielspalte angekommen.',
		);
	}

	/**
	 * **Weich gelöschte Vorgänge wandern mit.**
	 *
	 * Sie sind aus jeder Abfrage genommen, aber sie existieren. Blieben sie
	 * zurück, zeigte ein per `occ projektwerk:ticket:restore` zurückgeholter
	 * Vorgang auf eine Spalte, die es nicht mehr gibt — und wäre für niemanden
	 * mehr erreichbar.
	 */
	public function testASoftDeletedTicketMovesAlongToo(): void {
		$manager = $this->manager();
		$source = $this->columnId(LeakMatrixFixture::COLUMN_A);
		$target = $this->columnId(LeakMatrixFixture::COLUMN_B);

		$ticketId = $this->fixture->ticketIds['public/anna'];
		$ticket = Server::get(TicketMapper::class)->findVisible($manager, $ticketId);
		Server::get(TicketService::class)->delete($manager, $ticketId, (int)$ticket->getVersion());

		$this->columnService->delete($manager, $source, $target);

		$this->assertSame($target, $this->rawColumnOf($ticketId), 'Der weich gelöschte Vorgang ist zurückgeblieben.');
	}

	/**
	 * Die Vorgänge landen **hinter** denen der Zielspalte, in ihrer bisherigen
	 * Reihenfolge (§3.8).
	 *
	 * Geprüft aus Annas Sicht, weil sie als Einzige alle neun sieht: Die
	 * Reihenfolge stimmt genau dann, wenn Spalte B unverändert vorn steht und
	 * die Vorgänge aus A geschlossen dahinter folgen.
	 */
	public function testMovedTicketsAreAppendedInOrder(): void {
		$manager = $this->manager();
		$tickets = Server::get(TicketMapper::class);
		$target = $this->columnId(LeakMatrixFixture::COLUMN_B);

		$inA = $this->orderedLabels($tickets->findVisibleInBoard($manager, $this->columnId(LeakMatrixFixture::COLUMN_A)));
		$inB = $this->orderedLabels($tickets->findVisibleInBoard($manager, $target));

		$this->columnService->delete($manager, $this->columnId(LeakMatrixFixture::COLUMN_A), $target);

		$this->assertSame(
			array_merge($inB, $inA),
			$this->orderedLabels($tickets->findVisibleInBoard($manager, $target)),
			'Die Vorgänge stehen nicht hinten an oder haben ihre Reihenfolge verloren.',
		);
	}

	/**
	 * **Verwaltungsrecht reicht nicht — es muss der Eigentümer sein.**
	 *
	 * Bert wird dafür eigens zum Verwalter gemacht: Der Test soll an der
	 * Eigentümerfrage scheitern und nicht schon an der Stufe davor, sonst
	 * prüfte er die falsche Sperre.
	 */
	public function testOnlyTheOwnerMayRemoveAColumn(): void {
		$this->memberService->update($this->manager(), LeakMatrixFixture::BERT, ['isManager' => true]);
		$bert = $this->fixture->contextFor(LeakMatrixFixture::BERT);
		$this->assertTrue($bert->isManager, 'Der Test prüft sonst die Verwaltungssperre statt der Eigentümersperre.');

		$this->expectException(NotOwnerException::class);

		$this->columnService->delete(
			$bert,
			$this->columnId(LeakMatrixFixture::COLUMN_A),
			$this->columnId(LeakMatrixFixture::COLUMN_B),
		);
	}

	public function testTheTargetMustBeAnotherColumn(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->columnService->delete(
			$this->manager(),
			$this->columnId(LeakMatrixFixture::COLUMN_A),
			$this->columnId(LeakMatrixFixture::COLUMN_A),
		);
	}

	/**
	 * Ein Ziel aus einem fremden Board ist keins.
	 *
	 * Sonst wanderten Vorgänge in ein Projekt, dessen Mitglieder sie nie sehen
	 * durften — der schwerste denkbare Ausgang dieses Vorgangs.
	 */
	public function testTheTargetMustBelongToTheSameBoard(): void {
		$other = $this->boardService->create('lm-neu', 'Fremdes Projekt');
		$otherViewer = Server::get(BoardAccess::class)->contextFor('lm-neu', (int)$other->getId());
		$foreign = (int)Server::get(ColumnMapper::class)->findForBoard($otherViewer)[0]->getId();

		$this->expectException(DoesNotExistException::class);

		$this->columnService->delete($this->manager(), $this->columnId(LeakMatrixFixture::COLUMN_A), $foreign);
	}

	/**
	 * **Die letzte Spalte bleibt stehen** — es gäbe kein Ziel.
	 *
	 * Die Fixture hat zwei Spalten; nach dem ersten Entfernen ist eine übrig,
	 * und der zweite Versuch muss an dieser Regel scheitern und nicht an
	 * „Zielspalte unbekannt". Der Unterschied ist die Meldung, die der Benutzer
	 * liest.
	 */
	public function testTheLastColumnCannotBeRemoved(): void {
		$manager = $this->manager();
		$a = $this->columnId(LeakMatrixFixture::COLUMN_A);
		$b = $this->columnId(LeakMatrixFixture::COLUMN_B);

		$this->columnService->delete($manager, $a, $b);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('letzte Spalte');

		$this->columnService->delete($manager, $b, $a);
	}

	/**
	 * **Nach dem Entfernen sind die Positionen wieder lückenlos.**
	 *
	 * Nicht Kosmetik, sondern die Voraussetzung fürs Anlegen: `create()` vergibt
	 * `position = count($existing)`. Bliebe eine Lücke, träfe dieser Wert eine
	 * bestehende Spalte, und die neue Spalte erschiene **mitten im Board**
	 * statt hinten — bei `findForBoard()` entschiede dann die ID über die
	 * Reihenfolge. Nach zwei Entfernungen wäre das der Normalfall.
	 */
	public function testRemovingAColumnKeepsThePositionsGapless(): void {
		$manager = $this->manager();
		$columns = Server::get(ColumnMapper::class);

		// Vier Spalten: A(0), B(1), C(2), D(3).
		$c = (int)$this->columnService->create($manager, 'Dritte')->getId();
		$this->columnService->create($manager, 'Vierte');

		$this->columnService->delete($manager, $this->columnId(LeakMatrixFixture::COLUMN_A), $c);

		$positions = array_map(
			static fn ($column): int => (int)$column->getPosition(),
			$columns->findForBoard($manager),
		);
		$this->assertSame([0, 1, 2], $positions, 'Nach dem Entfernen klafft eine Lücke in den Positionen.');

		// Und die Probe aufs Exempel: Die nächste Spalte gehört ans Ende.
		$appended = $this->columnService->create($manager, 'Fünfte');
		$this->assertSame(3, (int)$appended->getPosition());
		$this->assertSame(
			'Fünfte',
			(string)$columns->findForBoard($manager)[3]->getTitle(),
			'Die neue Spalte steht nicht am Ende des Boards.',
		);
	}

	/**
	 * **Die Antwortformen des Endpunkts, einmal über den Controller.**
	 *
	 * Alle übrigen Fälle prüfen den Dienst — der Controller übersetzt aber
	 * Ausnahmen in Statuscodes, und diese Zuordnung ist eigener Code. Ohne
	 * diesen Test bliebe sie unbelegt, obwohl der Unterschied zwischen 403 und
	 * 404 hier eine Aussage über das Board ist: 404 hieße „gibt es nicht", und
	 * genau das darf die Antwort nicht behaupten, wenn der Betrachter Mitglied
	 * ist.
	 *
	 * Was dieser Test **nicht** abdeckt: dass Nextcloud einen JSON-Rumpf an
	 * einem DELETE überhaupt an die Parameter bindet. Das ist Framework-Verhalten
	 * (`Request::decodeContent()` decodiert für jede Methode außer GET) und
	 * lässt sich nur gegen eine echte HTTP-Anfrage zeigen, nicht gegen einen
	 * gestubbten `IRequest`.
	 */
	public function testTheEndpointAnswersWithTheRightStatusForEachRefusal(): void {
		$boardId = $this->fixture->boardId;
		$a = $this->columnId(LeakMatrixFixture::COLUMN_A);
		$b = $this->columnId(LeakMatrixFixture::COLUMN_B);

		// Verwaltungsrecht ja, Eigentum nein: 403 mit Begruendung, nicht 404 —
		// Bert ist Mitglied und sieht das Board.
		$this->memberService->update($this->manager(), LeakMatrixFixture::BERT, ['isManager' => true]);
		$refused = $this->settingsController(LeakMatrixFixture::BERT)->deleteColumn($boardId, $a, $b);
		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());

		// Nichtmitglied: 404 — dieselbe Antwort wie fuer ein Board, das es
		// nicht gibt. Alles andere waere eine Auskunft ueber fremde Projekte.
		$stranger = $this->settingsController(LeakMatrixFixture::FREMD)->deleteColumn($boardId, $a, $b);
		$this->assertSame(Http::STATUS_NOT_FOUND, $stranger->getStatus());

		$owner = $this->settingsController(LeakMatrixFixture::ANNA);

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$owner->deleteColumn($boardId, $a, 999999)->getStatus(),
			'Eine unbekannte Zielspalte muss 404 ergeben.',
		);
		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$owner->deleteColumn($boardId, $a, $a)->getStatus(),
			'Ziel gleich Quelle ist eine falsche Anfrage, kein fehlendes Etwas.',
		);

		// Und der Erfolgsfall: 204 ohne Rumpf. Eine Anzahl zurueckzugeben waere
		// eine Auskunft ueber die ungefilterte Menge.
		$done = $owner->deleteColumn($boardId, $a, $b);
		$this->assertSame(Http::STATUS_NO_CONTENT, $done->getStatus());
		$this->assertNull($done->getData());
	}

	private function settingsController(string $userId): SettingsController {
		return new SettingsController(
			$this->createStub(IRequest::class),
			$this->boardService,
			$this->columnService,
			$this->memberService,
			Server::get(BoardAccess::class),
			$userId,
		);
	}

	/**
	 * Alle fünf Betrachter der Leak-Matrix, einschließlich des Fremden.
	 *
	 * Sein Kontext entsteht von Hand, an `BoardAccess` vorbei — kein
	 * realistischer Ablauf, sondern die Probe auf die zweite Sperre.
	 *
	 * @return array<string, ViewerContext>
	 */
	private function allViewers(): array {
		$viewers = [];
		foreach ([
			LeakMatrixFixture::ANNA,
			LeakMatrixFixture::BERT,
			LeakMatrixFixture::CARLA,
			LeakMatrixFixture::DIRK,
		] as $userId) {
			$viewers[$userId] = $this->fixture->contextFor($userId);
		}

		$viewers[LeakMatrixFixture::FREMD] = ViewerContext::forMember(
			LeakMatrixFixture::FREMD,
			$this->fixture->boardId,
			ViewerContext::ROLE_INTERNAL,
			true,
		);

		return $viewers;
	}

	/**
	 * @param \OCA\Projektwerk\Db\Ticket[] $tickets
	 * @return string[] in Serverreihenfolge, **nicht** sortiert
	 */
	private function orderedLabels(array $tickets): array {
		$byId = array_flip($this->fixture->ticketIds);

		return array_map(
			static fn ($ticket): string => $byId[(int)$ticket->getId()],
			$tickets,
		);
	}

	/** Ungefiltert, ohne Betrachter — nur im Test zulässig. */
	private function rawCountInColumn(int $columnId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('pwerk_tickets')
			->where($qb->expr()->eq('column_id', $qb->createNamedParameter($columnId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	private function rawColumnOf(int $ticketId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('column_id')
			->from('pwerk_tickets')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$columnId = (int)$result->fetchOne();
		$result->closeCursor();

		return $columnId;
	}

	private function manager(): ViewerContext {
		// Anna ist Eigentuemerin und interne Verwalterin.
		return $this->fixture->contextFor(LeakMatrixFixture::ANNA);
	}

	private function columnId(string $title): int {
		return $this->fixture->columnIds[$title];
	}
}
