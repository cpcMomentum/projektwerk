<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

/**
 * Aus einer E-Mail-Antwort den **eigentlichen** Text herausschneiden (#287).
 *
 * Eine Antwort per Mail schleppt fast immer das Zitat der Ursprungsmail und
 * eine Signatur mit. Beides gehört nicht in den Kommentar am Vorgang — der
 * Vorgang trägt seinen Verlauf ohnehin. Geschnitten wird an der **ersten**
 * Grenze, die auftaucht: eine zitierte Zeile (`>`), der Signaturtrenner
 * (`-- `), oder einer der gängigen Antwort-Marker („Am … schrieb …",
 * „-----Ursprüngliche Nachricht-----", „Von: … Gesendet: …", „On … wrote:").
 *
 * **Rein und statisch**, ohne Netz und ohne Zustand — die Regeln sind der
 * heikle Teil (ein zu gieriger Schnitt frisst echten Text, ein zu zaghafter
 * lässt das Zitat stehen), und genau deshalb hängt eine Maschine daran.
 */
final class ReplyTextExtractor {

	/** Obergrenze; darüber wird mit Hinweis gekappt. */
	public const MAX_LENGTH = 10000;

	/**
	 * Zeilen-Marker, ab denen (einschließlich) alles Weitere abgeschnitten wird.
	 *
	 * @var string[]
	 */
	private const CUT_PATTERNS = [
		// Zitierte Zeile.
		'/^\s*>/',
		// Signaturtrenner „-- " (mit oder ohne folgendes Leerzeichen).
		'/^--\s*$/',
		// „-----Ursprüngliche Nachricht-----" / „-----Original Message-----".
		'/^\s*-{2,}\s*(ursprüngliche nachricht|original message)\s*-{2,}/iu',
		// „Am 14.09.2026 um 10:00 schrieb Anna:" / „Am … schrieb …".
		'/^\s*am\s.+\sschrieb.*:?\s*$/iu',
		// „On Mon, 14 Sep 2026, Anna wrote:".
		'/^\s*on\s.+\swrote:?\s*$/iu',
		// Zitierter Kopfzeilenblock: „Von:/From:" mit einer Adresse dahinter.
		'/^\s*(von|from):\s.*@/iu',
		// „Gesendet:/Sent:" als Teil des zitierten Kopfblocks.
		'/^\s*(gesendet|sent):\s/iu',
	];

	/**
	 * Den Antworttext herausschneiden: Zitat/Signatur ab, getrimmt, gedeckelt.
	 *
	 * @param string $raw Der rohe (bereits nach UTF-8 dekodierte) Text-Body.
	 * @return string Der bereinigte Kommentartext; leer, wenn nichts übrig bleibt.
	 */
	public static function extract(string $raw): string {
		// Zeilenenden vereinheitlichen, dann Zeile für Zeile bis zur ersten Grenze.
		$lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
		$kept = [];
		foreach ($lines as $line) {
			if (self::isCut($line)) {
				break;
			}
			$kept[] = $line;
		}

		$text = trim(implode("\n", $kept));

		if (mb_strlen($text) > self::MAX_LENGTH) {
			$text = mb_substr($text, 0, self::MAX_LENGTH) . "\n…";
		}

		return $text;
	}

	/**
	 * Ist diese Zeile eine Schnittgrenze?
	 *
	 * @param string $line Eine einzelne Zeile.
	 */
	private static function isCut(string $line): bool {
		foreach (self::CUT_PATTERNS as $pattern) {
			if (preg_match($pattern, $line) === 1) {
				return true;
			}
		}

		return false;
	}
}
