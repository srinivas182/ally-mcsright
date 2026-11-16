<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Sprint SA1f (specs/connect/19): a post can carry a carousel (2 to 10 images, in order). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('ads_social_posts', function (Blueprint $table): void {
            $table->json('images')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('ads_social_posts', function (Blueprint $table): void {
            $table->dropColumn('images');
        });
    }
};
