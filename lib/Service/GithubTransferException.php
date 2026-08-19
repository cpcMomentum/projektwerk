<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

/**
 * Die GitHub-Überführung ist nicht zustande gekommen (#12).
 *
 * Ein Sammelfall für alles, was zwischen „Knopf gedrückt" und „Issue steht"
 * schiefgehen kann und **die Person selbst richten kann oder wissen sollte**:
 * kein Token hinterlegt, der Token ist ungültig (401), das Board zeigt auf ein
 * Repo, das es nicht gibt oder für das der Token keine Rechte hat (404), GitHub
 * lehnt die Eingabe ab (422), oder der Dienst ist gerade nicht erreichbar.
 *
 * **400 und nicht 409.** Wie bei {@see NoFolderException}: Es fehlt kein Recht,
 * und die Anfrage ist richtig gebaut — es hakt an der Einrichtung oder an der
 * Gegenstelle. Ein 409 läse das Frontend als Versionskonflikt („bitte neu
 * laden") und verschluckte die eigentliche Meldung, die sagt, was zu tun ist.
 *
 * Die Meldung ist bewusst schon deutsch und kundentauglich formuliert: Sie geht
 * unverändert als Servermeldung an die Oberfläche.
 */
class GithubTransferException extends \RuntimeException {
}
