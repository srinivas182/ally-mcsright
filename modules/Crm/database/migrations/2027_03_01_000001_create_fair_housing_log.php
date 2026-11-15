<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Fair Housing checks on every channel, with an audit pack (specs/grow/12): how many messages each channel checked
 * each day, and every time the check stopped, warned about or changed something, and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->create('crm_fair_housing_checks', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->date('day');
            $table->string('channel', 20);
            $table->unsignedInteger('checked')->default(0);
            $table->unsignedInteger('flagged')->default(0);
            $table->unique(['tenant_id', 'day', 'channel']);
        });
        $cell->create('crm_fair_housing_log', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('channel', 20);
            $table->string('action', 10); // blocked | removed | warned
            $table->json('phrases');
            $table->string('excerpt', 300);
            $table->string('where', 120)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->dateTime('created_at');
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $cell = Schema::connection(Connections::cell());
        $cell->dropIfExists('crm_fair_housing_log');
        $cell->dropIfExists('crm_fair_housing_checks');
    }
};
