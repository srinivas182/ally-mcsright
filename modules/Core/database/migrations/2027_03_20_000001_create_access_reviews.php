<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Quarterly access review (specs/core/03, R1): owners confirm or remove every role someone holds; the signed-off
 * review is the SOC 2 attestation.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('access_reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('quarter', 7); // 2026-Q4
            $table->unsignedBigInteger('started_by');
            $table->dateTime('started_at');
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->dateTime('reminded_at')->nullable();
            $table->unique(['tenant_id', 'quarter'], 'access_reviews_quarter_unq');
        });

        $schema->create('access_review_items', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('review_id')->constrained('access_reviews')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->string('role_name', 80);
            $table->boolean('risky')->default(false);
            $table->dateTime('expires_at')->nullable();
            $table->string('decision', 10)->nullable(); // keep, remove
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->unique(['review_id', 'user_id', 'role_id'], 'access_review_items_unq');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->dropIfExists('access_review_items');
        $schema->dropIfExists('access_reviews');
    }
};
