<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** FX6: an owner or staff member asks for a copy of every new lead by email (off by default). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('crm_lead_alert_prefs', function (Blueprint $table): void {
            $table->boolean('copy_all')->default(false);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('crm_lead_alert_prefs', function (Blueprint $table): void {
            $table->dropColumn('copy_all');
        });
    }
};
