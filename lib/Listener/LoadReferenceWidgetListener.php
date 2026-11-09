<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Listener;

use OCA\Projektwerk\AppInfo\Application;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Lädt das kleine Widget-Bundle dort, wo Vorschaukarten gerendert werden (Talk, Text, Deck).
 *
 * @template-implements IEventListener<RenderReferenceEvent>
 */
class LoadReferenceWidgetListener implements IEventListener {

	public function handle(Event $event): void {
		if (!$event instanceof RenderReferenceEvent) {
			return;
		}

		Util::addTranslations(Application::APP_ID);
		Util::addScript(Application::APP_ID, Application::APP_ID . '-reference');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-reference');
	}
}
