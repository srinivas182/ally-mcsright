<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * LQ2, Lead-IQ Prospector (specs/grow/15): owners of expired and withdrawn listings (from a broker back-office MLS feed)
 * and for-sale-by-owner homes the agent adds, each with the reason it is worth a call or a card; and the brokerage's
 * copy of the National Do Not Call Registry for the area codes it works, so nobody on it is cold-called.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('crm_prospects', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('kind', 10); // expired, withdrawn, fsbo
            $table->unsignedBigInteger('listing_id')->nullable();
            $table->string('street', 160);
            $table->string('city', 80)->nullable();
            $table->char('state', 2)->nullable();
            $table->string('zip', 10)->nullable();
            $table->string('owner_name', 160)->nullable();
            $table->json('mailing')->nullable(); // the owner's mailing address, from property data
            $table->string('phone', 20)->nullable(); // added by the agent; never bought or scraped
            $table->string('reason', 300);
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('status', 10)->default('new'); // new, working, contact, dismissed
            $table->date('found_on');
            $table->timestamps();
            $table->unique(['tenant_id', 'listing_id']);
            $table->index(['tenant_id', 'assigned_to', 'status']);
        });
        $cell->create('crm_prospector_settings', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->json('agent_ids')->nullable(); // who receives prospects
            $table->unsignedSmallInteger('per_agent_daily')->default(5);
            $table->json('zips')->nullable(); // areas to watch; null: every area the feed covers
            $table->timestamps();
            $table->unique('tenant_id');
        });
        $cell->create('crm_dnc_numbers', function (Blueprint $table): void {
            $table->tenant();
            $table->char('area_code', 3);
            $table->char('number_hash', 64);
            $table->primary(['tenant_id', 'number_hash']);
            $table->index(['tenant_id', 'area_code']);
        });
        $cell->create('crm_dnc_imports', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->char('area_code', 3);
            $table->unsignedInteger('numbers');
            $table->date('downloaded_on'); // the registry data must be no older than 31 days
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();
            $table->unique(['tenant_id', 'area_code']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        foreach (['crm_dnc_imports', 'crm_dnc_numbers', 'crm_prospector_settings', 'crm_prospects'] as $t) {
            $cell->dropIfExists($t);
        }
    }
};
