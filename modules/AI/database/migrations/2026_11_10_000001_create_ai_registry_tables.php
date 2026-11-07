<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Platform-level AI model registry (control plane), managed by RightAlly HQ (specs/ai/01).
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->create('ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->string('adapter', 40);
            $table->string('base_url', 255);
            $table->string('credential_config_key', 120)->nullable();
            $table->boolean('zero_retention_confirmed')->default(false);
            $table->boolean('no_training_confirmed')->default(false);
            $table->date('dpa_signed_at')->nullable();
            $table->string('status', 20)->default('disabled');
            $table->timestamps();
        });

        $schema->create('ai_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->restrictOnDelete();
            $table->string('model_id', 120);
            $table->string('display_name', 120);
            $table->json('capabilities');
            $table->unsignedInteger('context_window')->default(0);
            $table->unsignedInteger('input_cost_per_mtok_cents')->default(0);
            $table->unsignedInteger('output_cost_per_mtok_cents')->default(0);
            $table->unsignedInteger('cache_read_cost_per_mtok_cents')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['provider_id', 'model_id']);
        });

        $schema->create('ai_feature_routes', function (Blueprint $table): void {
            $table->id();
            $table->string('feature_key', 80)->unique();
            $table->foreignId('primary_model_id')->constrained('ai_models')->restrictOnDelete();
            $table->json('fallback_model_ids');
            $table->string('temperature', 8)->default('0.2');
            $table->unsignedInteger('max_output_tokens')->default(1024);
            $table->unsignedInteger('timeout_seconds')->default(30);
            $table->boolean('locked')->default(false);
            $table->string('credit_action_key', 80);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('ai_feature_routes');
        $schema->dropIfExists('ai_models');
        $schema->dropIfExists('ai_providers');
    }
};
