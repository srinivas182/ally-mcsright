<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Lead intake, routing and speed to lead (specs/grow/04, Sprint 16A): lead sources (API keys, hosted
 * forms, forwarding addresses), routing rules, per-brokerage lead settings, one row per inquiry, and
 * agents' lead alert choices. The forwarding-address map lives on the control connection because one
 * inbound-email webhook serves every brokerage.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());

        $cell->create('crm_lead_sources', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('name', 80);
            $table->string('kind', 10); // api | form | email
            $table->string('source', 30); // crm Source enum
            $table->string('token', 40)->unique(); // form address / forwarding address
            $table->string('key_hash', 64)->nullable()->unique(); // API key (SHA-256)
            $table->string('key_last4', 4)->nullable();
            $table->string('consent_wording', 500)->nullable();
            $table->string('heading', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->dateTime('last_lead_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        $cell->create('crm_lead_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->primary();
            $table->unsignedSmallInteger('respond_minutes')->default(5);
            $table->unsignedTinyInteger('max_passes')->default(2);
            $table->string('fallback', 10)->default('broker'); // broker | pond
            $table->unsignedBigInteger('fallback_user_id')->nullable();
            $table->timestamps();
        });

        $cell->create('crm_routing_rules', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('name', 80);
            $table->json('sources')->nullable();
            $table->json('zips')->nullable();
            $table->unsignedInteger('price_min')->nullable();
            $table->unsignedInteger('price_max')->nullable();
            $table->string('intent', 10)->default('any'); // any | buy | sell | rent
            $table->string('method', 12); // agent | round_robin | first_claim | pond
            $table->json('agent_ids');
            $table->unsignedInteger('cursor')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'active', 'position']);
        });

        $cell->create('crm_leads', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('crm_lead_sources')->nullOnDelete();
            $table->string('external_id', 100)->nullable();
            $table->string('source', 30);
            $table->string('channel', 20);
            $table->string('referrer_host', 120)->nullable();
            $table->json('utm')->nullable();
            $table->string('landing_url', 500)->nullable();
            $table->string('intent', 10)->nullable();
            $table->string('zip', 10)->nullable();
            $table->unsignedInteger('price_min')->nullable();
            $table->unsignedInteger('price_max')->nullable();
            $table->string('property', 200)->nullable();
            $table->text('message')->nullable(); // encrypted
            $table->text('raw')->nullable(); // encrypted: emails that need review
            $table->string('status', 15)->default('new'); // new | assigned | offered | responded | pond | needs_review
            $table->boolean('new_contact')->default(true);
            $table->foreignId('rule_id')->nullable()->constrained('crm_routing_rules')->nullOnDelete();
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->dateTime('assigned_at')->nullable();
            $table->json('offered_to')->nullable();
            $table->json('passed_over')->nullable();
            $table->unsignedTinyInteger('passes')->default(0);
            $table->dateTime('responded_at')->nullable();
            $table->unsignedBigInteger('responded_by')->nullable();
            $table->unsignedInteger('response_seconds')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'source_id', 'external_id'], 'crm_leads_external_unique');
            $table->index(['tenant_id', 'status', 'assigned_at']);
            $table->index(['tenant_id', 'assigned_user_id', 'created_at']);
            $table->index(['tenant_id', 'contact_id']);
        });

        $cell->create('crm_lead_alert_prefs', function (Blueprint $table): void {
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->dateTime('texts_consent_at')->nullable();
            $table->dateTime('calls_consent_at')->nullable();
            $table->dateTime('away_until')->nullable();
            $table->timestamps();
            $table->primary(['tenant_id', 'user_id']);
        });

        Schema::connection(Connections::control())->create('crm_inbound_addresses', function (Blueprint $table): void {
            $table->string('token', 40)->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('crm_inbound_addresses');
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('crm_lead_alert_prefs');
        $cell->dropIfExists('crm_leads');
        $cell->dropIfExists('crm_routing_rules');
        $cell->dropIfExists('crm_lead_settings');
        $cell->dropIfExists('crm_lead_sources');
    }
};
