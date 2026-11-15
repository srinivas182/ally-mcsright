<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Buyer demand before listing (specs/grow/10): the reports agents run for a home before the listing appointment.
 * Counts only, never people: the result holds numbers, price points and similar homes for sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('site_demand_reports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('address', 150);
            $table->char('zip', 5);
            $table->json('inputs');
            $table->json('result');
            $table->dateTime('agents_told_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('site_demand_reports');
    }
};
