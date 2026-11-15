<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** The daily brief (specs/core/09): each person's morning email choice and the day it was last sent. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('core_brief_prefs', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->boolean('email')->default(true);
            $table->date('last_sent_on')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('core_brief_prefs');
    }
};
