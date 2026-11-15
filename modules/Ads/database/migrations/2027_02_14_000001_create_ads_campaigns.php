<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Listing ads made in RightAlly and published to the person's own ad account (specs/ads/01). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('ads_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');            // who made it (and whose ad account)
            $table->unsignedBigInteger('connection_id');      // the Meta Ads connection used
            $table->string('channel', 20)->default('meta');
            $table->string('ad_account_id', 40);
            $table->string('page_id', 40);
            $table->char('listing_ulid', 26);
            $table->string('listing_label', 200);
            $table->string('headline', 60);
            $table->string('body', 1000);
            $table->string('image_url', 1000);
            $table->string('link', 1000);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('radius_miles');
            $table->unsignedInteger('daily_budget_cents');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('draft');  // publishing, active, paused, ended, failed
            $table->string('error', 500)->nullable();
            $table->json('meta_ids')->nullable();             // campaign, ad set, creative, ad
            $table->unsignedInteger('spend_cents')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->timestamp('results_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('ads_campaigns');
    }
};
