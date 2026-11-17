<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * CM1, pricing (specs/listings/01): CMAs (the subject home, the chosen comps with any adjustment the agent changed,
 * the result and its share link), seller net sheets and buyer cost estimates, and the brokerage's own defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('pricing_cmas', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('owner_user_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('deal_id')->nullable();
            $table->string('title', 160);
            $table->json('subject');            // address and facts of the home being priced
            $table->json('picks');              // [{listing_id, on, overrides: {key: cents}}]
            $table->json('result')->nullable(); // range, confidence, comps with adjustments, market, net sheet
            $table->text('summary')->nullable();
            $table->string('lang', 2)->default('en');
            $table->json('net')->nullable();     // seller net sheet inputs
            $table->string('share_hash', 64)->nullable()->unique();
            $table->dateTime('shared_at')->nullable();
            $table->dateTime('share_expires_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->dateTime('last_viewed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'owner_user_id', 'updated_at']);
            $table->index(['tenant_id', 'contact_id']);
        });
        $cell->create('pricing_sheets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('owner_user_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('deal_id')->nullable();
            $table->string('kind', 8); // buyer
            $table->string('title', 160);
            $table->json('inputs');
            $table->json('result');
            $table->string('lang', 2)->default('en');
            $table->string('share_hash', 64)->nullable()->unique();
            $table->dateTime('share_expires_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->dateTime('last_viewed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'owner_user_id', 'updated_at']);
        });
        $cell->create('pricing_settings', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->json('values'); // the brokerage's own defaults, over config/pricing.php
            $table->timestamps();
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        foreach (['pricing_settings', 'pricing_sheets', 'pricing_cmas'] as $t) {
            $cell->dropIfExists($t);
        }
    }
};
