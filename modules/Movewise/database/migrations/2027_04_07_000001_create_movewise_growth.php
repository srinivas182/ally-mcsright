<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Movewise MW7 (specs/movewise/07): verified Movewise reviews (published only with the person's say-so, one public
 * reply from the agent, removed only for abuse, personal data or proven falsehood, with the reason logged), and
 * Movewise's own ad spend with its stop-loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = Schema::connection(Connections::control());
        $db->table('movewise_connections', function (Blueprint $table): void {
            $table->boolean('review_public')->default(false)->after('rating_note');
            $table->timestamp('reviewed_at')->nullable()->after('review_public');
            $table->string('review_reply', 1000)->nullable()->after('reviewed_at');
            $table->timestamp('review_replied_at')->nullable()->after('review_reply');
            $table->string('review_removed_reason', 300)->nullable()->after('review_replied_at');
            $table->unsignedBigInteger('review_removed_by')->nullable()->after('review_removed_reason');
        });
        $db->create('movewise_ad_spend', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->string('channel', 20);                      // meta | google | other
            $table->char('state', 2)->nullable();
            $table->unsignedBigInteger('spend_cents');
            $table->string('note', 200)->nullable();
            $table->unsignedBigInteger('by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['day', 'channel', 'state']);
        });
        $db->create('movewise_ad_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('monthly_budget_cents')->default(0);
            $table->unsignedBigInteger('weekly_topup_cents')->default(0);
            $table->unsignedBigInteger('target_cents')->default(0);      // target cost per accepted introduction
            $table->unsignedBigInteger('stop_loss_cents')->default(0);   // hold top-ups above this cost per accepted introduction (7 days)
            $table->string('status', 10)->default('ok');                 // ok | held
            $table->string('reason', 300)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $db = Schema::connection(Connections::control());
        $db->dropIfExists('movewise_ad_settings');
        $db->dropIfExists('movewise_ad_spend');
        $db->table('movewise_connections', fn (Blueprint $table) => $table->dropColumn(['review_public', 'reviewed_at', 'review_reply', 'review_replied_at', 'review_removed_reason', 'review_removed_by']));
    }
};
