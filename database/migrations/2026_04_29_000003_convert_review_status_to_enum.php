<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // DDL fuera de transacción para evitar conflictos con dollar-quoting en PDO/PostgreSQL
    public $withinTransaction = false;

    public function up(): void
    {
        $typeExists = DB::selectOne(
            "SELECT 1 FROM pg_type WHERE typname = 'review_status'"
        );

        if (!$typeExists) {
            DB::unprepared(
                "CREATE TYPE review_status AS ENUM ('auto_graded','needs_review','reviewed')"
            );
        }

        DB::unprepared(
            "ALTER TABLE student_answers ALTER COLUMN review_status DROP DEFAULT"
        );

        DB::unprepared(
            "ALTER TABLE student_answers
                ALTER COLUMN review_status
                TYPE review_status
                USING review_status::review_status"
        );

        DB::unprepared(
            "ALTER TABLE student_answers
                ALTER COLUMN review_status SET DEFAULT 'auto_graded'::review_status"
        );
    }

    public function down(): void
    {
        DB::unprepared(
            "ALTER TABLE student_answers ALTER COLUMN review_status DROP DEFAULT"
        );

        DB::unprepared(
            "ALTER TABLE student_answers
                ALTER COLUMN review_status
                TYPE text
                USING review_status::text"
        );

        DB::unprepared(
            "ALTER TABLE student_answers
                ALTER COLUMN review_status SET DEFAULT 'auto_graded'"
        );

        DB::unprepared("DROP TYPE IF EXISTS review_status");
    }
};
