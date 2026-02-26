<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // calendar events
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->enum('event_type', ['exam','activity','reminder','meeting']);
            $table->uuid('exam_id')->nullable();
            $table->uuid('group_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions');
            $table->foreign('exam_id')->references('id')->on('exams');
            $table->foreign('group_id')->references('id')->on('groups');
            $table->foreign('created_by')->references('id')->on('users');
        });

        // pivot: group_students
        Schema::create('group_students', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('group_id');
            $table->uuid('student_user_id');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();

            $table->foreign('institution_id')->references('id')->on('institutions');
            $table->foreign('group_id')->references('id')->on('groups');
            $table->foreign('student_user_id')->references('id')->on('users');
        });

        // pivot: exam_targets
        Schema::create('exam_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('exam_id');
            $table->uuid('group_id');

            $table->foreign('institution_id')->references('id')->on('institutions');
            $table->foreign('exam_id')->references('id')->on('exams');
            $table->foreign('group_id')->references('id')->on('groups');
        });

        // study resources
        Schema::create('study_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');

            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('resource_type', ['video','article','exercise','book','pdf','link']);
            $table->string('url');

            $table->integer('estimated_duration')->nullable();
            $table->enum('difficulty', ['basic','intermediate','advanced'])->nullable();
            $table->integer('grade_min')->nullable();
            $table->integer('grade_max')->nullable();
            $table->string('language')->default('es');

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions');
            $table->foreign('created_by')->references('id')->on('users');
        });

        // pivot: student_answer_options
        Schema::create('student_answer_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('student_answer_id');
            $table->bigInteger('option_id')->unsigned();

            $table->foreign('institution_id')->references('id')->on('institutions');
            $table->foreign('student_answer_id')->references('id')->on('student_answers');
            $table->foreign('option_id')->references('id')->on('question_options');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_answer_options');
        Schema::dropIfExists('study_resources');
        Schema::dropIfExists('exam_targets');
        Schema::dropIfExists('group_students');
        Schema::dropIfExists('calendar_events');
    }
};