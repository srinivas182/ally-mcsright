<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint C8a (specs/connect/20): reviews from Google Business Profile and RealSatisfied (Zillow and Testimonial Tree
 * when their partner access is approved), kept per agent (or the brokerage) with their source and date. Shown on the
 * websites and, later, on Movewise (MW7). Never edited; hidden only for abuse, personal data or proven falsehood, with
 * the reason kept (FTC rule on consumer reviews).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('site_reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id')->nullable();     // the agent; null: the brokerage
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->string('source', 20);                          // google | realsatisfied | zillow | testimonial_tree
            $table->string('external_id', 190);
            $table->string('author', 120)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();     // 1–5; null when the source has none
            $table->text('text')->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamp('reviewed_at');
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->boolean('hidden')->default(false);
            $table->string('hidden_reason', 20)->nullable();       // abuse | personal_data | false
            $table->unsignedBigInteger('hidden_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'source', 'external_id']);
            $table->index(['tenant_id', 'user_id', 'hidden']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('site_reviews');
    }
};
