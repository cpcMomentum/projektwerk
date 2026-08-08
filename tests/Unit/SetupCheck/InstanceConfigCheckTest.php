<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\SetupCheck;

use OCA\Projektwerk\SetupCheck\InstanceConfigCheck;
use OCP\IConfig;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

/**
 * Die drei Instanzeinstellungen, einzeln und zusammen.
 *
 * Jeder Fall setzt genau **einen** Wert auf den Problemwert und die anderen
 * beiden auf gute Werte. Ein Test, der alles gleichzeitig kaputt macht, ist
 * gruen, sobald irgendeine der drei Pruefungen anschlaegt — und bemerkt nicht,
 * wenn zwei davon nie laufen.
 */
class InstanceConfigCheckTest extends TestCase {

	/** Werte, bei denen keine der drei Pruefungen etwas zu melden hat. */
	private const HEALTHY = [
		'backgroundjobs_mode' => 'cron',
		'overwrite.cli.url' => 'https://cloud.example.org',
		'mail_smtptimeout' => 3,
	];

	public function testHealthyInstanceReportsSuccess(): void {
		$result = $this->runWith(self::HEALTHY);

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}

	public function testAjaxCronIsReported(): void {
		$result = $this->runWith(['backgroundjobs_mode' => 'ajax'] + self::HEALTHY);

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('ajax', (string)$result->getDescription());
		$this->assertStringContainsString('occ background:cron', (string)$result->getDescription());
	}

	/**
	 * Ungesetzt ist der haeufigste Fall und zugleich der stille: Nextcloud
	 * verhaelt sich dann wie bei `ajax`.
	 */
	public function testUnsetCronModeCountsAsAjax(): void {
		$config = $this->createStub(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			// Genau das Verhalten von IConfig: Ohne Eintrag kommt der uebergebene
			// Vorgabewert zurueck — und den setzt der Check auf 'ajax'.
			static fn (string $app, string $key, $default = '') => $default,
		);
		$config->method('getSystemValueString')->willReturn(self::HEALTHY['overwrite.cli.url']);
		$config->method('getSystemValueInt')->willReturn(self::HEALTHY['mail_smtptimeout']);

		$result = (new InstanceConfigCheck($config, $this->l10n()))->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('ajax', (string)$result->getDescription());
	}

	public function testEmptyCliUrlIsReported(): void {
		$result = $this->runWith(['overwrite.cli.url' => ''] + self::HEALTHY);

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('overwrite.cli.url', (string)$result->getDescription());
	}

	/**
	 * @param string $url Adresse, die nur lokal gilt
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('localUrls')]
	public function testLocalCliUrlIsReported(string $url): void {
		$result = $this->runWith(['overwrite.cli.url' => $url] + self::HEALTHY);

		$this->assertSame(SetupResult::WARNING, $result->getSeverity(), $url);
		$this->assertStringContainsString('overwrite.cli.url', (string)$result->getDescription());
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function localUrls(): array {
		return [
			// Der auf nextcloud-dev am 2026-08-08 gemessene Wert.
			'http://localhost' => ['http://localhost'],
			'mit Pfad' => ['http://localhost/nextcloud'],
			'IPv4-Rueckschleife' => ['https://127.0.0.1'],
			'ohne Schema' => ['localhost'],
		];
	}

	/**
	 * @param string $url Von aussen erreichbare Adresse
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('publicUrls')]
	public function testPublicCliUrlPasses(string $url): void {
		$result = $this->runWith(['overwrite.cli.url' => $url] + self::HEALTHY);

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity(), $url);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function publicUrls(): array {
		return [
			'https' => ['https://cloud.example.org'],
			// Der Rechnername enthaelt „localhost" als Teilkette und ist trotzdem
			// oeffentlich. Eine Pruefung mit str_contains() faellt hier durch.
			'Teilkette im Namen' => ['https://localhost.cloud.example.org'],
		];
	}

	public function testDefaultMailTimeoutIsReported(): void {
		$result = $this->runWith(['mail_smtptimeout' => 10] + self::HEALTHY);

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('mail_smtptimeout', (string)$result->getDescription());
		$this->assertStringContainsString('10', (string)$result->getDescription());
	}

	public function testMeasuredMailTimeoutPasses(): void {
		$result = $this->runWith(['mail_smtptimeout' => 3] + self::HEALTHY);

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}

	/**
	 * Alle drei zugleich — die Meldung nennt auch alle drei.
	 *
	 * Der Fall ist nicht konstruiert: Genau so stand `nextcloud-dev` am
	 * 2026-08-08. Eine Meldung, die nach dem ersten Fund abbricht, wuerde einen
	 * Betreiber zweimal zurueckschicken.
	 */
	public function testAllThreeProblemsAppearInOneResult(): void {
		$result = $this->runWith([
			'backgroundjobs_mode' => 'ajax',
			'overwrite.cli.url' => 'http://localhost',
			'mail_smtptimeout' => 10,
		]);

		$description = (string)$result->getDescription();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('occ background:cron', $description);
		$this->assertStringContainsString('overwrite.cli.url', $description);
		$this->assertStringContainsString('mail_smtptimeout', $description);
	}

	/**
	 * @param array{backgroundjobs_mode: string, 'overwrite.cli.url': string, mail_smtptimeout: int} $values
	 */
	private function runWith(array $values): SetupResult {
		$config = $this->createStub(IConfig::class);
		$config->method('getAppValue')->willReturn($values['backgroundjobs_mode']);
		$config->method('getSystemValueString')->willReturn($values['overwrite.cli.url']);
		$config->method('getSystemValueInt')->willReturn($values['mail_smtptimeout']);

		return (new InstanceConfigCheck($config, $this->l10n()))->run();
	}

	/**
	 * Uebersetzt nicht, setzt aber die Parameter ein — sonst pruefte der Test
	 * Platzhalter statt Werte.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string
				=> $parameters === [] ? $text : vsprintf($text, $parameters),
		);

		return $l10n;
	}
}
