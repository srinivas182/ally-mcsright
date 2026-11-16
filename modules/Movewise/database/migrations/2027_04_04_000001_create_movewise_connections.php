<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Movewise MW4 (specs/movewise/04): choosing an agent.
 *
 * - movewise_people gains what the person tells us at "Talk to an agent" (first name, their move) and their verified
 *   mobile (encrypted), the readiness score, and "I already have an agent".
 * - movewise_phone_codes: one-time codes that verify the mobile before anyone may contact it.
 * - movewise_shows: every set of three agents shown, with why each was chosen (the Fair Housing audit trail).
 * - movewise_connections: the person's choice of one agent and one way to be contacted (the consent, word for word),
 *   the agent's accept timer, the charge and any refund.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = Schema::connection(Connections::control());
        $db->table('movewise_people', function (Blueprint $table): void {
            $table->string('first_name', 80)->nullable()->after('name');
            $table->text('phone')->nullable()->after('first_name');          // encrypted E.164
            $table->char('phone_hash', 64)->nullable()->after('phone');
            $table->timestamp('phone_verified_at')->nullable()->after('phone_hash');
            $table->json('about')->nullable()->after('plan');                 // lead type, timeline, pre-approval, budget, selling first, home
            $table->boolean('has_agent')->default(false)->after('about');
            $table->unsignedTinyInteger('readiness')->nullable()->after('has_agent');
        });
        $db->create('movewise_phone_codes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('person_id')->index();
            $table->char('phone_hash', 64);
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
        });
        $db->create('movewise_shows', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('person_id')->index();
            $table->json('context');                                          // state, zip, city, price, lead type, language, listing
            $table->json('agents');                                           // [{agent, package, fresh, reasons}]
            $table->json('pending')->nullable();                              // the choice waiting for the phone code
            $table->timestamp('created_at');
        });
        $db->create('movewise_connections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('person_id')->index();
            $table->unsignedBigInteger('show_id');
            $table->unsignedBigInteger('agent_id')->index();
            $table->unsignedBigInteger('package_id')->index();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('lead_type', 8);
            $table->string('method', 8);                                      // text | call | time
            $table->timestamp('slot_at')->nullable();                          // "Pick a time"
            $table->text('consent_text');
            $table->timestamp('consent_at');
            $table->string('consent_ip', 45)->nullable();
            $table->string('status', 10)->default('offered');                  // offered | accepted | expired | declined | withdrawn
            $table->timestamp('offered_at');
            $table->timestamp('accept_by');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('model', 12);
            $table->unsignedInteger('price_credits')->default(0);
            $table->string('refund_status', 10)->nullable();                   // requested | refunded | denied
            $table->string('refund_reason', 300)->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();                 // the lead in the agent's account
            $table->timestamps();
            $table->index(['package_id', 'offered_at']);
            $table->index(['status', 'accept_by']);
        });
    }

    public function down(): void
    {
        $db = Schema::connection(Connections::control());
        foreach (['movewise_connections', 'movewise_shows', 'movewise_phone_codes'] as $t) {
            $db->dropIfExists($t);
        }
        $db->table('movewise_people', fn (Blueprint $table) => $table->dropColumn(['first_name', 'phone', 'phone_hash', 'phone_verified_at', 'about', 'has_agent', 'readiness']));
    }
};
