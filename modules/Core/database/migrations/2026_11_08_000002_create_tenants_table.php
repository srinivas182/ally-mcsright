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
        Schema::connection($this->getConnection())->create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('type', 20)->index();
            $table->string('name', 150);
            $table->string('slug', 40)->unique();
            $table->string('status', 20)->default('provisioning')->index();
            $table->foreignId('cell_id')->constrained('cells')->restrictOnDelete();
            $table->string('timezone', 40)->default('America/Chicago');
            $table->string('locale', 5)->default('en');
            $table->char('state_code', 2)->nullable();
            $table->string('suspended_reason')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('purge_after')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('tenants');
    }
};
