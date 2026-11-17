<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * SH1, showings and the seller's view (specs/listings/04): showing requests from buyers' agents, approval, access
 * instructions sent only once approved (kept encrypted), feedback after each showing, and the agent's note to the
 * seller.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('listiq_listings', function (Blueprint $table): void {
            $table->string('showing_mode', 8)->default('approve'); // approve, auto
            $table->text('access')->nullable();                     // lockbox code and instructions, encrypted
            $table->text('seller_note')->nullable();
            $table->dateTime('seller_note_at')->nullable();
        });
        $cell->create('listiq_showings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('listing_id');
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 20)->nullable();
            $table->string('brokerage', 120)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 10)->default('requested'); // requested, approved, declined, cancelled
            $table->string('decline_reason', 200)->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('feedback_hash', 64)->nullable()->unique();
            $table->unsignedTinyInteger('feedback_asks')->default(0);
            $table->dateTime('feedback_asked_at')->nullable();
            $table->json('feedback')->nullable(); // interest 1-5, price low|right|high, comments
            $table->dateTime('feedback_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'listing_id', 'starts_at']);
            $table->index(['tenant_id', 'status', 'ends_at']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('listiq_showings');
        $cell->table('listiq_listings', fn (Blueprint $t) => $t->dropColumn(['showing_mode', 'access', 'seller_note', 'seller_note_at']));
    }
};
