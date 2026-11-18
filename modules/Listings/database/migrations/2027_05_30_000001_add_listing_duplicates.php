<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * SR1 (specs/websites/02): the same home listed in two MLSs is shown once. Each listing keeps a normalised address
 * key; a copy points at the listing that is shown (the one with the most photos, then the latest update).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::listings())->table('listings', function (Blueprint $table): void {
            $table->string('address_key', 140)->nullable();
            $table->unsignedBigInteger('duplicate_of_id')->nullable();
            $table->unsignedBigInteger('duplicate_of_source_id')->nullable();
            $table->index('address_key', 'listings_address_key_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::listings())->table('listings', function (Blueprint $table): void {
            $table->dropIndex('listings_address_key_idx');
            $table->dropColumn(['address_key', 'duplicate_of_id', 'duplicate_of_source_id']);
        });
    }
};
