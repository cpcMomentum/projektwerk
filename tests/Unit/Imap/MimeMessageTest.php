<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Imap;

use OCA\Projektwerk\Imap\MimeMessage;
use PHPUnit\Framework\TestCase;

/**
 * Der MIME-Parser (#287) — die Felder, auf denen Zuordnung und Schutz beruhen.
 *
 * Zerlegt eine rohe Mail ohne IMAP: Absenderadresse (für die Gegenprüfung),
 * Betreff (Token), Auto-Submitted/Precedence (Automatenschutz), Anhänge-Flag
 * und der reine Text-Body (auch aus multipart/alternative).
 */
class MimeMessageTest extends TestCase {

	public function testExtractsBareFromAddressLowercased(): void {
		$raw = "From: \"Anna Reuter\" <Anna@Firma.DE>\r\nSubject: Re: [PW-abc]\r\n\r\nHallo";
		$mail = MimeMessage::parse($raw);

		$this->assertSame('anna@firma.de', $mail['from'], 'Nur die Adresse zählt, klein geschrieben.');
	}

	/**
	 * Die Adresse kommt nur aus der Stelle, an der sie nach RFC 5322 steht.
	 * Ein `<…>` im Anzeigenamen, in einem kodierten Wort oder in einem
	 * Kommentar ist Text — sonst ließe sich die Absender-Gegenprüfung mit
	 * einer Mail von der eigenen Domain täuschen.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('absender')]
	public function testFromAddressIsTakenFromTheAddrSpecOnly(string $header, string $erwartet): void {
		$mail = MimeMessage::parse("From: {$header}\r\nSubject: s\r\n\r\nText");

		$this->assertSame($erwartet, $mail['from']);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function absender(): array {
		return [
			'nackte Adresse' => ['anna@firma.de', 'anna@firma.de'],
			'Name ohne Anführungszeichen' => ['Anna Reuter <anna@firma.de>', 'anna@firma.de'],
			'Name mit Komma in Anführungszeichen' => ['"Reuter, Anna" <anna@firma.de>', 'anna@firma.de'],
			'Kommentar hinter der Adresse' => ['<anna@firma.de> (Anna)', 'anna@firma.de'],
			'kodierter Anzeigename' => ['=?utf-8?Q?J=C3=B6rg?= <joerg@firma.de>', 'joerg@firma.de'],
			'fremde Adresse im Anzeigenamen' => ['"<opfer@kunde.de>" <taeter@evil.example>', 'taeter@evil.example'],
			'fremde Adresse im kodierten Wort' => ['=?utf-8?B?PG9wZmVyQGt1bmRlLmRlPg==?= <taeter@evil.example>', 'taeter@evil.example'],
			'fremde Adresse im Kommentar' => ['(<opfer@kunde.de>) <taeter@evil.example>', 'taeter@evil.example'],
			'maskiertes Anführungszeichen im Namen' => ['"a\\" <opfer@kunde.de> \\"b" <taeter@evil.example>', 'taeter@evil.example'],
			'zwei spitze Klammern' => ['<opfer@kunde.de> <taeter@evil.example>', ''],
			'zwei Adressen' => ['opfer@kunde.de, taeter@evil.example', ''],
			'Text hinter der Adresse' => ['<opfer@kunde.de> taeter@evil.example', ''],
			'keine Adresse' => ['Anna Reuter', ''],
		];
	}

	public function testTwoFromHeadersYieldNoSender(): void {
		$mail = MimeMessage::parse("From: opfer@kunde.de\r\nFrom: taeter@evil.example\r\nSubject: s\r\n\r\nText");

		$this->assertSame('', $mail['from'], 'Welcher From gilt, ist nicht eindeutig — also keiner.');
	}

	/**
	 * Eine tief verschachtelte Mail darf den Parser nicht in die Speichergrenze
	 * treiben. Jenseits der Tiefengrenze gibt er auf, statt weiter zu kopieren.
	 */
	public function testDeeplyNestedMultipartIsCutOff(): void {
		$tiefe = 400;
		$raw = "From: a@b.de\r\nSubject: s\r\nContent-Type: multipart/mixed; boundary=\"b0\"\r\n\r\n";
		for ($i = 0; $i < $tiefe; $i++) {
			$raw .= "--b{$i}\r\nContent-Type: multipart/mixed; boundary=\"b" . ($i + 1) . "\"\r\n\r\n";
		}
		$raw .= "--b{$tiefe}\r\nContent-Type: text/plain\r\n\r\nganz innen\r\n--b{$tiefe}--\r\n";

		$mail = MimeMessage::parse($raw);

		$this->assertStringNotContainsString('ganz innen', $mail['text'], 'Jenseits der Tiefengrenze wird nicht weiter zerlegt.');
		$this->assertSame('a@b.de', $mail['from']);
	}

