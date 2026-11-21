<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** IDX7: the MLS boards chosen at sign-up; they are added to the new brokerage (on demo listings) once it has paid. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->table('signups', function (Blueprint $table): void {
            $table->json('idx_boards')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->table('signups', function (Blueprint $table): void {
            $table->dropColumn('idx_boards');
        });
    }
};
