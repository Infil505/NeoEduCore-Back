-- Volumen realista para auditar consultas. SOLO base local desechable (neoeducoreperf).
\set ON_ERROR_STOP on
\timing off
\set inst '''00000000-0000-4000-8000-000000000001'''

insert into institutions(id, code, name, is_active, created_at, updated_at, settings)
values (:inst, 'PERF001', 'Colegio de rendimiento', true, now(), now(), '{}');

-- Personal
insert into users(id, institution_id, email, password_hash, full_name, user_type, status, must_change_password, created_at, updated_at)
values (gen_random_uuid(), :inst, 'admin@perf.test', 'x', 'Admin Perf', 'admin', 'active', false, now(), now());

create temp table tt as
  select g as n, gen_random_uuid() as id from generate_series(1, 40) g;
insert into users(id, institution_id, email, password_hash, full_name, user_type, status, must_change_password, created_at, updated_at)
  select id, :inst, 'docente' || n || '@perf.test', 'x', 'Docente ' || n, 'teacher', 'active', false, now() - (n || ' hours')::interval, now() from tt;

-- Materias y aulas
create temp table ts as select g as n, gen_random_uuid() as id from generate_series(1, 12) g;
insert into subjects(id, institution_id, name, created_at, updated_at)
  select id, :inst, 'Materia ' || n, now(), now() from ts;

create temp table tg as
  select g as n, gen_random_uuid() as id, ((g - 1) / 7) + 1 as grade, chr(64 + ((g - 1) % 7) + 1) as section
  from generate_series(1, 40) g;
insert into groups(id, institution_id, name, grade, section, year, group_code, student_count, created_at, updated_at)
  select id, :inst, grade || '-' || section, grade, section, '2026', 'GRP-' || n, 0, now(), now() from tg;

-- Asignaciones docente-aula-materia (4 por aula)
insert into teacher_assignments(id, institution_id, teacher_user_id, group_id, subject_id, assigned_at, created_at, updated_at)
  select gen_random_uuid(), :inst, t.id, g.id, s.id, now(), now(), now()
  from tg g
  join generate_series(0, 3) k on true
  join ts s on s.n = ((g.n + k) % 12) + 1
  join tt t on t.n = ((g.n * 4 + k) % 40) + 1;

-- Estudiantes (1.500)
create temp table tst as
  select g as n, gen_random_uuid() as id, (g % 40) + 1 as gn from generate_series(1, 1500) g;
insert into users(id, institution_id, email, password_hash, full_name, user_type, status, must_change_password, created_at, updated_at)
  select id, :inst, 'alumno' || n || '@perf.test', 'x', 'Estudiante Apellido' || n, 'student', 'active', false, now() - (n || ' minutes')::interval, now() from tst;
insert into students(user_id, institution_id, student_code, grade, section, year, status, enrolled_at, exams_completed_count, overall_average, group_code, created_at, updated_at)
  select s.id, :inst, 'STU-' || lpad(s.n::text, 5, '0'), g.grade, g.section, 2026, 'active', now(), 0, 0, 'GRP-' || g.n, now(), now()
  from tst s join tg g on g.n = s.gn;
insert into group_students(id, institution_id, group_id, student_user_id, joined_at)
  select gen_random_uuid(), :inst, g.id, s.id, now() from tst s join tg g on g.n = s.gn;
update groups gr set student_count = (select count(*) from group_students where group_id = gr.id);

-- Exámenes (200) con dirección a 2 aulas del mismo grado
create temp table te as
  select g as n, gen_random_uuid() as id, ((g - 1) % 6) + 1 as grade, ((g - 1) % 40) + 1 as tn, (g % 12) + 1 as sn
  from generate_series(1, 200) g;
insert into exams(id, institution_id, created_by_teacher_id, title, subject_id, grade, duration_minutes, status, max_attempts,
                  show_results_immediately, allow_review_after_submission, randomize_questions, created_at, updated_at)
  select e.id, :inst, t.id, 'Examen ' || e.n, s.id, e.grade, 45,
         (case when e.n % 10 = 0 then 'active' when e.n % 17 = 0 then 'draft' else 'completed' end)::exam_status,
         1, true, false, false, now() - (e.n || ' days')::interval, now()
  from te e join tt t on t.n = e.tn join ts s on s.n = e.sn;
insert into exam_targets(id, institution_id, exam_id, group_id)
  select gen_random_uuid(), :inst, e.id, gr.id
  from te e
  join lateral (select id from tg where tg.grade = e.grade order by (tg.n * e.n) % 97 limit 2) gr on true;

-- Preguntas (20 por examen) y opciones
create temp table tq as
  select gen_random_uuid() as id, e.id as exam_id, q as idx,
         (case when q <= 14 then 'multiple_choice' when q <= 18 then 'true_false' else 'short_answer' end)::question_type as qt
  from te e join generate_series(1, 20) q on true;
insert into questions(id, institution_id, exam_id, question_text, question_type, points, correct_answer_text, order_index, created_at, updated_at, topic)
  select id, :inst, exam_id, 'Pregunta ' || idx || ' del examen', qt, 5, case when qt = 'short_answer' then 'respuesta' end, idx, now(), now(), 'Tema ' || (idx % 5)
  from tq;
