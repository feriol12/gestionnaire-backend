<?php

namespace App\Services\Admin;

use Illuminate\Support\Carbon;

/** BE-03 — résultat d'une demande de transition, traduit en HTTP par le contrôleur. */
final class TransitionOutcome
{
    public const CHANGED = 'changed';

    public const NOT_FOUND = 'not_found';

    public const CONFIRMATION_MISMATCH = 'confirmation_mismatch';

    public const REDUNDANT = 'redundant';

    private function __construct(
        public readonly string $kind,
        public readonly ?int $userId = null,
        public readonly ?string $status = null,
        public readonly ?Carbon $changedAt = null,
        public readonly ?string $eventId = null,
    ) {}

    public static function changed(int $userId, string $status, Carbon $at, string $eventId): self
    {
        return new self(self::CHANGED, $userId, $status, $at, $eventId);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    public static function confirmationMismatch(): self
    {
        return new self(self::CONFIRMATION_MISMATCH);
    }

    /** $status = état cible demandé, déjà en vigueur. */
    public static function redundant(string $status): self
    {
        return new self(self::REDUNDANT, status: $status);
    }
}
