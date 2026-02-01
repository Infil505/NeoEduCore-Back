-- =========================
-- 01_schema.sql (PostgreSQL) - AJUSTADO A MODELOS
-- =========================
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ---------- ENUM TYPES ----------
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'user_type') THEN
    CREATE TYPE user_type AS ENUM ('admin','teacher','student');
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'user_status') THEN
    CREATE TYPE user_status AS ENUM ('active','inactive','suspended');
  END IF;

  -- ExamStatus en tu modelo: draft | published | active | completed
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'exam_status') THEN
    CREATE TYPE exam_status AS ENUM ('draft','published','active','completed');
  END IF;

  -- QuestionType en tu modelo: multiple_choice | true_false | short_answer
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'question_type') THEN
    CREATE TYPE question_type AS ENUM ('multiple_choice','true_false','short_answer');
  END IF;

  -- ResourceType en tu modelo (puedes ampliar según tu Enum)
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'resource_type') THEN
    CREATE TYPE resource_type AS ENUM ('video','article','exercise','book','pdf','link','other');
  END IF;

  -- StudentStatus (ajustalo si tu Enum tiene otros valores)
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'student_status') THEN
    CREATE TYPE student_status AS ENUM ('active','inactive');
  END IF;

  -- AI Recommendation type según tu modelo: strength | weakness | resource | action
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'ai_recommendation_type') THEN
    CREATE TYPE ai_recommendation_type AS ENUM ('strength','weakness','resource','action');
  END IF;

  -- CalendarEvent type según tu modelo: exam | activity | reminder | meeting
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'calendar_event_type') THEN
    CREATE TYPE calendar_event_type AS ENUM ('exam','activity','reminder','meeting');
  END IF;

  -- Grade status (ExamAttempt.grade_status): pending | graded | completed
  IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'grade_status') THEN
    CREATE TYPE grade_status AS ENUM ('pending','graded','completed');
  END IF;
END $$;

