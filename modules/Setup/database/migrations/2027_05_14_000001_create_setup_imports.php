<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** SU1 (specs/setup/01): files brought in from the old system, and what each one added (so it can be undone). */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('setup_imports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('kind', 8); // people, agents, deals
            $table->string('source', 30)->nullable();
            $table->string('label', 80);
            $table->string('file_path', 191);
            $table->string('filename', 200);
            $table->json('headers');
            $table->json('mapping');
            $table->unsignedInteger('total_rows')->default(0);
            $table->string('status', 10)->default('reviewing'); // reviewing, importing, done, undone, failed
            $table->json('summary')->nullable();
            $table->boolean('ai_matched')->default(false);
            $table->unsignedBigInteger('crm_import_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('undone_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
        $cell->create('setup_import_items', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('import_id');
            $table->string('item_type', 12); // deal, invitation
            $table->unsignedBigInteger('item_id');
            $table->index(['import_id', 'item_type']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('setup_import_items');
        $cell->dropIfExists('setup_imports');
    }
};
