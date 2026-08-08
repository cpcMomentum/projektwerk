<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\SetupCheck;

use OCA\Projektwerk\SetupCheck\GuestsWhitelistCheck;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

/**
 * Der Check, der nur meldet — und dessen Meldung genau deshalb stimmen muss.
 *
 * Weil er nicht schreibt (E5), ist der vorgeschlagene Sollwert das eigentliche
 * Ergebnis. Ein Sollwert, der die bestehende Liste nicht mitfuehrt, richtet beim
 * Kopieren genau den Schaden an, den die Entscheidung verhindern sollte —
 * deshalb prueft {@see testTargetValuePreservesTheExistingList()} das
 * ausdruecklich.
 */
class GuestsWhitelistCheckTest extends TestCase {

	/**
	 * Die eingebaute Vorgabe aus Guests 4.9.0, gemessen in S1 am 2026-08-07.
	 *
	 * Steht hier als Testdatum, **nicht** im Produktivcode: Der Check darf sie
	 * nicht nachbilden, sondern liest sie ueber `IAppConfig` von Guests selbst.
	 */
	private const GUESTS_DEFAULT = 'files_trashbin,files_versions,files_sharing,files_texteditor,'
		. 'text,activity,firstrunwizard,photos,notifications,dashboard,user_status,weather_status';

	/**
	 * Ohne Guests gibt es nichts einzurichten — und keine Meldung.
	 */
	public function testSilentWhenGuestsIsNotInstalled(): void {
		$result = $this->runCheck(installed: false, useWhitelist: true, whitelist: '');

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}

	/**
	 * Ist die Liste abgeschaltet, erreichen Gaeste ohnehin jede App.
	 */
	public function testSilentWhenWhitelistIsDisabled(): void {
		$result = $this->runCheck(installed: true, useWhitelist: false, whitelist: self::GUESTS_DEFAULT);

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}

	public function testSuccessWhenBothEntriesArePresent(): void {
		$result = $this->runCheck(
			installed: true,
			useWhitelist: true,
			whitelist: self::GUESTS_DEFAULT . ',projektwerk,viewer',
		);

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}

	/**
	 * Fehlt die App selbst, ist sie fuer Kunden unbenutzbar — Fehler, nicht
	 * Warnung.
	 */
	public function testErrorWhenTheAppItselfIsMissing(): void {
		$result = $this->runCheck(
			installed: true,
			useWhitelist: true,
			whitelist: self::GUESTS_DEFAULT,
		);

		$this->assertSame(SetupResult::ERROR, $result->getSeverity());
		$this->assertStringContainsString('projektwerk', (string)$result->getDescription());
	}

	/**
	 * Der Ist-Zustand von `nextcloud-dev` am 2026-08-08: ProjektWerk steht auf
	 * der Liste, der Viewer nicht. Anhaenge lassen sich dann nicht oeffnen,
	 * alles andere geht — eine Warnung.
	 */
	public function testWarningWhenOnlyTheViewerIsMissing(): void {
		$result = $this->runCheck(
			installed: true,
			useWhitelist: true,
			whitelist: self::GUESTS_DEFAULT . ',projektwerk',
		);

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('viewer', (string)$result->getDescription());
	}

	/**
	 * **Der wichtigste Test dieser Datei.**
	 *
	 * Der vorgeschlagene Sollwert muss die bestehende Liste vollstaendig
	 * enthalten. Wer ihn kopiert und einsetzt, darf den zwoelf Vorgabe-Apps
	 * nichts wegnehmen — das ist der ganze Grund, warum E5 gegen automatisches
	 * Schreiben entschieden hat.
	 */
	public function testTargetValuePreservesTheExistingList(): void {
		$result = $this->runCheck(
			installed: true,
			useWhitelist: true,
			whitelist: self::GUESTS_DEFAULT,
		);

		$description = (string)$result->getDescription();

		foreach (explode(',', self::GUESTS_DEFAULT) as $app) {
			$this->assertStringContainsString($app, $description, $app . ' fehlt im vorgeschlagenen Sollwert.');
		}

		$this->assertStringContainsString(
			'occ config:app:set guests whitelist --value="' . self::GUESTS_DEFAULT . ',projektwerk,viewer"',
			$description,
			'Der Sollwert ist nicht als vollstaendiger Befehl zum Kopieren enthalten.',
		);
	}

	/**
	 * Der Check schreibt nichts — mechanisch festgehalten.
	 *
	 * E5 ist eine Entscheidung, die in einem Dokument steht. Hier wird sie zu
	 * einer Eigenschaft des Codes: Jede schreibende Methode von `IAppConfig`
	 * laesst diesen Test fallen.
	 */
	public function testTheCheckNeverWrites(): void {
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);
		$appConfig->method('getValueString')->willReturn(self::GUESTS_DEFAULT);

		foreach (['setValueString', 'setValueBool', 'setValueArray', 'setValueInt', 'deleteKey'] as $writer) {
			$appConfig->expects($this->never())->method($writer);
		}

		(new GuestsWhitelistCheck($appManager, $appConfig, $this->l10n()))->run();
	}

	private function runCheck(bool $installed, bool $useWhitelist, string $whitelist): SetupResult {
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($installed);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn($useWhitelist);
		$appConfig->method('getValueString')->willReturn($whitelist);

		return (new GuestsWhitelistCheck($appManager, $appConfig, $this->l10n()))->run();
	}

	private function l10n(): IL10N {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string
				=> $parameters === [] ? $text : vsprintf($text, $parameters),
		);

		return $l10n;
	}
}