insert into question_options(institution_id, question_id, option_index, option_text, is_correct)
  select :inst, q.id, o, 'Opción ' || o, o = 1
  from tq q join generate_series(1, 4) o on true where q.qt = 'multiple_choice';
insert into question_options(institution_id, question_id, option_index, option_text, is_correct)
  select :inst, q.id, o, case o when 1 then 'Verdadero' else 'Falso' end, o = 1
  from tq q join generate_series(1, 2) o on true where q.qt = 'true_false';

-- Intentos entregados (hasta 10 por estudiante) y respuestas
insert into exam_attempts(id, institution_id, exam_id, student_user_id, attempt_number, started_at, submitted_at, score, max_score, grade_status, created_at, updated_at, total_paused_seconds)
  select gen_random_uuid(), :inst, x.exam_id, x.sid, 1,
         now() - ((x.rn * 5 + (random() * 3)::int) || ' days')::interval,
         now() - ((x.rn * 5 + (random() * 3)::int) || ' days')::interval + interval '30 minutes',
         round((random() * 100)::numeric, 2), 100, 'graded', now(), now(), 0
  from (
    select gs.student_user_id as sid, et.exam_id,
           row_number() over (partition by gs.student_user_id order by et.exam_id) as rn
    from group_students gs
    join exam_targets et on et.group_id = gs.group_id
    join exams ex on ex.id = et.exam_id and ex.status <> 'draft'
    where gs.left_at is null
  ) x where x.rn <= 10;
insert into student_answers(id, institution_id, attempt_id, question_id, answer_text, is_correct, points_awarded, answered_at, review_status, created_at, updated_at)
  select gen_random_uuid(), :inst, a.id, q.id, '1', (random() > 0.4), case when random() > 0.4 then 5 else 0 end,
         a.submitted_at, 'auto_graded', now(), now()
  from exam_attempts a join questions q on q.exam_id = a.exam_id;

-- Progreso por materia, recomendaciones, sesiones del tutor
insert into student_progress(id, institution_id, student_user_id, subject_id, mastery_percentage, updated_at)
  select gen_random_uuid(), :inst, s.id, sb.id, round((random() * 100)::numeric, 2), now()
  from tst s join ts sb on sb.n <= 6;
insert into ai_recommendations(id, institution_id, student_user_id, subject_id, exam_id, recommendation_type, recommendation_text, generated_at, created_at, updated_at, generated_by)
  select gen_random_uuid(), :inst, s.id, sb.id, null, (array['strength','weakness','resource','action'])[1 + (k % 4)]::ai_recommendation_type,
         'Recomendación ' || k, now() - (k || ' hours')::interval, now(), now(), 'heuristic'
  from tst s join ts sb on sb.n = (s.n % 12) + 1 join generate_series(1, 10) k on true;
insert into ai_chat_sessions(id, institution_id, student_user_id, subject_id, created_at, updated_at, messages)
  select gen_random_uuid(), :inst, s.id, null, now() - (k || ' hours')::interval, now() - (k || ' hours')::interval, '[]'
  from tst s join generate_series(1, 2) k on true;

-- Agenda y recursos
insert into calendar_events(id, institution_id, title, description, start_at, end_at, event_type, group_id, created_by, created_at, updated_at)
  select gen_random_uuid(), :inst, 'Aviso ' || g, 'texto', now() + (g || ' hours')::interval, now() + (g || ' hours')::interval + interval '1 hour',
         'activity', tg.id, t.id, now(), now()
  from generate_series(1, 600) g join tg on tg.n = (g % 40) + 1 join tt t on t.n = (g % 40) + 1;
create temp table tr as select g as n, gen_random_uuid() as id from generate_series(1, 300) g;
insert into study_resources(id, institution_id, title, description, resource_type, url, language, created_by, created_at, updated_at, subject_id)
  select r.id, :inst, 'Recurso ' || r.n, 'd', 'video', 'https://www.youtube.com/watch?v=abc' || r.n, 'es', t.id, now(), now(), s.id
  from tr r join tt t on t.n = (r.n % 40) + 1 join ts s on s.n = (r.n % 12) + 1;
insert into study_resource_groups(id, institution_id, study_resource_id, group_id)
  select gen_random_uuid(), :inst, r.id, g.id from tr r join tg g on g.n in ((r.n % 40) + 1, ((r.n + 7) % 40) + 1);

-- Notificaciones
insert into notifications(id, type, notifiable_type, notifiable_id, data, read_at, created_at, updated_at)
  select gen_random_uuid(), 'App\Notifications\AvisoDelCalendario', 'App\Models\Admin\User', s.id,
         '{"title":"Aviso","message":"Texto de aviso"}'::jsonb, case when k % 3 = 0 then now() end, now() - (k || ' hours')::interval, now()
  from tst s join generate_series(1, 12) k on true;

analyze;
select 'usuarios' t, count(*) from users union all select 'estudiantes', count(*) from students union all
select 'intentos', count(*) from exam_attempts union all select 'respuestas', count(*) from student_answers union all
select 'preguntas', count(*) from questions union all select 'notificaciones', count(*) from notifications union all
select 'recomendaciones', count(*) from ai_recommendations;
