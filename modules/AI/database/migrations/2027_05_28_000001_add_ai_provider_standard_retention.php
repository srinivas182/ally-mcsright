<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * AI2: HQ may switch a provider on with its standard retention (kept for a short time for abuse checks, never used for
 * training) once HQ has knowingly accepted it, recorded here; zero retention stays the stronger, preferred choice.
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
            $table->boolean('standard_retention_accepted')->default(false)->after('zero_retention_confirmed');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('ai_providers', function (Blueprint $table): void {
            $table->dropColumn('standard_retention_accepted');
        });
    }
};
