<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** SX6, control database: the spreadsheet sent with a concierge setup request (stored encrypted; removed after 30 days). */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        Schema::connection(Connections::control())->table('support_tickets', function (Blueprint $table): void {
            $table->string('file_path', 200)->nullable();
            $table->string('file_name', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->table('support_tickets', function (Blueprint $table): void {
            $table->dropColumn(['file_path', 'file_name']);
        });
    }
};
