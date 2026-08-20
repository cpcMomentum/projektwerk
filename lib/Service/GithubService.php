<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCP\Http\Client\IClientService;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

/**
 * Die Anbindung an GitHub für die einseitige Überführung (#12, Stufe 1).
 *
 * Zwei Zuständigkeiten, bewusst hier gebündelt und nirgends sonst:
 *
 * 1. **Der Token je Person**, verschlüsselt in Nextclouds
 *    {@see ICredentialsManager}. Kein eigenes Datenbankfeld, keine eigene
 *    Verschlüsselung — Nextcloud legt den Wert verschlüsselt ab und gibt ihn
 *    nur der eintragenden Person zurück. Nach außen (Controller, Frontend)
 *    verlässt der Token diesen Dienst nie; erfragbar ist allein, **ob** einer
 *    hinterlegt ist.
 * 2. **Das Anlegen eines Issues** über die GitHub-REST-API. Ausgehend, synchron,
 *    einseitig. Jeder Fehlausgang wird zu einer {@see GithubTransferException}
 *    mit einer schon kundentauglichen deutschen Meldung — der rohe HTTP-Status
 *    verlässt diesen Dienst nicht.
 */
class GithubService {

	/**
	 * Der Bezeichner, unter dem der Token je Person im Credentials-Speicher
	 * liegt. Nextcloud schlüsselt intern zusätzlich nach Benutzerkennung — der
	 * eine Bezeichner genügt.
	 */
	public const TOKEN_ID = 'github-token';

	private const API_BASE = 'https://api.github.com';

	/** Höchstens so viele Seiten à 100 Repos holen wir für die Auswahl (#196). */
	private const REPO_PAGES = 2;

	/** Höchstens so viele Treffer gehen an das Tippfeld zurück. */
	private const REPO_LIMIT = 30;

	public function __construct(
		private ICredentialsManager $credentials,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Den Token einer Person hinterlegen. Ein leerer Token wird abgewiesen —
	 * „speichern" mit nichts wäre eine stille Löschung mit falschem Namen.
	 *
	 * @throws \InvalidArgumentException wenn der Token leer ist
	 */
	public function storeToken(string $userId, string $token): void {
		$token = trim($token);
		if ($token === '') {
			throw new \InvalidArgumentException('Der GitHub-Token darf nicht leer sein.');
		}

		$this->credentials->store($userId, self::TOKEN_ID, $token);
	}

	/**
	 * Ob diese Person einen Token hinterlegt hat. **Nie der Token selbst** —
	 * das ist die einzige Frage, die der Controller beantworten darf.
	 */
	public function hasToken(string $userId): bool {
		return $this->tokenOf($userId) !== null;
	}

	/**
	 * Den Token wieder entfernen. Idempotent: Ist keiner da, geschieht nichts.
	 */
	public function deleteToken(string $userId): void {
		$this->credentials->delete($userId, self::TOKEN_ID);
	}

	/**
	 * Ein GitHub-Issue anlegen und die Nummer samt Adresse zurückgeben.
	 *
	 * @param string $userId Wessen Token verwendet wird — der überführenden Person.
	 * @param string $repo Ziel im Format `owner/repo`.
	 * @param string $title Der Titel des Issues.
	 * @param string $body Der Rumpf (Beschreibung samt Rücklink).
	 *
	 * @return array{number: int, url: string}
	 *
	 * @throws GithubTransferException bei jedem Fehlausgang, mit deutscher Meldung
	 */
	public function createIssue(string $userId, string $repo, string $title, string $body): array {
		$token = $this->tokenOf($userId);
		if ($token === null) {
			throw new GithubTransferException(
				'Kein GitHub-Token hinterlegt. Bitte in „Meine Einstellungen" einen Token eintragen.',
			);
		}

		$repo = trim($repo);
		if (!preg_match('#^[^/\s]+/[^/\s]+$#', $repo)) {
			throw new GithubTransferException(
				'Das Ziel-Repository ist nicht im Format „owner/repo" hinterlegt.',
			);
		}

		$client = $this->clientService->newClient();

		try {
			$response = $client->post(self::API_BASE . '/repos/' . $repo . '/issues', [
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Accept' => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					// GitHub weist Anfragen ohne User-Agent ab.
					'User-Agent' => 'ProjektWerk',
					'Content-Type' => 'application/json',
				],
				'body' => json_encode(['title' => $title, 'body' => $body], JSON_THROW_ON_ERROR),
				// Selbst prüfen statt von Guzzle werfen lassen: So wird aus dem
				// Status eine gezielte, deutsche Meldung statt einer generischen
				// Ausnahme.
				'http_errors' => false,
				'timeout' => 10,
			]);
		} catch (\Throwable $e) {
			// Netz weg, Zeitüberschreitung, TLS — die Gegenstelle war nicht
			// erreichbar. Der Vorgang bleibt unverändert (fail-closed).
			$this->logger->warning('GitHub-Überführung: Verbindung fehlgeschlagen', ['exception' => $e]);
			throw new GithubTransferException(
				'GitHub war nicht erreichbar. Bitte später erneut versuchen.',
			);
		}

		$status = $response->getStatusCode();
		if ($status === 201) {
			return $this->parseCreated((string)$response->getBody());
		}

		throw new GithubTransferException($this->messageForStatus($status));
	}

