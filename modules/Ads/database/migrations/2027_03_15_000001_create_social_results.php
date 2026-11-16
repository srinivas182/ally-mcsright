<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint SA4 (specs/grow/13): results. Each posted place's numbers (read daily for 30 days from the networks that
 * share them), which Studio design or video a post came from (so results show by style, format and time), and the
 * leads that arrived through its link (its utm_campaign).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('ads_social_metrics', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('target_id')->unique();
            $table->unsignedInteger('reach')->default(0); // people who saw it (or unique views)
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('likes')->default(0);
            $table->unsignedInteger('comments')->default(0);
            $table->unsignedInteger('shares')->default(0);
            $table->unsignedInteger('saves')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->dateTime('fetched_at');
            $table->string('error', 200)->nullable();
            $table->timestamps();
        });
        $cell->table('ads_social_posts', function (Blueprint $table): void {
            $table->unsignedBigInteger('studio_design_id')->nullable()->after('video_url');
            $table->unsignedBigInteger('studio_video_id')->nullable()->after('studio_design_id');
            $table->string('format', 10)->nullable()->after('studio_video_id'); // photo | carousel | reel | tour | link
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('ads_social_posts', fn (Blueprint $table) => $table->dropColumn(['studio_design_id', 'studio_video_id', 'format']));
        $cell->dropIfExists('ads_social_metrics');
    }
};
