<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * The portability guarantee (specs/grow/03, SO1): when an agent leaves, a private link (emailed to them, 30 days, a few
 * downloads) to take their own people with them. Only the link's hash is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('crm_portability_links', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->unsignedTinyInteger('downloads')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('crm_portability_links');
    }
};
