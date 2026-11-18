<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * FL1 (specs/core/16), control database: feature switches per area (everyone or one brokerage), seat limits per
 * brokerage, outside-service settings (HQ's and each brokerage's, test and live keys kept apart and encrypted), and
 * support access (whether RightAlly support may enter a brokerage's account without asking first, requests, visits).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        $control = Schema::connection(Connections::control());
        $control->create('feature_switches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0); // 0 = every brokerage
            $table->string('area', 40);
            $table->string('hq_state', 3)->nullable(); // on, off; null = follow everyone's setting
            $table->unsignedTinyInteger('rollout_percent')->nullable(); // everyone's row only: share of brokerages that get it
            $table->boolean('brokerage_off')->default(false); // the brokerage switched it off for itself
            $table->string('note', 200)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'area']);
        });
        $control->create('tenant_seats', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('area', 40);
            $table->unsignedInteger('seats');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'area']);
        });
        $control->create('outside_services', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0); // 0 = HQ's own account
            $table->string('service', 40);
            $table->string('provider', 40);
            $table->string('mode', 4)->default('off'); // off, test, live
            $table->text('test_keys')->nullable(); // encrypted JSON
            $table->text('live_keys')->nullable(); // encrypted JSON
            $table->string('test_contacts', 500)->nullable(); // phone numbers or emails test mode may reach
            $table->dateTime('test_passed_at')->nullable(); // unlocks Live
            $table->dateTime('checked_at')->nullable();
            $table->boolean('check_ok')->nullable();
            $table->string('check_mode', 4)->nullable();
            $table->string('check_message', 300)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'service']);
        });
        $control->create('support_access', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->string('policy', 4)->default('open'); // open, ask
            $table->dateTime('open_until')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        $control->create('support_access_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('by_user_id');
            $table->string('by_name', 160);
            $table->string('reason', 300);
            $table->string('status', 8)->default('waiting'); // waiting, allowed, declined
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
        });
        $control->create('support_visits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('by_user_id');
            $table->string('by_name', 160);
            $table->string('viewed_name', 160);
            $table->string('reason', 300);
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        $control = Schema::connection(Connections::control());
        foreach (['support_visits', 'support_access_requests', 'support_access', 'outside_services', 'tenant_seats', 'feature_switches'] as $t) {
            $control->dropIfExists($t);
        }
    }
};
