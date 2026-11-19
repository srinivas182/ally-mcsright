<?php

declare(strict_types=1);

namespace Modules\Marketing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Builder\ModuleCatalog;
use Modules\Billing\Models\Package;
use Modules\Core\Support\Connections;

/**
 * MK1: what RightAlly's website shows about the modules, read live from the package builder. A module appears once it
 * is in at least one live package for that audience (before any package lists modules, every module offered is
 * shown), with the packages that include it and its 1-minute video when HQ has added one (HQ → Help videos, page
 * "/modules/<module>"). Turning a module on in a package puts it on the website; nobody edits the page.
 */
final class Showcase
{
    /** "What you pay today" categories (plain names, no company names) → the modules that replace them. */
    public const CATEGORIES = [
        'website' => ['label' => 'Website and lead capture', 'modules' => ['website']],
        'crm' => ['label' => 'CRM and follow-up', 'modules' => ['crm']],
        'texting' => ['label' => 'Texting and calling', 'modules' => ['texting']],
        'transactions' => ['label' => 'Transactions and e-signatures', 'modules' => ['transactions']],
        'commissions' => ['label' => 'Commissions and agent payouts', 'modules' => ['compensation'], 'audience' => 'brokerage'],
        'accounting' => ['label' => 'Bookkeeping and trust accounting', 'modules' => ['accounting'], 'audience' => 'brokerage'],
        'marketing' => ['label' => 'Email marketing', 'modules' => ['marketing']],
        'social' => ['label' => 'Social posts and video', 'modules' => ['studio']],
        'training' => ['label' => 'Training and coaching', 'modules' => ['learn']],
    ];

    /**
     * @param  Collection<int, Package>  $packages
     * @return array{modules: list<array{key: string, label: string, about: string, packages: list<string>, video: string|null}>,
     *     categories: list<array{key: string, label: string, modules: list<string>}>, packages: list<array{name: string, monthly: int, modules: list<string>, ulid: string}>}
     */
    public static function for(string $audience, Collection $packages): array
    {
        $offered = ModuleCatalog::for($audience);
        $listed = $packages->contains(fn (Package $p): bool => ($p->getAttribute('modules') ?? []) !== []);
        $videos = DB::connection(Connections::control())->table('help_videos')->where('path', 'like', '/modules/%')->pluck('url', 'path');
        $modules = [];
        foreach ($offered as $key => $m) {
            $in = $packages->filter(fn (Package $p): bool => in_array($key, $p->getAttribute('modules') ?? [], true) || ($m['always'] ?? false))->pluck('name')->values()->all();
            if ($listed && $in === []) {
                continue;
            }
            $modules[] = ['key' => $key, 'label' => (string) $m['label'], 'about' => (string) $m['about'], 'packages' => array_values(array_map('strval', $in)),
                'video' => isset($videos['/modules/'.$key]) ? (string) $videos['/modules/'.$key] : null];
        }
        $categories = [];
        foreach (self::CATEGORIES as $key => $c) {
            if (($c['audience'] ?? $audience) === $audience) {
                $categories[] = ['key' => $key, 'label' => $c['label'], 'modules' => $c['modules']];
            }
        }

        return ['modules' => $modules, 'categories' => $categories,
            'packages' => array_values($packages->map(fn (Package $p): array => ['name' => (string) $p->name, 'monthly' => (int) $p->monthly_cents, 'ulid' => (string) $p->ulid,
                'modules' => array_values(array_map('strval', [...($p->getAttribute('modules') ?? []), 'back_office']))])->all())];
    }
}
