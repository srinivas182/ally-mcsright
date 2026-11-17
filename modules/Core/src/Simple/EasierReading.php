<?php

declare(strict_types=1);

namespace Modules\Core\Simple;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;

/**
 * Easier reading (AX1, specs/core/13): each person can make RightAlly easier to read: bigger text (everything grows,
 * since all sizes are in rem), stronger contrast, no movement, and underlined links. Applied on the <html> element
 * before the page draws, so there is no flash of small text.
 */
final class EasierReading
{
    public const TEXT = ['standard' => 'Standard', 'large' => 'Large', 'largest' => 'Largest'];

    public const DEFAULTS = ['text' => 'standard', 'contrast' => false, 'motion' => false, 'links' => false];

    /**
     * @return array{text: string, contrast: bool, motion: bool, links: bool}
     */
    public static function for(?User $user): array
    {
        $saved = $user !== null ? (array) ($user->getAttribute('ui_reading') ?? []) : [];
        $text = is_string($saved['text'] ?? null) && array_key_exists($saved['text'], self::TEXT) ? $saved['text'] : self::DEFAULTS['text'];

        return ['text' => $text, 'contrast' => (bool) ($saved['contrast'] ?? false), 'motion' => (bool) ($saved['motion'] ?? false), 'links' => (bool) ($saved['links'] ?? false)];
    }

    /**
     * The attributes for the <html> element, e.g. data-text="large" data-contrast="high".
     *
     * @return array<string, string>
     */
    public static function htmlAttributes(mixed $user): array
    {
        $r = self::for($user instanceof User ? $user : null);

        return array_filter([
            'data-text' => $r['text'] !== 'standard' ? $r['text'] : null,
            'data-contrast' => $r['contrast'] ? 'high' : null,
            'data-motion' => $r['motion'] ? 'reduce' : null,
            'data-links' => $r['links'] ? 'underline' : null,
        ], fn (?string $v): bool => $v !== null);
    }

    /**
     * @param  array{text?: string|null, contrast?: bool|null, motion?: bool|null, links?: bool|null}  $changes
     */
    public function set(User $user, array $changes): void
    {
        $now = self::for($user);
        if (isset($changes['text'])) {
            if (! array_key_exists($changes['text'], self::TEXT)) {
                throw ValidationException::withMessages(['text' => 'Choose Standard, Large or Largest.']);
            }
            $now['text'] = $changes['text'];
        }
        foreach (['contrast', 'motion', 'links'] as $k) {
            if (isset($changes[$k])) {
                $now[$k] = (bool) $changes[$k];
            }
        }
        $user->forceFill(['ui_reading' => $now])->save();
        app(AuditLogger::class)->record('core.reading.changed', metadata: $now, severity: 'info');
    }
}
