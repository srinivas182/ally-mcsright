<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Which brokerage connection owns an account in another app (a Facebook page, …), so one app-level webhook address
 * can route each event to the right brokerage (specs/connect/10). The latest connection for an account wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->create('connect_routes', function (Blueprint $table): void {
            $table->id();
            $table->string('app_key', 40);
            $table->string('external_key', 120);
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('connection_id');
            $table->timestamps();
            $table->unique(['app_key', 'external_key']);
            $table->index(['tenant_id', 'connection_id']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('connect_routes');
    }
};
