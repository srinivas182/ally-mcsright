<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** HQ app registry (specs/connect/00): which apps brokers and agents may use, and each app's RightAlly-level keys. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->create('hq_connect_apps', function (Blueprint $table): void {
            $table->id();
            $table->string('app_key', 40)->unique();
            $table->boolean('enabled')->default(false);
            $table->text('credentials')->nullable(); // encrypted JSON: partner keys, client IDs and secrets
            $table->date('secret_expires_on')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('hq_connect_apps');
    }
};
