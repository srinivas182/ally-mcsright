<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * OB1 (specs/core/20), control database: Watchtower — everything that goes wrong on RightAlly, grouped into problems
 * by fingerprint (a thousand identical errors are one problem and one email); scheduled-task heartbeats; the latest
 * system checks; sampled page timings; downtime reported by the uptime checker; and HQ's switches. Nothing personal
 * is stored: messages and traces are masked before they are written.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        $schema = Schema::connection(Connections::control());
        $schema->create('watchtower_incidents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('fingerprint', 40)->unique();
            $table->string('kind', 16);                       // exception | browser | job | scheduler | queue | check | downtime | slow
            $table->string('severity', 10);                   // critical | high | normal
            $table->string('status', 12)->default('new');     // new | acknowledged | fixed | resolved | ignored
            $table->string('title', 255);
            $table->string('exception_class', 255)->nullable();
            $table->text('message')->nullable();
            $table->string('file', 255)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('location', 255)->nullable();      // "GET contacts/{contact}", "job Modules\…\SendX", "command crm:score"
            $table->string('surface', 12)->nullable();        // hq | workspace | website | movewise | api | console | queue
            $table->text('trace')->nullable();
            $table->json('context')->nullable();
            $table->unsignedBigInteger('occurrences')->default(0);
            $table->unsignedInteger('brokerages')->default(0);
            $table->dateTime('hour_bucket')->nullable();
            $table->unsignedInteger('hour_count')->default(0);
            $table->string('first_release', 40)->nullable();
            $table->string('last_release', 40)->nullable();
            $table->boolean('ongoing')->default(false);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('alerted_at')->nullable();
            $table->string('alerted_severity', 10)->nullable();
            $table->unsignedBigInteger('alerted_occurrences')->default(0);
            $table->dateTime('regressed_at')->nullable();
            $table->string('fixed_in', 40)->nullable();
            $table->dateTime('fixed_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->json('analysis')->nullable();
            $table->date('analysed_on')->nullable()->index();
            $table->timestamps();
            $table->index(['status', 'severity', 'last_seen_at']);
            $table->index('last_seen_at');
        });
        $schema->create('watchtower_incident_days', function (Blueprint $table): void {
            $table->ulid('incident_id');
            $table->date('day');
            $table->unsignedInteger('count')->default(0);
            $table->primary(['incident_id', 'day']);
        });
        $schema->create('watchtower_incident_brokerages', function (Blueprint $table): void {
            $table->ulid('incident_id');
            $table->unsignedBigInteger('tenant_id');
            $table->primary(['incident_id', 'tenant_id']);
        });
        $schema->create('watchtower_heartbeats', function (Blueprint $table): void {
            $table->string('task', 120)->primary();
            $table->string('expression', 60);
            $table->string('timezone', 40)->nullable();
            $table->dateTime('last_started_at')->nullable();
            $table->dateTime('last_finished_at')->nullable();
            $table->string('last_status', 10)->nullable();
            $table->unsignedInteger('last_runtime_ms')->nullable();
        });
        $schema->create('watchtower_checks', function (Blueprint $table): void {
            $table->string('name', 80)->primary();
            $table->boolean('ok');
            $table->string('message', 255)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->dateTime('checked_at');
            $table->dateTime('failing_since')->nullable();
        });
        $schema->create('watchtower_timings', function (Blueprint $table): void {
            $table->string('route', 200);
            $table->dateTime('hour');
            $table->unsignedInteger('samples')->default(0);
            $table->unsignedInteger('le300')->default(0);
            $table->unsignedInteger('le1000')->default(0);
            $table->unsignedInteger('le2000')->default(0);
            $table->unsignedInteger('slow')->default(0);
            $table->unsignedInteger('max_ms')->default(0);
            $table->boolean('evaluated')->default(false);
            $table->primary(['route', 'hour']);
            $table->index(['evaluated', 'hour']);
        });
        $schema->create('watchtower_downtimes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('monitor', 160);
            $table->string('cause', 255)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->index(['monitor', 'started_at']);
        });
        $schema->create('watchtower_settings', function (Blueprint $table): void {
            $table->id();
            $table->json('recipients')->nullable();
            $table->boolean('alerts')->default(true);         // emails for critical and high problems
            $table->boolean('digest')->default(true);         // the daily report
            $table->boolean('ai')->default(true);             // the AI explains problems
            $table->unsignedSmallInteger('ai_daily_cap')->default(30);
            $table->boolean('browser_errors')->default(true);
            $table->boolean('slow_pages')->default(true);
            $table->text('uptime_token')->nullable();         // encrypted
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::control());
        foreach (['watchtower_settings', 'watchtower_downtimes', 'watchtower_timings', 'watchtower_checks', 'watchtower_heartbeats', 'watchtower_incident_brokerages',
            'watchtower_incident_days', 'watchtower_incidents'] as $t) {
            $schema->dropIfExists($t);
        }
    }
};
