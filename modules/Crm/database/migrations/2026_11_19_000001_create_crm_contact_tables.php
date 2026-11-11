<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * CRM contacts, households and relationships (specs/crm/01, Sprint 4D). Consent columns are
 * created now and used from Sprint 7.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('crm_contacts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('type', 10)->default('person');
            $table->string('first_name', 80)->default('');
            $table->string('last_name', 80)->default('');
            $table->string('company_name', 150)->nullable();
            $table->json('emails');
            $table->json('phones');
            $table->string('primary_email', 254)->nullable();
            $table->string('primary_phone', 20)->nullable();
            $table->string('search_name', 200)->default('');
            $table->string('address_line1', 150)->nullable();
            $table->string('address_line2', 150)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state', 2)->nullable();
            $table->string('zip', 10)->nullable();
            $table->date('birthday')->nullable();
            $table->string('origin', 10);
            $table->string('source', 30);
            $table->string('source_detail', 150)->nullable();
            $table->string('lifecycle', 20)->default('lead');
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->dateTime('last_activity_at')->nullable();
            $table->dateTime('next_task_at')->nullable();
            $table->boolean('email_opt_in')->default(false);
            $table->dateTime('email_opt_in_at')->nullable();
            $table->boolean('sms_opt_in')->default(false);
            $table->string('sms_consent_text', 500)->nullable();
            $table->dateTime('sms_opt_in_at')->nullable();
            $table->dateTime('sms_opt_out_at')->nullable();
            $table->boolean('do_not_call')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'owner_user_id', 'lifecycle'], 'crm_contacts_owner_idx');
            $table->index(['tenant_id', 'origin', 'lifecycle'], 'crm_contacts_origin_idx');
            $table->index(['tenant_id', 'primary_email'], 'crm_contacts_email_idx');
            $table->index(['tenant_id', 'primary_phone'], 'crm_contacts_phone_idx');
            $table->index(['tenant_id', 'search_name'], 'crm_contacts_name_idx');
        });

        $schema->create('crm_households', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('name', 150);
            $table->timestamps();
        });

        $schema->create('crm_household_members', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('household_id')->constrained('crm_households')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->string('role', 30)->default('member');

            $table->unique(['household_id', 'contact_id'], 'crm_household_member_unq');
        });

        $schema->create('crm_relationships', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->foreignId('related_contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->string('relation', 20);

            $table->unique(['contact_id', 'related_contact_id', 'relation'], 'crm_relationship_unq');
        });

        Schema::connection(Connections::control())->table('tenants', function (Blueprint $table): void {
            $table->boolean('crm_personal_visible_to_owners')->default(false);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::control())->table('tenants', fn (Blueprint $table) => $table->dropColumn('crm_personal_visible_to_owners'));
        $schema = Schema::connection(Connections::cell());
        $schema->dropIfExists('crm_relationships');
        $schema->dropIfExists('crm_household_members');
        $schema->dropIfExists('crm_households');
        $schema->dropIfExists('crm_contacts');
    }
};
