<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Movewise MW5 (specs/movewise/05): the bridge. HQ's pool of relay numbers, and on each introduction: its relay
 * number, the agent's contact it became, pause and stop, changing agent, and handing over to the agent's own number.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = Schema::connection(Connections::control());
        $db->create('movewise_relay_numbers', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 16)->unique();      // E.164, a Twilio number in HQ's account
            $table->string('area', 60)->nullable();      // e.g. "Houston"
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        $db->table('movewise_connections', function (Blueprint $table): void {
            $table->unsignedBigInteger('contact_id')->nullable()->after('lead_id');
            $table->string('relay_number', 16)->nullable()->after('contact_id');
            $table->timestamp('paused_until')->nullable()->after('relay_number');
            $table->timestamp('handover_asked_at')->nullable()->after('paused_until');
            $table->timestamp('handed_over_at')->nullable()->after('handover_asked_at');
            $table->string('end_reason', 200)->nullable()->after('handed_over_at');
            $table->index(['tenant_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        $db = Schema::connection(Connections::control());
        $db->table('movewise_connections', fn (Blueprint $table) => $table->dropIndex(['tenant_id', 'contact_id']));
        $db->table('movewise_connections', fn (Blueprint $table) => $table->dropColumn(['contact_id', 'relay_number', 'paused_until', 'handover_asked_at', 'handed_over_at', 'end_reason']));
        $db->dropIfExists('movewise_relay_numbers');
    }
};
