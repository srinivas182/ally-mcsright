<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint SA3b (specs/grow/13): YouTube listing tours (landscape, with chapters) beside Reels, and Autopilot Reels
 * for new listings (from the package's Reels only, unless credits for extra posts are allowed).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('studio_videos', function (Blueprint $table): void {
            $table->string('kind', 6)->default('reel')->after('user_id'); // reel | tour
            $table->json('chapters')->nullable()->after('seconds'); // tours: [{at, title}]
            $table->unsignedBigInteger('design_id')->nullable()->change(); // a tour is made from the listing itself
        });
        $cell->table('studio_queue', function (Blueprint $table): void {
            $table->unsignedBigInteger('video_id')->nullable()->after('design_id');
        });
        $cell->table('studio_autopilot', function (Blueprint $table): void {
            $table->boolean('auto_reels')->default(true)->after('auto_enhance');
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('studio_autopilot', fn (Blueprint $table) => $table->dropColumn('auto_reels'));
        $cell->table('studio_queue', fn (Blueprint $table) => $table->dropColumn('video_id'));
        $cell->table('studio_videos', fn (Blueprint $table) => $table->dropColumn(['kind', 'chapters']));
    }
};
