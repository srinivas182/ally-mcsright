<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Lead Generation Engine 1 (specs/grow/03): buyer and seller funnels at /go/{slug} on the brokerage's website. Each
 * one has a job (home value, new listings, first home, moving here), an area and price, an owner (an agent, or the
 * brokerage) and its own counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('site_funnels', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('owner_user_id')->nullable(); // null: the brokerage (its lead rules route the leads)
            $table->string('kind', 20); // home_value, new_listings, first_home, moving
            $table->string('slug', 80);
            $table->string('area', 80)->nullable();
            $table->unsignedInteger('min_price')->nullable();
            $table->unsignedInteger('max_price')->nullable();
            $table->unsignedTinyInteger('beds')->nullable();
            $table->string('headline', 120);
            $table->string('intro', 400)->nullable();
            $table->string('status', 10)->default('live'); // live, paused
            $table->unsignedInteger('visits')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['tenant_id', 'site_id', 'slug'], 'site_funnels_slug_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('site_funnels');
    }
};
