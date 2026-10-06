<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

/**
 * Das Ergebnis eines Werkzeugaufrufs: strukturiert plus dieselben Daten als Text.
 */
final class ToolResult {

	/**
	 * Eine Meldung für jedes „nicht gefunden", damit verborgen, gelöscht und nie
	 * angelegt nicht unterscheidbar sind.
	 */
	public const NOT_FOUND = 'Not found. The board or ticket does not exist or is not visible to you.';

	/** Obergrenze für den serialisierten Inhalt; Listen blättern darunter. */
	public const MAX_BYTES = 65536;

	/**
	 * @param array<string, mixed>|null $structured
	 */
	private function __construct(
		private ?array $structured,
		private string $text,
		private bool $isError,
	) {
	}

	/**
	 * @param array<string, mixed> $structured
	 */
	public static function ok(array $structured): self {
		return new self($structured, self::encode($structured), false);
	}

	public static function error(string $message): self {
		return new self(null, $message, true);
	}

	public static function notFound(): self {
		return self::error(self::NOT_FOUND);
	}

	public function isError(): bool {
		return $this->isError;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function structured(): ?array {
		return $this->structured;
	}

	/**
	 * @return array<string, mixed> das `result` der JSON-RPC-Antwort, ohne `resultType`
	 */
	public function toArray(): array {
		$result = [
			'content' => [['type' => 'text', 'text' => $this->text]],
			'isError' => $this->isError,
		];
		if ($this->structured !== null) {
			$result['structuredContent'] = $this->structured;
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function encode(array $data): string {
		return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	/**
	 * Wie viele der Zeilen (ab Anfang) unter die Obergrenze passen; mindestens eine.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	public static function rowsThatFit(array $rows, int $reserve = 2048): int {
		$budget = intdiv(self::MAX_BYTES - $reserve, 2);
		$used = 0;
		foreach ($rows as $index => $row) {
			$used += strlen(self::encode($row)) + 1;
			if ($used > $budget) {
				return max(1, $index);
			}
		}

		return count($rows);
	}
}
