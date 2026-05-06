<?php

namespace App\Services\Exams;

use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Exams\QuestionOption;
use App\Models\Students\StudentAnswer;

class ExamGradingService
{
    public function gradeAttempt(
        Exam $exam,
        ExamAttempt $attempt,
        array $answersPayload
    ): ExamAttempt {

        $questions = Question::where('exam_id', $exam->id)
            ->with('options')
            ->get()
            ->keyBy('id');

        $totalScore = 0;
        $maxScore = $questions->sum('points');

        $byQuestion = collect($answersPayload)->keyBy('question_id');

        foreach ($questions as $question) {
            $payload = $byQuestion->get($question->id);

            $answerText = $payload['answer_text'] ?? null;
            $selectedIds = $payload['selected_option_ids'] ?? [];

            $isCorrect = false;
            $points = 0;
            $correctSnapshot = null;
            $reviewStatus = 'auto_graded';

            if ($question->question_type->value === 'short_answer') {
                $isCorrect = mb_strtolower(trim((string)$answerText))
                    === mb_strtolower(trim((string)$question->correct_answer_text));
                $points = $isCorrect ? $question->points : 0;
                $reviewStatus = 'needs_review';
                $correctSnapshot = ['correct_answer_text' => $question->correct_answer_text];
            } elseif ($question->question_type->value === 'essay') {
                // Essay siempre requiere revisión manual
                $points = 0;
                $reviewStatus = 'needs_review';
            } else {
                $correctOption = $question->options->firstWhere('is_correct', true);
                if ($correctOption) {
                    $picked = (string) ($selectedIds[0] ?? '');
                    $isCorrect = $picked !== '' && $picked === (string) $correctOption->id;
                    $points = $isCorrect ? $question->points : 0;
                    $correctSnapshot = ['option_text' => $correctOption->option_text];
                }
            }

            $totalScore += $points;

            $answer = StudentAnswer::create([
                'attempt_id'              => $attempt->id,
                'question_id'             => $question->id,
                'answer_text'             => $answerText,
                'is_correct'              => $isCorrect,
                'points_awarded'          => $points,
                'answered_at'             => now(),
                'review_status'           => $reviewStatus,
                'correct_answer_snapshot' => $correctSnapshot,
            ]);

            if (!empty($selectedIds)) {
                $validIds = QuestionOption::where('question_id', $question->id)
                    ->whereIn('id', $selectedIds)
                    ->pluck('id');

                $answer->selectedOptions()->sync($validIds);
            }
        }

        $attempt->update([
            'score' => round($totalScore, 2),
            'max_score' => round($maxScore, 2),
            'submitted_at' => now(),
            'grade_status' => 'completed',
        ]);

        return $attempt->fresh();
    }
}