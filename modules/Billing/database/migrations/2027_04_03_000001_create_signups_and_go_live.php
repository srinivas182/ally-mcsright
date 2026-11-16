<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * HC1b (specs/billing/07): self-serve signups (a brokerage picks a package, pays, and its RightAlly goes live by itself),
 * each new brokerage's 30-day go-live plan with its named coach, and the language of a Health Check.
 */
return new class extends Migration
{
    public function up(): void
    {
        $control = Schema::connection(Connections::control());
        $control->create('signups', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('brokerage', 150);
            $table->string('slug', 63);
            $table->string('owner_name', 120);
            $table->string('owner_email', 190);
            $table->char('state', 2)->nullable();
            $table->unsignedInteger('agents')->nullable();
            $table->unsignedBigInteger('package_id');
            $table->string('interval', 5); // month, year
            $table->unsignedBigInteger('health_check_id')->nullable();
            $table->string('status', 10)->default('pending'); // pending, live, failed
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('slug');
        });
        $control->create('go_live_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->date('started_on');
            $table->string('coach_name', 120)->nullable();
            $table->string('coach_email', 190)->nullable();
            $table->string('coach_phone', 30)->nullable();
            $table->dateTime('last_mailed_at')->nullable();
            $table->timestamps();
        });
        $control->table('health_checks', function (Blueprint $table): void {
            $table->char('lang', 2)->default('en')->after('email');
        });
    }

    public function down(): void
    {
        $control = Schema::connection(Connections::control());
        $control->table('health_checks', fn (Blueprint $table) => $table->dropColumn('lang'));
        $control->dropIfExists('go_live_plans');
        $control->dropIfExists('signups');
    }
};
