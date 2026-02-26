<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // institutions
        Schema::create('institutions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // users
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id')->nullable();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('full_name');
            $table->enum('user_type', ['admin','teacher','student','parent']);
            $table->enum('status', ['active','inactive'])->default('active');
            $table->rememberToken();
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions');
        });

        // subjects
        Schema::create('subjects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->string('name');
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions');
        });

        // groups
        Schema::create('groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->integer('grade');
            $table->string('section')->nullable();
            $table->string('year')->nullable();
            $table->string('group_code')->nullable();
            $table->integer('student_count')->default(0);
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions');
        });

        // exams
        Schema::create('exams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('created_by_teacher_id');
            $table->string('title');
            $table->uuid('subject_id');
            $table->integer('grade');
            $table->text('instructions')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->enum('status', ['draft','published','active','completed'])->default('draft');
            $table->integer('max_attempts')->default(1);
            $table->boolean('show_results_immediately')->default(false);
            $table->boolean('allow_review_after_submission')->default(false);
            $table->boolean('randomize_questions')->default(false);
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions');
            $table->foreign('created_by_teacher_id')->references('id')->on('users');
            $table->foreign('subject_id')->references('id')->on('subjects');
        });

        // questions
        Schema::create('questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('exam_id');
            $table->text('question_text');
            $table->enum('question_type', ['multiple_choice','true_false','short_answer']);
            $table->integer('points')->default(1);
            $table->text('correct_answer_text')->nullable();
            $table->integer('order_index')->default(0);
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions');
            $table->foreign('exam_id')->references('id')->on('exams');
        });

        // question options
        Schema::create('question_options', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('institution_id');
            $table->uuid('question_id');
            $table->integer('option_index');
            $table->text('option_text');
            $table->boolean('is_correct')->default(false);

            $table->foreign('question_id')->references('id')->on('questions');
        });

        // student answers
        Schema::create('student_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('attempt_id');
            $table->uuid('question_id');
            $table->text('answer_text')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->decimal('points_awarded', 5, 2)->default(0);
            $table->json('correct_answer_snapshot')->nullable();
            $table->text('explanation')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->enum('review_status', ['auto_graded','needs_review'])->default('auto_graded');
            $table->timestamps();

            $table->foreign('attempt_id')->references('id')->on('exam_attempts');
            $table->foreign('question_id')->references('id')->on('questions');
        });

        // exam attempts
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('exam_id');
            $table->uuid('student_user_id');
            $table->integer('attempt_number')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->decimal('score', 5, 2)->default(0);
            $table->decimal('max_score', 5, 2)->default(0);
            $table->enum('grade_status', ['pending','graded','completed'])->default('pending');
            $table->timestamps();

            $table->foreign('exam_id')->references('id')->on('exams');
            $table->foreign('student_user_id')->references('id')->on('users');
        });

        // student progress
        Schema::create('student_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('student_user_id');
            $table->uuid('subject_id');
            $table->decimal('mastery_percentage', 5, 2)->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->foreign('student_user_id')->references('id')->on('users');
            $table->foreign('subject_id')->references('id')->on('subjects');
        });

        // ai recommendations
        Schema::create('ai_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('institution_id');
            $table->uuid('student_user_id');
            $table->uuid('subject_id');
            $table->uuid('exam_id')->nullable();
            $table->string('recommendation_type');
            $table->text('recommendation_text');
            $table->json('resource')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->foreign('student_user_id')->references('id')->on('users');
            $table->foreign('subject_id')->references('id')->on('subjects');
            $table->foreign('exam_id')->references('id')->on('exams');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_recommendations');
        Schema::dropIfExists('student_progress');
        Schema::dropIfExists('exam_attempts');
        Schema::dropIfExists('student_answers');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('exams');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('users');
        Schema::dropIfExists('institutions');
    }
};