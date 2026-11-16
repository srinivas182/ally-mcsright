<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint C4d (specs/ads/04): instant forms on RightAlly Boost ads. The form lives on RightAlly's Page, so each form
 * is mapped here to the one brokerage and ad it belongs to; a lead is handed only to that brokerage. No lead details
 * are kept here (they go straight into that brokerage's CRM).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->create('ads_boost_forms', function (Blueprint $table): void {
            $table->id();
            $table->string('form_id', 64)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('campaign_id');
            $table->timestamps();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('ads_boost_forms');
    }
};
