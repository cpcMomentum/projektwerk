<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Controller;

use OCA\Projektwerk\Access\BoardAccess;
use OCA\Projektwerk\Access\NotAMemberException;
use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Db\MemberMapper;
use OCA\Projektwerk\Service\NotManagerException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Konten suchen, um sie einem Projekt hinzuzufuegen.
 *
 * **Warum ein eigener Endpunkt und nicht Nextclouds Personensuche.** Die
 * Hausregel verbietet letztere, weil sie in Gast-Sitzungen prinzipbedingt eine
 * leere Liste liefert. Hier greift der Grund nicht — Mitglieder pflegen nur
 * interne Verwalter, nie Gaeste.
 *
 * Trotzdem kein direkter Aufruf im Frontend: Stuende dort ein Sucher, der in
 * Gast-Sitzungen leer zurueckkommt, waere er irgendwann an eine Stelle kopiert,
 * wo Gaeste hinkommen. Der Fehler waere schwer zu finden, weil nichts
 * kaputtgeht — es findet nur nichts.
 *
 * Der eigene Endpunkt haelt die Regel woertlich wahr und erlaubt zusaetzlich,
 * die Beschraenkung **serverseitig** zu erzwingen statt nur die Bedienung
 * wegzulassen.
 */
class MemberSearchController extends Controller {

	/** Die Liste ist eine Suchhilfe, kein Verzeichnis. */
	private const LIMIT = 20;

	public function __construct(
		IRequest $request,
		private IUserManager $users,
		private IConfig $config,
		private MemberMapper $members,
		private BoardAccess $access,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Konten, die zu diesem Projekt passen koennten.
	 *
	 * @param int $boardId Kennung des Projekts.
	 * @param string $search Suchbegriff.
	 */
	#[NoAdminRequired]
	public function search(int $boardId, string $search = ''): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$viewer = $this->access->contextFor($this->userId, $boardId);
		} catch (NotAMemberException) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		// **403 und keine leere Liste.** Eine leere Antwort saehe aus wie
		// „niemand gefunden" — und wer den Unterschied nicht kennt, sucht den
		// Fehler bei sich statt bei seinen Rechten.
		if (!$viewer->isManager) {
			return new JSONResponse(
				['error' => (new NotManagerException(
					'Mitglieder dürfen nur interne Mitglieder mit Verwaltungsrecht pflegen.',
				))->getMessage()],
				Http::STATUS_FORBIDDEN,
			);
		}

		$term = trim($search);
		if ($term === '') {
			return new JSONResponse(['users' => []]);
		}

		// **Nextclouds Auffindbarkeits-Einstellung achten.** Steht sie auf
		// „nein", findet die Instanz Konten absichtlich nicht ueber eine
		// Teilzeichenkette — ProjektWerk darf sie dann nicht doch finden. Der
		// Vergleich laeuft in diesem Fall auf die vollstaendige Kennung.
		$enumeration = $this->config->getAppValue(
			'core',
			'shareapi_allow_share_dialog_user_enumeration',
			'yes',
		) === 'yes';

		$gefunden = $enumeration
			? $this->users->searchDisplayName($term, self::LIMIT * 2)
			: array_filter([$this->users->get($term)]);

		$vorhanden = [];
		foreach ($this->members->findForBoard($viewer) as $member) {
			$vorhanden[(string)$member->getUserId()] = true;
		}

		$treffer = [];
		foreach ($gefunden as $user) {
			if (!$user instanceof IUser || isset($vorhanden[$user->getUID()])) {
				// Bereits Mitglied: erneut anzubieten erzeugt nur den Fehler
				// „ist bereits Mitglied" — und der ist keine Auskunft, sondern
				// eine Sackgasse.
				continue;
			}

			$treffer[] = [
				'userId' => $user->getUID(),
				// Beides, nicht nur der Name: Zwei Konten mit gleichem
				// Anzeigenamen waeren sonst nicht unterscheidbar.
				'displayName' => $user->getDisplayName(),
			];

			if (count($treffer) >= self::LIMIT) {
				break;
			}
		}

		return new JSONResponse(['users' => $treffer]);
	}
}
