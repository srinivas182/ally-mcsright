<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Tenancy\TenantContext;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    /**
     * @return array{name: string, isHq: bool}|null
     */
    private function tenant(): ?array
    {
        $context = app(TenantContext::class);

        return $context->check() ? ['name' => $context->current()->name, 'isHq' => $context->current()->isHq()] : null;
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            // The brokerage (or HQ) this page belongs to, and the menu this user may see.
            'tenant' => fn (): ?array => $this->tenant(),
            'navigation' => fn (): array => app(NavigationRegistry::class)->for($request->user() instanceof User ? $request->user() : null),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
