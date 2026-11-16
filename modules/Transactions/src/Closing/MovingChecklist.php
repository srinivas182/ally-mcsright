<?php

declare(strict_types=1);

namespace Modules\Transactions\Closing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Portal\Copy;

/**
 * The moving checklist in the client portal (specs/ai/02, §21), in English and Spanish, dated from the deal's own
 * closing date so lead times are not missed (internet installs, movers), with the brokerage's preferred vendors and
 * official links. Clients tick items off; the agent sees how far along they are. Part of the deal, not another app.
 */
final class MovingChecklist
{
    /**
     * @var array<string, array{for: list<string>, days: int, vendor: string|null, link: string|null, en: string, es: string}>
     */
    public const ITEMS = [
        'movers' => ['for' => ['buyer', 'seller'], 'days' => -30, 'vendor' => 'mover', 'link' => null,
            'en' => 'Book movers (good ones book up 4 to 6 weeks ahead)', 'es' => 'Reserve la mudanza (las buenas se llenan con 4 a 6 semanas)'],
        'internet' => ['for' => ['buyer'], 'days' => -14, 'vendor' => 'internet', 'link' => null,
            'en' => 'Order internet and TV for the new home (installs can take 1 to 2 weeks)', 'es' => 'Contrate internet y TV para la casa nueva (la instalación puede tardar 1 a 2 semanas)'],
        'insurance' => ['for' => ['buyer'], 'days' => -10, 'vendor' => 'insurance', 'link' => null,
            'en' => 'Have home insurance in place (your lender needs it before closing)', 'es' => 'Tenga el seguro de la casa listo (su prestamista lo necesita antes del cierre)'],
        'mail' => ['for' => ['buyer', 'seller'], 'days' => -7, 'vendor' => null, 'link' => 'https://moversguide.usps.com/',
            'en' => 'Forward your mail with USPS', 'es' => 'Haga el cambio de dirección del correo con USPS'],
        'utilities_start' => ['for' => ['buyer'], 'days' => -5, 'vendor' => 'utilities', 'link' => null,
            'en' => 'Start electricity, water, gas and trash in your name from closing day (some need a few days\' notice)', 'es' => 'Ponga la luz, el agua, el gas y la basura a su nombre desde el día del cierre (algunos piden unos días de aviso)'],
        'utilities_stop' => ['for' => ['seller'], 'days' => -5, 'vendor' => 'utilities', 'link' => null,
            'en' => 'Stop or transfer your utilities from the day after closing', 'es' => 'Dé de baja o transfiera sus servicios a partir del día después del cierre'],
        'keys' => ['for' => ['seller'], 'days' => -1, 'vendor' => null, 'link' => null,
            'en' => 'Leave keys, garage openers, codes and manuals for the buyers', 'es' => 'Deje llaves, controles del garaje, códigos y manuales para los compradores'],
        'locks' => ['for' => ['buyer'], 'days' => 1, 'vendor' => 'locksmith', 'link' => null,
            'en' => 'Change or rekey the locks', 'es' => 'Cambie o recodifique las cerraduras'],
        'seller_insurance' => ['for' => ['seller'], 'days' => 1, 'vendor' => null, 'link' => null,
            'en' => 'Cancel your home insurance only after closing (not before)', 'es' => 'Cancele el seguro de la casa solo después del cierre (no antes)'],
        'address' => ['for' => ['buyer', 'seller'], 'days' => 10, 'vendor' => null, 'link' => null,
            'en' => 'Update your address: driver\'s license (check your state\'s DMV deadline), voter registration, banks', 'es' => 'Actualice su dirección: licencia de conducir (revise el plazo del DMV de su estado), registro de votante, bancos'],
    ];

    /**
     * The checklist for one person, with due dates, done ticks and the brokerage's vendors for each item.
     *
     * @return list<array{key: string, label: string, due: string|null, due_on: string|null, done: bool, overdue: bool, link: string|null, vendors: list<array{name: string, phone: string|null, website: string|null, note: string|null}>}>
     */
    public static function for(Deal $deal, \stdClass $access): array
    {
        $role = (string) $access->role;
        if (! in_array($role, ['buyer', 'seller'], true) || $deal->type === 'referral') {
            return [];
        }
        $locale = Copy::locale((string) $access->locale);
        $done = DB::connection(Connections::cell())->table('tx_portal_moving')->where('access_id', $access->id)->pluck('item')->all();
        $vendors = collect(Vendors::all(true))->groupBy('category');
        $closing = $deal->closing_date !== null ? CarbonImmutable::parse($deal->closing_date->toDateString()) : null;
        $out = [];
        foreach (self::ITEMS as $key => $i) {
            if (! in_array($role, $i['for'], true)) {
                continue;
            }
            $due = $closing?->addDays($i['days']);
            $out[] = ['key' => $key, 'label' => $locale === 'es' ? $i['es'] : $i['en'], 'due' => $due !== null ? Copy::date($locale, $due->toDateString()) : null, 'due_on' => $due?->toDateString(),
                'done' => in_array($key, $done, true), 'overdue' => $due !== null && ! in_array($key, $done, true) && $due->lt(CarbonImmutable::today()), 'link' => $i['link'],
                'vendors' => $i['vendor'] !== null ? array_values(array_map(fn (array $v): array => ['name' => (string) $v['name'], 'phone' => $v['phone'] !== null ? (string) $v['phone'] : null, 'website' => $v['website'] !== null ? (string) $v['website'] : null, 'note' => $v['note'] !== null ? (string) $v['note'] : null],
                    ($vendors->get($i['vendor']) ?? collect())->all())) : []];
        }

        return $out;
    }

    public static function toggle(\stdClass $access, string $item): void
    {
        if (! array_key_exists($item, self::ITEMS)) {
            abort(404);
        }
        $db = DB::connection(Connections::cell());
        $q = $db->table('tx_portal_moving')->where('access_id', $access->id)->where('item', $item);
        $q->exists() ? $q->delete() : $db->table('tx_portal_moving')->insert(['tenant_id' => $access->tenant_id, 'access_id' => $access->id, 'item' => $item, 'done_at' => now()]);
    }

    /** "3 of 7" for the agent. */
    public static function progress(\stdClass $access, Deal $deal): ?string
    {
        $items = self::for($deal, $access);

        return $items === [] ? null : count(array_filter($items, fn (array $i): bool => $i['done'])).' of '.count($items);
    }
}
