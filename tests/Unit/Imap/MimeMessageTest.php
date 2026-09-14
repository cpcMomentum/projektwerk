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
