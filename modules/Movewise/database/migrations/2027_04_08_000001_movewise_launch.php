<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Movewise MW8 (specs/movewise/08): launch hardening. Each state's page, place and sitemap queries filter listings by
 * state and status; this index keeps them fast at scale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::listings())->table('listings', function (Blueprint $table): void {
            $table->index(['state_or_province', 'standard_status', 'internet_display_allowed'], 'listings_state_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::listings())->table('listings', fn (Blueprint $table) => $table->dropIndex('listings_state_status_idx'));
    }
};
