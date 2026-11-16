<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Deal-IQ DQ1–DQ2 (specs/ai/02): property type and financing (checklists adapt to them), single-purpose upload links
 * for the other side, and the assistant's proposals (shown, then confirmed).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('tx_deals', function (Blueprint $table): void {
            $table->string('property_type', 20)->nullable(); // single_family, condo, townhome, multi_family, land, manufactured
            $table->string('financing', 12)->nullable(); // cash, conventional, fha, va, usda, other
            $table->string('setup_status', 12)->nullable(); // reading, review (intake waiting for the agent to confirm)
        });
        $schema->create('tx_upload_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('what', 160);
            $table->string('role', 20);
            $table->string('name', 160);
            $table->string('email', 254);
            $table->string('token_hash', 64)->unique();
            $table->unsignedBigInteger('requested_by');
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->timestamps();
        });
        $schema->create('tx_assistant_turns', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->foreignId('deal_id')->constrained('tx_deals')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('said', 1000);
            $table->text('answer')->nullable();
            $table->json('actions');
            $table->string('status', 10); // proposed, done, dismissed
            $table->json('results')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->dropIfExists('tx_assistant_turns');
        $schema->dropIfExists('tx_upload_requests');
        $schema->table('tx_deals', function (Blueprint $table): void {
            $table->dropColumn(['property_type', 'financing', 'setup_status']);
        });
    }
};
