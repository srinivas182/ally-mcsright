<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * HC1a: the free Brokerage Health Check (specs/billing/06). A broker's answers, the tools they pay for, the result of
 * the website check and the report RightAlly worked out from them. The report is opened with a private link (only its
 * hash is kept) and the broker can delete it at any time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->create('health_checks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->char('token_hash', 64)->unique();
            $table->string('brokerage', 160);
            $table->string('contact_name', 120);
            $table->string('email', 190);
            $table->char('state', 2)->nullable();
            $table->unsignedInteger('agents');
            $table->json('answers');
            $table->json('tools');
            $table->string('website_url', 255)->nullable();
            $table->json('website')->nullable(); // the website check's result, once it has run
            $table->char('brand_color', 7)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->json('scores');
            $table->json('savings');
            $table->boolean('contact_ok')->default(false);
            $table->dateTime('viewed_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('health_checks');
    }
};
