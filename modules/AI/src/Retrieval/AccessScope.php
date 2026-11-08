<?php

declare(strict_types=1);

namespace Modules\AI\Retrieval;

/**
 * What the acting user may retrieve, resolved by the platform (never supplied by callers).
 * Mirrors the permission scopes in specs/core/03: own, team, office, tenant.
 */
final readonly class AccessScope
{
    /**
     * @param  'own'|'team'|'office'|'tenant'  $level
     * @param  list<int>  $teamIds
     * @param  list<int>  $officeIds
     */
    public function __construct(
        public int $userId,
        public string $level,
        public array $teamIds = [],
        public array $officeIds = [],
        public bool $includeSensitive = false,
    ) {}

    /**
     * @param  list<int>  $docTeamIds
     */
    public function allows(?int $ownerUserId, array $docTeamIds, ?int $officeId, bool $sensitive): bool
    {
        if ($sensitive && ! $this->includeSensitive) {
            return false;
        }

        return match ($this->level) {
            'tenant' => true,
            'office' => $ownerUserId === $this->userId || ($officeId !== null && in_array($officeId, $this->officeIds, true)),
            'team' => $ownerUserId === $this->userId || array_intersect($docTeamIds, $this->teamIds) !== [],
            default => $ownerUserId === $this->userId,
        };
    }
}
