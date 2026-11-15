<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Which brokerage(s) a sign-in email belongs to (specs/connect/03, M2b), so a shared address can send people to their
 * own brokerage. Emails are stored only as keyed hashes (HMAC with the app key), never in plain text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->create('identity_directory', function (Blueprint $table): void {
            $table->id();
            $table->char('email_hash', 64);
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
            $table->index('email_hash');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('identity_directory');
    }
};
