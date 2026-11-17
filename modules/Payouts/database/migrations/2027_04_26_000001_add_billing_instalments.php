<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** CP1 (specs/compensation/03): fees paid in a set number of instalments (e.g. a yearly franchise fee in 4 quarterly payments). */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('bill_items', function (Blueprint $t): void {
            $t->unsignedSmallInteger('instalments')->nullable();
            $t->string('frequency', 12)->change(); // "quarterly" is longer than the original 8 characters
        });
        $cell->table('bill_assignments', function (Blueprint $t): void {
            $t->unsignedSmallInteger('charges_left')->nullable();
            $t->unsignedSmallInteger('charges_total')->nullable();
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->table('bill_assignments', fn (Blueprint $t) => $t->dropColumn(['charges_left', 'charges_total']));
        $cell->table('bill_items', function (Blueprint $t): void {
            $t->dropColumn('instalments');
            $t->string('frequency', 8)->change();
        });
    }
};
