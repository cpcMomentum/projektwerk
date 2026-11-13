<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Service;

use OCA\Projektwerk\Access\ViewerContext;
use OCA\Projektwerk\Db\ProjectLog;
use OCA\Projektwerk\Db\ProjectLogMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/** Wer im Projekt Mitglieder, Rollen und Boards geändert hat; Lesen nur für interne Verwalter, jeder Eintrag auch ins Admin-Log. */
class ProjectLogService {

	public const PAGE_SIZE = 50;
	private const MAX_LENGTH = 255;

	public function __construct(
		private ProjectLogMapper $log,
		private IEventDispatcher $events,
		private IUserManager $users,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	public function record(ViewerContext $viewer, string $action, ?string $targetUserId = null, ?string $subject = null, ?string $oldValue = null, ?string $newValue = null): void {
		$this->recordFor($viewer->projectId, $viewer->userId, $viewer->via, $action, $targetUserId, $subject, $oldValue, $newValue);
	}

	public function recordFor(int $projectId, string $actorUserId, ?string $via, string $action, ?string $targetUserId = null, ?string $subject = null, ?string $oldValue = null, ?string $newValue = null): void {
		try {
			$this->schreibe($projectId, $actorUserId, $via, $action, $targetUserId, $subject, $oldValue, $newValue);
		} catch (\Throwable $e) {
			// Die Änderung selbst ist schon gespeichert; ein fehlender Protokolleintrag darf sie nicht als Fehler melden.
			$this->logger->error('ProjektWerk: Protokolleintrag nicht geschrieben', ['exception' => $e, 'action' => $action, 'project' => $projectId]);
		}
	}

	private function schreibe(int $projectId, string $actorUserId, ?string $via, string $action, ?string $targetUserId, ?string $subject, ?string $oldValue, ?string $newValue): void {
		$entry = new ProjectLog();
		$entry->setProjectId($projectId);
		$entry->setActorUserId($actorUserId);
		$entry->setVia($via);
		$entry->setAction($action);
		$entry->setTargetUserId($targetUserId);
		$entry->setSubject($this->cut($subject));
		$entry->setOldValue($this->cut($oldValue));
		$entry->setNewValue($this->cut($newValue));
		// tz-ok: Zeitstempel des Eintrags
		$entry->setCreatedAt(new \DateTime());
		$this->log->insert($entry);

		// admin_audit verwirft die Zeile, wenn ein Parameter null ist.
		$this->events->dispatchTyped(new CriticalActionPerformedEvent(
			'ProjektWerk: %s in project %s by %s%s, subject "%s", target %s, %s -> %s',
			[
				'action' => $action,
				'project' => (string)$projectId,
				'actor' => $actorUserId,
				'via' => $via !== null ? ' via ' . $via : '',
				'subject' => $this->cut($subject) ?? '-',
				'target' => $targetUserId ?? '-',
				'old' => $oldValue ?? '-',
				'new' => $newValue ?? '-',
			],
		));
	}

	/**
	 * @return array{entries: list<array<string, mixed>>, hasMore: bool} neueste zuerst, mit aufgelösten Namen
	 * @throws NotManagerException
	 */
	public function listFor(ViewerContext $viewer, ?int $beforeId = null): array {
		if (!$viewer->isInternal() || !$viewer->isManager) {
			throw new NotManagerException($this->l10n->t('Das Protokoll sehen nur interne Mitglieder mit Verwaltungsrecht.'));
		}

		$namen = [];
		$name = function (?string $uid) use (&$namen): ?string {
			if ($uid === null || $uid === '') {
				return null;
			}

			return $namen[$uid] ??= $this->users->getDisplayName($uid) ?? $uid;
		};

		$zeilen = $this->log->findForProject($viewer, self::PAGE_SIZE + 1, $beforeId);

		return [
			'entries' => array_map(
				static fn (ProjectLog $entry): array => $entry->jsonSerialize() + [
					'actorName' => $name($entry->getActorUserId()),
					'targetName' => $name($entry->getTargetUserId()),
				],
				array_slice($zeilen, 0, self::PAGE_SIZE),
			),
			'hasMore' => count($zeilen) > self::PAGE_SIZE,
		];
	}

	private function cut(?string $value): ?string {
		return $value === null ? null : mb_substr($value, 0, self::MAX_LENGTH);
	}
}
