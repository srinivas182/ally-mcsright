<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('cells', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name', 60)->unique();
            $table->string('region', 30);
            $table->string('status', 20)->default('active')->index();
            $table->string('db_connection', 60);
            $table->unsignedInteger('capacity_tenants')->default(2000);
            $table->unsignedInteger('capacity_agents')->default(50000);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('cells');
    }
};
