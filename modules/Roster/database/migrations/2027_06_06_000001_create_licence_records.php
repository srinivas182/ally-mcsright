<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * LN4 (specs/team/…, Outside services → Agent licence look-up, "State public licence data"): the licence lists states
 * publish, loaded by HQ, so agents' licences are checked automatically for free in those states.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->create('licence_records', function (Blueprint $table): void {
            $table->id();
            $table->char('state', 2);
            $table->string('number', 40);
            $table->string('last_name', 80)->nullable();
            $table->string('full_name', 160)->nullable();
            $table->string('status', 20); // active, expired, suspended, inactive
            $table->date('expires_on')->nullable();
            $table->dateTime('loaded_at');
            $table->unique(['state', 'number']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('licence_records');
    }
};
