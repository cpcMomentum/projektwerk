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
use OCA\Projektwerk\Service\AccountType;
use OCA\Projektwerk\Service\NotManagerException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
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
		private IAppConfig $config,
		private MemberMapper $members,
		private BoardAccess $access,
		private ?string $userId,
		private IL10N $l10n,
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
					$this->l10n->t('Mitglieder dürfen nur interne Mitglieder mit Verwaltungsrecht pflegen.'),
				))->getMessage()],
				Http::STATUS_FORBIDDEN,
			);
		}

		$term = trim($search);
		if ($term === '') {
			return new JSONResponse(['users' => []]);
		}

		$gefunden = $this->auffindbar($term);

		$vorhanden = [];
		foreach ($this->members->findForBoard($viewer) as $member) {
			$vorhanden[(string)$member->getUserId()] = true;
		}

		$treffer = [];
		foreach ($gefunden as $user) {
			if (isset($vorhanden[$user->getUID()])) {
				// Bereits Mitglied: erneut anzubieten erzeugt nur den Fehler
				// „ist bereits Mitglied" — und der ist keine Auskunft, sondern
				// eine Sackgasse.
				continue;
			}

			// E-Mail statt der internen Konto-ID als Unterscheidung (#309): lesbar
			// und für Menschen brauchbar. **Bei Gästen unterdrückt** (F2): deren
			// System-E-Mail ist die Einladungsadresse, die nicht als Klartext in
			// der Suchliste stehen soll — stattdessen kennzeichnet die Oberfläche
			// sie über `isGuest` als „Gast". Die Hash-UID verlässt den Server hier
			// weiterhin (technischer Schlüssel), wird aber nicht mehr angezeigt.
			$isGuest = $user->getBackendClassName() === AccountType::GUEST_BACKEND;
			$email = $user->getEMailAddress();
			$treffer[] = [
				'userId' => $user->getUID(),
				'displayName' => $user->getDisplayName(),
				'email' => $isGuest ? null : ($email !== '' ? $email : null),
				'isGuest' => $isGuest,
			];

			if (count($treffer) >= self::LIMIT) {
				break;
			}
		}

		return new JSONResponse(['users' => $treffer]);
	}

	/** @return array<string, IUser> */
	private function auffindbar(string $term): array {
		// Gruppen- oder Telefonbuch-Grenze: nur exakte Treffer, weil die App keine Gruppen abfragt (Architekturtest).
		$teiltreffer = $this->core('shareapi_allow_share_dialog_user_enumeration', 'yes')
			&& !$this->core('shareapi_restrict_user_enumeration_to_group', 'no')
			&& !$this->core('shareapi_restrict_user_enumeration_to_phone', 'no');
		$exakt = $this->core('shareapi_restrict_user_enumeration_full_match', 'yes');
		$perKennung = $exakt && $this->core('shareapi_restrict_user_enumeration_full_match_userid', 'yes');
		$perMail = $exakt && $this->core('shareapi_restrict_user_enumeration_full_match_email', 'yes');
		if (!$teiltreffer && !$exakt) {
			return [];
		}

		$gesucht = mb_strtolower($term);
		$genaue = [];
		$weitere = [];
		foreach ($this->users->searchDisplayName($term, self::LIMIT * 2) as $user) {
			$genau = mb_strtolower($user->getDisplayName()) === $gesucht
				|| ($perKennung && mb_strtolower($user->getUID()) === $gesucht)
				|| ($perMail && mb_strtolower((string)$user->getEMailAddress()) === $gesucht);
			if ($exakt && $genau) {
				$genaue[$user->getUID()] = $user;
			} elseif ($teiltreffer) {
				$weitere[$user->getUID()] = $user;
			}
		}
		foreach ([$perKennung ? $this->users->get($term) : null, ...($perMail ? $this->users->getByEmail($term) : [])] as $user) {
			if ($user instanceof IUser) {
				$genaue[$user->getUID()] = $user;
			}
		}

		return array_filter($genaue + $weitere, static fn (IUser $user): bool => $user->isEnabled());
	}

	private function core(string $key, string $default): bool {
		return $this->config->getValueString('core', $key, $default) === 'yes';
	}
}