	/**
	 * Die Repositorys, auf die der Token einer Person Zugriff hat — für die
	 * Live-Auswahl des Ziel-Repos (#196).
	 *
	 * Fragt `GET /user/repos` (die Menge, die der Token freigibt — bei einem auf
	 * eine Organisation ausgestellten Token also deren Repos) und filtert
	 * serverseitig nach dem Suchbegriff. Bewusst **kein** Aufruf der Such-API:
	 * Die hat eine eigene, strengere Ratengrenze und müsste den Eigentümer des
	 * Tokens erst ermitteln; die Repo-Liste des Tokens ist die genauere Menge.
	 *
	 * Gedeckelt auf {@see self::REPO_PAGES} Seiten à 100 und {@see self::REPO_LIMIT}
	 * Treffer — ein Tippfeld braucht keine vollständige Liste, und eine
	 * Organisation mit sehr vielen Repos soll die Anfrage nicht sprengen.
	 *
	 * @return list<string> `owner/repo`, alphabetisch, höchstens {@see self::REPO_LIMIT}
	 *
	 * @throws GithubTransferException bei fehlendem Token oder GitHub-Fehler
	 */
	public function searchRepos(string $userId, string $query): array {
		$token = $this->tokenOf($userId);
		if ($token === null) {
			throw new GithubTransferException(
				'Kein GitHub-Token hinterlegt. Bitte in „Meine Einstellungen" einen Token eintragen.',
			);
		}

		$client = $this->clientService->newClient();
		$names = [];

		for ($page = 1; $page <= self::REPO_PAGES; $page++) {
			$url = self::API_BASE . '/user/repos?' . http_build_query([
				'per_page' => 100,
				'page' => $page,
				'sort' => 'full_name',
			]);

			try {
				$response = $client->get($url, [
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						'Accept' => 'application/vnd.github+json',
						'X-GitHub-Api-Version' => '2022-11-28',
						'User-Agent' => 'ProjektWerk',
					],
					'http_errors' => false,
					'timeout' => 10,
				]);
			} catch (\Throwable $e) {
				$this->logger->warning('GitHub-Repos: Verbindung fehlgeschlagen', ['exception' => $e]);
				throw new GithubTransferException('GitHub war nicht erreichbar. Bitte später erneut versuchen.');
			}

			if ($response->getStatusCode() !== 200) {
				throw new GithubTransferException($this->messageForStatus($response->getStatusCode()));
			}

			$data = json_decode((string)$response->getBody(), true);
			if (!is_array($data) || $data === []) {
				break;
			}

			foreach ($data as $repo) {
				if (is_array($repo) && isset($repo['full_name']) && is_string($repo['full_name'])) {
					$names[] = $repo['full_name'];
				}
			}

			// Weniger als eine volle Seite heißt: das war die letzte.
			if (count($data) < 100) {
				break;
			}
		}

		$needle = trim($query);
		if ($needle !== '') {
			$lc = mb_strtolower($needle);
			$names = array_values(array_filter(
				$names,
				static fn (string $name): bool => str_contains(mb_strtolower($name), $lc),
			));
		}

		return array_slice($names, 0, self::REPO_LIMIT);
	}

	/**
	 * Den Token einer Person lesen — oder `null`, wenn keiner hinterlegt ist.
	 *
	 * Bleibt privat: Der Token verlässt diesen Dienst ausschließlich als
	 * `Authorization`-Kopfzeile in {@see createIssue()} und {@see searchRepos()}.
	 */
	private function tokenOf(string $userId): ?string {
		$stored = $this->credentials->retrieve($userId, self::TOKEN_ID);
		if (!is_string($stored)) {
			return null;
		}

		$stored = trim($stored);

		return $stored === '' ? null : $stored;
	}

	/**
	 * Nummer und Adresse aus der 201-Antwort ziehen.
	 *
	 * @return array{number: int, url: string}
	 *
	 * @throws GithubTransferException wenn die Antwort nicht die erwartete Form hat
	 */
	private function parseCreated(string $body): array {
		$data = json_decode($body, true);
		if (!is_array($data) || !isset($data['number'], $data['html_url'])) {
			$this->logger->warning('GitHub-Überführung: unerwartete Antwort auf 201', ['body' => $body]);
			throw new GithubTransferException(
				'GitHub hat das Issue angelegt, aber unerwartet geantwortet.',
			);
		}

		return [
			'number' => (int)$data['number'],
			'url' => (string)$data['html_url'],
		];
	}

	/**
	 * Aus einem Fehlstatus eine deutsche, handlungsleitende Meldung.
	 */
	private function messageForStatus(int $status): string {
		return match ($status) {
			401 => 'Der GitHub-Token ist ungültig oder abgelaufen. Bitte in „Meine Einstellungen" erneuern.',
			403, 429 => 'GitHub hat die Anfrage abgelehnt (Rechte oder Ratenbegrenzung). Bitte den Token und dessen Repo-Rechte prüfen.',
			404 => 'Das Repository wurde nicht gefunden, oder der Token hat keinen Zugriff darauf.',
			410, 422 => 'GitHub hat den Vorgang abgelehnt — für dieses Repository lassen sich keine Issues anlegen.',
			default => 'GitHub hat mit einem Fehler geantwortet (HTTP ' . $status . ').',
		};
	}
}
