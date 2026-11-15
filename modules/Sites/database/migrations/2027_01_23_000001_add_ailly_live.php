<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint 17D-4 (specs/websites/14): an agent taking over a live AIlly chat, which A/B variant a chat saw, and the
 * agent who wrote a message; the AI first responder continuing a website chat by text.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());

        $cell->table('ailly_conversations', function (Blueprint $table): void {
            $table->unsignedBigInteger('taken_by')->nullable()->after('hot_alerted_at'); // the agent talking now (AIlly is quiet)
            $table->timestamp('taken_at')->nullable()->after('taken_by');
            $table->timestamp('agent_alerted_at')->nullable()->after('taken_at'); // last "they wrote" alert to that agent
            $table->char('variant', 1)->nullable()->after('agent_alerted_at'); // a or b while an A/B test runs
            $table->index(['tenant_id', 'variant', 'created_at']);
        });

        $cell->table('ailly_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->after('role'); // the agent, for role "agent"
        });

        // The AI first responder picking up a website chat by text, in the assistant's own name.
        $cell->table('crm_ai_threads', function (Blueprint $table): void {
            $table->string('origin', 20)->nullable()->after('status'); // website_chat
            $table->string('assistant_name', 40)->nullable()->after('origin');
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('crm_ai_threads', fn (Blueprint $table) => $table->dropColumn(['origin', 'assistant_name']));
        $cell->table('ailly_messages', fn (Blueprint $table) => $table->dropColumn('user_id'));
        $cell->table('ailly_conversations', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'variant', 'created_at']);
            $table->dropColumn(['taken_by', 'taken_at', 'agent_alerted_at', 'variant']);
        });
    }
};
