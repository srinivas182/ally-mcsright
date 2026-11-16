<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Movewise MW1 (specs/movewise/01): the managing broker partners and the states, each with its own switch and the
 * legal checklist that must be complete before it is switched on. Movewise lives on the control plane.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = Schema::connection(Connections::control());
        $db->create('movewise_partners', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name', 160);                  // the brokerage's legal name, as licensed
            $table->string('broker_name', 120);           // the designated (managing) broker
            $table->string('licence_number', 40);
            $table->char('licence_state', 2);
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 200)->nullable();
            $table->string('iabs_url', 500)->nullable();  // Texas: the broker's Information About Brokerage Services form
            $table->date('contract_signed_on')->nullable();
            $table->string('status', 10)->default('active'); // active | inactive
            $table->timestamps();
        });
        $db->create('movewise_states', function (Blueprint $table): void {
            $table->char('code', 2)->primary();
            $table->string('name', 40);
            $table->boolean('on')->default(false);
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->json('checklist')->nullable();       // each legal item: {done, note, by, at}
            $table->timestamp('turned_on_at')->nullable();
            $table->unsignedBigInteger('turned_on_by')->nullable();
            $table->timestamps();
        });
        $now = now();
        foreach (['TX' => 'Texas', 'LA' => 'Louisiana', 'MS' => 'Mississippi', 'AL' => 'Alabama'] as $code => $name) {
            DB::connection(Connections::control())->table('movewise_states')->insertOrIgnore(['code' => $code, 'name' => $name, 'on' => false, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->dropIfExists('movewise_states');
        Schema::connection(Connections::control())->dropIfExists('movewise_partners');
    }
};
