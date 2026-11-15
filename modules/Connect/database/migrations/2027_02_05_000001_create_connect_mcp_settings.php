<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** The brokerage owner's switch for AI assistant access (MCP, specs/connect/03). On unless the owner turns it off. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('connect_mcp_settings', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->boolean('enabled')->default(true);
            $table->boolean('allow_writes')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('connect_mcp_settings');
    }
};
