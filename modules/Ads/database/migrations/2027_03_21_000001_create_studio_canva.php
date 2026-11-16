<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint C7d-3 (specs/grow/13): open a design in Canva with the brand kit and listing photos, and bring the finished
 * design back for posts and ads. Canva sends everyone back to one address, so the control table says which
 * brokerage website each Canva session returns to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('studio_canva', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('connection_id');
            $table->string('listing_ulid', 26)->nullable();
            $table->unsignedBigInteger('source_design_id')->nullable();
            $table->string('design_ref', 64)->nullable();     // Canva's design id
            $table->text('edit_url')->nullable();             // only works for the person who made it, for 30 days
            $table->string('status', 10)->default('preparing'); // preparing | editing | importing | ready | failed
            $table->unsignedBigInteger('result_design_id')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id']);
        });
        Schema::connection(Connections::control())->create('canva_returns', function (Blueprint $table): void {
            $table->char('state', 26)->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('host', 190);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('canva_returns');
        Schema::connection(Connections::cell())->dropIfExists('studio_canva');
    }
};
