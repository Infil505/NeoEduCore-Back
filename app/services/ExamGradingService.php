<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\StudentAnswer;

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

            if ($question->question_type->value === 'short_answer') {
                $isCorrect = mb_strtolower(trim((string)$answerText))
                    === mb_strtolower(trim((string)$question->correct_answer_text));
                $points = $isCorrect ? $question->points : 0;
            } else {
                $correctOption = $question->options->firstWhere('is_correct', true);
                if ($correctOption) {
                    $picked = (int) ($selectedIds[0] ?? 0);
                    $isCorrect = $picked === (int) $correctOption->id;
                    $points = $isCorrect ? $question->points : 0;
                }
            }

            $totalScore += $points;

            $answer = StudentAnswer::create([
                'attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'answer_text' => $answerText,
                'is_correct' => $isCorrect,
                'points_awarded' => $points,
                'answered_at' => now(),
                'review_status' => $question->question_type->value === 'short_answer'
                    ? 'needs_review'
                    : 'auto_graded',
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