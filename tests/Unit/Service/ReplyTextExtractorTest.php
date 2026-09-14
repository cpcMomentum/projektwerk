<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Service;

use OCA\Projektwerk\Service\ReplyTextExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Zitat und Signatur abschneiden (#287) — der heikle Teil.
 *
 * Zu gierig gefrisst echten Text, zu zaghaft lässt das Zitat stehen. Jeder Fall
 * fixiert genau eine Grenze.
 */
class ReplyTextExtractorTest extends TestCase {

	public function testKeepsPlainReply(): void {
		$this->assertSame('Passt so, danke!', ReplyTextExtractor::extract('Passt so, danke!'));
	}

	public function testCutsAtQuotedLines(): void {
		$raw = "Ja, bitte umsetzen.\n\n> Am Montag hattest du gefragt\n> ob wir starten";
		$this->assertSame('Ja, bitte umsetzen.', ReplyTextExtractor::extract($raw));
	}

	public function testCutsAtSignatureDelimiter(): void {
		$raw = "Kurze Rückmeldung: passt.\n-- \nAnna Reuter\nGeschäftsführung";
		$this->assertSame('Kurze Rückmeldung: passt.', ReplyTextExtractor::extract($raw));
	}

	public function testCutsAtGermanReplyMarker(): void {
		$raw = "Sieht gut aus.\nAm 14.09.2026 um 10:00 schrieb Anna Reuter:\nDer Entwurf liegt bei.";
		$this->assertSame('Sieht gut aus.', ReplyTextExtractor::extract($raw));
	}

	public function testCutsAtEnglishReplyMarker(): void {
		$raw = "Looks good.\nOn Mon, 14 Sep 2026 at 10:00, Anna wrote:\nThe draft is attached.";
		$this->assertSame('Looks good.', ReplyTextExtractor::extract($raw));
	}

	public function testCutsAtOriginalMessageSeparator(): void {
		$raw = "Freigegeben.\n-----Ursprüngliche Nachricht-----\nVon: ProjektWerk";
		$this->assertSame('Freigegeben.', ReplyTextExtractor::extract($raw));
	}

	public function testCutsAtQuotedHeaderBlock(): void {
		$raw = "Danke!\nVon: projekte@firma.de\nGesendet: Montag\nBetreff: Vorgang";
		$this->assertSame('Danke!', ReplyTextExtractor::extract($raw));
	}

	public function testReturnsEmptyWhenOnlyQuote(): void {
		$raw = "> nur zitierter Text\n> zweite Zeile";
		$this->assertSame('', ReplyTextExtractor::extract($raw));
	}

	public function testTrimsSurroundingWhitespace(): void {
		$raw = "\n\n   Mit Abstand vorn und hinten   \n\n";
		$this->assertSame('Mit Abstand vorn und hinten', ReplyTextExtractor::extract($raw));
	}

	public function testCapsAtMaxLength(): void {
		$raw = str_repeat('a', ReplyTextExtractor::MAX_LENGTH + 500);
		$result = ReplyTextExtractor::extract($raw);

		// Gedeckelt auf MAX_LENGTH plus die Hinweis-Ellipse.
		$this->assertSame(ReplyTextExtractor::MAX_LENGTH + 2, mb_strlen($result));
		$this->assertStringEndsWith('…', $result);
	}

	public function testHandlesCrlfLineEndings(): void {
		$raw = "Erste Zeile\r\nZweite Zeile\r\n> zitiert\r\n> mehr";
		$this->assertSame("Erste Zeile\nZweite Zeile", ReplyTextExtractor::extract($raw));
	}
}
