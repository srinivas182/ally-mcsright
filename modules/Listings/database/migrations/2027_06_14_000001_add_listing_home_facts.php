<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * IDX10: home facts from the MLS so plain-words search can filter on them ("with a pool", "2 car garage",
 * "waterfront", "new construction") instead of listing them back. Null when the MLS does not say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::listings())->table('listings', function (Blueprint $table): void {
            $table->boolean('pool')->nullable();
            $table->unsignedTinyInteger('garage_spaces')->nullable();
            $table->boolean('waterfront')->nullable();
            $table->boolean('new_construction')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::listings())->table('listings', function (Blueprint $table): void {
            $table->dropColumn(['pool', 'garage_spaces', 'waterfront', 'new_construction']);
        });
    }
};
