<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Two-way texting (Sprint 16A-2): each brokerage can have its own texting number (set by HQ), and replies to the
 * shared platform number are matched to the brokerage that last texted that person from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $control = Schema::connection(Connections::control());
        $control->table('hq_messaging_tenants', function (Blueprint $table): void {
            $table->string('sms_number', 20)->nullable()->unique();
        });
        $control->create('sms_threads', function (Blueprint $table): void {
            $table->string('contact_phone', 20);
            $table->string('from_number', 20);
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();
            $table->primary(['contact_phone', 'from_number']);
        });
        $control->table('crm_inbound_addresses', function (Blueprint $table): void {
            $table->string('kind', 10)->default('lead'); // lead | reply
        });
    }

    public function down(): void
    {
        $control = Schema::connection(Connections::control());
        $control->table('crm_inbound_addresses', fn (Blueprint $table) => $table->dropColumn('kind'));
        $control->dropIfExists('sms_threads');
        $control->table('hq_messaging_tenants', function (Blueprint $table): void {
            $table->dropUnique(['sms_number']);
            $table->dropColumn('sms_number');
        });
    }
};
