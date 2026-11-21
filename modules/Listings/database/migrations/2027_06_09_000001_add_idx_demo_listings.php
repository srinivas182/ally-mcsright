<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * IDX5: demo listings. HQ marks a feed as demo data (for example Repliers' sample data); a brokerage that has added a
 * board but has none live yet shows those listings, marked as samples, unless it switches them off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::listings())->table('mls_sources', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false);
        });
        Schema::connection(Connections::control())->create('idx_tenant_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->primary();
            $table->boolean('demo_listings')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('idx_tenant_settings');
        Schema::connection(Connections::listings())->table('mls_sources', function (Blueprint $table): void {
            $table->dropColumn('is_demo');
        });
    }
};
