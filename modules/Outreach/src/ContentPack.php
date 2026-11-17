<?php

declare(strict_types=1);

namespace Modules\Outreach;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Search\MarketStats;

/**
 * The monthly content pack (MK2, specs/crm/21): for the area the agent picks, this month's real numbers from the MLS
 * feed, written up as four social posts and a short newsletter in English and Spanish, ready to copy. Plain templates
 * on real figures, no AI cost and nothing invented; every post names the source and date. Fair Housing: it describes
 * homes and prices, never people or neighbourhoods' make-up.
 */
final class ContentPack
{
    public function __construct(private readonly TenantContext $tenants, private readonly MarketStats $stats) {}

    /**
     * @return list<array{slug: string, name: string}>
     */
    public function places(): array
    {
        return array_map(fn (array $p): array => ['slug' => $p['slug'], 'name' => $p['name']], $this->stats->places($this->tenants->id(), 40));
    }

    /**
     * @return array{place: string, month: string, stats: array<string, mixed>, posts: array{en: list<string>, es: list<string>}, newsletter: array{en: array{subject: string, body: string}, es: array{subject: string, body: string}}}|null
     */
    public function build(User $agent, string $place, ?CarbonImmutable $today = null): ?array
    {
        $s = $this->stats->forPlace($this->tenants->id(), $place);
        if ($s === null) {
            return null;
        }
        $today ??= CarbonImmutable::today();
        $month = $today->format('F Y');
        $mes = self::mes((int) $today->format('n')).' '.$today->format('Y');
        $area = (string) $s['name'];
        $money = fn (?int $v): string => $v === null ? '—' : '$'.number_format($v);
        $median = $money($s['median_price']);
        $dom = $s['median_dom'] !== null ? (int) $s['median_dom'] : null;
        $source = 'Source: MLS listings for sale, '.$today->format('M j, Y').'.';
        $fuente = 'Fuente: propiedades en venta del MLS, '.$today->format('j/n/Y').'.';
        $name = $agent->first_name;

        $posts = [
            "{$area} market update for {$month}: {$s['active']} homes for sale, with a typical asking price of {$median}. Thinking of moving? I'm happy to walk you through what it means for you. {$source}",
            "{$s['new_30']} new listings came on the market in {$area} in the last 30 days.".($s['reduced_30'] > 0 ? " {$s['reduced_30']} sellers lowered their price." : '')." Want a list that matches what you're looking for? Send me a message. {$source}",
            $dom !== null ? "Homes in {$area} are typically on the market for {$dom} days right now. Pricing right from day one still matters most. Curious what yours would list for? Ask me for a free estimate. {$source}"
                : "Curious what your home in {$area} would list for today? Ask me for a free, no-pressure estimate. {$source}",
            "Asking prices in {$area} right now run from {$money($s['low_price'])} to {$money($s['high_price'])}".($s['median_ppsf'] !== null ? ", about {$money($s['median_ppsf'])} per square foot" : '').". Every home is different; I can tell you where yours fits. {$source}",
        ];
        $postsEs = [
            "Actualización del mercado en {$area}, {$mes}: {$s['active']} casas en venta, con un precio típico de {$median}. ¿Piensa mudarse? Con gusto le explico qué significa para usted. {$fuente}",
            "{$s['new_30']} casas nuevas salieron a la venta en {$area} en los últimos 30 días.".($s['reduced_30'] > 0 ? " {$s['reduced_30']} vendedores bajaron su precio." : '')." ¿Quiere una lista a su medida? Escríbame. {$fuente}",
            $dom !== null ? "Las casas en {$area} tardan unos {$dom} días en venderse ahora. Poner el precio correcto desde el primer día sigue siendo clave. ¿Quiere saber cuánto vale la suya? Pídame un estimado gratis. {$fuente}"
                : "¿Quiere saber cuánto vale hoy su casa en {$area}? Pídame un estimado gratis y sin compromiso. {$fuente}",
            "Los precios de venta en {$area} van de {$money($s['low_price'])} a {$money($s['high_price'])}".($s['median_ppsf'] !== null ? ", unos {$money($s['median_ppsf'])} por pie cuadrado" : '').". Cada casa es distinta; le digo dónde queda la suya. {$fuente}",
        ];
        $body = "Hi {first_name},\n\nHere is a quick look at the {$area} market for {$month}:\n\n"
            ."- Homes for sale: {$s['active']}\n- Typical asking price: {$median}\n- New listings in the last 30 days: {$s['new_30']}\n"
            .($dom !== null ? "- Typical days on the market: {$dom}\n" : '')
            ."\nIf you're wondering what this means for your home or your next move, just reply. I'm always glad to help.\n\n{$name}\n\n{$source}";
        $cuerpo = "Hola {first_name}:\n\nAquí tiene un resumen del mercado en {$area} para {$mes}:\n\n"
            ."- Casas en venta: {$s['active']}\n- Precio típico: {$median}\n- Casas nuevas en los últimos 30 días: {$s['new_30']}\n"
            .($dom !== null ? "- Días típicos en el mercado: {$dom}\n" : '')
            ."\nSi quiere saber qué significa para su casa o su próxima mudanza, responda a este correo. Con gusto le ayudo.\n\n{$name}\n\n{$fuente}";

        return ['place' => $area, 'month' => $month, 'stats' => $s, 'posts' => ['en' => $posts, 'es' => $postsEs],
            'newsletter' => ['en' => ['subject' => "{$area} market update: {$month}", 'body' => $body], 'es' => ['subject' => "Mercado en {$area}: {$mes}", 'body' => $cuerpo]]];
    }

    private static function mes(int $n): string
    {
        return ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][$n - 1];
    }
}
