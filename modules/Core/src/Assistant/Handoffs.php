<?php

declare(strict_types=1);

namespace Modules\Core\Assistant;

use App\Models\User;
use Closure;

/**
 * Where "Your AI Assistant" can hand a conversation to a person (AS1, specs/know/03): modules register targets, such
 * as the person's broker (Team chat) or RightAlly support (a support request). Only targets available to the person
 * are offered.
 */
final class Handoffs
{
    /** @var array<string, array{label: string, available: Closure(User): bool, send: Closure(User, string, string): string}> */
    private array $targets = [];

    /**
     * @param  Closure(User): bool  $available
     * @param  Closure(User, string, string): string  $send  (person, short subject, the conversation) => what happened, in plain words
     */
    public function register(string $key, string $label, Closure $available, Closure $send): void
    {
        $this->targets[$key] = ['label' => $label, 'available' => $available, 'send' => $send];
    }

    /** @return list<array{key: string, label: string}> */
    public function for(User $user): array
    {
        $out = [];
        foreach ($this->targets as $key => $t) {
            if (($t['available'])($user)) {
                $out[] = ['key' => $key, 'label' => $t['label']];
            }
        }

        return $out;
    }

    public function send(string $key, User $user, string $subject, string $conversation): ?string
    {
        $t = $this->targets[$key] ?? null;
        if ($t === null || ! ($t['available'])($user)) {
            return null;
        }

        return ($t['send'])($user, $subject, $conversation);
    }
}
