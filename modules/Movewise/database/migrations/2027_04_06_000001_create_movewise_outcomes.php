<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Movewise MW6 (specs/movewise/06): what happens after an introduction.
 *
 * - Status flows back from the agent (contacted → appointment → agreement → under contract → closed, or lost), with
 *   reminders and a pause when it stops; the person rates the agent and confirms a held appointment.
 * - Per-appointment charges (when both sides confirm), the referral agreement signed at accept for share-at-close,
 *   referral invoices raised at closing, and monthly settlements with each managing broker partner.
 * - The scorecard and tier, recalculated weekly from the last 90 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = Schema::connection(Connections::control());
        $db->table('movewise_connections', function (Blueprint $table): void {
            $table->string('stage', 20)->nullable()->after('status');                 // contacted | appointment | met | agreement | contract | closed | lost
            $table->timestamp('stage_at')->nullable()->after('stage');
            $table->timestamp('reminded_at')->nullable()->after('stage_at');
            $table->timestamp('met_agent_at')->nullable()->after('reminded_at');       // the agent says the appointment was held
            $table->timestamp('met_person_at')->nullable()->after('met_agent_at');     // the person confirms it
            $table->unsignedTinyInteger('rating')->nullable()->after('met_person_at');
            $table->string('rating_note', 500)->nullable()->after('rating');
            $table->text('referral_agreement')->nullable()->after('rating_note');     // share at close: the agreement, word for word
            $table->timestamp('referral_signed_at')->nullable()->after('referral_agreement');
            $table->decimal('share_pct', 5, 2)->nullable()->after('referral_signed_at');
        });
        $db->create('movewise_status_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('connection_id')->index();
            $table->string('stage', 20);
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('close_price_cents')->nullable();
            $table->unsignedBigInteger('commission_cents')->nullable();             // the agent's side, gross, at closing
            $table->date('close_date')->nullable();
            $table->unsignedBigInteger('by_user_id')->nullable();
            $table->timestamp('created_at');
        });
        $db->create('movewise_invoices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('number', 20)->unique();
            $table->unsignedBigInteger('connection_id')->unique();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('partner_id')->index();
            $table->unsignedBigInteger('commission_cents');
            $table->decimal('share_pct', 5, 2);
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 10)->default('issued');                          // issued | paid | void
            $table->date('due_on');
            $table->timestamp('paid_at')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamps();
        });
        $db->create('movewise_settlements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('partner_id');
            $table->char('period', 7);                                                // YYYY-MM
            $table->unsignedInteger('invoices');
            $table->unsignedBigInteger('collected_cents');
            $table->decimal('hq_pct', 5, 2);
            $table->unsignedBigInteger('hq_cents');
            $table->string('status', 10)->default('open');                            // open | settled
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['partner_id', 'period']);
        });
        $db->table('movewise_partners', function (Blueprint $table): void {
            $table->decimal('hq_share_pct', 5, 2)->default(0)->after('status');    // HQ's share of referral fees the partner collects
        });
        $db->table('movewise_agents', function (Blueprint $table): void {
            $table->json('scorecard')->nullable()->after('strikes');
            $table->timestamp('scored_at')->nullable()->after('scorecard');
        });
    }

    public function down(): void
    {
        $db = Schema::connection(Connections::control());
        $db->table('movewise_agents', fn (Blueprint $table) => $table->dropColumn(['scorecard', 'scored_at']));
        $db->table('movewise_partners', fn (Blueprint $table) => $table->dropColumn('hq_share_pct'));
        foreach (['movewise_settlements', 'movewise_invoices', 'movewise_status_events'] as $t) {
            $db->dropIfExists($t);
        }
        $db->table('movewise_connections', fn (Blueprint $table) => $table->dropColumn(['stage', 'stage_at', 'reminded_at', 'met_agent_at', 'met_person_at', 'rating', 'rating_note',
            'referral_agreement', 'referral_signed_at', 'share_pct']));
    }
};
