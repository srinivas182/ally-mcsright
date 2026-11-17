<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** LM1 (specs/transactions/30): each deal's email-in address, and the listing agreement's end date for the inventory board. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('tx_deals', function (Blueprint $table): void {
            $table->string('inbox_token', 40)->nullable()->unique();
            $table->date('listing_expires_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('tx_deals', function (Blueprint $table): void {
            $table->dropUnique(['inbox_token']);
            $table->dropColumn(['inbox_token', 'listing_expires_on']);
        });
    }
};
