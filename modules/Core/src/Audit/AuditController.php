<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\ContactVisibility;
use Modules\Crm\Models\Contact;

/**
 * The brokerage audit log (owners): filters by area, person, date and severity.
 */
final class AuditController
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can('core.audit.view'), 403);

        $filters = $request->validate([
            'area' => ['nullable', 'in:core.auth,core.impersonation,core.user,core.role,core.audit,crm.contact,crm.task,crm.consent'],
            'actor' => ['nullable', 'string', 'size:26'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'severity' => ['nullable', 'in:notice,warning,critical'],
            'q' => ['nullable', 'string', 'max:100'],
            'view' => ['nullable', 'in:activity,technical'],
            'important' => ['nullable', 'boolean'],
        ]);
        $view = $filters['view'] ?? 'activity';

        $actorId = isset($filters['actor']) ? User::query()->where('ulid', $filters['actor'])->value('id') : null;
        $events = AuditEvent::query()
            ->when($filters['area'] ?? null, fn ($q, $area) => $q->where('action', 'like', $area.'.%'))
            ->when($actorId, fn ($q, $id) => $q->where(fn ($w) => $w->where('actor_id', $id)->orWhere('on_behalf_of_user_id', $id)))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('occurred_at', '>=', $d.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('occurred_at', '<=', $d.' 23:59:59.999999'))
            ->when($filters['severity'] ?? null, fn ($q, $s) => $q->whereIn('severity', array_slice(['notice', 'warning', 'critical'], array_search($s, ['notice', 'warning', 'critical'], true) ?: 0)))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where('subject_label', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $text).'%'))
            ->orderByDesc('chain_seq')
            ->paginate(100)->withQueryString();
        $models = collect($events->items())->filter(fn (AuditEvent $e): bool => ! ($filters['important'] ?? false) || AuditActivity::important($e))->values();
        $rows = $view === 'activity' ? app(AuditActivity::class)->lines($models) : $models->map(fn (AuditEvent $e): array => AuditPresenter::row($e))->all();
        $links = $events->linkCollection()->all();

        return Inertia::render('audit/index', [
            'view' => $view,
            'rows' => $rows,
            'links' => $links,
            'filters' => $filters + ['area' => null, 'actor' => null, 'from' => null, 'to' => null, 'severity' => null, 'q' => null, 'important' => false],
            'retention' => ['years' => (int) app(TenantContext::class)->current()->getAttribute('audit_retention_years'), 'min' => AuditRetention::MIN_YEARS, 'max' => AuditRetention::MAX_YEARS],
            'streams' => AuditStream::query()->orderBy('id')->get()->map(fn (AuditStream $st): array => [
                'ulid' => $st->ulid, 'type' => $st->type, 'name' => $st->name, 'status' => $st->status,
                'last_success_at' => $st->last_success_at?->toIso8601String(), 'last_error' => $st->last_error,
                'failing_since' => $st->failing_since?->toIso8601String(),
                'behind' => max(0, (int) AuditEvent::query()->max('chain_seq') - $st->cursor_seq),
            ]),
            'holds' => LegalHold::query()->whereNull('released_at')->orderByDesc('id')->get()->map(fn (LegalHold $h): array => [
                'ulid' => $h->ulid, 'reason' => $h->reason, 'scope' => $h->describe(), 'since' => $h->created_at?->toIso8601String(),
            ]),
            'people' => User::query()->orderBy('first_name')->get(['ulid', 'first_name', 'last_name'])->map(fn (User $u): array => ['ulid' => $u->ulid, 'name' => $u->name]),
        ]);
    }

    public function retention(Request $request): RedirectResponse
    {
        $this->owner($request);
        $data = $request->validate(['years' => ['required', 'integer', 'min:'.AuditRetention::MIN_YEARS, 'max:'.AuditRetention::MAX_YEARS]]);
        $tenants = app(TenantContext::class);
        Tenant::query()->whereKey($tenants->id())->update(['audit_retention_years' => (int) $data['years']]);
        $tenants->set($tenants->current()->fresh() ?? $tenants->current());
        app(AuditLogger::class)->record('core.audit.retention_changed', metadata: ['years' => (int) $data['years']], severity: 'warning');

        return back();
    }

    public function placeHold(Request $request): RedirectResponse
    {
        $user = $this->owner($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:300'],
            'from_date' => ['nullable', 'date'], 'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'contact' => ['nullable', 'string', 'size:26'],
        ]);
        $contact = isset($data['contact']) ? Contact::query()->where('ulid', $data['contact'])->first() : null;
        LegalHold::query()->create([
            'reason' => $data['reason'], 'from_date' => $data['from_date'] ?? null, 'to_date' => $data['to_date'] ?? null,
            'subject_type' => $contact !== null ? 'Contact' : null, 'subject_id' => $contact?->ulid, 'subject_label' => $contact?->name, 'created_by' => $user->id,
        ]);
        app(AuditLogger::class)->record('core.audit.legal_hold_placed', $contact, metadata: ['reason' => $data['reason']], severity: 'warning');

        return back();
    }

    public function releaseHold(Request $request, LegalHold $hold): RedirectResponse
    {
        $user = $this->owner($request);
        $hold->update(['released_at' => now(), 'released_by' => $user->id]);
        app(AuditLogger::class)->record('core.audit.legal_hold_released', metadata: ['reason' => $hold->reason, 'scope' => $hold->describe()], severity: 'warning');

        return back();
    }

    public function evidencePack(Request $request, Contact $contact): \Symfony\Component\HttpFoundation\Response
    {
        $user = $this->owner($request);
        abort_unless(app(ContactVisibility::class)->canSee($user, $contact), 404);
        app(AuditLogger::class)->record('crm.contact.evidence_pack', $contact, severity: 'notice');
        (new FlushAuditOutbox)->handle();

        $tenant = app(TenantContext::class)->current();
        $events = AuditEvent::query()->where('subject_type', 'Contact')->where('subject_id', $contact->ulid)->orderBy('chain_seq')->get()
            ->map(fn (AuditEvent $e): array => AuditPresenter::row($e))->all();

        return Pdf::loadView('pdf.evidence-pack', [
            'contact' => $contact->name, 'brokerage' => $tenant->name, 'generatedAt' => now()->toDayDateTimeString(), 'generatedBy' => $user->name,
            'verification' => app(AuditVerifier::class)->verify($tenant->id), 'events' => $events,
        ])->download('evidence-pack-'.Str::slug($contact->name).'-'.now()->format('Ymd').'.pdf');
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isKind(UserKind::Owner), 403);

        return $user;
    }

    public function addStream(Request $request, AuditStreamer $streamer): RedirectResponse
    {
        $user = $this->owner($request);
        $data = $request->validate(['type' => ['required', 'in:webhook,s3,datadog,splunk'], 'name' => ['nullable', 'string', 'max:80'], 'config' => ['required', 'array']]);
        $streamer->add($data['type'], (string) ($data['name'] ?? ''), array_map('strval', $data['config']), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Streaming destination added. New events will be sent every minute.']);

        return back();
    }

    public function removeStream(Request $request, AuditStream $stream, AuditStreamer $streamer): RedirectResponse
    {
        $this->owner($request);
        $streamer->remove($stream);

        return back();
    }

    public function testStream(Request $request, AuditStream $stream, AuditStreamer $streamer): RedirectResponse
    {
        $this->owner($request);
        try {
            $streamer->test($stream);
            Inertia::flash('toast', ['type' => 'success', 'message' => "Test event delivered to {$stream->name}."]);
        } catch (\Throwable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "Test failed: {$e->getMessage()}"]);
        }

        return back();
    }

    public function resumeStream(Request $request, AuditStream $stream, AuditStreamer $streamer): RedirectResponse
    {
        $this->owner($request);
        $streamer->resume($stream);

        return back();
    }
}
