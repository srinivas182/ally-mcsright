<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * RC1, recruiting (specs/grow/17): the brokerage's view of every agent in the MLSs it holds a back-office licence for
 * (production worked out from closed listings), its recruiting pipeline with notes and next steps, alerts when a
 * recruit changes brokerage or their production jumps, and the settings used for "what you'd earn here".
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('rec_mls_agents', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('mls_source_id');
            $table->string('agent_mls_id', 30);
            $table->string('name', 120)->nullable();
            $table->string('office_mls_id', 30)->nullable();
            $table->string('office_name', 120)->nullable();
            $table->string('previous_office_mls_id', 30)->nullable();
            $table->string('previous_office_name', 120)->nullable();
            $table->date('office_changed_on')->nullable();
            $table->unsignedInteger('sides')->default(0); // closed sides, last 12 months
            $table->unsignedInteger('list_sides')->default(0);
            $table->unsignedInteger('buyer_sides')->default(0);
            $table->unsignedBigInteger('volume_cents')->default(0);
            $table->unsignedInteger('sides_before')->default(0); // the 12 months before that
            $table->unsignedBigInteger('volume_before_cents')->default(0);
            $table->unsignedInteger('active_listings')->default(0);
            $table->date('last_closed_on')->nullable();
            $table->boolean('ours')->default(false); // in one of the brokerage's own offices
            $table->dateTime('refreshed_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'mls_source_id', 'agent_mls_id'], 'rec_mls_agents_unique');
            $table->index(['tenant_id', 'ours', 'sides']);
        });
        $cell->create('rec_recruits', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('mls_agent_id')->nullable();
            $table->string('name', 120);
            $table->string('email', 190)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('brokerage', 120)->nullable();
            $table->string('stage', 12)->default('prospect'); // prospect, talking, meeting, offer, joined, not_now
            $table->string('source', 12)->default('manual'); // mls, referral, manual
            $table->unsignedBigInteger('owner_user_id')->nullable(); // the recruiter
            $table->string('next_step', 200)->nullable();
            $table->date('next_step_on')->nullable();
            $table->string('not_now_reason', 200)->nullable();
            $table->date('stage_changed_on')->nullable();
            $table->decimal('current_split', 5, 2)->nullable(); // their share today, %
            $table->unsignedBigInteger('current_cap_cents')->nullable();
            $table->unsignedBigInteger('current_fee_cents')->nullable(); // per deal
            $table->unsignedBigInteger('compare_plan_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'mls_agent_id']);
            $table->index(['tenant_id', 'stage']);
        });
        $cell->create('rec_notes', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('recruit_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('kind', 10)->default('note'); // note, call, email, meeting, stage, alert
            $table->text('body');
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'recruit_id', 'occurred_at']);
        });
        $cell->create('rec_alerts', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('recruit_id');
            $table->string('kind', 12); // moved, production
            $table->string('message', 300);
            $table->dateTime('seen_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'seen_at']);
        });
        $cell->create('rec_settings', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->json('own_office_ids')->nullable(); // MLS office IDs that are this brokerage
            $table->decimal('commission_rate', 5, 4)->default(0.025); // used to estimate commission from a closed price
            $table->unsignedBigInteger('compare_plan_id')->nullable(); // the plan offered, by default
            $table->timestamps();
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        foreach (['rec_settings', 'rec_alerts', 'rec_notes', 'rec_recruits', 'rec_mls_agents'] as $t) {
            $cell->dropIfExists($t);
        }
    }
};
