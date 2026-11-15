<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** The map between another app's ids and ours, per connection (specs/connect/00): syncs never make duplicates. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('connect_external_ids', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('connection_id')->constrained('connect_connections')->cascadeOnDelete(); // disconnecting forgets the map
            $table->string('entity', 30);       // contact, note, task, user
            $table->string('external_id', 120);
            $table->unsignedBigInteger('local_id');
            $table->timestamps();
            $table->unique(['connection_id', 'entity', 'external_id']);
            $table->index(['tenant_id', 'entity', 'local_id']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('connect_external_ids');
    }
};
