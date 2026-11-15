<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Switch in a weekend (specs/connect/12): deals brought over from dotloop, SkySlope or Lone Wolf say where they came
 * from, so the deal page can show it and the import never touches a deal someone has since closed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('tx_deals', function (Blueprint $table): void {
            $table->string('imported_from', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('tx_deals', fn (Blueprint $t) => $t->dropColumn('imported_from'));
    }
};
