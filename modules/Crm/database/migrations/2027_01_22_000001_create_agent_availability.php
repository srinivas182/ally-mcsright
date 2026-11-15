<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint 17D-3 (specs/crm/booking): when each agent takes calls, showings and listing appointments booked from the
 * website, and how long each slot is. Busy times come from their CRM tasks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('agent_availability', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->boolean('enabled')->default(true);
            $table->json('hours'); // {"mon": [["09:00","18:00"]], ... } in the brokerage's time zone
            $table->unsignedSmallInteger('slot_minutes')->default(30);
            $table->unsignedSmallInteger('notice_hours')->default(2);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('agent_availability');
    }
};
