<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** API1 (specs/api/01): personal API keys (cell), partner apps (control) and each brokerage's allowed apps (cell). */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('name', 80);
            $table->char('key_hash', 64)->unique();
            $table->char('last4', 4);
            $table->json('scopes');
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id']);
        });
        $cell->create('api_partner_grants', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('client_id', 100);
            $table->unsignedBigInteger('allowed_by');
            $table->timestamps();
            $table->unique(['tenant_id', 'client_id']);
        });
        Schema::connection(Connections::control())->create('api_partner_apps', function (Blueprint $table): void {
            $table->id();
            $table->string('client_id', 100)->unique(); // the OAuth client
            $table->string('name', 80);
            $table->string('company', 120);
            $table->string('website', 200);
            $table->string('description', 500);
            $table->json('scopes');
            $table->boolean('verified')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('api_partner_apps');
        Schema::connection(Connections::cell())->dropIfExists('api_partner_grants');
        Schema::connection(Connections::cell())->dropIfExists('api_keys');
    }
};
