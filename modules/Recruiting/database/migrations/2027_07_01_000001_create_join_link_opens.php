<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** AG1: how often each agent's join link is opened, per day (counts only, nothing about the visitor). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('recruiting_link_opens', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('sponsor_user_id');
            $table->date('day');
            $table->unsignedInteger('opens')->default(0);
            $table->unique(['tenant_id', 'sponsor_user_id', 'day'], 'recruiting_link_opens_day_uq');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('recruiting_link_opens');
    }
};
