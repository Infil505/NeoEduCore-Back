<?php

namespace Tests\Feature\Db;

use App\Models\Academic\CalendarEvent;
use App\Models\Academic\Group;
use App\Models\Academic\StudentSubject;
use App\Models\Academic\StudyResource;
use App\Models\Academic\Subject;
use App\Models\Academic\TeacherAssignment;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\AI\AiChatSession;
use App\Models\AI\AiRecommendation;
use App\Models\AI\AiTutorIncident;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\ExamNarrative;
use App\Models\Exams\Question;
use App\Models\Exams\QuestionOption;
use App\Models\Students\Student;
use App\Models\Students\StudentAnswer;
use App\Models\Students\StudentProgress;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cada clave foránea del esquema tiene su `belongsTo` en el modelo.
 *
 * Existe porque el diagrama de clases del informe se dibuja leyendo los
 * modelos: hasta el 03/10/2026 a nueve de ellos les faltaba `institution()`
 * y el diagrama salía incompleto respecto al ERD (O8). Una FK nueva sin su
 * relación hace fallar este test en vez de quedarse desfasada en silencio.
 */
class EloquentRelationsMatchFksTest extends TestCase
{
    /**
     * Tablas cuya FK no se declara con `belongsTo` en un modelo nuestro:
     * pivotes que se manipulan por query builder y `notifications`, que usa el
     * modelo de Laravel (`DatabaseNotification`) — su relación es el
     * `notifications()` de `Notifiable` en `User`, comprobado aparte.
     */
    private const PIVOTES_SIN_MODELO = ['exam_targets', 'study_resource_groups', 'group_students', 'student_answer_options', 'notifications'];

    /** "tabla.columna" => [modelo, relación] */
    private const RELACIONES = [
        'ai_chat_sessions.institution_id'    => [AiChatSession::class, 'institution'],
        'ai_chat_sessions.student_user_id'   => [AiChatSession::class, 'student'],
        'ai_chat_sessions.subject_id'        => [AiChatSession::class, 'subject'],
        'ai_chat_sessions.exam_id'           => [AiChatSession::class, 'exam'],

        'ai_recommendations.institution_id'  => [AiRecommendation::class, 'institution'],
        'ai_recommendations.student_user_id' => [AiRecommendation::class, 'student'],
        'ai_recommendations.subject_id'      => [AiRecommendation::class, 'subject'],
        'ai_recommendations.exam_id'         => [AiRecommendation::class, 'exam'],
        'ai_recommendations.attempt_id'      => [AiRecommendation::class, 'attempt'],

        'ai_tutor_incidents.institution_id'  => [AiTutorIncident::class, 'institution'],
        'ai_tutor_incidents.student_user_id' => [AiTutorIncident::class, 'student'],
        'ai_tutor_incidents.session_id'      => [AiTutorIncident::class, 'session'],

        'calendar_events.institution_id'     => [CalendarEvent::class, 'institution'],
        'calendar_events.exam_id'            => [CalendarEvent::class, 'exam'],
        'calendar_events.group_id'           => [CalendarEvent::class, 'group'],
        'calendar_events.created_by'         => [CalendarEvent::class, 'creator'],

        'exam_analysis_narratives.institution_id' => [ExamNarrative::class, 'institution'],
        'exam_analysis_narratives.exam_id'        => [ExamNarrative::class, 'exam'],

        'exam_attempts.institution_id'       => [ExamAttempt::class, 'institution'],
        'exam_attempts.exam_id'              => [ExamAttempt::class, 'exam'],
        'exam_attempts.student_user_id'      => [ExamAttempt::class, 'student'],

        'exams.institution_id'               => [Exam::class, 'institution'],
        'exams.subject_id'                   => [Exam::class, 'subject'],
        'exams.created_by_teacher_id'        => [Exam::class, 'teacher'],

        'groups.institution_id'              => [Group::class, 'institution'],

        'question_options.institution_id'    => [QuestionOption::class, 'institution'],
        'question_options.question_id'       => [QuestionOption::class, 'question'],

        'questions.institution_id'           => [Question::class, 'institution'],
        'questions.exam_id'                  => [Question::class, 'exam'],

        'student_answers.institution_id'     => [StudentAnswer::class, 'institution'],
        'student_answers.attempt_id'         => [StudentAnswer::class, 'attempt'],
        'student_answers.question_id'        => [StudentAnswer::class, 'question'],

        'student_progress.institution_id'    => [StudentProgress::class, 'institution'],
        'student_progress.student_user_id'   => [StudentProgress::class, 'student'],
        'student_progress.subject_id'        => [StudentProgress::class, 'subject'],

        'student_subjects.institution_id'    => [StudentSubject::class, 'institution'],
        'student_subjects.student_user_id'   => [StudentSubject::class, 'student'],
        'student_subjects.subject_id'        => [StudentSubject::class, 'subject'],

        'students.institution_id'            => [Student::class, 'institution'],
        'students.user_id'                   => [Student::class, 'user'],

        'study_resources.institution_id'     => [StudyResource::class, 'institution'],
        'study_resources.subject_id'         => [StudyResource::class, 'subject'],
        'study_resources.created_by'         => [StudyResource::class, 'creator'],

        'subjects.institution_id'            => [Subject::class, 'institution'],

        'teacher_assignments.institution_id' => [TeacherAssignment::class, 'institution'],
        'teacher_assignments.teacher_user_id'=> [TeacherAssignment::class, 'teacher'],
        'teacher_assignments.group_id'       => [TeacherAssignment::class, 'group'],
        'teacher_assignments.subject_id'     => [TeacherAssignment::class, 'subject'],

        'users.institution_id'               => [User::class, 'institution'],
    ];

