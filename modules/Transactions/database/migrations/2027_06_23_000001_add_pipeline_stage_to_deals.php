<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * PG1: deals that are not under contract yet live in the CRM pipeline, in a stage (new, showing, offer), with the
 * date they entered it.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::cell();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('tx_deals', function (Blueprint $table): void {
            $table->string('pipeline_stage', 20)->nullable();
            $table->dateTime('pipeline_stage_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('tx_deals', function (Blueprint $table): void {
            $table->dropColumn(['pipeline_stage', 'pipeline_stage_at']);
        });
    }
};
