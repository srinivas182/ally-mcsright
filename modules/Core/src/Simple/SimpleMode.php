<?php

declare(strict_types=1);

namespace Modules\Core\Simple;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\TenantContext;

/**
 * Simple mode (SM1): for people who want RightAlly to be obvious. A "What do you want to do?" home with big
 * buttons, a short menu of the pages they use most, larger text, talking instead of typing, undo, a "Show me" tour on
 * the main pages, and English or Spanish for all of it. Anyone can switch between simple and full at any time.
 */
final class SimpleMode
{
    public const LOCALES = ['en' => 'English', 'es' => 'Español'];

    /** The short menu: only pages the person can already open, in this order. */
    public const MENU = ['/today', '/deals', '/contacts', '/tasks', '/policies'];

    private const EN = [
        'home' => 'Home', '/today' => 'Who to call today', '/deals' => 'My deals', '/contacts' => 'My people', '/tasks' => 'My to-dos', '/policies' => 'Ask a question',
        'greeting' => 'Hello, :name', 'what' => 'What do you want to do?',
        'talk' => 'Talk to RightAlly', 'talk_hint' => 'Say it: "Add Maria Lopez, she wants to sell in spring"',
        'add_person' => 'Add a person', 'start_deal' => 'Start a deal', 'call_today' => 'Who to call today', 'my_deals' => 'My deals', 'ask' => 'Ask a question', 'price_home' => 'Price a home',
        'needs_you' => 'Needs you today', 'nothing' => 'Nothing needs you right now. Nice work.',
        'show_me' => 'Show me how', 'next' => 'Next', 'done' => 'Done', 'simple' => 'Simple view', 'full' => 'Full view', 'language' => 'Language',
        'choose_title' => 'Would you like RightAlly simpler?', 'choose_intro' => 'Simple view shows only what you use most, with bigger buttons and text. You can switch any time.',
        'choose_simple' => 'Yes, keep it simple', 'choose_full' => 'No, show me everything', 'undo' => 'Undo',
        // SX4: Today's 10 on the home.
        'call' => 'Call', 'text' => 'Text', 'how_went' => 'How did it go?', 'talked' => 'We talked', 'left_message' => 'Left a message', 'no_answer' => 'No answer',
        'no_call' => 'No phone number we may call.', 'no_text' => 'Texts only after they say yes to texts.',
    ];

    private const ES = [
        'home' => 'Inicio', '/today' => 'A quién llamar hoy', '/deals' => 'Mis transacciones', '/contacts' => 'Mis contactos', '/tasks' => 'Mis pendientes', '/policies' => 'Hacer una pregunta',
        'greeting' => 'Hola, :name', 'what' => '¿Qué quiere hacer?',
        'talk' => 'Hablar con RightAlly', 'talk_hint' => 'Dígalo: "Agrega a María López, quiere vender en primavera"',
        'add_person' => 'Agregar una persona', 'start_deal' => 'Empezar una transacción', 'call_today' => 'A quién llamar hoy', 'my_deals' => 'Mis transacciones', 'ask' => 'Hacer una pregunta', 'price_home' => 'Valuar una casa',
        'needs_you' => 'Lo que necesita hoy', 'nothing' => 'Nada pendiente por ahora. ¡Buen trabajo!',
        'show_me' => 'Muéstrame cómo', 'next' => 'Siguiente', 'done' => 'Listo', 'simple' => 'Vista sencilla', 'full' => 'Vista completa', 'language' => 'Idioma',
        'choose_title' => '¿Quiere RightAlly más sencillo?', 'choose_intro' => 'La vista sencilla muestra solo lo que más usa, con botones y letra más grandes. Puede cambiar cuando quiera.',
        'choose_simple' => 'Sí, más sencillo', 'choose_full' => 'No, mostrar todo', 'undo' => 'Deshacer',
        'call' => 'Llamar', 'text' => 'Mensaje', 'how_went' => '¿Cómo le fue?', 'talked' => 'Hablamos', 'left_message' => 'Dejé un mensaje', 'no_answer' => 'No contestó',
        'no_call' => 'No hay un número al que podamos llamar.', 'no_text' => 'Mensajes solo después de que acepten recibirlos.',
    ];

    public static function on(?User $user): bool
    {
        return self::mode($user) === 'simple';
    }

    /**
     * The person's view: their own choice, or, before they choose, simple for a solo agent's RightAlly (SO1) and not
     * yet chosen (null, which asks them once) everywhere else.
     */
    public static function mode(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        $mode = $user->getAttribute('ui_mode');
        if (is_string($mode)) {
            return $mode;
        }
        $tenants = app(TenantContext::class);

        return $tenants->check() && $tenants->current()->type === TenantType::Solo ? 'simple' : null;
    }

    public static function locale(?User $user): string
    {
        $l = (string) ($user?->getAttribute('ui_locale') ?? 'en');

        return array_key_exists($l, self::LOCALES) ? $l : 'en';
    }

    /**
     * The words for the simple surfaces.
     *
     * @return array<string, string>
     */
    public static function words(string $locale): array
    {
        return $locale === 'es' ? self::ES + self::EN : self::EN;
    }

    /**
     * Both languages have every key (checked by the tests).
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        return array_merge(array_diff(array_keys(self::EN), array_keys(self::ES)), array_diff(array_keys(self::ES), array_keys(self::EN)));
    }

    /**
     * The short menu from the person's full menu (never a page they could not open).
     *
     * @param  list<array{label: string, items: list<array{title: string, href: string, icon: string, mobile: bool}>}>  $full
     * @return list<array{label: string, items: list<array{title: string, href: string, icon: string, mobile: bool}>}>
     */
    public static function menu(array $full, string $locale): array
    {
        $w = self::words($locale);
        $all = [];
        foreach ($full as $group) {
            foreach ($group['items'] as $item) {
                $all[$item['href']] = $item;
            }
        }
        $items = [['title' => $w['home'], 'href' => '/simple', 'icon' => 'layout-grid', 'mobile' => true]];
        foreach (self::MENU as $href) {
            if (isset($all[$href])) {
                $items[] = ['title' => $w[$href], 'href' => $href, 'icon' => $all[$href]['icon'], 'mobile' => true];
            }
        }

        return [['label' => 'RightAlly', 'items' => $items]];
    }

    public function set(User $user, ?string $mode, ?string $locale): void
    {
        if ($mode !== null && ! in_array($mode, ['simple', 'full'], true)) {
            throw ValidationException::withMessages(['mode' => 'Choose simple or full.']);
        }
        if ($locale !== null && ! array_key_exists($locale, self::LOCALES)) {
            throw ValidationException::withMessages(['locale' => 'Choose English or Spanish.']);
        }
        $user->forceFill(array_filter(['ui_mode' => $mode, 'ui_locale' => $locale], fn ($v): bool => $v !== null))->save();
        app(AuditLogger::class)->record('core.view.changed', metadata: ['mode' => $mode, 'locale' => $locale], severity: 'info');
    }
}
