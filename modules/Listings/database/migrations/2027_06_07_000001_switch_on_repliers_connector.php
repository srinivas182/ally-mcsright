<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;

/** IDX3: the Repliers connector is built (listings copied into RightAlly), so it starts On in HQ › IDX coverage. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        DB::connection($this->getConnection())->table('idx_connectors')->where('key', 'repliers')->where('status', 'disabled')
            ->update(['status' => 'active', 'kind' => 'replication', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->table('idx_connectors')->where('key', 'repliers')->update(['status' => 'disabled', 'kind' => 'live', 'updated_at' => now()]);
    }
};
