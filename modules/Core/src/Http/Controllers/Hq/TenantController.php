<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers\Hq;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Http\Requests\Hq\ChangeSlugRequest;
use Modules\Core\Http\Requests\Hq\ProvisionTenantRequest;
use Modules\Core\Http\Requests\Hq\TenantReasonRequest;
use Modules\Core\Tenancy\Actions\ChangeTenantSlug;
use Modules\Core\Tenancy\Actions\ProvisionTenant;
use Modules\Core\Tenancy\Actions\TenantLifecycle;
use Modules\Core\Tenancy\Data\ProvisionTenantData;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Exceptions\InvalidTenantTransition;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\Models\TenantHostRedirect;

/**
 * HQ console: tenants list, detail and lifecycle actions (specs/core/01).
 * Thin controller: all rules live in the tenancy actions.
 */
final class TenantController
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $type = (string) $request->query('type', '');
        $status = (string) $request->query('status', '');

        $tenants = Tenant::query()
            ->with('cell:id,name')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")))
            ->when(TenantType::tryFrom($type) !== null, fn ($q) => $q->where('type', $type))
            ->when(TenantStatus::tryFrom($status) !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Tenant $tenant): array => [
                'ulid' => $tenant->ulid,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'type' => $tenant->type->value,
                'status' => $tenant->status->value,
                'cell' => $tenant->cell?->name,
                'created_at' => $tenant->created_at?->toIso8601String(),
            ]);

        return Inertia::render('hq/tenants/index', [
            'tenants' => $tenants,
            'filters' => ['search' => $search, 'type' => $type, 'status' => $status],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('hq/tenants/create', [
            'platformDomain' => (string) config('core.platform_domain'),
        ]);
    }

    public function store(ProvisionTenantRequest $request, ProvisionTenant $provision): RedirectResponse
    {
        $tenant = $provision->handle(new ProvisionTenantData(
            type: TenantType::from($request->string('type')->toString()),
            name: $request->string('name')->toString(),
            slug: $request->string('slug')->lower()->toString(),
            ownerEmail: $request->string('owner_email')->lower()->toString(),
            ownerName: $request->string('owner_name')->toString(),
            idempotencyKey: $request->string('idempotency_key')->toString(),
            stateCode: $request->filled('state_code') ? $request->string('state_code')->upper()->toString() : null,
            createdByUserId: $request->user()?->getAuthIdentifier() !== null ? (int) $request->user()->getAuthIdentifier() : null,
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$tenant->name} is live."]);

        return to_route('hq.tenants.show', $tenant);
    }

    public function show(Tenant $tenant): Response
    {
        return Inertia::render('hq/tenants/show', [
            'billing' => ['exception' => (bool) $tenant->getAttribute('billing_exception'), 'reason' => $tenant->getAttribute('billing_exception_reason')],
            'tenant' => [
                'ulid' => $tenant->ulid,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'type' => $tenant->type->value,
                'status' => $tenant->status->value,
                'state_code' => $tenant->state_code,
                'timezone' => $tenant->timezone,
                'cell' => $tenant->cell?->name,
                'suspended_reason' => $tenant->suspended_reason,
                'closing_at' => $tenant->closing_at?->toIso8601String(),
                'closing_reason' => $tenant->closing_reason,
                'closed_at' => $tenant->closed_at?->toIso8601String(),
                'purge_after' => $tenant->purge_after?->toIso8601String(),
                'created_at' => $tenant->created_at?->toIso8601String(),
                'is_hq' => $tenant->isHq(),
            ],
            'domains' => $tenant->domains()->orderByDesc('is_primary')->get()->map(fn (TenantDomain $d): array => [
                'host' => $d->host,
                'kind' => $d->kind->value,
                'is_primary' => $d->is_primary,
                'verification_status' => $d->verification_status->value,
                'ssl_status' => $d->ssl_status->value,
            ]),
            'redirects' => TenantHostRedirect::query()->where('tenant_id', $tenant->id)->where('redirect_until', '>', now())->get()
                ->map(fn (TenantHostRedirect $r): array => ['host' => $r->host, 'until' => $r->redirect_until->toIso8601String()]),
        ]);
    }

    public function suspend(TenantReasonRequest $request, Tenant $tenant, TenantLifecycle $lifecycle): RedirectResponse
    {
        return $this->transition(fn () => $lifecycle->suspend($tenant, $request->string('reason')->toString(), $this->userId($request)), $tenant, 'Tenant suspended.',
            'core.tenant.suspended', ['reason' => $request->string('reason')->toString()]);
    }

    public function reactivate(Request $request, Tenant $tenant, TenantLifecycle $lifecycle): RedirectResponse
    {
        return $this->transition(fn () => $lifecycle->reactivate($tenant, $this->userId($request)), $tenant, 'Tenant reactivated.', 'core.tenant.reactivated');
    }

    public function close(TenantReasonRequest $request, Tenant $tenant, TenantLifecycle $lifecycle): RedirectResponse
    {
        return $this->transition(fn () => $lifecycle->close($tenant, $request->string('reason')->toString(), $this->userId($request)), $tenant, 'Closing started. It can be reversed for 30 days.',
            'core.tenant.closing', ['reason' => $request->string('reason')->toString()]);
    }

    public function changeSlug(ChangeSlugRequest $request, Tenant $tenant, ChangeTenantSlug $change): RedirectResponse
    {
        $old = $tenant->slug;
        $change->handle($tenant, $request->string('slug')->lower()->toString(), $this->userId($request));
        $this->hqAudit($tenant, 'core.tenant.slug_changed', ['old' => $old, 'new' => $tenant->fresh()?->slug]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Address changed. The old address redirects for 90 days.']);

        return to_route('hq.tenants.show', $tenant);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function transition(callable $action, Tenant $tenant, string $message, string $auditAction, array $metadata = []): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidTenantTransition $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }
        $this->hqAudit($tenant, $auditAction, $metadata);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('hq.tenants.show', $tenant);
    }

    /**
     * HQ actions are recorded in the brokerage's own log (so owners see what support did) and in HQ's.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function hqAudit(Tenant $tenant, string $action, array $metadata = []): void
    {
        $logger = app(AuditLogger::class);
        $logger->record($action, $tenant, metadata: $metadata, severity: 'warning', label: $tenant->name, tenantId: $tenant->id);
        $logger->record($action, $tenant, metadata: $metadata, severity: 'notice', label: $tenant->name);
    }

    private function userId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }
}
