<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** HQ → Connect setup (specs/connect/02): RightAlly's own Google and Microsoft sign-in apps, set from the HQ screen. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->create('hq_oauth_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 20)->unique(); // google, microsoft
            $table->string('client_id', 255);
            $table->text('client_secret');            // encrypted, write-only
            $table->date('secret_expires_on')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('hq_oauth_clients');
    }
};
