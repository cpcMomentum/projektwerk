<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

/**
 * Die Sichtbarkeit laesst sich nicht aendern, solange Anhaenge daran haengen.
 *
 * **Das ist der einzige Punkt, an dem ein Leck physisch wird** (§3.10). Ueberall
 * sonst ist die Sichtbarkeit eine Bedingung in einer Abfrage — eine falsche
 * Zeile laesst sich korrigieren, und danach stimmt es wieder. Hier ist sie ein
 * Verzeichnis auf einer Festplatte: Liegt die Datei erst in `90_Austausch`,
 * hat die Kundenseite sie gesehen, und keine spaetere Codekorrektur nimmt das
 * zurueck.
 *
 * Ein Umzug der Dateien beim Wechsel waere der naheliegende Weg und ist genau
 * deshalb nicht gebaut: Er ist **nicht transaktional zur Datenbank**. Braeche
 * er in der Mitte ab, laege die Datei im falschen Ordner, waehrend das Ticket
 * schon umgestellt ist. §11.3 — ob Datei-ID, Versionen und Freigabezustand ein
 * Verschieben zwischen zwei Unterordnern desselben Team-Ordners ueberleben —
 * ist unbeantwortet; Spike S2 soll das klaeren. Bis dahin verweigert die App
 * den Wechsel, statt einen Umzug zu wagen, dessen Rueckabwicklung sie nicht
 * garantieren kann.
 *
 * Der Preis ist benannt und liegt beim Menschen: Wer umstellen will, entfernt
 * die Anhaenge zuerst und haengt sie danach wieder an. Das ist Arbeit — aber
 * sichtbare Arbeit, und keine stille Wette.
 *
 * **409 und nicht 403.** Es fehlt kein Recht, und die Anfrage ist nicht falsch
 * gebaut: Der Vorgang ist nur gerade in einem Zustand, in dem sie nicht geht.
 * Genau dafuer ist der Status da.
 */
class AttachmentsPresentException extends \RuntimeException {

	public function __construct(
		public readonly int $count,
	) {
		parent::__construct(
			// Der Satz nennt die Zahl, weil sie die Handlung bestimmt, und sagt
			// ausdruecklich „entfernen", nicht „loeschen": Die Dateien bleiben
			// liegen, geloest wird nur die Verknuepfung.
			$count === 1
				? 'Bitte den Anhang zuerst vom Vorgang lösen, dann lässt sich die Sichtbarkeit ändern.'
				: sprintf('Bitte die %d Anhänge zuerst vom Vorgang lösen, dann lässt sich die Sichtbarkeit ändern.', $count),
		);
	}
}
