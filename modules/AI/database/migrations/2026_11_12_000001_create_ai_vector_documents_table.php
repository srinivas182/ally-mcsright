<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Searchable chunks of tenant records for AI retrieval (specs/ai/01). Every row carries the
 * access metadata the retriever filters on. In production the vectors live in OpenSearch k-NN;
 * this table backs the database implementation and holds the source of truth for re-indexing.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::cell();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('ai_vector_documents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('source_type', 60);
            $table->string('source_id', 40);
            $table->unsignedSmallInteger('chunk_index');
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->json('team_ids');
            $table->unsignedBigInteger('office_id')->nullable();
            $table->boolean('sensitive')->default(false);
            $table->string('model_id', 120);
            $table->unsignedSmallInteger('dimensions');
            $table->binary('embedding');
            $table->text('content');
            $table->timestamps();

            $table->index(['tenant_id', 'source_type', 'source_id']);
            $table->index(['tenant_id', 'model_id', 'owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('ai_vector_documents');
    }
};
