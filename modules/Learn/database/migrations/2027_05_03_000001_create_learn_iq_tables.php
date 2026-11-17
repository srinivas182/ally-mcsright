<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;

/** LN2 (specs/learn/02): course skills, certifications that open tools, mentors and the New Agent Launch. */
return new class extends Migration
{
    public function up(): void
    {
        $s = Schema::connection(Connections::cell());
        $s->table('learn_courses', function (Blueprint $table): void {
            $table->string('skill', 30)->nullable()->after('summary');
        });
        $s->create('learn_gates', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->string('tool', 40);
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('set_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'tool']);
        });
        $s->create('learn_mentors', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->string('strengths', 200)->nullable();
            $table->unsignedTinyInteger('max_mentees')->default(2);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
        $s->create('learn_launches', function (Blueprint $table): void {
            $table->id();
            $table->tenant();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('mentor_id')->nullable();
            $table->date('started_on');
            $table->json('done')->nullable(); // step keys ticked by hand
            $table->dateTime('finished_at')->nullable();
            $table->unsignedBigInteger('started_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        $s = Schema::connection(Connections::cell());
        foreach (['learn_launches', 'learn_mentors', 'learn_gates'] as $t) {
            $s->dropIfExists($t);
        }
        $s->table('learn_courses', fn (Blueprint $table) => $table->dropColumn('skill'));
    }
};
