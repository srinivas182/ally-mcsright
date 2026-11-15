<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Ally Studio SA2b (specs/grow/13): the brokerage's rules (broker review before agents' posts go out, a caption
 * disclaimer), learning the agent's voice from their own posts (with their OK), and planned posts that need the
 * client's OK (closings).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('studio_rules', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->boolean('require_review')->default(false);
            $table->string('caption_disclaimer', 300)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
        });
        $cell->table('studio_autopilot', function (Blueprint $table): void {
            $table->boolean('learn_voice')->default(false)->after('moments');
            $table->json('voice')->nullable()->after('learn_voice');
            $table->dateTime('voice_at')->nullable()->after('voice');
        });
        $cell->table('studio_queue', function (Blueprint $table): void {
            $table->boolean('needs_client_ok')->default(false)->after('reason');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('needs_client_ok');
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('studio_queue', fn (Blueprint $table) => $table->dropColumn(['needs_client_ok', 'reviewed_by']));
        $cell->table('studio_autopilot', fn (Blueprint $table) => $table->dropColumn(['learn_voice', 'voice', 'voice_at']));
        $cell->dropIfExists('studio_rules');
    }
};
