<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Mcp;

/**
 * Prüft Werkzeug-Argumente gegen die kleine JSON-Schema-Teilmenge, die die Werkzeuge nutzen.
 *
 * Unterstützt: `type` (object, string, integer, boolean), `properties`, `required`,
 * `additionalProperties: false`, `enum`, `minLength`, `maxLength`, `minimum`, `maximum`.
 * Ein Schlüsselwort außerhalb dieser Menge ist ein Programmierfehler und wirft.
 */
class SchemaValidator {

	private const SUPPORTED = [
		'type', 'properties', 'required', 'additionalProperties', 'enum',
		'minLength', 'maxLength', 'minimum', 'maximum', 'description', 'title',
	];

	/**
	 * @param array<string, mixed> $schema
	 * @return list<string> Fehlermeldungen für das Modell; leer heißt gültig
	 */
	public function validate(array $schema, mixed $value, string $path = 'arguments'): array {
		foreach (array_keys($schema) as $keyword) {
			if (!in_array($keyword, self::SUPPORTED, true)) {
				throw new \LogicException('Unsupported schema keyword: ' . $keyword);
			}
		}

		$type = $schema['type'] ?? null;
		if ($type !== null && !$this->hasType($value, (string)$type)) {
			return [$path . ' must be of type ' . $type . '.'];
		}

		$errors = [];
		if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
			$errors[] = $path . ' must be one of: ' . implode(', ', array_map('strval', $schema['enum'])) . '.';
		}
		if (is_string($value)) {
			$length = mb_strlen($value);
			if (isset($schema['minLength']) && $length < $schema['minLength']) {
				$errors[] = $path . ' must be at least ' . $schema['minLength'] . ' characters long.';
			}
			if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
				$errors[] = $path . ' must be at most ' . $schema['maxLength'] . ' characters long.';
			}
		}
		if (is_int($value)) {
			if (isset($schema['minimum']) && $value < $schema['minimum']) {
				$errors[] = $path . ' must be at least ' . $schema['minimum'] . '.';
			}
			if (isset($schema['maximum']) && $value > $schema['maximum']) {
				$errors[] = $path . ' must be at most ' . $schema['maximum'] . '.';
			}
		}
		if ($type === 'object' && is_array($value)) {
			$errors = array_merge($errors, $this->validateObject($schema, $value, $path));
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $schema
	 * @param array<mixed> $value
	 * @return list<string>
	 */
	private function validateObject(array $schema, array $value, string $path): array {
		$errors = [];
		$properties = $schema['properties'] ?? [];

		foreach ($schema['required'] ?? [] as $name) {
			if (!array_key_exists($name, $value)) {
				$errors[] = $path . '.' . $name . ' is required.';
			}
		}
		foreach ($value as $name => $item) {
			if (!isset($properties[$name])) {
				if (($schema['additionalProperties'] ?? true) === false) {
					$errors[] = $path . '.' . $name . ' is not a known argument.';
				}
				continue;
			}
			$errors = array_merge($errors, $this->validate($properties[$name], $item, $path . '.' . $name));
		}

		return $errors;
	}

	private function hasType(mixed $value, string $type): bool {
		return match ($type) {
			// Ein leeres JSON-Objekt kommt als leeres PHP-Array an.
			'object' => is_array($value) && ($value === [] || !array_is_list($value)),
			'string' => is_string($value),
			'integer' => is_int($value),
			'boolean' => is_bool($value),
			default => throw new \LogicException('Unsupported schema type: ' . $type),
		};
	}
}
