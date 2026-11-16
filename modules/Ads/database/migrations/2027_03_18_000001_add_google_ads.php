<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint C7d-2 (specs/ads/05): listing ads on Google search and YouTube. `channel` (already 'meta' by default) says
 * where an ad runs; Google's resource names, the search keywords and the YouTube video are kept with the ad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('ads_campaigns', function (Blueprint $table): void {
            $table->json('google_ids')->nullable()->after('meta_ids');
            $table->json('keywords')->nullable()->after('body');
            $table->string('youtube_video_id', 20)->nullable()->after('video_url');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('ads_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['google_ids', 'keywords', 'youtube_video_id']);
        });
    }
};
