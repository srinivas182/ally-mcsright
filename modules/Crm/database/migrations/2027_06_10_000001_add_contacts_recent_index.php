<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint 60 (load test): the contacts list shows the most recently changed first. With 100,000 contacts it read the
 * whole brokerage to sort them (about 0.3 s); with this index it reads the first page directly (about 1 ms).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('crm_contacts', function (Blueprint $table): void {
            $table->index(['tenant_id', 'updated_at'], 'crm_contacts_recent_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('crm_contacts', function (Blueprint $table): void {
            $table->dropIndex('crm_contacts_recent_idx');
        });
    }
};