-- ---------- TABLES ----------
CREATE TABLE IF NOT EXISTS institutions (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  code text NOT NULL UNIQUE,
  name text NOT NULL,

  address text,
  phone text,
  email text,
  is_active boolean NOT NULL DEFAULT true,

  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

-- Usuarios
CREATE TABLE IF NOT EXISTS users (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  institution_id uuid REFERENCES institutions(id) ON DELETE SET NULL,

  email text NOT NULL UNIQUE,
  password_hash text NOT NULL,
  full_name text NOT NULL,

  user_type user_type NOT NULL,
  status user_status NOT NULL DEFAULT 'active',

  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_users_institution ON users(institution_id);
CREATE INDEX IF NOT EXISTS idx_users_type ON users(user_type);

-- Perfil de estudiante (PK = user_id)
CREATE TABLE IF NOT EXISTS students (
  user_id uuid PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,

  student_code text UNIQUE,

  grade int,
  section text,
  status student_status NOT NULL DEFAULT 'active',

  enrolled_at timestamptz,
  last_activity_at timestamptz,
  exams_completed_count int NOT NULL DEFAULT 0 CHECK (exams_completed_count >= 0),
  overall_average numeric(10,2) NOT NULL DEFAULT 0 CHECK (overall_average >= 0),

  birth_date date,
  parent_name text,
  parent_email text,

  group_code text,

  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

-- Materias
CREATE TABLE IF NOT EXISTS subjects (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  institution_id uuid NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,

  name text NOT NULL,

  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),

  UNIQUE (institution_id, name)
);

CREATE INDEX IF NOT EXISTS idx_subjects_institution ON subjects(institution_id);

-- Grupos
CREATE TABLE IF NOT EXISTS groups (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  institution_id uuid NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,

  name text NOT NULL,          -- ej: "7-A 2026"
  grade int NOT NULL CHECK (grade > 0),
  section text NOT NULL,
  year int NOT NULL CHECK (year >= 2000),
  group_code text,             -- soporte extra si lo usas

  student_count int NOT NULL DEFAULT 0 CHECK (student_count >= 0),

  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),

  UNIQUE (institution_id, year, grade, section)
);

CREATE INDEX IF NOT EXISTS idx_groups_institution_year ON groups(institution_id, year);

-- Pivot grupo-estudiantes
CREATE TABLE IF NOT EXISTS group_students (
  group_id uuid NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
  student_user_id uuid NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,

  joined_at timestamptz NOT NULL DEFAULT now(),
  left_at timestamptz,

  PRIMARY KEY (group_id, student_user_id)
);

CREATE INDEX IF NOT EXISTS idx_group_students_student ON group_students(student_user_id);

-- Exámenes (ajustado al modelo Exam)
CREATE TABLE IF NOT EXISTS exams (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  institution_id uuid NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,

  created_by_teacher_id uuid NOT NULL REFERENCES users(id),
  subject_id uuid NOT NULL REFERENCES subjects(id),

  title text NOT NULL,
  instructions text,

  grade int, -- 7-12 (si lo querés restringir, agregamos CHECK)
  duration_minutes int NOT NULL CHECK (duration_minutes > 0),

  status exam_status NOT NULL DEFAULT 'draft',

  max_attempts int NOT NULL DEFAULT 1 CHECK (max_attempts >= 1),
  show_results_immediately boolean NOT NULL DEFAULT false,
  allow_review_after_submission boolean NOT NULL DEFAULT false,
  randomize_questions boolean NOT NULL DEFAULT false,

  available_from timestamptz,
  available_until timestamptz,

  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_exams_institution ON exams(institution_id);
CREATE INDEX IF NOT EXISTS idx_exams_teacher ON exams(created_by_teacher_id);
CREATE INDEX IF NOT EXISTS idx_exams_subject ON exams(subject_id);
CREATE INDEX IF NOT EXISTS idx_exams_status ON exams(status);

-- Destinatarios del examen (grupos)
CREATE TABLE IF NOT EXISTS exam_targets (
  exam_id uuid NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
  group_id uuid NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
  institution_id uuid NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
  PRIMARY KEY (exam_id, group_id)
);

CREATE INDEX IF NOT EXISTS idx_exam_targets_group ON exam_targets(group_id);
CREATE INDEX IF NOT EXISTS idx_exam_targets_institution ON exam_targets(institution_id);

-- Preguntas (ajustado al modelo Question)
CREATE TABLE IF NOT EXISTS questions (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  institution_id uuid NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,

  exam_id uuid NOT NULL REFERENCES exams(id) ON DELETE CASCADE,

  question_text text NOT NULL,
  question_type question_type NOT NULL,

  points int NOT NULL DEFAULT 1 CHECK (points >= 0),
  correct_answer_text text,    -- para short_answer

  order_index int NOT NULL DEFAULT 0 CHECK (order_index >= 0),

  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),

  UNIQUE (exam_id, order_index)
);

CREATE INDEX IF NOT EXISTS idx_questions_exam ON questions(exam_id);
CREATE INDEX IF NOT EXISTS idx_questions_institution ON questions(institution_id);

-- Opciones (bigserial, sin timestamps)
CREATE TABLE IF NOT EXISTS question_options (
  id bigserial PRIMARY KEY,
  institution_id uuid NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,

  question_id uuid NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
  option_index int NOT NULL CHECK (option_index >= 0),
  option_text text NOT NULL,
  is_correct boolean NOT NULL DEFAULT false,

  UNIQUE (question_id, option_index)
);

CREATE INDEX IF NOT EXISTS idx_options_question ON question_options(question_id);
CREATE INDEX IF NOT EXISTS idx_question_options_institution ON question_options(institution_id);

-- Intentos
CREATE TABLE IF NOT EXISTS exam_attempts (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),

  exam_id uuid NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
  student_user_id uuid NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,

  attempt_number int NOT NULL DEFAULT 1 CHECK (attempt_number >= 1),

  started_at timestamptz,
  submitted_at timestamptz,

  score numeric(10,2) NOT NULL DEFAULT 0 CHECK (score >= 0),
  max_score numeric(10,2) NOT NULL DEFAULT 0 CHECK (max_score >= 0),

  grade_status grade_status NOT NULL DEFAULT 'pending',

  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),

  UNIQUE (exam_id, student_user_id, attempt_number)
);

CREATE INDEX IF NOT EXISTS idx_attempts_exam ON exam_attempts(exam_id);
CREATE INDEX IF NOT EXISTS idx_attempts_student ON exam_attempts(student_user_id);
CREATE INDEX IF NOT EXISTS idx_attempts_exam_submitted ON exam_attempts(exam_id, submitted_at);

