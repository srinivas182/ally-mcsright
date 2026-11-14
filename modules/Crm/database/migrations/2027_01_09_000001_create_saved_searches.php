<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Saved searches on contacts with new-listing and price-drop email alerts (specs/grow/08, Sprint 17B-2). Each
 * listing is sent to a contact once per kind and price, so alerts never repeat.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('crm_saved_searches', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('name', 100);
            $table->json('filters');
            $table->string('frequency', 8)->default('daily'); // instant | daily | weekly | off
            $table->dateTime('last_checked_at')->nullable();
            $table->dateTime('last_sent_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->dateTime('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'frequency', 'last_checked_at'], 'crm_saved_searches_due_idx');
            $table->index(['tenant_id', 'contact_id']);
        });
        $cell->create('crm_search_alerts_sent', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('saved_search_id')->constrained('crm_saved_searches')->cascadeOnDelete();
            $table->string('listing_ulid', 26);
            $table->string('kind', 10); // new | price_drop
            $table->unsignedBigInteger('price');
            $table->dateTime('sent_at');
            $table->unique(['saved_search_id', 'listing_ulid', 'kind', 'price'], 'crm_search_alerts_unique');
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('crm_search_alerts_sent');
        $cell->dropIfExists('crm_saved_searches');
    }
};
