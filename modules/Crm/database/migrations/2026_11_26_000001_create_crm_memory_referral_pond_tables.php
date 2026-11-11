<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Personal memory, referrals and lead ponds (CRM differentiators 19, 20, 22).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('crm_memories', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->string('category', 12); // family, pet, hobby, preference, milestone, other
            $table->text('detail');
            $table->unsignedBigInteger('source_activity_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'contact_id'], 'crm_memories_contact_idx');
        });

        $schema->create('crm_referrals', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('referrer_contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->foreignId('referred_contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->string('status', 10)->default('received'); // received, closed, lost
            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['referred_contact_id'], 'crm_referrals_referred_unq');
            $table->index(['tenant_id', 'referrer_contact_id'], 'crm_referrals_referrer_idx');
        });

        $schema->table('crm_contacts', function (Blueprint $table): void {
            $table->dateTime('owner_assigned_at')->nullable();
            $table->dateTime('in_pond_since')->nullable();
            $table->index(['tenant_id', 'in_pond_since'], 'crm_contacts_pond_idx');
        });

        Schema::connection(Connections::control())->table('tenants', function (Blueprint $table): void {
            $table->boolean('crm_pond_enabled')->default(false);
            $table->unsignedSmallInteger('crm_pond_after_days')->default(2);
            $table->unsignedSmallInteger('crm_pond_daily_claims')->default(5);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->table('tenants', fn (Blueprint $table) => $table->dropColumn(['crm_pond_enabled', 'crm_pond_after_days', 'crm_pond_daily_claims']));
        $schema = Schema::connection(Connections::cell());
        $schema->table('crm_contacts', function (Blueprint $table): void {
            $table->dropIndex('crm_contacts_pond_idx');
            $table->dropColumn(['owner_assigned_at', 'in_pond_since']);
        });
        $schema->dropIfExists('crm_referrals');
        $schema->dropIfExists('crm_memories');
    }
};
