<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Db\TicketMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;

/**
 * Der Einstieg von aussen: `/apps/projektwerk/t/{ticketId}`.
 *
 * **Fragmentfrei, und das ist der ganze Zweck.** Ein Link mit `#/tickets/42`
 * verliert sein Fragment beim Login-Umweg — der Browser schickt es nie zum
 * Server, und Nextcloud kann es nach der Anmeldung nicht wiederherstellen.
 * Betroffen waeren ausgerechnet die Gaeste, die den Link am dringendsten
 * brauchen, weil sie am seltensten angemeldet sind. Outlook SafeLinks und
 * aehnliche Umschreiber entfernen Fragmente zusaetzlich (R7).
 *
 * **Kein `@` in Pfad oder Query.** Nextclouds Login-Controller verwirft solche
 * Ruecksprungziele stillschweigend. Deshalb steht hier die Ticket-**ID** und
 * nicht etwa eine Kennung, die eine E-Mail-Adresse enthalten koennte.
 *
 * **Dieselbe Huelle wie `page#index`**, nur mit einem Initial State daneben.
 * Kein `RedirectResponse` auf eine Hash-Route: Der wuerde die Anmeldung ein
 * zweites Mal durchlaufen und das Fragment am selben Punkt wieder verlieren.
 */
class DeepLinkController extends Controller {

	public function __construct(
		IRequest $request,
		private TicketMapper $tickets,
		private IInitialState $initialState,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Die App oeffnen und dabei sagen, welcher Vorgang gemeint war.
	 *
	 * `#[NoCSRFRequired]` ist hier richtig und anderswo nicht: Die Route wird
	 * aus einer E-Mail heraus aufgerufen, also ohne Token — und sie **liest**
	 * nur. §3.5 erlaubt das Attribut ausdruecklich nur an `page#index` und
	 * dieser Route.
	 *
	 * Ist niemand angemeldet, leitet Nextcloud vor dem Controller zur Anmeldung
	 * und kehrt danach hierher zurueck. Weil die Kennung im **Pfad** steht,
	 * ueberlebt sie diesen Umweg.
	 *
	 * @param int $ticketId Kennung des Vorgangs aus dem Link.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ticket(int $ticketId): TemplateResponse {
		$this->initialState->provideInitialState('deepLink', $this->target($ticketId));

		return new TemplateResponse(Application::APP_ID, 'index');
	}

	/**
	 * Wohin die Oberflaeche springen soll — oder dass sie es nicht kann.
	 *
	 * **Ein einziger Fehlerfall fuer drei Ursachen**: Ticket gibt es nicht,
	 * Ticket ist verborgen, Board ist fremd. Der Mapper wirft in allen dreien
	 * dieselbe Ausnahme, weil `TicketScope` ueber einen INNER JOIN auf die
	 * Mitgliedschaft geht. Wuerde hier unterschieden, verriete die Antwort
	 * genau das, was die Sichtbarkeitsregel verbirgt — und zwar an jemanden,
	 * der nur eine Zahl im Link hochzaehlen muss.
	 *
	 * Die Nummernluecken sind ohnehin offengelegt (§11.2), die **Existenz** ist
	 * also kein Geheimnis. Der Zugriff auf Titel, Board und Zugehoerigkeit ist
	 * es sehr wohl.
	 *
	 * @return array{ticketId: int, boardId?: int, available: bool}
	 */
	private function target(int $ticketId): array {
		if ($this->userId === null) {
			return ['ticketId' => $ticketId, 'available' => false];
		}

		try {
			$ticket = $this->tickets->findVisibleAnywhere($this->userId, $ticketId);
		} catch (DoesNotExistException) {
			return ['ticketId' => $ticketId, 'available' => false];
		}

		return [
			'ticketId' => $ticketId,
			'boardId' => (int)$ticket->getBoardId(),
			'available' => true,
		];
	}
}
