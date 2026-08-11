<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Settings;

use OCA\Projektwerk\AppInfo\Application;
use OCA\Projektwerk\Db\NotifyPref;
use OCA\Projektwerk\Db\NotifyPrefMapper;
use OCA\Projektwerk\Service\NotifyPrefService;
use OCP\IL10N;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

/**
 * Die allgemeinen Kanalschalter — in Nextclouds persönlichen Einstellungen.
 *
 * **Deklarativ statt gebaut.** Nextcloud kann ein Formular aus einer
 * Beschreibung erzeugen; für zwei Häkchen ist das genau richtig. Die Alternative
 * wäre ein zweiter Vue-Einstiegspunkt gewesen — ein eigenes Bundle, eine eigene
 * Vorlage, eine eigene Fehlerbehandlung, alles für zwei Zeilen Formular.
 *
 * **`storage_type: external`** ist der Kern: Nextcloud speichert **nicht**
 * selbst. Es fragt bei jedem Anzeigen {@see getValue()} und ruft beim Umlegen
 * {@see setValue()}. Damit bleibt `pwerk_notify_prefs` die einzige Quelle, und
 * die dreistufige Auflösung (Projekt → global → an) hat weiterhin genau einen
 * Ort. Mit `internal` läge derselbe Wert an zwei Stellen, und die zweite wäre
 * die, die niemand prüft.
 *
 * Was hier steht, ist die **globale** Ebene (`board_id = 0`): Sie gilt für jedes
 * Projekt ohne eigene Einstellung — auch für die, die es heute noch nicht gibt.
 * Die Ausnahme je Projekt steht in den Projekteinstellungen, dort, wo man sie
 * sucht.
 */
class PersonalNotifications implements IDeclarativeSettingsFormWithHandlers {

	/** Die Feldkennungen im Formular — bewusst gleich den Kanalnamen. */
	private const FELDER = [NotifyPref::CHANNEL_MAIL, NotifyPref::CHANNEL_BELL];

	public function __construct(
		private NotifyPrefService $service,
		private IL10N $l10n,
	) {
	}

	public function getSchema(): array {
		return [
			'id' => 'projektwerk-notifications',
			'priority' => 50,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_PERSONAL,
			// Nextclouds eigener Abschnitt für Benachrichtigungen — dort sucht
			// man solche Schalter, nicht unter einem App-Namen.
			'section_id' => 'notifications',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => $this->l10n->t('ProjektWerk'),
			'description' => $this->l10n->t(
				'Gilt für alle Projekte ohne eigene Einstellung — auch für neue. Einzelne Projekte lassen sich in deren Projekteinstellungen davon ausnehmen.',
			),
			'fields' => [
				[
					'id' => NotifyPref::CHANNEL_MAIL,
					'title' => $this->l10n->t('E-Mail'),
					'description' => $this->l10n->t('Bei Zuweisung eines Vorgangs oder Arbeitsschritts und bei neuen Vorgängen.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => true,
				],
				[
					'id' => NotifyPref::CHANNEL_BELL,
					'title' => $this->l10n->t('Glocke in Nextcloud'),
					'description' => $this->l10n->t('Dieselben Anlässe, nur innerhalb von Nextcloud.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => true,
				],
			],
		];
	}

	public function getValue(string $fieldId, IUser $user): mixed {
		$this->assertFeld($fieldId);

		// **Der gespeicherte Stand der globalen Zeile, nicht der aufgelöste.**
		// Hier gibt es nichts aufzulösen — das ist die oberste Ebene. Fehlt die
		// Zeile, gilt „an", und genau das ist auch der `default` im Schema.
		return $this->service->forUser($user->getUID())['global'][$fieldId] ?? true;
	}

	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		$this->assertFeld($fieldId);

		$this->service->set(
			$user->getUID(),
			$fieldId,
			NotifyPrefMapper::GLOBAL_SCOPE,
			(bool)$value,
		);
	}

	/**
	 * @param string $fieldId Was das Formular geschickt hat.
	 * @throws \InvalidArgumentException unbekanntes Feld
	 */
	private function assertFeld(string $fieldId): void {
		if (!in_array($fieldId, self::FELDER, true)) {
			// Der Aufruf kommt von Nextcloud, nicht aus unserem Frontend — ein
			// unbekanntes Feld hiesse, dass Schema und Verarbeitung
			// auseinandergelaufen sind. Das gehoert gemeldet, nicht stumm
			// ignoriert.
			throw new \InvalidArgumentException(Application::APP_ID . ': unbekanntes Feld ' . $fieldId);
		}
	}
}
