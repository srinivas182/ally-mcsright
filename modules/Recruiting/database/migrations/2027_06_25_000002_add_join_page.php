<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** CM4b: the join page's words, video and extra states (where the brokerage works through a managing broker). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('rec_settings', function (Blueprint $table): void {
            $table->json('join_page')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('rec_settings', function (Blueprint $table): void {
            $table->dropColumn('join_page');
        });
    }
};
