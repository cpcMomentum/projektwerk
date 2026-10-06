<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

/**
 * Ein MCP-Werkzeug. Es arbeitet nur über `BoardAccess`, die Lesemodelle und die Dienste.
 */
interface Tool {

	public function name(): string;

	public function title(): string;

	/**
	 * Text für das Modell: was das Werkzeug tut und was es zurückgibt.
	 */
	public function description(): string;

	/**
	 * @return array<string, mixed> JSON Schema 2020-12, immer mit `additionalProperties: false`
	 */
	public function inputSchema(): array;

	/**
	 * @return array<string, bool> MCP-Tool-Annotations, mindestens `readOnlyHint`
	 */
	public function annotations(): array;

	/**
	 * @param array<string, mixed> $arguments bereits gegen {@see inputSchema()} geprüft
	 */
	public function call(AuthenticatedCaller $caller, array $arguments): ToolResult;
}
