<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Ally Studio designs (specs/grow/13, SA1b): each design made for a person, in three sizes, with the art director's
 * checks; counted against the allowance once, when it is first downloaded or posted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('studio_designs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('batch', 26); // the three designs made together
            $table->string('moment', 20);
            $table->char('listing_ulid', 26)->nullable();
            $table->string('collection', 20);
            $table->string('variant', 10); // full | split | frame | type
            $table->json('content');
            $table->json('files')->nullable(); // size => storage path
            $table->json('checks')->nullable(); // what the art director found
            $table->string('status', 10)->default('rendering'); // rendering | ready | failed
            $table->dateTime('used_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('studio_designs');
    }
};
