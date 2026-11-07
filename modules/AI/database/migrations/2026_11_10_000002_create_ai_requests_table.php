<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * One row per attempt. Prompts and outputs are deliberately NOT stored (specs/ai/01).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::cell();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('ai_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('feature_key', 80);
            $table->string('prompt_key', 120);
            $table->string('prompt_version', 20);
            $table->string('provider_key', 40)->nullable();
            $table->string('model_id', 120)->nullable();
            $table->unsignedTinyInteger('attempt');
            $table->string('status', 20);
            $table->string('failure_reason', 255)->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cost_microcents')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->json('source_record_ids');
            $table->string('output_policy_result', 20)->nullable();
            $table->string('idempotency_key', 191);
            $table->dateTime('created_at', 6);

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'feature_key', 'created_at']);
            $table->index(['tenant_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('ai_requests');
    }
};
