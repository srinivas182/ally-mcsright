<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * AI contact briefs (CRM additions, Sprint 8D). Content is encrypted at rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('crm_contact_briefs', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('contact_id')->unique()->constrained('crm_contacts')->cascadeOnDelete();
            $table->text('content');
            $table->boolean('stale')->default(false);
            $table->string('ai_request_ulid', 26)->nullable();
            $table->unsignedInteger('helpful')->default(0);
            $table->unsignedInteger('not_helpful')->default(0);
            $table->dateTime('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('crm_contact_briefs');
    }
};
