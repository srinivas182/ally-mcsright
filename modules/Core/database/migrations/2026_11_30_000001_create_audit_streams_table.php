<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Audit streaming destinations set up by the brokerage (specs/core/04, addition 1, Sprint 7A-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('audit_streams', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('type', 10); // webhook, s3, datadog, splunk
            $table->string('name', 80);
            $table->text('config'); // encrypted
            $table->string('status', 10)->default('active'); // active, paused
            $table->unsignedBigInteger('cursor_seq')->default(0);
            $table->dateTime('last_success_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->dateTime('failing_since')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('audit_streams');
    }
};
