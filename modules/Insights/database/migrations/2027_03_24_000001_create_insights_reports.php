<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Saved and scheduled reports, and the anomaly log (specs/insights/01, K2). */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->create('insights_reports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('owner_user_id');
            $table->string('name', 120);
            $table->json('definition');
            $table->boolean('shared')->default(false); // everyone who can open reports; each sees only what they may
            $table->string('schedule', 10)->nullable(); // weekly, monthly
            $table->dateTime('last_sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('insights_anomalies', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('key', 120);
            $table->string('text', 300);
            $table->dateTime('found_at');
            $table->index(['tenant_id', 'key', 'found_at'], 'insights_anomalies_key_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->dropIfExists('insights_anomalies');
        $schema->dropIfExists('insights_reports');
    }
};
