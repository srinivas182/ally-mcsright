<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** DQ4 (specs/ai/02): each agent's own Deal-IQ email address (kind "dealiq"), owned by that agent. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->table('crm_inbound_addresses', function (Blueprint $table): void {
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->index(['tenant_id', 'kind', 'owner_user_id'], 'crm_inbound_addresses_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->table('crm_inbound_addresses', function (Blueprint $table): void {
            $table->dropIndex('crm_inbound_addresses_owner_idx');
            $table->dropColumn('owner_user_id');
        });
    }
};
