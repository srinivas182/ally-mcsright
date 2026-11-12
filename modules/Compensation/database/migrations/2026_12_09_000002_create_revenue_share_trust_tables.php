<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Vesting and beneficiaries, sponsorship disputes, and held payments (specs/compensation/02,
 * additions 4, 12, 14; milestone bonuses use rs_entries kind "bonus").
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('rs_members', function (Blueprint $table): void {
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->date('joined_on');
            $table->date('left_on')->nullable();
            $table->date('deceased_on')->nullable();
            $table->unsignedBigInteger('beneficiary_user_id')->nullable();
            $table->timestamps();
            $table->primary(['tenant_id', 'user_id']);
        });

        $schema->create('rs_disputes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('agent_user_id');
            $table->unsignedBigInteger('claimant_user_id');
            $table->unsignedBigInteger('current_sponsor_user_id')->nullable();
            $table->text('evidence');
            $table->string('status', 10); // open, upheld, rejected
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->date('effective_from')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
        });

        $schema->create('rs_holds', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('entry_id')->unique();
            $table->json('reasons');
            $table->string('status', 10); // held, released, rejected
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('note', 300)->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        foreach (['rs_holds', 'rs_disputes', 'rs_members'] as $t) {
            $schema->dropIfExists($t);
        }
    }
};
