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
        Schema::connection($this->getConnection())->create('tenant_domains', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('host', 253)->unique();
            $table->string('kind', 20);
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('verification_status', 20)->default('pending');
            $table->string('ssl_status', 20)->default('pending');
            $table->string('provider_hostname_id', 100)->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('tenant_domains');
    }
};
