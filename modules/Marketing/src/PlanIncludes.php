<?php

declare(strict_types=1);

namespace Modules\Marketing;

use Illuminate\Support\Collection;
use Modules\Billing\Builder\ModuleCatalog;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\Models\Package;
use Modules\Billing\ProductFeatures;

/**
 * SITE1: what each price on the website includes, in plain words, read live from HQ › Packages: the package's modules,
 * agent seats, AIlly conversations and the MLS board for the website. Changing a package changes the website.
 */
final class PlanIncludes
{
    public const SHOWN = 16;

    /** Spanish names for the modules (English is in the catalog). */
    private const ES = [
        'back_office' => 'Oficina administrativa', 'crm' => 'CRM', 'crm_ai' => 'IA para contactos', 'todays_ten' => 'Los 10 de hoy', 'website' => 'Sitio web con AIlly',
        'texting' => 'Mensajes de texto', 'calling' => 'Llamadas y transcripciones', 'ai_voice' => 'Llamadas con IA', 'transactions' => 'Tratos, formularios y firma electrónica',
        'compensation' => 'Comisiones y pagos', 'accounting' => 'Contabilidad y cuentas fiduciarias', 'studio' => 'Ally Studio', 'marketing' => 'Email marketing',
        'property_data' => 'Datos de propiedades y dueños', 'learn' => 'Cursos y coaching', 'blog_writer' => 'Redactor de blog con IA', 'recruiting' => 'Reclutamiento',
    ];

    /**
     * @param  array<string, Collection<int, Package>>  $byAudience
     * @return array<string, list<string>> package ulid => what it includes
     */
    public static function forPackages(array $byAudience, string $locale): array
    {
        $es = $locale === 'es';
        $out = [];
        foreach ($byAudience as $audience => $packages) {
            foreach ($packages as $p) {
                $lines = [];
                if ((int) ($p->getAttribute('seats') ?? 0) > 1 && $audience === 'brokerage') {
                    $lines[] = $es ? (int) $p->seats.' puestos de agente incluidos' : (int) $p->seats.' agent seats included';
                }
                $modules = array_values(array_unique(['back_office', ...array_map('strval', (array) ($p->getAttribute('modules') ?? []))]));
                foreach ($modules as $key) {
                    if (! isset(ModuleCatalog::MODULES[$key])) {
                        continue;
                    }
                    $label = $es ? self::ES[$key] : ModuleCatalog::MODULES[$key]['label'];
                    if ($key === 'website' && $audience === 'brokerage') {
                        $label = $es ? 'Sitio web IDX con AIlly y 1 junta MLS' : 'IDX website with AIlly and 1 MLS board';
                    }
                    $lines[] = $label;
                }
                if ((int) $p->ailly_conversations > 0) {
                    $lines[] = $es ? number_format((int) $p->ailly_conversations).' conversaciones de AIlly al mes' : number_format((int) $p->ailly_conversations).' AIlly conversations a month';
                }
                $out[(string) $p->ulid] = $lines;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, AgentPlan>  $seats
     * @return array<int, list<string>> position => what the agent plan includes
     */
    public static function forSeats(array $seats, string $locale): array
    {
        $names = ProductFeatures::all();
        $out = [];
        foreach ($seats as $i => $seat) {
            $out[$i] = array_values(array_filter(array_map(fn ($f): ?string => isset($names[(string) $f]) ? trim((string) preg_replace('/\s*\([A-Z0-9]+\)$/', '', $names[(string) $f])) : null,
                (array) ($seat->features ?? []))));
        }

        return $out;
    }
}
