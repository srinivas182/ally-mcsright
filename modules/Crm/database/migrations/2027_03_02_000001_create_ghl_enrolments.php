<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * GoHighLevel campaigns (specs/connect/15, C7a-2): "put these RightAlly contacts into that GoHighLevel workflow,
 * campaign or tag", kept up to date hourly, and who has been put in (each person once per enrolment).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('crm_ghl_enrolments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('connection_id');
            $table->string('name', 120);
            $table->string('target_type', 10); // workflow | campaign | tag
            $table->string('target_id', 100);
            $table->string('target_name', 150);
            $table->string('source_type', 12); // tag | smart_list
            $table->string('source_value', 100);
            $table->string('channel', 6)->default('email'); // email | sms | any: whose consent is needed
            $table->boolean('active')->default(true);
            $table->unsignedInteger('enrolled')->default(0);
            $table->unsignedInteger('left_out')->default(0);
            $table->dateTime('last_run_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['tenant_id', 'connection_id', 'active']);
        });
        $cell->create('crm_ghl_enrolled', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('enrolment_id');
            $table->unsignedBigInteger('contact_id');
            $table->dateTime('created_at');
            $table->unique(['enrolment_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('crm_ghl_enrolled');
        $cell->dropIfExists('crm_ghl_enrolments');
    }
};
