<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

/**
 * HTTP-Status plus JSON-RPC-Rumpf; ein Rumpf von null heißt „ohne Rumpf" (202).
 */
final readonly class McpResponse {

	/**
	 * @param array<string, mixed>|null $body
	 */
	public function __construct(
		public int $status,
		public ?array $body,
	) {
	}

	/**
	 * @param array<string, mixed> $result
	 */
	public static function result(mixed $id, array $result): self {
		return new self(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => (object)$result]);
	}

	/**
	 * @param array<string, mixed>|null $data
	 */
	public static function error(int $status, mixed $id, int $code, string $message, ?array $data = null): self {
		$error = ['code' => $code, 'message' => $message];
		if ($data !== null) {
			$error['data'] = $data;
		}

		return new self($status, ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]);
	}
}
