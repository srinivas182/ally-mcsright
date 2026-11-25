<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * ML1: agents' MLS IDs found in the brokerage's MLS feed (listing agent on a listing from the brokerage's office), or
 * typed by the agent. Nothing is saved to the agent's record until the agent confirms it (or the owner assigns it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('roster_mls_matches', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id')->nullable(); // null: the owner must say who this is
            $table->unsignedBigInteger('mls_source_id');
            $table->string('agent_mls_id', 30);
            $table->string('agent_name', 120)->nullable(); // the name the MLS shows on the listings
            $table->unsignedInteger('listings')->default(0);
            $table->json('sample')->nullable(); // up to three addresses, so the agent can recognise them
            $table->string('status', 10); // suggested, confirmed, declined, review
            $table->string('matched_by', 8); // feed, typed, owner
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'mls_source_id', 'agent_mls_id'], 'roster_mls_matches_unique');
            $table->index(['tenant_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('roster_mls_matches');
    }
};
