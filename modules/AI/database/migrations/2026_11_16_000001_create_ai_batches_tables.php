<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Overnight batch work (specs/ai/01). Results are stored encrypted only until collected and are
 * purged after 7 days at most; the ai_requests log still never stores prompts or answers.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::cell();
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->create('ai_batches', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('feature_key', 80);
            $table->string('provider_key', 40);
            $table->string('model_id', 120);
            $table->string('mode', 20); // provider_batch | immediate
            $table->string('external_id', 120)->nullable();
            $table->string('status', 20); // submitted | completed | failed
            $table->unsignedInteger('item_count');
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->dateTime('submitted_at');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'ai_batches_tenant_status_idx');
        });

        $schema->create('ai_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('batch_id')->constrained('ai_batches')->cascadeOnDelete();
            $table->string('idempotency_key', 191);
            $table->string('prompt_key', 120);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending | ok | blocked | failed
            $table->text('permitted')->nullable();
            $table->text('result')->nullable();
            $table->dateTime('purge_after');
            $table->timestamps();

            $table->unique(['batch_id', 'idempotency_key'], 'ai_batch_items_batch_key_unq');
            $table->index(['tenant_id', 'purge_after'], 'ai_batch_items_purge_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('ai_batch_items');
        $schema->dropIfExists('ai_batches');
    }
};
