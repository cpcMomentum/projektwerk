<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

/**
 * Fuer diesen Vorgang gibt es keinen Ablageort.
 *
 * Zwei Gruende, dieselbe Antwort:
 *
 * 1. **Die Sichtbarkeit gibt keinen her.** Ein interner Vorgang der Kundenseite
 *    hat keinen Ordner, in dem die Datei genauso eng laege wie der Vorgang
 *    selbst. Einen der beiden Team-Ordner zu nehmen hiesse, sie jemandem
 *    hinzulegen, der den Vorgang nicht sehen darf. Das ist kein Mangel,
 *    sondern die Zusage (§3.10). (Ein „Nur ich"-Vorgang hat seit #184 Phase B
 *    einen eigenen Ablageort und faellt hier nicht mehr herein.)
 * 2. **Am Projekt ist noch keiner hinterlegt.** Ein Board ohne Ordner ist ein
 *    gueltiger Zustand — es hat dann eben keine Anhaenge. Die Meldung nennt
 *    deshalb den Weg dorthin, statt nach einem Fehler zu klingen.
 *
 * **400 und nicht 403.** Es fehlt kein Recht: Wer hier ankommt, sieht den
 * Vorgang und duerfte anhaengen — es gibt nur nichts, woran. Ein 403 laese sich
 * als „Sie duerfen das nicht" verstehen und schickte in die falsche Richtung.
 */
class NoFolderException extends \RuntimeException {
}
