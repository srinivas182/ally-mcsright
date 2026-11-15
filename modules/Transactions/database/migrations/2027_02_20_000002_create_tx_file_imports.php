<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Bring over files (specs/connect/12, Sprint C5a-2): a SkySlope or Lone Wolf file export (a ZIP of PDFs by
 * transaction) uploaded once, filed into the imported deals by address. One row per upload, with what was filed
 * and the folders that matched no deal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('tx_file_imports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('filename', 200);
            $table->string('path', 300)->nullable(); // the ZIP, deleted once read
            $table->string('status', 12)->default('queued'); // queued, done, failed
            $table->unsignedInteger('files')->default(0);
            $table->unsignedInteger('filed')->default(0);
            $table->unsignedInteger('deals')->default(0);
            $table->json('unmatched')->nullable();
            $table->string('error', 300)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['tenant_id', 'created_at'], 'tx_file_imports_recent_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('tx_file_imports');
    }
};
