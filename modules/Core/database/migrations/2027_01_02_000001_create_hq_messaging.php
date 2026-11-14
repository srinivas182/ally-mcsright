<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Texts and automated voice calls, configured by RightAlly HQ: the Twilio account (encrypted), the numbers,
 * platform-wide switches, and which brokerages may use texts and calls for deadline alerts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::control());

        $schema->create('hq_messaging', function (Blueprint $table): void {
            $table->id();
            $table->text('credentials')->nullable(); // encrypted JSON: sid, token
            $table->string('sms_from', 20)->nullable();
            $table->string('voice_from', 20)->nullable();
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('voice_enabled')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        $schema->create('hq_messaging_tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->primary();
            $table->boolean('sms')->default(false);
            $table->boolean('voice')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::control());
        $schema->dropIfExists('hq_messaging_tenants');
        $schema->dropIfExists('hq_messaging');
    }
};
