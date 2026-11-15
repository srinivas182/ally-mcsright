<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * "Your AI Assistant" (specs/know/03): each person's conversations, kept 30 days. A turn is what the person said,
 * what the assistant said, a skill it used (with the result), or a card (a preview to approve, or an app to connect).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('ai_assistant_turns', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->char('conversation', 26);
            $table->string('role', 10); // person | assistant | skill | card
            $table->text('body')->nullable(); // encrypted
            $table->text('data')->nullable(); // encrypted JSON: skill, arguments, result, card
            $table->string('status', 10)->nullable(); // cards: waiting | done | cancelled
            $table->dateTime('created_at');
            $table->index(['tenant_id', 'user_id', 'conversation', 'id']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('ai_assistant_turns');
    }
};
