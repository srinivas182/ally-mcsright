<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Ally Studio style library (specs/grow/13, SA1d): each kit's finish (the accents: gold, champagne, platinum…),
 * an agent's favourite styles (Autopilot rotates through them) and the styles a brokerage allows when its brand
 * is locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('studio_brand_kits', function (Blueprint $table): void {
            $table->string('finish', 20)->nullable()->after('collection');
            $table->json('favorites')->nullable()->after('finish');
            $table->json('styles')->nullable()->after('favorites'); // brokerage kit: styles agents may use when locked
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('studio_brand_kits', fn (Blueprint $table) => $table->dropColumn(['finish', 'favorites', 'styles']));
    }
};
