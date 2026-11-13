<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** Roster part 2 (specs/roster/01): agreements and signatures, onboarding and offboarding, self-service requests. */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(Connections::cell());

        $schema->create('roster_agreements', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('kind', 10); // agreement, policy
            $table->unsignedSmallInteger('version');
            $table->string('title', 120);
            $table->longText('body');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['tenant_id', 'kind', 'version'], 'roster_agreements_version_unq');
        });

        $schema->create('roster_signatures', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('agreement_id')->constrained('roster_agreements');
            $table->string('typed_name', 120);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('sha256', 64);
            $table->unsignedBigInteger('document_id')->nullable();
            $table->dateTime('signed_at');
        });

        $schema->create('roster_flows', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('kind', 12); // onboarding, offboarding
            $table->string('status', 10); // open, complete
            $table->date('leaving_on')->nullable();
            $table->string('reason', 200)->nullable();
            $table->unsignedBigInteger('started_by');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });

        $schema->create('roster_tasks', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->foreignId('flow_id')->constrained('roster_flows')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('title', 160);
            $table->string('who', 6); // agent, broker
            $table->boolean('automatic')->default(false);
            $table->unsignedSmallInteger('sort');
            $table->dateTime('done_at')->nullable();
            $table->unsignedBigInteger('done_by')->nullable();
        });

        $schema->create('roster_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('kind', 12); // licence, eo, association, mls
            $table->json('data');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('status', 10); // pending, approved, rejected
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(Connections::cell());
        foreach (['roster_requests', 'roster_tasks', 'roster_flows', 'roster_signatures', 'roster_agreements'] as $t) {
            $schema->dropIfExists($t);
        }
    }
};
