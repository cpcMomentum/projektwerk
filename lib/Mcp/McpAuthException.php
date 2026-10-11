<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

/**
 * Eine abgewiesene Anfrage, mit HTTP-Status und optionaler Bearer-Challenge.
 */
class McpAuthException extends \RuntimeException {

	/**
	 * @param string|null $challenge Wert für `WWW-Authenticate`, oder null
	 */
	public function __construct(
		public readonly int $status,
		public readonly ?string $challenge = null,
		string $message = '',
	) {
		parent::__construct($message);
	}
}
