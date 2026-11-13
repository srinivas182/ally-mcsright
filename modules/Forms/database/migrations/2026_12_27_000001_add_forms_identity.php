<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/**
 * Forms part 5a (specs/forms/01): identity checks (government ID + live selfie) required by risk rules, seller
 * ownership match against the owner of record, and reusable verification. No ID images are stored here: the
 * identity service keeps them; RightAlly keeps only the result.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('form_settings', function (Blueprint $table): void {
            $table->tenant();
            $table->primary('tenant_id');
            $table->string('id_policy', 12)->default('risk'); // off, risk, sellers, everyone
            $table->boolean('owner_check')->default(true);
            $table->unsignedTinyInteger('below_value_percent')->default(70); // flag a price under this % of the estimate
            $table->timestamps();
        });

        $schema->table('form_templates', function (Blueprint $table): void {
            $table->boolean('high_risk')->default(false); // deeds, powers of attorney: everyone verifies
        });

        $schema->table('form_packets', function (Blueprint $table): void {
            $table->json('risk_flags')->nullable();
            $table->json('owner_record')->nullable();
            $table->unsignedBigInteger('owner_override_by')->nullable();
            $table->string('owner_override_reason', 300)->nullable();
        });

        $schema->create('form_identity_checks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->string('email', 254)->index();
            $table->string('name', 160);
            $table->string('provider', 20);
            $table->string('provider_ref', 120)->nullable()->index();
            $table->string('status', 10)->default('pending'); // pending, verified, failed
            $table->string('verified_name', 160)->nullable();
            $table->string('document_type', 40)->nullable();
            $table->string('document_country', 2)->nullable();
            $table->string('failure_reason', 200)->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });

        $schema->table('form_signers', function (Blueprint $table): void {
            $table->boolean('met_in_person')->nullable(); // has the agent met this person face to face?
            $table->boolean('id_required')->default(false);
            $table->unsignedBigInteger('identity_check_id')->nullable();
            $table->string('id_status', 16)->nullable(); // verified, failed, name_mismatch, owner_mismatch
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        $schema->table('form_signers', fn (Blueprint $t) => $t->dropColumn(['met_in_person', 'id_required', 'identity_check_id', 'id_status']));
        $schema->dropIfExists('form_identity_checks');
        $schema->table('form_packets', fn (Blueprint $t) => $t->dropColumn(['risk_flags', 'owner_record', 'owner_override_by', 'owner_override_reason']));
        $schema->table('form_templates', fn (Blueprint $t) => $t->dropColumn('high_risk'));
        $schema->dropIfExists('form_settings');
    }
};
