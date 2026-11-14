<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * MLS ingestion (specs/websites/02, Sprint 17A). Listing data is stored once per MLS on the listings connection
 * and shared by every licensed brokerage; licences (authorisations) are platform records on the control
 * connection, checked on every query.
 */
return new class extends Migration
{
    public function up(): void
    {
        $listings = Schema::connection(Connections::listings());

        $listings->create('mls_rules_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('program', 5)->default('idx'); // idx | vow
            $table->string('attribution_template', 200)->default('Listing courtesy of {ListOfficeName}');
            $table->text('disclaimer')->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->boolean('sold_allowed')->default(false);
            $table->unsignedSmallInteger('sold_delay_days')->default(0);
            $table->json('suppressed_fields')->nullable();
            $table->unsignedSmallInteger('freshness_minutes')->default(720);
            $table->boolean('index_off_market')->default(false);
            $table->timestamps();
        });

        $listings->create('mls_sources', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('key', 40)->unique();
            $table->string('name', 120);
            $table->json('states');
            $table->string('provider', 15); // mls_grid | trestle | bridge | direct_reso | fake
            $table->string('base_url', 300);
            $table->string('originating_system', 60)->nullable(); // MLS Grid filter
            $table->text('credentials')->nullable(); // encrypted JSON; write-only in the UI
            $table->string('status', 10)->default('setup'); // setup | active | paused | error
            $table->unsignedSmallInteger('sync_interval_minutes')->default(5);
            $table->unsignedInteger('rate_limit_per_hour')->default(7200);
            $table->foreignId('rules_profile_id')->nullable()->constrained('mls_rules_profiles')->nullOnDelete();
            $table->string('timezone', 40)->default('America/Chicago');
            $table->string('cursor', 40)->nullable(); // last committed ModificationTimestamp
            $table->text('next_link')->nullable(); // resumable paging
            $table->boolean('full_sync_done')->default(false);
            $table->dateTime('last_success_at')->nullable();
            $table->dateTime('last_reconciled_at')->nullable();
            $table->dateTime('backoff_until')->nullable();
            $table->unsignedTinyInteger('failures')->default(0);
            $table->string('last_error', 300)->nullable();
            $table->timestamps();
        });

        $listings->create('listings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('mls_source_id')->constrained('mls_sources')->cascadeOnDelete();
            $table->string('listing_key', 60);
            $table->string('listing_id', 40)->nullable();
            $table->string('standard_status', 30)->nullable();
            $table->string('mls_status', 40)->nullable();
            $table->string('property_type', 40)->nullable();
            $table->string('property_sub_type', 60)->nullable();
            $table->unsignedBigInteger('list_price_cents')->nullable();
            $table->unsignedBigInteger('original_list_price_cents')->nullable();
            $table->unsignedBigInteger('close_price_cents')->nullable();
            $table->date('close_date')->nullable();
            $table->unsignedTinyInteger('beds_total')->nullable();
            $table->unsignedTinyInteger('baths_full')->nullable();
            $table->unsignedTinyInteger('baths_half')->nullable();
            $table->unsignedInteger('living_area_sqft')->nullable();
            $table->unsignedBigInteger('lot_size_sqft')->nullable();
            $table->unsignedSmallInteger('year_built')->nullable();
            $table->string('street_number', 20)->nullable();
            $table->string('street_name', 100)->nullable();
            $table->string('unit_number', 20)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state_or_province', 2)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('county', 60)->nullable();
            $table->string('subdivision', 100)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('public_remarks')->nullable();
            $table->string('list_agent_mls_id', 30)->nullable();
            $table->string('list_office_mls_id', 30)->nullable();
            $table->string('list_office_name', 120)->nullable();
            $table->string('list_agent_name', 120)->nullable();
            $table->string('co_list_agent_mls_id', 30)->nullable();
            $table->string('buyer_agent_mls_id', 30)->nullable();
            $table->string('buyer_office_mls_id', 30)->nullable();
            $table->string('virtual_tour_url_unbranded', 500)->nullable();
            $table->unsignedSmallInteger('photos_count')->default(0);
            $table->date('listing_contract_date')->nullable();
            $table->date('on_market_date')->nullable();
            $table->unsignedSmallInteger('days_on_market')->nullable();
            $table->dateTime('modification_timestamp')->nullable();
            $table->dateTime('photos_change_timestamp')->nullable();
            $table->dateTime('off_market_at')->nullable();
            $table->boolean('internet_display_allowed')->default(true);
            $table->boolean('address_display_allowed')->default(true);
            $table->boolean('avm_allowed')->default(true);
            $table->boolean('comments_allowed')->default(true);
            $table->timestamps();
            $table->unique(['mls_source_id', 'listing_key'], 'listings_source_key_unique');
            $table->index(['mls_source_id', 'standard_status', 'list_price_cents'], 'listings_status_price_idx');
            $table->index(['postal_code', 'standard_status'], 'listings_zip_status_idx');
            $table->index(['city', 'standard_status'], 'listings_city_status_idx');
            $table->index(['list_agent_mls_id'], 'listings_agent_idx');
            $table->index(['list_office_mls_id'], 'listings_office_idx');
        });

        $listings->create('listing_payloads', function (Blueprint $table): void {
            $table->unsignedBigInteger('listing_id')->primary();
            $table->json('payload');
            $table->dateTime('received_at');
        });

        $listings->create('mls_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mls_source_id')->constrained('mls_sources')->cascadeOnDelete();
            $table->string('kind', 12); // full | incremental | reconcile
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('changed')->default(0);
            $table->unsignedInteger('removed')->default(0);
            $table->string('result', 10); // ok | partial | failed | throttled
            $table->string('error', 300)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->index(['mls_source_id', 'started_at']);
        });

        Schema::connection(Connections::control())->create('mls_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('mls_source_id');
            $table->string('broker_mls_id', 30);
            $table->json('office_mls_ids')->nullable();
            $table->json('scopes'); // idx | vow | back_office | agent_production
            $table->string('agreement_ref', 120)->nullable();
            $table->string('status', 10)->default('pending'); // pending | active | revoked | expired
            $table->date('expires_on')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'mls_source_id']);
            $table->index(['status', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('mls_authorizations');
        $listings = Schema::connection(Connections::listings());
        foreach (['mls_sync_runs', 'listing_payloads', 'listings', 'mls_sources', 'mls_rules_profiles'] as $table) {
            $listings->dropIfExists($table);
        }
    }
};
