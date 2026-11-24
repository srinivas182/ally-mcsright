<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * CM5: more than one agent on a deal (co-listing, co-buying, a team), each with their share of the commission.
 * No rows means the deal's agent has 100%.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::cell();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('tx_deal_agents', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('deal_id');
            $table->unsignedBigInteger('user_id');
            $table->decimal('share_percent', 7, 4);
            $table->unsignedBigInteger('set_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'deal_id', 'user_id'], 'tx_deal_agents_unique');
            $table->index(['tenant_id', 'user_id'], 'tx_deal_agents_user_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('tx_deal_agents');
    }
};
