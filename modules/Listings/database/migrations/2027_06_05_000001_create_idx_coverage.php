<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Core\Support\Connections;
use Modules\Listings\Idx\StarterDirectory;

/**
 * IDX1: IDX coverage (HQ › IDX coverage): which states are open, the data connectors, and the MLS board directory with
 * each board's main and backup connector, our cost, the client's fee and the IDX display template. Platform records.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::control();
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->create('idx_states', function (Blueprint $table): void {
            $table->char('code', 2)->primary();
            $table->string('name', 40);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
        $schema->create('idx_connectors', function (Blueprint $table): void {
            $table->string('key', 30)->primary();
            $table->string('name', 60);
            $table->string('kind', 12); // live | replication
            $table->string('status', 12)->default('active'); // active | maintenance | disabled
            $table->unsignedSmallInteger('margin_percent')->default(30); // client fee = our cost + this, unless HQ sets a fee
            $table->string('notes', 500)->nullable(); // internal only (wholesale terms)
            $table->timestamps();
        });
        $schema->create('idx_boards', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->char('state', 2)->index();
            $table->json('other_states')->nullable();
            $table->string('region', 120)->nullable();
            $table->string('visibility', 12)->default('coming_soon'); // public | coming_soon | hidden
            $table->unsignedSmallInteger('approval_days_min')->default(3);
            $table->unsignedSmallInteger('approval_days_max')->default(7);
            $table->string('main_connector', 30)->nullable();
            $table->unsignedInteger('main_cost_cents')->nullable();
            $table->string('backup_connector', 30)->nullable();
            $table->unsignedInteger('backup_cost_cents')->nullable();
            $table->string('connector_code', 60)->nullable(); // the board's code at the connector (e.g. HAR, mlsunited)
            $table->unsignedInteger('monthly_fee_cents')->nullable(); // null: our cost + the connector's margin
            $table->unsignedInteger('setup_fee_cents')->default(0);
            $table->unsignedBigInteger('rules_profile_id')->nullable(); // IDX display template (logo, disclaimer, attribution)
            $table->unsignedBigInteger('mls_source_id')->nullable(); // the feed, once connected
            $table->boolean('checked')->default(false); // HQ has checked the name, connector and fee
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        $db = DB::connection($this->getConnection());
        $now = now();
        foreach (StarterDirectory::STATES as $code => $name) {
            $db->table('idx_states')->insert(['code' => $code, 'name' => $name, 'enabled' => in_array($code, StarterDirectory::LAUNCH_STATES, true), 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (StarterDirectory::CONNECTORS as $key => [$name, $kind, $status]) {
            $db->table('idx_connectors')->insert(['key' => $key, 'name' => $name, 'kind' => $kind, 'status' => $status, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (StarterDirectory::BOARDS as $code => [$name, $state, $region, $main, $backup, $connectorCode]) {
            $db->table('idx_boards')->insert(['ulid' => (string) Str::ulid(), 'code' => $code, 'name' => $name, 'state' => $state, 'region' => $region,
                'main_connector' => $main, 'backup_connector' => $backup, 'connector_code' => $connectorCode, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('idx_boards');
        $schema->dropIfExists('idx_connectors');
        $schema->dropIfExists('idx_states');
    }
};
