<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * SO2 (specs/grow/03): ready-made follow-up plans are remembered by key, so new ones reach every RightAlly once, and
 * a plan a brokerage switched off is never added back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('crm_plans', function (Blueprint $table): void {
            $table->string('template_key', 40)->nullable()->after('owner_user_id');
            $table->index(['tenant_id', 'template_key'], 'crm_plans_template_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('crm_plans', function (Blueprint $table): void {
            $table->dropIndex('crm_plans_template_idx');
            $table->dropColumn('template_key');
        });
    }
};
