<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * RC3 (specs/grow/17): agents refer recruits for their revenue share (sponsor), recruits are invited into RightAlly
 * and followed once they join (results by source against spend), and the broker checks in with their own agents
 * who may be thinking of leaving.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('rec_recruits', function (Blueprint $table): void {
            $table->unsignedBigInteger('sponsor_user_id')->nullable(); // the agent who referred them
            $table->unsignedBigInteger('invitation_id')->nullable();
            $table->dateTime('invited_at')->nullable();
            $table->unsignedBigInteger('user_id')->nullable(); // once they joined
            $table->date('joined_on')->nullable();
            $table->index(['tenant_id', 'sponsor_user_id']);
        });
        $cell->create('rec_spend', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedSmallInteger('year');
            $table->string('source', 12);
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();
            $table->unique(['tenant_id', 'year', 'source']);
        });
        $cell->create('rec_checkins', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id'); // the agent checked in with
            $table->unsignedBigInteger('by_user_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
        $cell->table('rec_settings', function (Blueprint $table): void {
            $table->boolean('agents_see_database')->default(false); // agents may browse the MLS agent database to refer people
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('rec_settings', fn (Blueprint $t) => $t->dropColumn('agents_see_database'));
        $cell->dropIfExists('rec_checkins');
        $cell->dropIfExists('rec_spend');
        $cell->table('rec_recruits', function (Blueprint $t): void {
            $t->dropIndex(['tenant_id', 'sponsor_user_id']);
            $t->dropColumn(['sponsor_user_id', 'invitation_id', 'invited_at', 'user_id', 'joined_on']);
        });
    }
};