	public function testReadsTextFromOrdinaryNesting(): void {
		$raw = "From: a@b.de\r\nSubject: s\r\nContent-Type: multipart/mixed; boundary=\"aussen\"\r\n\r\n"
			. "--aussen\r\nContent-Type: multipart/alternative; boundary=\"innen\"\r\n\r\n"
			. "--innen\r\nContent-Type: text/plain\r\n\r\nAntwort\r\n"
			. "--innen--\r\n--aussen--\r\n";

		$this->assertSame('Antwort', trim(MimeMessage::parse($raw)['text']));
	}

	public function testExtractsSubjectAndBody(): void {
		$raw = "From: a@b.de\r\nSubject: Re: [PW-token123]\r\n\r\nMeine Antwort";
		$mail = MimeMessage::parse($raw);

		$this->assertStringContainsString('[PW-token123]', $mail['subject']);
		$this->assertSame('Meine Antwort', trim($mail['text']));
	}

	public function testDecodesRfc2047Subject(): void {
		$raw = "From: a@b.de\r\nSubject: =?utf-8?Q?Antwort_mit_Umlaut_=C3=A4?=\r\n\r\nHi";
		$mail = MimeMessage::parse($raw);

		$this->assertStringContainsString('Umlaut ä', $mail['subject']);
	}

	public function testFlagsAutoSubmitted(): void {
		$raw = "From: a@b.de\r\nAuto-Submitted: auto-replied\r\nSubject: Abwesend\r\n\r\nBin im Urlaub";
		$mail = MimeMessage::parse($raw);

		$this->assertSame('auto-replied', $mail['autoSubmitted']);
	}

	public function testFlagsPrecedence(): void {
		$raw = "From: a@b.de\r\nPrecedence: bulk\r\nSubject: Newsletter\r\n\r\nInhalt";
		$mail = MimeMessage::parse($raw);

		$this->assertSame('bulk', $mail['precedence']);
	}

	public function testDetectsAttachment(): void {
		$raw = "From: a@b.de\r\nContent-Type: multipart/mixed; boundary=\"b1\"\r\nSubject: mit Anhang\r\n\r\n"
			. "--b1\r\nContent-Type: text/plain\r\n\r\nText\r\n"
			. "--b1\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=\"x.pdf\"\r\n\r\ndata\r\n"
			. "--b1--\r\n";
		$mail = MimeMessage::parse($raw);

		$this->assertTrue($mail['hasAttachments']);
		$this->assertSame('Text', trim($mail['text']), 'Der text/plain-Teil gewinnt.');
	}

	public function testPrefersPlainTextOverHtmlInAlternative(): void {
		$raw = "From: a@b.de\r\nContent-Type: multipart/alternative; boundary=\"x\"\r\nSubject: s\r\n\r\n"
			. "--x\r\nContent-Type: text/plain\r\n\r\nReiner Text\r\n"
			. "--x\r\nContent-Type: text/html\r\n\r\n<p>HTML</p>\r\n"
			. "--x--\r\n";
		$mail = MimeMessage::parse($raw);

		$this->assertSame('Reiner Text', trim($mail['text']));
	}

	public function testNoAttachmentFlagForPlainReply(): void {
		$raw = "From: a@b.de\r\nSubject: s\r\n\r\nNur Text, kein Anhang";
		$mail = MimeMessage::parse($raw);

		$this->assertFalse($mail['hasAttachments']);
	}
}
