<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * IDX4: one row per brokerage and MLS board, tracked on its own: demo → awaiting signature → sent → waiting on the MLS →
 * live (or needs fixing, or cancelled). A board's feed may also be locked to one brokerage (its own key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->create('idx_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('board_id')->index();
            $table->string('connector', 30)->nullable(); // the winning connector (the board's main one unless HQ overrides)
            $table->string('status', 20)->default('demo')->index();
            $table->string('member_id', 30)->nullable(); // MLS member or office ID
            $table->string('office_id', 30)->nullable();
            $table->string('license_number', 40)->nullable();
            $table->string('managing_broker_name', 120)->nullable();
            $table->string('managing_broker_email', 190)->nullable();
            $table->text('own_key')->nullable(); // encrypted: the brokerage's own feed key (for example from MLS United), if it has one
            $table->string('feed_id', 120)->nullable(); // the connector's ID for this brokerage's feed, if one is issued
            $table->unsignedInteger('fee_cents')->nullable(); // the monthly fee when added (billing in IDX7)
            $table->unsignedBigInteger('mls_authorization_id')->nullable();
            $table->string('client_note', 300)->nullable(); // what needs fixing, shown to the brokerage
            $table->unsignedTinyInteger('reminders')->default(0);
            $table->timestamp('status_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('live_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'board_id']);
        });
        Schema::connection(Connections::listings())->table('mls_sources', function (Blueprint $table): void {
            $table->unsignedBigInteger('locked_tenant_id')->nullable()->index(); // IDX4: a feed only this brokerage may use (its own key)
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('idx_subscriptions');
        Schema::connection(Connections::listings())->table('mls_sources', function (Blueprint $table): void {
            $table->dropIndex(['locked_tenant_id']);
            $table->dropColumn('locked_tenant_id');
        });
    }
};
