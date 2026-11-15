<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * AI apps registered at the shared AI assistant address (specs/connect/03, M2b), and each one's own registration
 * inside every brokerage it has been used with (tokens are always issued by the brokerage, with its own data).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::control());
        $schema->create('mcp_central_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->json('redirect_uris');
            $table->timestamps();
        });
        $schema->create('mcp_central_links', function (Blueprint $table): void {
            $table->id();
            $table->uuid('central_client_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('local_client_id', 64);
            $table->timestamps();
            $table->unique(['central_client_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::control());
        $schema->dropIfExists('mcp_central_links');
        $schema->dropIfExists('mcp_central_clients');
    }
};
