<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_subjects', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('institution_id')->index();
            $table->uuid('student_user_id');
            $table->uuid('subject_id');
            $table->timestampTz('enrolled_at')->useCurrent();
            $table->timestamps();

            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign('student_user_id')->references('user_id')->on('students')->cascadeOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->cascadeOnDelete();

            $table->unique(['student_user_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_subjects');
    }
};
