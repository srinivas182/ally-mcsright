<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint 18A-2b: monthly allowances for texts, call minutes and website map views per package, the usage
 * counted against them, and the calls waiting for Twilio to report their real length (specs/billing/03).
 */
return new class extends Migration
{
    public function up(): void
    {
        $control = Schema::connection(Connections::control());
        $cell = Schema::connection(Connections::cell());

        $control->table('billing_packages', function (Blueprint $table): void {
            $table->unsignedInteger('included_text_segments')->default(0)->after('included_credits');
            $table->unsignedInteger('included_call_minutes')->default(0)->after('included_text_segments');
            $table->unsignedInteger('included_map_views')->default(0)->after('included_call_minutes');
        });

        // HQ's per-brokerage choices (e.g. RightAlly covers extra map views for a key client).
        $control->create('usage_tenant_policies', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->primary();
            $table->boolean('maps_covered')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        // Calls placed, until Twilio reports how long they really were.
        $control->create('usage_calls', function (Blueprint $table): void {
            $table->id();
            $table->string('call_sid', 64)->unique();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedSmallInteger('estimated_minutes');
            $table->unsignedSmallInteger('actual_minutes')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->dateTime('created_at');
        });

        $cell->create('usage_meters', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->char('period', 7);                       // YYYY-MM
            $table->string('meter', 20);                     // texts, calls, maps
            $table->unsignedBigInteger('used')->default(0);  // segments, minutes or views
            $table->unsignedBigInteger('over')->default(0);  // beyond the allowance
            $table->json('alerts_sent')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'period', 'meter']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('usage_meters');
        $control = Schema::connection(Connections::control());
        $control->dropIfExists('usage_calls');
        $control->dropIfExists('usage_tenant_policies');
        $control->table('billing_packages', fn (Blueprint $table) => $table->dropColumn(['included_text_segments', 'included_call_minutes', 'included_map_views']));
    }
};
