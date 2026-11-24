<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * CM4b: recruiters. People the brokerage adds (they need no licence and hold no deals) who bring in agents. They
 * are paid a flat bonus when a recruit closes their first deal; revenue share on levels 1–2 only when the owner
 * switches it on and confirms their state allows it.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Connections::cell();
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->create('rs_recruiters', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('email', 160);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('bonus_cents')->nullable(); // null: the brokerage's default
            $table->unsignedBigInteger('added_by');
            $table->dateTime('removed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id'], 'rs_recruiters_user_idx');
            $table->index(['tenant_id', 'email'], 'rs_recruiters_email_idx');
        });
        $schema->table('rs_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('recruiter_bonus_cents')->nullable();
            $table->unsignedTinyInteger('recruiter_share_levels')->default(0);
            $table->unsignedBigInteger('recruiter_share_confirmed_by')->nullable();
            $table->dateTime('recruiter_share_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->table('rs_settings', function (Blueprint $table): void {
            $table->dropColumn(['recruiter_bonus_cents', 'recruiter_share_levels', 'recruiter_share_confirmed_by', 'recruiter_share_confirmed_at']);
        });
        $schema->dropIfExists('rs_recruiters');
    }
};
