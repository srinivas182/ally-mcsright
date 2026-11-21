<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** IDX10: a text when a new home matches a saved search (written consent, 8am–9pm, at most 3 a day). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->table('crm_saved_searches', function (Blueprint $table): void {
            $table->boolean('text_alerts')->default(false);
            $table->timestamp('last_texted_at')->nullable();
            $table->date('texted_on')->nullable();
            $table->unsignedTinyInteger('texts_on_day')->default(0);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->table('crm_saved_searches', function (Blueprint $table): void {
            $table->dropColumn(['text_alerts', 'last_texted_at', 'texted_on', 'texts_on_day']);
        });
    }
};
