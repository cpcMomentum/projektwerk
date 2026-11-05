<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Db\IdemKey;
use OCA\Projektwerk\Db\IdemKeyMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Legt bei derselben Kennung genau einmal an und liefert jeder Wiederholung die erste Antwort.
 */
class IdempotencyService {

	public const HEADER = 'Idempotency-Key';

	// Eine erste Anfrage, die so lange ohne Antwort hängt, ist abgebrochen; erst danach darf neu angelegt werden.
	private const STALE_SECONDS = 600;

	public function __construct(
		private IdemKeyMapper $keys,
		private ITimeFactory $time,
		private IL10N $l10n,
		private IDBConnection $db,
	) {
	}

	/** @param array<string, mixed> $request alles, was die Anlage bestimmt; Abweichung bei gleicher Kennung ergibt 422 */
	public function once(string $userId, ?string $key, array $request, callable $create): \JsonSerializable|array {
		if ($key === null || $key === '') {
			return $create();
		}
		if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key) !== 1) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültige Kennung für die Anfrage.'));
		}

		$fingerprint = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));

		// Erst nachsehen: Nach einer Index-Verletzung lässt PostgreSQL in derselben Transaktion keine Abfrage mehr zu.
		try {
			$this->keys->findForUser($userId, $key);

			return $this->replay($userId, $key, $fingerprint);
		} catch (DoesNotExistException) {
		}

		$row = new IdemKey();
		$row->setUserId($userId);
		$row->setIdemKey($key);
		$row->setFingerprint($fingerprint);
		$row->setCreatedAt($this->time->getDateTime());

		try {
			$this->keys->insert($row);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}

			return $this->replay($userId, $key, $fingerprint);
		}

		// In einer Transaktion: Scheitert etwas nach dem Schreiben, etwa eine Benachrichtigung, bleibt nichts liegen, und die Wiederholung legt nicht doppelt an.
		$this->db->beginTransaction();
		try {
			$result = $create();
			$row->setResponse(json_encode($result, JSON_THROW_ON_ERROR));
			$this->keys->update($row);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			$this->keys->delete($row);
			throw $e;
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function replay(string $userId, string $key, string $fingerprint): array {
		try {
			$existing = $this->keys->findForUser($userId, $key);
		} catch (DoesNotExistException) {
			throw new IdempotencyInProgressException($this->l10n->t('Die Anfrage wird gerade bearbeitet.'));
		}

		if (!hash_equals($existing->getFingerprint(), $fingerprint)) {
			throw new IdempotencyMismatchException($this->l10n->t('Diese Kennung wurde bereits für eine andere Anfrage verwendet.'));
		}

		$response = $existing->getResponse();
		if ($response === null) {
			$age = $this->time->getTime() - $existing->getCreatedAt()->getTimestamp();
			if ($age > self::STALE_SECONDS) {
				$this->keys->delete($existing);
			}
			throw new IdempotencyInProgressException($this->l10n->t('Die Anfrage wird gerade bearbeitet.'));
		}

		return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
	}
}
