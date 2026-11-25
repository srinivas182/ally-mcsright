<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * ML2: agents' own MLS listings come into My listings. The owner can let them be added automatically; listings an
 * agent says are not theirs to add are remembered so they are not offered again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('tx_settings', function (Blueprint $table): void {
            $table->boolean('mls_auto_add')->default(false);
        });
        $schema->create('tx_mls_dismissed', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('mls_source_id');
            $table->string('listing_key', 60);
            $table->unsignedBigInteger('dismissed_by');
            $table->timestamps();
            $table->unique(['tenant_id', 'mls_source_id', 'listing_key'], 'tx_mls_dismissed_unique');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->dropIfExists('tx_mls_dismissed');
        $schema->table('tx_settings', function (Blueprint $table): void {
            $table->dropColumn('mls_auto_add');
        });
    }
};
