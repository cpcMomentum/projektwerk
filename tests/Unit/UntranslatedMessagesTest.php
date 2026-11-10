<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit;

use PHPUnit\Framework\TestCase;

class UntranslatedMessagesTest extends TestCase {

	private const DIRS = ['lib/Service', 'lib/Controller'];

	// Ganze Sätze enden mit Punkt; technische Meldungen hängen einen Wert an und bleiben unübersetzt.
	private const PATTERN = "/new\\s+[\\\\\\w]+Exception\\(\\s*'(?:[^'\\\\]|\\\\.)*\\.'\\s*[,)]/";

	public function testSentenceMessagesGoThroughL10n(): void {
		$root = dirname(__DIR__, 2);
		$found = [];
		foreach (self::DIRS as $dir) {
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
			foreach ($files as $file) {
				if ($file->getExtension() !== 'php') {
					continue;
				}
				$found = array_merge($found, self::untranslated((string)file_get_contents($file->getPathname()), $dir . '/' . $file->getFilename()));
			}
		}

		$this->assertSame([], $found, "Meldungen ohne \$this->l10n->t():\n" . implode("\n", $found));
	}

	public function testDetectsUntranslatedSentence(): void {
		$code = "throw new \\InvalidArgumentException('Eine Spalte braucht einen Namen.');\n"
			. "throw new NotManagerException(\n\t'Nur Verwalter.',\n);\n"
			. "throw new \\InvalidArgumentException(\$this->l10n->t('Übersetzt.'));\n"
			. "throw new \\InvalidArgumentException('Unbekannte Rolle: ' . \$role);\n";

		$this->assertSame(['x.php:1', 'x.php:2'], self::untranslated($code, 'x.php'));
	}

	/** @return list<string> */
	private static function untranslated(string $code, string $name): array {
		preg_match_all(self::PATTERN, $code, $matches, PREG_OFFSET_CAPTURE);
		return array_map(
			static fn (array $m): string => $name . ':' . (substr_count(substr($code, 0, $m[1]), "\n") + 1),
			$matches[0],
		);
	}
}
