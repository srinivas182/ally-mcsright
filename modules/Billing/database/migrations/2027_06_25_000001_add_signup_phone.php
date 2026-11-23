<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** FT1: the brokerage's (or agent's) phone number from sign-up, shown in the new website's header and footer. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('signups', function (Blueprint $table): void {
            $table->string('phone', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('signups', function (Blueprint $table): void {
            $table->dropColumn('phone');
        });
    }
};
