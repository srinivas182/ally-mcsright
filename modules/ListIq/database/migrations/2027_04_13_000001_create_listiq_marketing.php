<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * LI2, the listing marketing centre (specs/listings/03): the listing's own page on the brokerage website (once it is
 * in the MLS), QR codes for signs and flyers that count scans and tag leads, open houses with a sign-in kiosk, the
 * floor plan, and the activity that makes the seller's report.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('listiq_listings', function (Blueprint $table): void {
            $table->string('floor_plan_path', 300)->nullable();
            $table->boolean('in_mls')->default(false); // public marketing waits for the MLS (Clear Cooperation)
            $table->unsignedInteger('page_views')->default(0);
        });
        $cell->create('listiq_codes', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('listing_id');
            $table->string('code', 10)->unique();
            $table->string('kind', 10); // sign, flyer, social
            $table->string('label', 80);
            $table->unsignedInteger('scans')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->dateTime('last_scan_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'listing_id']);
        });
        $cell->create('listiq_open_houses', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('listing_id');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('ended_at')->nullable();
            $table->json('visitors')->nullable(); // [{name, contact_id, agent: yes|no|looking, at}]
            $table->timestamps();
            $table->index(['tenant_id', 'listing_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('listiq_open_houses');
        $cell->dropIfExists('listiq_codes');
        $cell->table('listiq_listings', fn (Blueprint $t) => $t->dropColumn(['floor_plan_path', 'in_mls', 'page_views']));
    }
};
