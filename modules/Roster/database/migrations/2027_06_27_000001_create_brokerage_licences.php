<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * LV2: the brokerage's own real estate licences (from sign-up, then Settings › Licences), what the state's records say
 * about each, and when the owner was last warned about expiry. They decide which states' MLS boards it may add and
 * whether its website may be public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('brokerage_licences', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->char('state', 2);
            $table->string('number', 40);
            $table->string('holder', 160)->nullable();
            $table->string('status', 12); // active, expired, inactive, suspended, not_found, new, unchecked, test
            $table->date('expires_on')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->dateTime('hq_verified_at')->nullable(); // HQ checked it by hand
            $table->unsignedBigInteger('hq_verified_by')->nullable();
            $table->unsignedSmallInteger('warned_days')->nullable(); // smallest expiry warning already sent (60, 30, 7, 0)
            $table->timestamps();
            $table->unique(['tenant_id', 'state', 'number']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('brokerage_licences');
    }
};
