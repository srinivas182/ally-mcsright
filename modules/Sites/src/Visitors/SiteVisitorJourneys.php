<?php

declare(strict_types=1);

namespace Modules\Sites\Visitors;

use Illuminate\Support\Facades\DB;
use Modules\Core\Leads\VisitorJourneys;
use Modules\Core\Support\Connections;
use Modules\Sites\Models\SiteEvent;
use Modules\Sites\Models\SiteVisitor;
use Modules\Sites\Rendering\Urls;
use Modules\Sites\SiteSetup;

/** The website journey shown on CRM leads and contacts (specs/websites/07). */
final class SiteVisitorJourneys implements VisitorJourneys
{
    public function summaries(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }
        $visitors = SiteVisitor::query()->whereIn('contact_id', $contactIds)->whereNull('merged_into')->get();
        if ($visitors->isEmpty()) {
            return [];
        }
        $counts = SiteEvent::query()->whereIn('visitor_id', $visitors->pluck('id'))->selectRaw('visitor_id, type, count(distinct coalesce(listing_ulid, id)) as n')
            ->groupBy('visitor_id', 'type')->toBase()->get()->groupBy('visitor_id');
        $out = [];
        foreach ($visitors as $v) {
            $c = collect($counts->get($v->id, []))->pluck('n', 'type');
            $out[(int) $v->contact_id] = ['summary' => (string) $v->summary, 'homes_viewed' => (int) ($c['listing'] ?? 0), 'searches' => (int) ($c['search'] ?? 0),
                'favourites' => DB::connection(Connections::cell())->table('site_favourites')->where('visitor_id', $v->id)->count(), 'visits' => $v->visits,
                'last_seen' => $v->last_seen_at?->toIso8601String()];
        }

        return $out;
    }

    public function forContact(int $contactId): ?array
    {
        $v = SiteVisitor::query()->where('contact_id', $contactId)->whereNull('merged_into')->orderBy('id')->first();
        if ($v === null) {
            return null;
        }
        $events = SiteEvent::query()->where('visitor_id', $v->id)->latest('id')->limit(300)->get();
        $s = $this->summaries([$contactId])[$contactId] ?? ['homes_viewed' => 0, 'searches' => 0, 'favourites' => 0, 'visits' => $v->visits];
        $host = app(SiteSetup::class)->liveHost();

        return [
            'summary' => (string) ($v->summary ?? JourneySummary::written(app(JourneySummary::class)->facts($v))),
            'summary_at' => $v->summary_at?->toIso8601String(), 'ai' => $v->summary_ai,
            'stats' => ['homes_viewed' => $s['homes_viewed'], 'searches' => $s['searches'], 'favourites' => $s['favourites'], 'visits' => $s['visits']],
            'events' => array_values($events->map(fn (SiteEvent $e): array => ['at' => (string) $e->created_at?->toIso8601String(), 'type' => $e->type,
                'text' => self::describe($e), 'url' => $e->listing_ulid !== null && $host !== null ? 'https://'.$host.Urls::listing($e->listing_ulid) : null])->all()),
            'brief' => $v->brief,
            'chat' => ClosingBrief::chat($v->id),
        ];
    }

    public static function describe(SiteEvent $e): string
    {
        $d = $e->data ?? [];
        $home = (string) ($d['label'] ?? 'a home').(isset($d['price']) ? ' ($'.number_format((int) $d['price']).')' : '');

        return match ($e->type) {
            'page' => 'Visited '.($e->path ?? 'a page'),
            'search' => 'Searched '.(JourneySummary::describeSearch($d) ?: 'homes'),
            'listing' => 'Viewed '.$home,
            'favourite' => 'Saved '.$home,
            'unfavourite' => 'Removed '.$home.' from saved homes',
            'compare' => 'Compared '.implode(' vs ', (array) ($d['labels'] ?? [])),
            'mortgage' => 'Worked out the monthly payment for '.$home.(isset($d['down_pct']) ? ' ('.$d['down_pct'].'% down)' : ''),
            'valuation' => 'Asked for a home value: '.(string) ($d['address'] ?? 'their home').(isset($d['mid']) ? ' (estimate $'.number_format((int) $d['mid']).')' : ''),
            'signup' => 'Created an account',
            'lead' => 'Sent an enquiry'.(isset($d['label']) ? ' about '.$d['label'] : ''),
            default => ucfirst($e->type),
        };
    }
}
