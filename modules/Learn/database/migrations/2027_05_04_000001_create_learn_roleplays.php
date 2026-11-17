<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** LN3 (specs/learn/03): role-play practice, daily drills, rehearsals, call coaching and deal debriefs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('learn_roleplays', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('kind', 12); // practice, daily, rehearsal, call, debrief
            $table->string('scenario', 40)->nullable();
            $table->string('skill', 30)->nullable();
            $table->text('brief')->nullable(); // encrypted: the agent's own appointment details
            $table->longText('transcript')->nullable(); // encrypted
            $table->unsignedSmallInteger('turns')->default(0);
            $table->unsignedTinyInteger('score')->nullable();
            $table->longText('feedback')->nullable(); // encrypted JSON
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('learn_roleplays');
    }
};
