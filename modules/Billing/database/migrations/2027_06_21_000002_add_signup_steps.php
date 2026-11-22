<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * SU2: signup in steps (about you and your boards, package, website design, pay), each saved as it is finished, so
 * HQ sees where people stop and they can carry on later. A signup is a "draft" until it goes to payment.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('signups', function (Blueprint $table): void {
            $table->unsignedTinyInteger('step')->default(1);
            $table->string('template', 20)->nullable();
            $table->unsignedBigInteger('package_id')->nullable()->change();
            $table->string('interval', 5)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('signups', function (Blueprint $table): void {
            $table->dropColumn(['step', 'template']);
        });
    }
};
