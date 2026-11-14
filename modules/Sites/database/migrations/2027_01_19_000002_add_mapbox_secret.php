<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint 18B-2: an encrypted Mapbox secret token (tokens:write) kept only on the server, used to mint one-hour map
 * tokens for website visitors so a copied token stops working within the hour (specs/websites/06).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->table('hq_site_branding', function (Blueprint $table): void {
            $table->text('mapbox_secret')->nullable()->after('mapbox_token');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->table('hq_site_branding', fn (Blueprint $table) => $table->dropColumn('mapbox_secret'));
    }
};
