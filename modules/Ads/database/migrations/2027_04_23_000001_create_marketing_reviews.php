<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** MK3 (specs/ads/06): marketing pieces sent to the broker for approval, and whether approval is required. */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('ads_reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('kind', 12);
            $table->string('title', 120);
            $table->text('body');
            $table->string('mls_number', 30)->nullable();
            $table->string('file_path', 300)->nullable();
            $table->string('file_mime', 40)->nullable();
            $table->json('findings');
            $table->string('status', 12)->default('pending'); // pending, approved, changes
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->string('note', 1000)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'user_id']);
        });
        $cell->create('ads_review_settings', function (Blueprint $table): void {
            $table->tenant();
            $table->boolean('approval_required')->default(false);
            $table->timestamps();
            $table->primary('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('ads_review_settings');
        Schema::connection(Connections::cell())->dropIfExists('ads_reviews');
    }
};
