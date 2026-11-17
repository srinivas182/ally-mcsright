<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** CH1 (specs/team/01): team chat in the brokerage's cell; support requests in the control database for HQ. */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('team_channels', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->ulid('ulid')->unique();
            $table->string('kind', 16); // everyone, announcements, group, direct
            $table->string('name', 80);
            $table->string('direct_key', 40)->nullable(); // "12:34" for a direct chat between users 12 and 34
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'direct_key']);
            $table->index(['tenant_id', 'kind']);
        });
        $cell->create('team_channel_members', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('channel_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('last_read_id')->default(0);
            $table->boolean('muted')->default(false);
            $table->timestamps();
            $table->unique(['channel_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });
        $cell->create('team_messages', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('channel_id');
            $table->unsignedBigInteger('user_id');
            $table->text('body'); // encrypted
            $table->json('mentions')->nullable();
            $table->dateTime('edited_at')->nullable();
            $table->dateTime('hidden_at')->nullable();
            $table->unsignedBigInteger('hidden_by')->nullable();
            $table->timestamps();
            $table->index(['channel_id', 'id']);
        });
        $cell->create('team_help_feedback', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('article', 80);
            $table->unsignedBigInteger('user_id');
            $table->boolean('helpful');
            $table->timestamps();
            $table->unique(['tenant_id', 'article', 'user_id']);
        });
        Schema::connection(Connections::control())->create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('user_name', 160);
            $table->string('user_email', 254);
            $table->string('topic', 30);
            $table->string('subject', 160);
            $table->text('body'); // encrypted
            $table->string('page', 200)->nullable();
            $table->string('status', 12)->default('open'); // open, answered, closed
            $table->text('reply')->nullable(); // encrypted
            $table->unsignedBigInteger('replied_by')->nullable();
            $table->dateTime('replied_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('support_tickets');
        $cell = Schema::connection(Connections::cell());
        foreach (['team_help_feedback', 'team_messages', 'team_channel_members', 'team_channels'] as $t) {
            $cell->dropIfExists($t);
        }
    }
};
