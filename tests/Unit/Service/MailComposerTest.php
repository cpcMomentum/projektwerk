<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Db\Board;
use OCA\Projektwerk\Db\BoardMapper;
use OCA\Projektwerk\Db\MailOutbox;
use OCA\Projektwerk\Db\Ticket;
use OCA\Projektwerk\Service\MailComposer;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Der Mail-Text (#248, Teil 2) — ohne Datenbank.
 *
 * Geprueft wird die Zusammensetzung: dass der Auslöser-Name, der Schritt-Titel
 * und der Projektname im Satz landen, wo sie bekannt sind, und dass der Satz
 * ohne sie auf seine schlichte Form zurueckfaellt. `IUserManager` (Name),
 * `BoardMapper` (Projektname) und `IL10N` sind Doppel; die Sprache bildet ein
 * echtes `vsprintf` nach, damit die `%1$s`-Platzhalter wirklich gefuellt werden.
 */
class MailComposerTest extends TestCase {

	private function l10n(): IL10N {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => $params === [] ? $text : vsprintf($text, $params),
		);

		return $l;
	}

	/**
	 * @param array<string, string> $namen Kennung => Anzeigename der bekannten Konten.
	 */
	private function users(array $namen): IUserManager {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid) use ($namen): ?IUser {
			if (!isset($namen[$uid])) {
				return null;
			}
			$user = $this->createMock(IUser::class);
			$user->method('getDisplayName')->willReturn($namen[$uid]);

			return $user;
		});

		return $users;
	}

	/**
	 * @param array<int, string> $boards Board-Id => Titel, sichtbar für den Empfänger.
	 */
	private function boards(array $boards): BoardMapper {
		$entities = [];
		foreach ($boards as $id => $titel) {
			$board = new Board();
			$board->setTitle($titel);
			$ref = new ReflectionProperty(Board::class, 'id');
			$ref->setValue($board, $id);
			$entities[] = $board;
		}

		$mapper = $this->createMock(BoardMapper::class);
		$mapper->method('findAllForUser')->willReturn($entities);

		return $mapper;
	}

	private function ticket(int $number, string $titel, int $boardId): Ticket {
		$ticket = new Ticket();
		$ticket->setNumber($number);
		$ticket->setTitle($titel);
		$ticket->setBoardId($boardId);

		return $ticket;
	}

	private function zeile(string $event, ?string $actorUid, ?string $stepTitle = null): MailOutbox {
		$zeile = new MailOutbox();
		$zeile->setRecipientUid('carla');
		$zeile->setEvent($event);
		$zeile->setActorUid($actorUid);
		$zeile->setStepTitle($stepTitle);

		return $zeile;
	}

	public function testActorAndProjectAppearInAssignment(): void {
		$composer = new MailComposer($this->users(['anna' => 'Anna Reuter']), $this->boards([7 => 'Relaunch Website']));

		$text = $composer->compose(
			$this->zeile(MailOutbox::EVENT_TICKET_ASSIGNED, 'anna'),
			$this->ticket(7, 'Logo liefern', 7),
			$this->l10n(),
		);

		$this->assertStringContainsString('Anna Reuter', $text['einleitung']);
		$this->assertStringContainsString('Logo liefern', $text['einleitung']);
		$this->assertStringContainsString('Relaunch Website', $text['meta'], 'Der Projektname gehört in die Metazeile.');
		$this->assertStringContainsString('#0007', $text['meta']);
	}

	public function testStepTitleAppearsInStepAssignment(): void {
		$composer = new MailComposer($this->users(['anna' => 'Anna Reuter']), $this->boards([7 => 'Relaunch Website']));

		$text = $composer->compose(
			$this->zeile(MailOutbox::EVENT_STEP_ASSIGNED, 'anna', 'Angebot einholen'),
			$this->ticket(7, 'Logo liefern', 7),
			$this->l10n(),
		);

		$this->assertStringContainsString('Anna Reuter', $text['einleitung']);
		$this->assertStringContainsString('Angebot einholen', $text['einleitung'], 'Der Schritt-Titel gehört in den Satz.');
	}

	public function testFallsBackWithoutActorOrProject(): void {
		// Auslöser unbekannt (null) und der Empfänger sieht das Board nicht mehr
		// (leere Board-Liste) — der Satz kommt ohne Auslöser aus, die Metazeile
		// entfällt.
		$composer = new MailComposer($this->users([]), $this->boards([]));

		$text = $composer->compose(
			$this->zeile(MailOutbox::EVENT_TICKET_ASSIGNED, null),
			$this->ticket(7, 'Logo liefern', 7),
			$this->l10n(),
		);

		$this->assertStringContainsString('Ihnen wurde der Vorgang', $text['einleitung']);
		$this->assertSame('', $text['meta'], 'Ohne Projektname keine Metazeile.');
	}

	public function testUnknownActorUidIsNotShownAsName(): void {
		// Das Konto zur actor_uid gibt es nicht (mehr) — statt der rohen Kennung
		// steht kein Auslöser im Satz.
		$composer = new MailComposer($this->users([]), $this->boards([7 => 'Relaunch Website']));

		$text = $composer->compose(
			$this->zeile(MailOutbox::EVENT_COMMENT_ADDED, 'geloeschtes-konto'),
			$this->ticket(7, 'Logo liefern', 7),
			$this->l10n(),
		);

		$this->assertStringNotContainsString('geloeschtes-konto', $text['einleitung']);
		$this->assertStringContainsString('neuen Kommentar', $text['einleitung']);
	}
}
