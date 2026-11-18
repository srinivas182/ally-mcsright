<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** AG1 (specs/grow/03): what each agent's own assistant may open (a preset), set by the agent. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(Connections::cell())->create('assistant_access', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('agent_user_id');
            $table->string('email', 254);
            $table->unsignedBigInteger('user_id')->nullable(); // set once the invitation is accepted
            $table->string('preset', 10)->default('full'); // full, leads, marketing, admin
            $table->timestamps();
            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'agent_user_id']);
        });
    }

    public function down(): void
    {
        Schema::connection(Connections::cell())->dropIfExists('assistant_access');
    }
};
