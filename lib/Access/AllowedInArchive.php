<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 cpcMomentum
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Projektwerk\Access;

/** Schreibweg, der auch in einem archivierten Board offen bleibt. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class AllowedInArchive {
}
