<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * AQ2 (specs/ai/08): per feature, a lighter model for short requests, and how long an identical answer may be
 * reused (0 = never). Set by HQ in the AI gateway.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('ai_feature_routes', function (Blueprint $table): void {
            $table->unsignedBigInteger('light_model_id')->nullable();
            $table->unsignedInteger('light_max_chars')->default(0);
            $table->unsignedSmallInteger('cache_minutes')->default(0);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('ai_feature_routes', function (Blueprint $table): void {
            $table->dropColumn(['light_model_id', 'light_max_chars', 'cache_minutes']);
        });
    }
};
