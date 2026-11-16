<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Movewise MW3 (specs/movewise/03): the agent and broker program, on the control plane.
 *
 * - movewise_agents: one row per agent per state they apply in (licence check, brokerage confirmation, HQ review of
 *   the profile card and public discipline record, program terms). The agent lives in a tenant (their brokerage, or a
 *   free Movewise Agent seat).
 * - movewise_packages: what an agent or a brokerage buys (areas, price range, lead types, caps, hours, pricing model);
 *   a broker package lists the agents it may show (movewise_package_agents).
 * - movewise_seat_requests: agents not on RightAlly asking for the free Movewise Agent seat.
 * - movewise_states.pricing: the models HQ switches on in each state and their prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = Schema::connection(Connections::control());
        $db->create('movewise_agents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->char('state', 2);
            $table->string('licence_number', 40);
            $table->string('licence_status', 16)->default('unchecked');   // unchecked | active | inactive | not_found | mismatch | manual
            $table->date('licence_expires_on')->nullable();
            $table->timestamp('licence_checked_at')->nullable();
            $table->string('brokerage_name', 160);
            $table->timestamp('brokerage_confirmed_at')->nullable();
            $table->unsignedBigInteger('brokerage_confirmed_by')->nullable();
            $table->timestamp('discipline_checked_at')->nullable();      // HQ looked at the state's public discipline record
            $table->json('profile');                                      // the card consumers see
            $table->string('profile_status', 12)->default('draft');       // draft | submitted | approved | changes
            $table->string('review_note', 500)->nullable();
            $table->string('terms_version', 20)->nullable();
            $table->timestamp('terms_signed_at')->nullable();
            $table->string('terms_signed_ip', 45)->nullable();
            $table->string('status', 12)->default('applying');            // applying | active | paused | removed
            $table->string('pause_reason', 200)->nullable();
            $table->string('tier', 10)->default('new');                    // new | standard | preferred
            $table->unsignedTinyInteger('strikes')->default(0);
            $table->boolean('seat')->default(false);                       // on a free Movewise Agent seat
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'state']);
            $table->index(['state', 'status']);
        });
        $db->create('movewise_packages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('agent_id')->nullable()->index();     // an agent package; null: a broker package
            $table->string('payer', 10);                                    // agent | brokerage
            $table->unsignedBigInteger('payer_user_id')->nullable();        // the agent whose credits pay (agent packages)
            $table->string('name', 120);
            $table->char('state', 2);
            $table->json('areas');                                           // {"zips": [...], "cities": [...]}
            $table->unsignedInteger('price_min')->default(0);
            $table->unsignedInteger('price_max')->default(0);                // 0: no upper limit
            $table->json('lead_types');                                      // ["buyer","seller"]
            $table->json('languages');                                       // ["en","es"]
            $table->unsignedSmallInteger('monthly_cap');
            $table->unsignedSmallInteger('daily_cap')->nullable();
            $table->json('hours');                                           // {"tz": "America/Chicago", "days": {"1": ["08:00","20:00"], ...}}
            $table->string('model', 12);                                     // connection | appointment | share
            $table->string('status', 10)->default('draft');                  // draft | active | paused
            $table->string('pause_reason', 200)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['state', 'status']);
        });
        $db->create('movewise_package_agents', function (Blueprint $table): void {
            $table->unsignedBigInteger('package_id');
            $table->unsignedBigInteger('agent_id');
            $table->primary(['package_id', 'agent_id']);
        });
        $db->create('movewise_seat_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('email', 190)->index();
            $table->char('state', 2);
            $table->string('licence_number', 40);
            $table->string('brokerage_name', 160);
            $table->string('licence_status', 16)->default('unchecked');
            $table->string('status', 10)->default('pending');                // pending | approved | declined
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();             // the seat, once approved
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
        $db->table('movewise_states', function (Blueprint $table): void {
            $table->json('pricing')->nullable()->after('checklist');
        });
    }

    public function down(): void
    {
        $db = Schema::connection(Connections::control());
        $db->table('movewise_states', fn (Blueprint $table) => $table->dropColumn('pricing'));
        foreach (['movewise_seat_requests', 'movewise_package_agents', 'movewise_packages', 'movewise_agents'] as $t) {
            $db->dropIfExists($t);
        }
    }
};
