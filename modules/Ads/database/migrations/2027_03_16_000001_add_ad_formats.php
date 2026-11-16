<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint C4c (specs/ads/03): Meta ads made from Ally Studio — a carousel or a Reel as the ad, a promoted Facebook
 * post — and instant-form lead ads whose leads go straight into the CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('ads_campaigns', function (Blueprint $table): void {
            $table->string('format', 10)->default('photo')->after('body'); // photo | carousel | video | post
            $table->string('goal', 10)->default('website')->after('format'); // website | form
            $table->json('images')->nullable()->after('image_url'); // a carousel, in order
            $table->string('video_url', 500)->nullable()->after('images');
            $table->string('object_story_id', 120)->nullable()->after('video_url'); // a Page post to promote
            $table->unsignedBigInteger('studio_design_id')->nullable()->after('object_story_id');
            $table->unsignedBigInteger('studio_video_id')->nullable()->after('studio_design_id');
            $table->unsignedBigInteger('social_post_id')->nullable()->after('studio_video_id');
            $table->string('privacy_url', 300)->nullable()->after('social_post_id'); // instant forms link the brokerage's privacy policy
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('ads_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['format', 'goal', 'images', 'video_url', 'object_story_id', 'studio_design_id', 'studio_video_id', 'social_post_id', 'privacy_url']);
        });
    }
};
