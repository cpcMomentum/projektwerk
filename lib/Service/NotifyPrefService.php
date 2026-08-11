<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Db\NotifyPref;
use OCA\Projektwerk\Db\NotifyPrefMapper;

/**
 * Die eigenen Kanalschalter lesen und setzen.
 *
 * **Die Grenze ist die Benutzerkennung, nicht ein Board.** Wer hier hereinkommt,
 * fragt nach seinen eigenen Schaltern — es gibt nichts Fremdes zu sehen und
 * nichts zu filtern. Deshalb steht der Dienst neben der Sichtbarkeitsregel und
 * nicht in ihr.
 *
 * Ein Schalter ist **kein Projektinhalt**. Dass jemand in Projekt 7 keine Mails
 * will, verrät nichts über Projekt 7 — es verrät etwas über die Person, und die
 * fragt selbst.
 */
class NotifyPrefService {

	/** Was gespeichert werden darf. */
	private const KANAELE = [NotifyPref::CHANNEL_BELL, NotifyPref::CHANNEL_MAIL];

	public function __construct(
		private NotifyPrefMapper $prefs,
	) {
	}

	/**
	 * Alle Schalter dieser Person, nach Geltungsbereich sortiert.
	 *
	 * **Zurück kommt der gespeicherte Stand, nicht der aufgelöste.** Die
	 * Oberfläche soll unterscheiden können zwischen „hier steht ausdrücklich
	 * aus" und „hier steht nichts, also gilt die globale Einstellung" — sonst
	 * sähe ein geerbtes „an" aus wie ein gesetztes, und ein Klick darauf täte
	 * nichts Sichtbares.
	 *
	 * @param string $userId Kennung der Person.
	 * @return array{global: array<string, bool>, boards: array<int, array<string, bool>>}
	 */
	public function forUser(string $userId): array {
		$ergebnis = ['global' => [], 'boards' => []];

		foreach ($this->prefs->findForUser($userId) as $pref) {
			$an = (int)$pref->getEnabled() === 1;
			$kanal = (string)$pref->getChannel();
			$boardId = (int)$pref->getBoardId();

			if ($boardId === NotifyPrefMapper::GLOBAL_SCOPE) {
				$ergebnis['global'][$kanal] = $an;
			} else {
				$ergebnis['boards'][$boardId][$kanal] = $an;
			}
		}

		return $ergebnis;
	}

	/**
	 * Einen Schalter setzen — global oder für ein Projekt.
	 *
	 * **Ohne Prüfung, ob die Person Mitglied dieses Projekts ist.** Das ist
	 * Absicht: Eine solche Prüfung wäre eine Auskunft darüber, welche Projekte
	 * es gibt — wer eine fremde Kennung durchprobiert, erführe an der
	 * Fehlermeldung, welche existieren. Ein Schalter zu einem Projekt, in dem
	 * jemand nicht Mitglied ist, bewirkt schlicht nichts: Er bekäme von dort
	 * ohnehin keine Benachrichtigung.
	 *
	 * @param string $userId Kennung der Person.
	 * @param string $channel Einer der Kanäle aus {@see NotifyPref}.
	 * @param int $boardId Projekt, oder {@see NotifyPrefMapper::GLOBAL_SCOPE}.
	 * @param bool $enabled Neuer Stand.
	 * @throws \InvalidArgumentException unbekannter Kanal oder negatives Projekt
	 */
	public function set(string $userId, string $channel, int $boardId, bool $enabled): NotifyPref {
		if (!in_array($channel, self::KANAELE, true)) {
			throw new \InvalidArgumentException('Unbekannter Kanal: ' . $channel);
		}
		if ($boardId < NotifyPrefMapper::GLOBAL_SCOPE) {
			throw new \InvalidArgumentException('Ungueltiges Projekt: ' . $boardId);
		}

		foreach ($this->prefs->findForUser($userId) as $pref) {
			if ((string)$pref->getChannel() === $channel && (int)$pref->getBoardId() === $boardId) {
				$pref->setEnabled($enabled ? 1 : 0);

				return $this->prefs->update($pref);
			}
		}

		$neu = new NotifyPref();
		$neu->setUserId($userId);
		$neu->setChannel($channel);
		$neu->setBoardId($boardId);
		$neu->setEnabled($enabled ? 1 : 0);

		return $this->prefs->insert($neu);
	}

	/**
	 * Alle Projekt-Ausnahmen wegräumen — der Urlaubsschalter.
	 *
	 * Danach gilt überall die globale Einstellung, auch dort, wo vorher
	 * ausdrücklich etwas anderes stand. **Die globale Zeile bleibt stehen**:
	 * Sie ist der Wert, auf den zurückgefallen wird, nicht eine der Ausnahmen.
	 *
	 * @param string $userId Kennung der Person.
	 * @return int Wie viele Ausnahmen weggeräumt wurden.
	 */
	public function clearBoardOverrides(string $userId): int {
		$anzahl = 0;

		foreach ($this->prefs->findForUser($userId) as $pref) {
			if ((int)$pref->getBoardId() !== NotifyPrefMapper::GLOBAL_SCOPE) {
				$this->prefs->delete($pref);
				$anzahl++;
			}
		}

		return $anzahl;
	}
}
