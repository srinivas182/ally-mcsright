<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Ally Studio (specs/grow/13, SA1a): HQ's monthly allowances for each Studio package, what each Studio item costs
 * RightAlly (in the cost list, so margins show), each brand kit, and each person's monthly usage.
 */
return new class extends Migration
{
    public function up(): void
    {
        $control = Schema::connection(Connections::control());
        $control->create('studio_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('app_key', 40)->unique(); // studio_essentials | studio_luxury | studio_brokerage
            $table->unsignedInteger('posts');
            $table->unsignedInteger('reels');
            $table->unsignedInteger('tours');
            $table->unsignedInteger('enhance');
            $table->unsignedInteger('voice');
            $table->json('features');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        $now = now();
        $all = ['launch_campaign', 'spanish', 'voice_learning', 'recruiting', 'approval_rules', 'team_report'];
        DB::connection(Connections::control())->table('studio_packages')->insert([
            ['app_key' => 'studio_essentials', 'posts' => 30, 'reels' => 4, 'tours' => 0, 'enhance' => 0, 'voice' => 0, 'features' => json_encode(['voice_learning']),
                'created_at' => $now, 'updated_at' => $now],
            ['app_key' => 'studio_luxury', 'posts' => 60, 'reels' => 12, 'tours' => 2, 'enhance' => 10, 'voice' => 4,
                'features' => json_encode(['launch_campaign', 'spanish', 'voice_learning']), 'created_at' => $now, 'updated_at' => $now],
            ['app_key' => 'studio_brokerage', 'posts' => 60, 'reels' => 12, 'tours' => 2, 'enhance' => 10, 'voice' => 4, 'features' => json_encode($all),
                'created_at' => $now, 'updated_at' => $now],
        ]);
        $row = fn (string $key, string $label, string $unit, int $micro, string $note): array => ['key' => $key, 'label' => $label, 'unit' => $unit, 'kind' => 'usage',
            'cost_microcents' => $micro, 'free_units_monthly' => 0, 'source_url' => null, 'note' => $note, 'checked_at' => null, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now];
        DB::connection(Connections::control())->table('cost_catalog')->insert([
            $row('studio.post', 'Ally Studio designed post', 'post', 3_000_000, 'Caption (AI), three sizes rendered, art director check. Enter your real cost.'),
            $row('studio.reel', 'Ally Studio Reel or Short', 'video', 15_000_000, 'Video rendering, music licence share, caption. Enter your real cost.'),
            $row('studio.tour', 'Ally Studio YouTube listing tour', 'video', 30_000_000, 'Longer landscape video with chapters. Enter your real cost.'),
            $row('studio.enhance', 'Ally Studio photo enhancement', 'photo', 20_000_000, 'Virtual twilight or sky replacement vendor. Enter your real cost.'),
            $row('studio.voice', 'Ally Studio voice narration', 'narration', 10_000_000, 'Voice vendor, per narration. Enter your real cost.'),
            $row('studio.cutout', 'Headshot background removal', 'photo', 20_000_000, 'remove.bg or equal, per headshot.'),
        ]);

        $cell = Schema::connection(Connections::cell());
        $cell->create('studio_brand_kits', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id')->nullable(); // null: the brokerage's kit
            $table->string('headshot_path', 255)->nullable();
            $table->string('cutout_path', 255)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('logo_dark_path', 255)->nullable();
            $table->json('colors')->nullable();   // primary, accent, ink, paper (#rrggbb)
            $table->json('fonts')->nullable();    // heading, body (from the curated list)
            $table->json('details')->nullable();  // display_name, title, licence_line, phone, tagline, handles, disclaimer
            $table->json('languages')->nullable();
            $table->string('collection', 30)->nullable();
            $table->boolean('brand_lock')->default(false); // brokerage kit: agents may not change collection, colours or fonts
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
        $cell->create('studio_usage', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->date('period'); // start of the paid month
            $table->string('item', 10); // post | reel | tour | enhance | voice
            $table->unsignedInteger('used')->default(0);
            $table->unsignedInteger('extra')->default(0); // paid with credits beyond the allowance
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'period', 'item']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('studio_usage');
        $cell->dropIfExists('studio_brand_kits');
        DB::connection(Connections::control())->table('cost_catalog')->where('key', 'like', 'studio.%')->delete();
        Schema::connection(Connections::control())->dropIfExists('studio_packages');
    }
};
