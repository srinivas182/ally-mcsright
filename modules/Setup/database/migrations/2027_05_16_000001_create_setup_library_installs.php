<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** SU3 (specs/setup/03): which ready-made items a brokerage installed, which version, and a fingerprint of what went in. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('setup_library_installs', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('item_key', 60);
            $table->unsignedSmallInteger('version');
            $table->string('local_type', 12);
            $table->unsignedBigInteger('local_id');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('installed_by');
            $table->timestamps();
            $table->unique(['tenant_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('setup_library_installs');
    }
};
