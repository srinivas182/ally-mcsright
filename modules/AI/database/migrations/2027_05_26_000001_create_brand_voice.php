<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** BV1 (specs/core/08): the brokerage's Brand Voice, what the AI read from its brand guide, and every brand check. */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('ai_brand_voice', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('tone', 10)->default('warm'); // warm, friendly, formal, plain
            $table->json('use_words');
            $table->json('avoid_words');
            $table->boolean('emojis')->default(false);
            $table->string('sign_off', 120)->nullable();
            $table->string('spanish', 6)->default('usted'); // usted, tu
            $table->boolean('name_brokerage')->default(true); // texts and posts name the brokerage
            $table->json('guide')->nullable(); // what the AI read from the uploaded brand guide, waiting for review
            $table->string('guide_file', 160)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
        });
        $cell->create('ai_brand_checks', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('feature', 60);
            $table->boolean('passed');
            $table->json('issues')->nullable();
            $table->dateTime('created_at');
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('ai_brand_checks');
        $cell->dropIfExists('ai_brand_voice');
    }
};
