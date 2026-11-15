<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Paid apps (specs/connect/14, Sprint C6b). HQ chooses per app: free for everyone, or a monthly add-on (with the
 * packages that include it free). A brokerage (or an agent, for their own connection) buys the add-on before
 * connecting; it is charged monthly in RightAlly credits and pauses the app's connections when unpaid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::control())->table('hq_connect_apps', function (Blueprint $table): void {
            $table->unsignedInteger('price_cents')->nullable(); // null: free for everyone
            $table->json('included_packages')->nullable();      // package ids that include it free
        });
        Schema::connection(Connections::cell())->create('connect_app_addons', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('app_key', 40);
            $table->unsignedBigInteger('payer_user_id')->nullable(); // null: the brokerage
            $table->string('status', 12); // active, cancelled, lapsed
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('credits');
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->dateTime('cancel_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['tenant_id', 'app_key', 'payer_user_id'], 'connect_app_addons_one_idx');
            $table->index(['status', 'period_end'], 'connect_app_addons_renew_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('connect_app_addons');
        Schema::connection(Connections::control())->table('hq_connect_apps', fn (Blueprint $t) => $t->dropColumn(['price_cents', 'included_packages']));
    }
};
