<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Tests\Unit\Mcp;

use OCA\Projektwerk\Mcp\Tool;
use OCA\Projektwerk\Tests\ReadPathRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Die Bauform des MCP-Endpunkts: eine Tür (`BoardAccess`), keine Sitzung, keine Admin-Ausnahme.
 */
class McpArchitectureTest extends TestCase {

	private const LIB = __DIR__ . '/../../../lib';

	private const MCP_FILES = ['Controller/McpController.php', 'Controller/McpMetadataController.php'];

	public function testMcpLayerNeverTouchesTheSessionOrBuildsItsOwnContext(): void {
		$forbidden = ['IUserSession', 'setUser', 'IGroupManager', 'isAdmin', 'forMember('];

		$offenders = [];
		foreach ($this->mcpFiles() as $relative => $path) {
			$content = (string)file_get_contents($path);
			foreach ($forbidden as $needle) {
				if (str_contains($content, $needle)) {
					$offenders[] = $relative . ' (' . $needle . ')';
				}
			}
		}

		$this->assertSame([], $offenders, 'Die MCP-Schicht läuft als Person nur über BoardAccess::contextFor().');
	}

	/**
	 * `#[PublicPage]` öffnet einen Endpunkt ohne Anmeldung, `#[NoCSRFRequired]` ohne CSRF-Schutz.
	 */
	public function testSessionlessAttributesStayOnTheirNamedControllers(): void {
		$allowed = [
			'#[PublicPage]' => self::MCP_FILES,
			'#[NoCSRFRequired]' => [...self::MCP_FILES, 'Controller/PageController.php', 'Controller/DeepLinkController.php'],
		];

		$offenders = [];
		foreach ($this->phpFilesIn(self::LIB) as $relative => $path) {
			$content = (string)file_get_contents($path);
			foreach ($allowed as $attribute => $files) {
				$used = preg_match('/^\s*' . preg_quote($attribute, '/') . '/m', $content) === 1;
				if ($used && !in_array($relative, $files, true)) {
					$offenders[] = $relative . ' ' . $attribute;
				}
			}
		}

		$this->assertSame([], $offenders, 'Neue Ausnahme nur mit Grund in docs/nextcloud-fallstricke.md und hier.');
	}

	public function testEveryReadToolIsInTheLeakMatrixAndViceVersa(): void {
		$readOnly = [];
		foreach ($this->tools() as $tool) {
			$annotations = $tool->annotations();
			$this->assertArrayHasKey('readOnlyHint', $annotations, $tool->name() . ' ohne readOnlyHint');
			if ($annotations['readOnlyHint'] === true) {
				$readOnly[] = $tool->name();
			}
		}
		sort($readOnly);
		$registered = ReadPathRegistry::MCP_TOOLS;
		sort($registered);

		$this->assertSame($registered, $readOnly, 'Lesende MCP-Werkzeuge und ReadPathRegistry::MCP_TOOLS weichen ab.');
	}

	public function testEverySchemaIsClosedAndUsesOnlySupportedKeywords(): void {
		foreach ($this->tools() as $tool) {
			$schema = $tool->inputSchema();
			$this->assertSame('object', $schema['type'], $tool->name());
			$this->assertFalse($schema['additionalProperties'], $tool->name() . ' muss unbekannte Argumente ablehnen.');
			foreach ($schema['properties'] ?? [] as $name => $property) {
				$this->assertArrayHasKey('description', $property, $tool->name() . '.' . $name);
				$unsupported = array_diff(array_keys($property), ['type', 'description', 'minimum', 'maximum', 'minLength', 'maxLength', 'enum']);
				$this->assertSame([], array_values($unsupported), $tool->name() . '.' . $name);
			}
		}
	}

	/**
	 * @return list<Tool> ohne Abhängigkeiten gebaut; Name, Schema und Annotations brauchen keine
	 */
	private function tools(): array {
		$tools = [];
		foreach (glob(self::LIB . '/Mcp/Tools/*.php') as $file) {
			$class = 'OCA\\Projektwerk\\Mcp\\Tools\\' . basename($file, '.php');
			$reflection = new \ReflectionClass($class);
			if ($reflection->implementsInterface(Tool::class)) {
				$tools[] = $reflection->newInstanceWithoutConstructor();
			}
		}
		$this->assertNotEmpty($tools);

		return $tools;
	}

	/**
	 * @return iterable<string, string>
	 */
	private function mcpFiles(): iterable {
		foreach ($this->phpFilesIn(self::LIB) as $relative => $path) {
			if (str_starts_with($relative, 'Mcp/') || in_array($relative, self::MCP_FILES, true)) {
				yield $relative => $path;
			}
		}
	}

	/**
	 * @return iterable<string, string> relativer Pfad => absoluter Pfad
	 */
	private function phpFilesIn(string $dir): iterable {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
		);
		foreach ($iterator as $file) {
			/** @var \SplFileInfo $file */
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$path = $file->getPathname();
			yield ltrim(str_replace(realpath($dir), '', realpath($path)), '/') => $path;
		}
	}
}
