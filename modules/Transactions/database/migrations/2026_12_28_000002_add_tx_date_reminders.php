<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Key dates: where each came from (a form, or added by hand) and when its reminder was last sent. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('tx_dates', function (Blueprint $table): void {
            $table->string('source', 20)->default('manual'); // manual, forms
            $table->date('reminded_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('tx_dates', fn (Blueprint $t) => $t->dropColumn(['source', 'reminded_on']));
    }
};
