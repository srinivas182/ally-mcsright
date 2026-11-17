<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * LI1, List-IQ (specs/listings/02): a listing being made ready (facts, notes, showing instructions, the checklist,
 * descriptions) and its photos (originals, enhanced and virtually staged versions, each labelled).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('listiq_listings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('owner_user_id');
            $table->unsignedBigInteger('deal_id')->nullable();
            $table->string('street', 150);
            $table->string('city', 80)->nullable();
            $table->char('state', 2)->nullable();
            $table->string('zip', 10)->nullable();
            $table->json('facts')->nullable();       // beds, baths, sqft, year, lot, price, features
            $table->text('notes')->nullable();        // the agent's own words about the home (for the description)
            $table->text('showing')->nullable();      // showing instructions
            $table->json('manual')->nullable();       // checklist items the agent ticks: disclosures, sign
            $table->json('descriptions')->nullable(); // [{style, text}]
            $table->text('description')->nullable();  // the chosen one, as edited
            $table->text('description_es')->nullable();
            $table->unsignedSmallInteger('remarks_limit')->default(1000);
            $table->string('status', 10)->default('preparing'); // preparing, ready
            $table->timestamps();
            $table->index(['tenant_id', 'owner_user_id', 'updated_at']);
        });
        $cell->create('listiq_photos', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('listing_id');
            $table->unsignedBigInteger('parent_id')->nullable(); // the original, for enhanced and staged versions
            $table->string('kind', 9)->default('original');      // original, enhanced, staged
            $table->string('room', 20);
            $table->string('style', 20)->nullable();              // staging style
            $table->string('path', 300)->nullable();
            $table->string('caption', 160)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('use')->default(true);                 // in the listing's photos
            $table->string('status', 10)->default('ready');        // queued, processing, ready, failed
            $table->string('provider_ref', 120)->nullable();
            $table->string('error', 200)->nullable();
            $table->string('charge_ref', 80)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'listing_id', 'position']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('listiq_photos');
        $cell->dropIfExists('listiq_listings');
    }
};
