<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * PB2, control database: add-ons HQ offers (more texts, call minutes or map views each month, or one extra module)
 * and the ones each brokerage or solo agent has bought. Billed monthly on the subscription's invoice.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        $control = Schema::connection(Connections::control());
        $control->create('billing_addons', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('kind', 10); // allowance, module
            $table->string('key', 40); // meter (texts, calls, maps) or module key
            $table->unsignedInteger('amount')->default(0); // allowance only: how many more a month
            $table->string('name', 80);
            $table->unsignedInteger('price_cents');
            $table->string('audience', 10)->default('both'); // brokerage, agent, both
            $table->string('price_reason', 300)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        $control->create('tenant_addons', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('addon_id');
            $table->unsignedInteger('price_cents'); // the price when bought
            $table->dateTime('started_at');
            $table->dateTime('ends_at')->nullable(); // set when stopped: the end of that month
            $table->unsignedBigInteger('added_by');
            $table->timestamps();
            $table->index(['tenant_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        $control = Schema::connection(Connections::control());
        $control->dropIfExists('tenant_addons');
        $control->dropIfExists('billing_addons');
    }
};