    /** Inversos que pide O8: todo lo que apunta a `institutions` y a `subjects`. */
    private const INVERSOS = [
        Institution::class => [
            'users' => User::class, 'subjects' => Subject::class, 'groups' => Group::class,
            'studyResources' => StudyResource::class, 'students' => Student::class,
            'studentSubjects' => StudentSubject::class, 'teacherAssignments' => TeacherAssignment::class,
            'calendarEvents' => CalendarEvent::class, 'exams' => Exam::class,
            'questions' => Question::class, 'questionOptions' => QuestionOption::class,
            'examAttempts' => ExamAttempt::class, 'studentAnswers' => StudentAnswer::class,
            'studentProgress' => StudentProgress::class, 'aiRecommendations' => AiRecommendation::class,
            'aiChatSessions' => AiChatSession::class, 'aiTutorIncidents' => AiTutorIncident::class,
        ],
        Subject::class => [
            'exams' => Exam::class, 'studentSubjects' => StudentSubject::class,
            'teacherAssignments' => TeacherAssignment::class, 'studyResources' => StudyResource::class,
            'studentProgress' => StudentProgress::class, 'aiRecommendations' => AiRecommendation::class,
            'aiChatSessions' => AiChatSession::class,
        ],
    ];

    public function test_every_foreign_key_has_a_matching_belongs_to(): void
    {
        $fks = $this->clavesForaneas();
        $this->assertNotEmpty($fks, 'No se leyó ninguna FK: ¿está cargado el esquema?');

        $sinRelacion = [];

        foreach ($fks as $fk) {
            if (in_array($fk->tabla, self::PIVOTES_SIN_MODELO, true)) {
                continue;
            }

            $clave = "{$fk->tabla}.{$fk->columna}";
            if (! isset(self::RELACIONES[$clave])) {
                $sinRelacion[] = "{$clave} → {$fk->destino}({$fk->destino_columna})";
                continue;
            }

            [$modelo, $metodo] = self::RELACIONES[$clave];
            $relacion = (new $modelo)->{$metodo}();

            $this->assertInstanceOf(BelongsTo::class, $relacion, "{$modelo}::{$metodo}()");
            $this->assertSame($fk->columna, $relacion->getForeignKeyName(), "{$modelo}::{$metodo}() usa otra columna");
            $this->assertSame($fk->destino, $relacion->getRelated()->getTable(), "{$modelo}::{$metodo}() apunta a otra tabla");
            $this->assertSame($fk->destino_columna, $relacion->getOwnerKeyName(), "{$modelo}::{$metodo}() usa otra clave destino");
        }

        $this->assertSame([], $sinRelacion, 'FK sin relación Eloquent declarada (añadirla al modelo y a RELACIONES)');
    }

    public function test_institution_and_subject_declare_their_inverse_relations(): void
    {
        foreach (self::INVERSOS as $padre => $relaciones) {
            foreach ($relaciones as $metodo => $hijo) {
                $relacion = (new $padre)->{$metodo}();

                $this->assertInstanceOf(HasMany::class, $relacion, "{$padre}::{$metodo}()");
                $this->assertInstanceOf($hijo, $relacion->getRelated(), "{$padre}::{$metodo}()");
            }
        }
    }

    public function test_user_notifications_point_at_the_notifiable_id_column(): void
    {
        $relacion = (new User)->notifications();

        $this->assertSame('notifications.notifiable_id', $relacion->getQualifiedForeignKeyName());
    }

    private function clavesForaneas(): array
    {
        return DB::select("
            SELECT c.conrelid::regclass::text  AS tabla,
                   a.attname                   AS columna,
                   c.confrelid::regclass::text AS destino,
                   af.attname                  AS destino_columna
            FROM pg_constraint c
            JOIN pg_attribute a  ON a.attrelid  = c.conrelid  AND a.attnum  = c.conkey[1]
            JOIN pg_attribute af ON af.attrelid = c.confrelid AND af.attnum = c.confkey[1]
            WHERE c.contype = 'f'
              AND c.connamespace = current_schema()::regnamespace
            ORDER BY 1, 2
        ");
    }
}
