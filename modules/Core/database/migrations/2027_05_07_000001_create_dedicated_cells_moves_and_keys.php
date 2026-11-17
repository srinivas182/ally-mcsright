<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** DS2 (specs/core/12): dedicated databases, moves between cells, and per-brokerage encryption keys. */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection(Connections::control());
        $s->table('cells', function (Blueprint $table): void {
            $table->boolean('dedicated')->default(false);
            $table->unsignedBigInteger('dedicated_tenant_id')->nullable();
            $table->text('credentials')->nullable(); // encrypted connection details; never shown again
        });
        $s->create('brokerage_moves', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('from_cell_id');
            $table->unsignedBigInteger('to_cell_id');
            $table->string('kind', 12); // move, switch_back, drill
            $table->string('status', 12)->default('planned'); // planned, approved, copying, final_sync, verifying, switched, done, failed, cancelled
            $table->json('plan')->nullable();
            $table->json('result')->nullable();
            $table->string('step', 120)->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('error', 300)->nullable();
            $table->dateTime('switched_at')->nullable();
            $table->dateTime('keep_until')->nullable(); // the old copy is kept until then for one-click switch-back
            $table->dateTime('cleaned_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'id']);
        });
        $s->create('brokerage_keys', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedSmallInteger('version');
            $table->text('wrapped'); // the brokerage's data key, encrypted with the platform key
            $table->string('status', 10)->default('active'); // active, retired
            $table->timestamps();
            $table->unique(['tenant_id', 'version']);
        });
    }

    public function down(): void
    {
        $s = Schema::connection(Connections::control());
        $s->dropIfExists('brokerage_keys');
        $s->dropIfExists('brokerage_moves');
        $s->table('cells', fn (Blueprint $t) => $t->dropColumn(['dedicated', 'dedicated_tenant_id', 'credentials']));
    }
};
