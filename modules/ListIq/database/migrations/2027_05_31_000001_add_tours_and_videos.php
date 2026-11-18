<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * MD1 (specs/listings/02, 03): a 3D or 360-degree tour (Matterport, iGUIDE, CubiCasa, Kuula, Zillow 3D Home or a
 * video) on the listing, and a listing video made from the List-IQ photos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('listiq_listings', function (Blueprint $table): void {
            $table->string('tour_url', 500)->nullable();
            $table->string('tour_provider', 20)->nullable();
            $table->string('video_status', 12)->nullable(); // making, ready, failed
            $table->string('video_path', 300)->nullable();
            $table->string('video_poster_path', 300)->nullable();
            $table->string('video_error', 200)->nullable();
            $table->string('video_charge_ref', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('listiq_listings', fn (Blueprint $table) => $table->dropColumn(['tour_url', 'tour_provider', 'video_status',
            'video_path', 'video_poster_path', 'video_error', 'video_charge_ref']));
    }
};
