<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint SA5a (specs/grow/13): AI photo enhancement for Ally Studio. Each enhanced listing photo (and its virtual
 * twilight) is kept beside the MLS original, which is never changed. Autopilot can enhance new listings within the
 * package's allowance and keeps posting inside the package unless the person allows credits for extra posts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('studio_photos', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->char('listing_ulid', 26);
            $table->unsignedBigInteger('media_id');
            $table->string('kind', 10); // enhanced | twilight
            $table->string('status', 12)->default('queued'); // queued | processing | ready | failed
            $table->string('provider_ref', 100)->nullable();
            $table->string('path', 255)->nullable();
            $table->boolean('upscaled')->default(false);
            $table->string('paid', 10)->nullable(); // allowance | credits
            $table->string('charge_ref', 40)->nullable(); // what it was counted under, to give it back if it fails
            $table->string('error', 300)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'listing_ulid', 'media_id', 'kind']);
            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
        $cell->table('studio_autopilot', function (Blueprint $table): void {
            $table->boolean('auto_enhance')->default(true)->after('learn_voice'); // enhance new listings' best photos, within the allowance only
            $table->boolean('extra_credits')->default(false)->after('auto_enhance'); // keep posting past the package with credits
        });

        $now = now();
        DB::connection(Connections::control())->table('cost_catalog')->where('key', 'studio.enhance')
            ->update(['note' => 'Autoenhance.ai per photo (HDR, colour, sky, lawn; virtual twilight is a second photo). Enter your real cost.', 'updated_at' => $now]);
        DB::connection(Connections::control())->table('cost_catalog')->insertOrIgnore([
            'key' => 'studio.upscale', 'label' => 'Ally Studio photo upscaling', 'unit' => 'photo', 'kind' => 'usage', 'cost_microcents' => 300_000, 'free_units_monthly' => 0,
            'source_url' => null, 'note' => 'Replicate (Real-ESRGAN 2x) per enhanced photo, when switched on. Enter your real cost.', 'checked_at' => null, 'updated_by' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::connection(Connections::control())->table('cost_catalog')->where('key', 'studio.upscale')->delete();
        Schema::connection(Connections::cell())->table('studio_autopilot', function (Blueprint $table): void {
            $table->dropColumn(['auto_enhance', 'extra_credits']);
        });
        Schema::connection(Connections::cell())->dropIfExists('studio_photos');
    }
};
