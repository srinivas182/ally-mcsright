<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** MK2 (specs/crm/21): each monthly value kept for the homeowner dashboard's chart. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('site_home_reports', function (Blueprint $table): void {
            $table->json('history')->nullable();
            $table->dateTime('dashboard_viewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('site_home_reports', fn (Blueprint $table) => $table->dropColumn(['history', 'dashboard_viewed_at']));
    }
};
