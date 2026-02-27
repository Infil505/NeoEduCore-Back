<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->uuid('institution_id');

            $table->string('student_code')->unique()->nullable();

            $table->integer('grade')->nullable();
            $table->string('section')->nullable();
            $table->integer('year')->nullable();

            $table->enum('status', ['active','inactive'])->default('active');
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->integer('exams_completed_count')->default(0);
            $table->decimal('overall_average', 5, 2)->nullable();

            $table->date('birth_date')->nullable();
            $table->string('parent_name')->nullable();
            $table->string('parent_email')->nullable();

            $table->string('group_code')->nullable();
            $table->enum('adecuacion_type', ['acceso','contenido','evaluacion'])->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('institution_id')->references('id')->on('institutions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};