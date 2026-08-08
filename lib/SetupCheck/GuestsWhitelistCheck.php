<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\SetupCheck;

use OCA\Projektwerk\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Steht ProjektWerk auf der Freigabeliste der Guests-App?
 *
 * Ohne diesen Eintrag liefert **jeder** Request unter `/apps/projektwerk/…` in
 * einer Gast-Sitzung eine HTML-Fehlerseite — auch API-Requests. Fuer den Kunden
 * ist die App damit vollstaendig unbenutzbar, und zwar auf eine Art, die beim
 * Betreiber nicht auffaellt: Mit einem regulaeren Konto funktioniert alles.
 *
 * **Dieser Check liest und meldet, er schreibt nicht** (Entscheidung E5). Der
 * Grund ist gemessen: Der Konfigurationswert ist im Auslieferungszustand gar
 * nicht gesetzt, die Vorgabe steckt als Lexikon-Eintrag im Code von Guests.
 * Wer die Liste zum ersten Mal schreibt, ersetzt damit die gesamte eingebaute
 * Vorgabe — zwoelf Apps, darunter `files_sharing`, `text` und `notifications`.
 * Eine App, die das im Hintergrund tut, nimmt fremden Kunden Funktionen weg,
 * ohne dass jemand es veranlasst hat. Deshalb nennt die Meldung den
 * vollstaendigen Sollwert zum Kopieren, und die Pflege bleibt beim Menschen.
 *
 * **Gelesen wird ueber `IAppConfig`, nicht ueber `occ`.** S1 hat gemessen, dass
 * `occ config:app:get guests whitelist` im Auslieferungszustand **nichts**
 * ausgibt, obwohl die Liste wirksam ist. Wer diese Leere als „keine Liste"
 * deutet, baut genau den Fehler, den E5 verhindert. `IAppConfig` liefert die
 * Lexikon-Vorgabe mit — nachgemessen am 2026-08-08.
 */
class GuestsWhitelistCheck implements ISetupCheck {

	private const GUESTS_APP = 'guests';

	/**
	 * Was auf der Liste stehen muss, und was fehlt, wenn es fehlt.
	 *
	 * `viewer` ist bewusst dabei: S1 hat festgestellt, dass er **nicht** in der
	 * eingebauten Vorgabe steht. Das im MVP zugesagte Oeffnen von Anhaengen
	 * funktioniert fuer Gaeste also erst nach ausdruecklicher Freischaltung.
	 *
	 * Die zweite Liste aus Guests — `WHITELIST_ALWAYS` mit `core`, `files`,
	 * `dav` und den uebrigen — wird hier **nicht** nachgebildet. Sie muesste es
	 * nur, wenn dieser Check die ganze Liste beurteilte; er fragt aber allein
	 * nach diesen beiden Eintraegen, und keiner von beiden steht dort. Eine
	 * Kopie einer fremden Konstante waere ein zweiter Ort, der stimmen muss.
	 *
	 * @var array<string, bool> App-ID => ohne sie ist die App unbenutzbar
	 */
	private const REQUIRED = [
		Application::APP_ID => true,
		'viewer' => false,
	];

	public function __construct(
		private IAppManager $appManager,
		private IAppConfig $appConfig,
		private IL10N $l10n,
	) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		return $this->l10n->t('ProjektWerk: Freigabeliste für Gäste');
	}

	public function run(): SetupResult {
		// Schweigt, wo Guests nicht im Einsatz ist. Die Instanz eines Betreibers
		// ohne Gastkonten hat hier nichts zu entscheiden, und ein Hinweis, der
		// niemanden betrifft, macht die Uebersicht wertlos.
		if (!$this->appManager->isInstalled(self::GUESTS_APP)) {
			return SetupResult::success(
				$this->l10n->t('Die Guests-App ist nicht im Einsatz — für ProjektWerk ist nichts einzurichten.'),
			);
		}

		if (!$this->appConfig->getValueBool(self::GUESTS_APP, 'usewhitelist', true)) {
			return SetupResult::success(
				$this->l10n->t('Die Freigabeliste der Guests-App ist abgeschaltet; Gäste erreichen alle Apps.'),
			);
		}

		$whitelist = $this->readWhitelist();
		$missing = array_keys(array_filter(
			self::REQUIRED,
			fn (bool $_, string $appId): bool => !in_array($appId, $whitelist, true),
			ARRAY_FILTER_USE_BOTH,
		));

		if ($missing === []) {
			return SetupResult::success(
				$this->l10n->t('ProjektWerk und der Viewer stehen auf der Freigabeliste der Guests-App.'),
			);
		}

		$target = implode(',', array_merge($whitelist, $missing));

		// Fehlt die App selbst, ist sie fuer Kunden vollstaendig unbenutzbar —
		// das ist ein Fehler. Fehlt nur der Viewer, laesst sich alles ausser dem
		// Oeffnen von Anhaengen tun; das ist eine Warnung. Die Unterscheidung
		// hat einen Zweck: Ein Betreiber soll am Schweregrad ablesen koennen, ob
		// er die Einfuehrung verschieben muss.
		$blocking = in_array(Application::APP_ID, $missing, true);

		$description = $blocking
			? $this->l10n->t(
				'ProjektWerk fehlt auf der Freigabeliste der Guests-App. '
				. 'Jede Anfrage eines Gastes an diese App wird abgewiesen — auch Schnittstellenaufrufe, '
				. 'die dann eine HTML-Fehlerseite statt Daten erhalten. Die App ist für Kunden unbenutzbar.',
			)
			: $this->l10n->t(
				'Der Viewer fehlt auf der Freigabeliste der Guests-App. '
				. 'Gäste können Anhänge dann nicht öffnen; alles andere funktioniert.',
			);

		$description .= "\n\n" . $this->l10n->t(
			'Fehlend: %1$s' . "\n"
			. 'Die bestehende Liste zuerst lesen und ergänzt zurückschreiben — blindes Setzen '
			. 'ersetzt die eingebaute Vorgabe der Guests-App und nimmt Gästen zwölf Apps weg. '
			. 'Der vollständige Sollwert lautet:' . "\n\n"
			. 'occ config:app:set guests whitelist --value="%2$s"',
			[implode(', ', $missing), $target],
		);

		return $blocking
			? SetupResult::error($description)
			: SetupResult::warning($description);
	}

	/**
	 * Die wirksame Freigabeliste.
	 *
	 * Der zweite Parameter von `getValueString()` bleibt leer und ist trotzdem
	 * kein Versehen: Steht kein Wert in der Konfiguration, liefert `IAppConfig`
	 * die **Lexikon-Vorgabe** der Guests-App und nicht diesen Wert. Genau
	 * deshalb ist das der richtige Weg — er sieht dasselbe wie Guests selbst.
	 *
	 * @return string[]
	 */
	private function readWhitelist(): array {
		$raw = $this->appConfig->getValueString(self::GUESTS_APP, 'whitelist', '');

		return array_values(array_filter(
			array_map('trim', explode(',', $raw)),
			static fn (string $entry): bool => $entry !== '',
		));
	}
}
