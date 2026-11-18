<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\License;

// Verzweigt wird nur ueber den Code; die Servermeldung wird hoechstens angezeigt.
class WerkPlusApiException extends \RuntimeException {

	public function __construct(
		private readonly string $errorCode,
		private readonly string $serverMessage = '',
		?\Throwable $previous = null,
		// Nur bei KEY_EXPIRED: Der geprüfte Schlüssel war eine Testlizenz.
		private readonly bool $trial = false,
	) {
		parent::__construct('WerkPlus: ' . $errorCode, 0, $previous);
	}

	public function wasTrial(): bool {
		return $this->trial;
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}

	public function getServerMessage(): string {
		return $this->serverMessage;
	}
}
