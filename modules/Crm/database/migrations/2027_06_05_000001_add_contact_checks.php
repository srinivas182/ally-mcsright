<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * LN4 (specs/grow/16, E24): email and phone checks (through the providers HQ chose), the name the phone network has
 * on file (a suggestion the agent confirms), and "may have moved" flags from MLS sales of the contact's home.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('crm_contacts', function (Blueprint $table): void {
            $table->string('email_status', 10)->nullable(); // valid, invalid, risky
            $table->dateTime('email_checked_at')->nullable();
            $table->string('phone_type', 10)->nullable(); // mobile, landline, voip, invalid
            $table->dateTime('phone_checked_at')->nullable();
            $table->string('caller_name', 120)->nullable();
            $table->date('moved_on')->nullable();
            $table->string('moved_note', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('crm_contacts', fn (Blueprint $table) => $table->dropColumn(['email_status', 'email_checked_at', 'phone_type',
            'phone_checked_at', 'caller_name', 'moved_on', 'moved_note']));
    }
};
