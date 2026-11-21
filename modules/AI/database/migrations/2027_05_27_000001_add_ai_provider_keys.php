<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * AI1: each AI provider's API key is saved in HQ › AI console (encrypted, never shown again) instead of the server's
 * .env file. A key in .env still works as a fallback, so servers set up the old way keep running.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('ai_providers', function (Blueprint $table): void {
            $table->text('api_key')->nullable();
            $table->string('api_key_hint', 8)->nullable();
            $table->timestamp('key_saved_at')->nullable();
            $table->timestamp('key_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('ai_providers', function (Blueprint $table): void {
            $table->dropColumn(['api_key', 'api_key_hint', 'key_saved_at', 'key_checked_at']);
        });
    }
};
