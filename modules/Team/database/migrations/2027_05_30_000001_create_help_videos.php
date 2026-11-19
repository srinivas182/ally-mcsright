<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** SX5, control database: HQ's 60-second "how this page works" video for each page (by the start of its address). */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection(Connections::control())->create('help_videos', function (Blueprint $table): void {
            $table->id();
            $table->string('path', 120)->unique();
            $table->string('title', 120);
            $table->string('url', 300);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('help_videos');
    }
};
