<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint SA5c (specs/grow/13): replies to comments and messages on the agent's Facebook Pages and Instagram. Each
 * comment or message is kept with the drafted reply and what happened to it; replies are off until the agent turns
 * them on, and wait for their OK unless they choose otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('studio_replies', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('connection_id');
            $table->string('channel', 10);                 // facebook | instagram
            $table->string('kind', 10);                    // comment | message
            $table->string('account', 64);                 // the Page or Instagram account
            $table->string('external_id', 200);            // the comment id, or the message id
            $table->string('from_ref', 64)->nullable();    // who wrote it (Page- or Instagram-scoped id)
            $table->string('from_name', 120)->nullable();
            $table->string('post_ref', 64)->nullable();    // the post or media a comment is on
            $table->unsignedBigInteger('social_post_id')->nullable();
            $table->text('text');
            $table->string('intent', 20)->nullable();      // question | interest | praise | complaint | spam | other
            $table->text('draft')->nullable();
            $table->string('status', 12)->default('drafting'); // drafting | awaiting | sensitive | sent | skipped | ignored | expired | failed
            $table->string('reason', 200)->nullable();
            $table->string('reply_ref', 200)->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'connection_id', 'kind', 'external_id'], 'studio_replies_once');
            $table->index(['tenant_id', 'user_id', 'status']);
        });
        $cell->create('studio_reply_settings', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->boolean('on')->default(false);
            $table->string('mode', 10)->default('approve'); // approve | auto (thanks and simple questions only)
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('studio_reply_settings');
        Schema::connection(Connections::cell())->dropIfExists('studio_replies');
    }
};
