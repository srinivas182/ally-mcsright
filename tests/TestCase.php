<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

abstract class TestCase extends BaseTestCase
{
    protected ?Tenant $hostTenant = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Tests never depend on built front-end assets.
        $this->withoutVite();
    }

    /**
     * Run this test on a brokerage's own address ("{slug}.rightally.app"): relative URLs and
     * route() point there, and the tenant context is set for factories.
     */
    protected function onTenantHost(string $slug = 'acme'): Tenant
    {
        $tenant = Tenant::factory()->withPlatformDomain()->create(['slug' => $slug]);
        $root = 'http://'.$slug.'.rightally.app';

        config(['app.url' => $root]);
        URL::forceRootUrl($root);
        app(TenantContext::class)->set($tenant);
        $this->hostTenant = $tenant;

        return $tenant;
    }

    /**
     * The tenancy middleware clears the tenant after each request (so long-running workers never
     * leak a tenant). Tests that live on a tenant host get it back for their next factory call.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        if ($this->hostTenant !== null) {
            app(TenantContext::class)->set($this->hostTenant);
        }

        return $response;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
