<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Sprint C8b (specs/connect/21): property facts from ATTOM and owner details from BatchData, looked up one address
 * at a time and paid with credits. Each answer is kept for 30 days, so looking again costs nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('crm_property_lookups', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('vendor', 12);          // attom | batchdata
            $table->char('address_key', 40);       // sha1 of the normalised address
            $table->string('address', 200);
            $table->json('data');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('fetched_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'vendor', 'address_key']);
        });
        $now = now();
        foreach ([['data.property', 'ATTOM property lookup', 'lookup', 25_000_000, 'ATTOM property, sale and AVM endpoints per lookup. Enter your real cost.'],
            ['data.owner', 'BatchData owner lookup', 'lookup', 10_000_000, 'BatchData property lookup per address. Enter your real cost.']] as [$key, $label, $unit, $cost, $note]) {
            DB::connection(Connections::control())->table('cost_catalog')->insertOrIgnore(['key' => $key, 'label' => $label, 'unit' => $unit, 'kind' => 'usage',
                'cost_microcents' => $cost, 'free_units_monthly' => 0, 'source_url' => null, 'note' => $note, 'checked_at' => null, 'updated_by' => null,
                'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('crm_property_lookups');
        DB::connection(Connections::control())->table('cost_catalog')->whereIn('key', ['data.property', 'data.owner'])->delete();
    }
};
