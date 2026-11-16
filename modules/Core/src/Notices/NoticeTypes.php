<?php

declare(strict_types=1);

namespace Modules\Core\Notices;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The kinds of alert people can get (specs/core/10, N1). Modules register theirs with a plain-English name and a
 * group; any other email notification is still caught, named from its class. Urgent alerts ignore quiet hours and
 * the digest. Essential messages (sign-in codes, password resets, receipts) are never held, hidden or turned off.
 */
final class NoticeTypes
{
    /** Class-name words that mark a message people must always get by email at once. */
    private const ESSENTIAL_WORDS = ['Password', 'Verify', 'Verification', 'SignIn', 'Code', 'Otp', 'Security', 'Receipt', 'Invitation', 'Invite', 'Digest', 'DueMail', 'Welcome', 'Consent'];

    /** @var array<string, array{key: string, label: string, group: string, urgent: bool}> class => type */
    private array $byClass = [];

    /** @var array<string, array{key: string, label: string, group: string, urgent: bool}> key => type */
    private array $byKey = [];

    /**
     * @param  class-string<Notification>  $class
     */
    public function register(string $class, string $label, string $group = 'General', bool $urgent = false): void
    {
        $type = ['key' => self::keyOf($class), 'label' => $label, 'group' => $group, 'urgent' => $urgent];
        $this->byClass[$class] = $type;
        $this->byKey[$type['key']] = $type;
    }

    /**
     * @return array{key: string, label: string, group: string, urgent: bool}
     */
    public function for(Notification $notification): array
    {
        $class = $notification::class;
        if (isset($this->byClass[$class])) {
            return $this->byClass[$class];
        }
        $key = self::keyOf($class);

        return $this->byKey[$key] ?? ['key' => $key, 'label' => Str::of(class_basename($class))->replaceMatches('/(Mail|Notification)$/', '')->headline()->toString(), 'group' => 'General', 'urgent' => false];
    }

    public function essential(Notification $notification): bool
    {
        if (isset($this->byClass[$notification::class])) {
            return false;
        }
        if (str_starts_with($notification::class, 'Illuminate\\') || str_starts_with($notification::class, 'Laravel\\')) {
            return true;
        }
        $base = class_basename($notification);
        foreach (self::ESSENTIAL_WORDS as $word) {
            if (str_contains($base, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{key: string, label: string, group: string, urgent: bool}>
     */
    public function all(): array
    {
        return $this->byKey;
    }

    public function label(string $key): string
    {
        return $this->byKey[$key]['label'] ?? Str::headline($key);
    }

    private static function keyOf(string $class): string
    {
        return Str::snake((string) preg_replace('/(Mail|Notification)$/', '', class_basename($class)));
    }
}
