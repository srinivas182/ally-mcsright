<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** MK3 (specs/grow/03): what the homeowner paid and when, for "since you bought" on the monthly report. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('site_home_reports', function (Blueprint $table): void {
            $table->unsignedBigInteger('bought_price')->nullable(); // dollars
            $table->date('bought_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('site_home_reports', fn (Blueprint $table) => $table->dropColumn(['bought_price', 'bought_on']));
    }
};
