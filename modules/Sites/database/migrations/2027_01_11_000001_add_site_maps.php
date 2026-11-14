<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Sprint 17C-2a: the platform's Mapbox public token for website maps (HQ-managed, stored encrypted). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->table('hq_site_branding', function (Blueprint $table): void {
            $table->text('mapbox_token')->nullable()->after('powered_url');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->table('hq_site_branding', function (Blueprint $table): void {
            $table->dropColumn('mapbox_token');
        });
    }
};
