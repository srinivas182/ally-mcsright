<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;

/** IDX2: the Spark connector is built, so it starts On in HQ › IDX coverage (HQ can still pause it). */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        DB::connection($this->getConnection())->table('idx_connectors')->where('key', 'spark')->where('status', 'disabled')->update(['status' => 'active', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->table('idx_connectors')->where('key', 'spark')->update(['status' => 'disabled', 'updated_at' => now()]);
    }
};