-- Respuestas del estudiante (ajustado a StudentAnswer)
CREATE TABLE IF NOT EXISTS student_answers (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  institution_id uuid NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,

  attempt_id uuid NOT NULL REFERENCES exam_attempts(id) ON DELETE CASCADE,
  question_id uuid NOT NULL REFERENCES questions(id) ON DELETE CASCADE,

  answer_text text,

  is_correct boolean,
  points_awarded numeric(10,2) NOT NULL DEFAULT 0 CHECK (points_awarded >= 0),

  correct_answer_snapshot jsonb,
  explanation text,

  answered_at timestamptz,
  review_status text, -- 'auto_graded' | 'needs_review' | 'reviewed' (si querés lo pasamos a enum)

  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),

  UNIQUE (attempt_id, question_id)
);

CREATE INDEX IF NOT EXISTS idx_answers_attempt ON student_answers(attempt_id);
CREATE INDEX IF NOT EXISTS idx_answers_question ON student_answers(question_id);
CREATE INDEX IF NOT EXISTS idx_student_answers_institution ON student_answers(institution_id);

-- Pivot respuesta-opciones seleccionadas
CREATE TABLE IF NOT EXISTS student_answer_options (
  student_answer_id uuid NOT NULL REFERENCES student_answers(id) ON DELETE CASCADE,
  option_id bigint NOT NULL REFERENCES question_options(id) ON DELETE CASCADE,
  institution_id uuid NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,
  PRIMARY KEY (student_answer_id, option_id)
);

CREATE INDEX IF NOT EXISTS idx_answer_options_option ON student_answer_options(option_id);
CREATE INDEX IF NOT EXISTS idx_student_answer_options_institution ON student_answer_options(institution_id);

-- Progreso (sin created_at, solo updated_at)
CREATE TABLE IF NOT EXISTS student_progress (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  student_user_id uuid NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
  subject_id uuid NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,

  mastery_percentage numeric(5,2) NOT NULL DEFAULT 0 CHECK (mastery_percentage BETWEEN 0 AND 100),
  updated_at timestamptz NOT NULL DEFAULT now(),

  UNIQUE (student_user_id, subject_id)
);

CREATE INDEX IF NOT EXISTS idx_progress_student ON student_progress(student_user_id);

-- Recursos de estudio (ajustado a StudyResource)
CREATE TABLE IF NOT EXISTS study_resources (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  institution_id uuid NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,

  title text NOT NULL,
  description text,
  resource_type resource_type NOT NULL DEFAULT 'other',
  url text,

  estimated_duration int,
  difficulty text,      -- basic | intermediate | advanced
  grade_min int,
  grade_max int,
  language text NOT NULL DEFAULT 'es',

  created_by uuid REFERENCES users(id),

  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_resources_institution ON study_resources(institution_id);

-- Recomendaciones IA (ajustado a AiRecommendation)
CREATE TABLE IF NOT EXISTS ai_recommendations (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  institution_id uuid NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,

  student_user_id uuid NOT NULL REFERENCES students(user_id) ON DELETE CASCADE,
  subject_id uuid REFERENCES subjects(id) ON DELETE SET NULL,
  exam_id uuid REFERENCES exams(id) ON DELETE SET NULL,

  type ai_recommendation_type NOT NULL,
  recommendation_text text NOT NULL,
  resource jsonb,

  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_ai_student ON ai_recommendations(student_user_id);
CREATE INDEX IF NOT EXISTS idx_ai_institution ON ai_recommendations(institution_id);

-- Eventos calendario (ajustado a CalendarEvent)
CREATE TABLE IF NOT EXISTS calendar_events (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  institution_id uuid NOT NULL REFERENCES institutions(id) ON DELETE CASCADE,

  title text NOT NULL,
  description text,

  start_at timestamptz NOT NULL,
  end_at timestamptz NOT NULL CHECK (end_at > start_at),

  event_type calendar_event_type NOT NULL,

  exam_id uuid REFERENCES exams(id) ON DELETE SET NULL,
  group_id uuid REFERENCES groups(id) ON DELETE SET NULL,

  created_by uuid REFERENCES users(id) ON DELETE SET NULL,

  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_calendar_institution ON calendar_events(institution_id);
CREATE INDEX IF NOT EXISTS idx_calendar_time ON calendar_events(start_at, end_at);
