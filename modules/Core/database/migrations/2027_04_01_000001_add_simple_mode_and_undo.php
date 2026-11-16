<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simple mode (SM1): each person's choice of the simple or full view and of English or Spanish for it, and the undo
 * list (an action that can be undone for ten minutes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('ui_mode', 6)->nullable(); // simple, full (null: not chosen yet)
            $table->string('ui_locale', 2)->nullable(); // en, es
        });
        Schema::create('undo_actions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('kind', 40);
            $table->json('payload');
            $table->string('label', 160);
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('undo_actions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['ui_mode', 'ui_locale']));
    }
};
