<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Connect (specs/connect/01): connections to other apps (credentials encrypted), sync runs, outgoing webhook
 * deliveries; and, on the control plane, which apps brokerages ask for (HQ sees demand).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());

        $cell->create('connect_connections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('app_key', 40);
            $table->unsignedBigInteger('owner_user_id')->nullable(); // null: the brokerage's; else that person's own
            $table->string('label', 120)->nullable();                // e.g. the connected account, or "Zapier – new leads"
            $table->string('status', 20)->default('connected');       // connected, error, needs_reconnect, paused
            $table->text('credentials')->nullable();                  // encrypted JSON, never sent to the browser
            $table->json('settings')->nullable();
            $table->string('cursor', 255)->nullable();                // where the last sync got to
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedSmallInteger('failures')->default(0);     // in a row
            $table->timestamp('alerted_at')->nullable();
            $table->unsignedBigInteger('connected_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'app_key']);
        });

        $cell->create('connect_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('connection_id')->index();
            $table->string('status', 12); // running, ok, failed
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->string('note', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
        });

        $cell->create('connect_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('connection_id')->index();
            $table->string('event', 40);
            $table->text('payload'); // encrypted: it can hold contact details
            $table->string('status', 12)->default('pending'); // pending, delivered, failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::connection(Connections::control())->create('hq_app_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('app_key', 40);
            $table->unsignedInteger('requests')->default(1);
            $table->timestamp('last_requested_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'app_key']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('hq_app_requests');
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('connect_webhook_deliveries');
        $cell->dropIfExists('connect_sync_runs');
        $cell->dropIfExists('connect_connections');
    }
};
