--
-- PostgreSQL database dump
--


-- Dumped from database version 17.9
-- Dumped by pg_dump version 17.9

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: -
--



--
-- Name: adecuacion_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.adecuacion_type AS ENUM (
    'acceso',
    'contenido',
    'evaluacion'
);


--
-- Name: ai_recommendation_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.ai_recommendation_type AS ENUM (
    'strength',
    'weakness',
    'resource',
    'action'
);


--
-- Name: calendar_event_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.calendar_event_type AS ENUM (
    'exam',
    'activity',
    'reminder',
    'meeting'
);


--
-- Name: exam_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.exam_status AS ENUM (
    'draft',
    'published',
    'active',
    'completed'
);


--
-- Name: grade_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.grade_status AS ENUM (
    'pending',
    'graded',
    'completed'
);


--
-- Name: learning_style; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.learning_style AS ENUM (
    'visual',
    'auditivo',
    'lector'
);


--
-- Name: question_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.question_type AS ENUM (
    'multiple_choice',
    'true_false',
    'short_answer',
    'essay'
);


--
-- Name: resource_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.resource_type AS ENUM (
    'video',
    'article',
    'exercise',
    'book',
    'pdf',
    'link',
    'other'
);


--
-- Name: review_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.review_status AS ENUM (
    'auto_graded',
    'needs_review',
    'reviewed'
);


--
-- Name: student_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.student_status AS ENUM (
    'active',
    'inactive',
    'suspended'
);


--
-- Name: user_status; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.user_status AS ENUM (
    'active',
    'inactive',
    'suspended'
);


--
-- Name: user_type; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.user_type AS ENUM (
    'admin',
    'teacher',
    'student',
    'parent'
);


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: ai_chat_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_chat_sessions (
    id uuid NOT NULL,
    institution_id uuid NOT NULL,
    student_user_id uuid NOT NULL,
    subject_id uuid,
    exam_id uuid,
    ended_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    messages jsonb DEFAULT '[]'::jsonb NOT NULL
);


--
-- Name: ai_recommendations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ai_recommendations (
    id uuid NOT NULL,
    institution_id uuid NOT NULL,
    student_user_id uuid NOT NULL,
    subject_id uuid NOT NULL,
    exam_id uuid,
    recommendation_type public.ai_recommendation_type NOT NULL,
    recommendation_text text NOT NULL,
    resource json,
    generated_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: calendar_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.calendar_events (
    id uuid NOT NULL,
    institution_id uuid NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    start_at timestamp(0) without time zone,
    end_at timestamp(0) without time zone,
    event_type public.calendar_event_type NOT NULL,
    exam_id uuid,
    group_id uuid,
    created_by uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: exam_attempts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.exam_attempts (
    id uuid NOT NULL,
    institution_id uuid NOT NULL,
    exam_id uuid NOT NULL,
    student_user_id uuid NOT NULL,
    attempt_number integer DEFAULT 1 NOT NULL,
    started_at timestamp(0) without time zone,
    submitted_at timestamp(0) without time zone,
    score numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    max_score numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    grade_status public.grade_status DEFAULT 'pending'::public.grade_status NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    paused_at timestamp(0) without time zone,
    total_paused_seconds integer DEFAULT 0 NOT NULL
);


--
-- Name: exam_targets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.exam_targets (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    institution_id uuid NOT NULL,
    exam_id uuid NOT NULL,
    group_id uuid NOT NULL
);


--
-- Name: exams; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.exams (
    id uuid NOT NULL,
    institution_id uuid NOT NULL,
    created_by_teacher_id uuid,
    title character varying(255) NOT NULL,
    subject_id uuid NOT NULL,
    grade integer NOT NULL,
    instructions text,
    duration_minutes integer,
    status public.exam_status DEFAULT 'draft'::public.exam_status NOT NULL,
    max_attempts integer DEFAULT 1 NOT NULL,
    show_results_immediately boolean DEFAULT false NOT NULL,
    allow_review_after_submission boolean DEFAULT false NOT NULL,
    randomize_questions boolean DEFAULT false NOT NULL,
    available_from timestamp(0) without time zone,
    available_until timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: group_students; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.group_students (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    institution_id uuid NOT NULL,
    group_id uuid NOT NULL,
    student_user_id uuid NOT NULL,
    joined_at timestamp(0) without time zone,
    left_at timestamp(0) without time zone
);


--
-- Name: groups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.groups (
    id uuid NOT NULL,
    institution_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    grade integer NOT NULL,
    section character varying(255),
    year character varying(255),
    group_code character varying(255),
    student_count integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: institutions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.institutions (
    id uuid NOT NULL,
    code character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    address text,
    phone character varying(255),
    email character varying(255),
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    settings jsonb
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id uuid NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: question_options; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.question_options (
    id bigint NOT NULL,
    institution_id uuid NOT NULL,
    question_id uuid NOT NULL,
    option_index integer NOT NULL,
    option_text text NOT NULL,
    is_correct boolean DEFAULT false NOT NULL
);


--
-- Name: question_options_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.question_options_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: question_options_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.question_options_id_seq OWNED BY public.question_options.id;


--
-- Name: questions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.questions (
    id uuid NOT NULL,
    institution_id uuid NOT NULL,
    exam_id uuid NOT NULL,
    question_text text NOT NULL,
    question_type public.question_type NOT NULL,
    points integer DEFAULT 1 NOT NULL,
    correct_answer_text text,
    order_index integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: student_answer_options; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.student_answer_options (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    institution_id uuid NOT NULL,
    student_answer_id uuid NOT NULL,
    option_id bigint NOT NULL
);


--
-- Name: student_answers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.student_answers (
    id uuid NOT NULL,
    institution_id uuid NOT NULL,
    attempt_id uuid NOT NULL,
    question_id uuid NOT NULL,
    answer_text text,
    is_correct boolean DEFAULT false NOT NULL,
    points_awarded numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    correct_answer_snapshot json,
    explanation text,
    answered_at timestamp(0) without time zone,
    review_status public.review_status DEFAULT 'auto_graded'::public.review_status NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: student_progress; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.student_progress (
    id uuid NOT NULL,
    institution_id uuid NOT NULL,
    student_user_id uuid NOT NULL,
    subject_id uuid NOT NULL,
    mastery_percentage numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    updated_at timestamp(0) without time zone,
    reset_at timestamp(0) without time zone
);


--
-- Name: student_subjects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.student_subjects (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    institution_id uuid NOT NULL,
    student_user_id uuid NOT NULL,
    subject_id uuid NOT NULL,
    enrolled_at timestamp(0) with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: students; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.students (
    user_id uuid NOT NULL,
    institution_id uuid NOT NULL,
    student_code character varying(255),
    grade integer,
    section character varying(255),
    year integer,
    status public.student_status DEFAULT 'active'::public.student_status NOT NULL,
    enrolled_at timestamp(0) without time zone,
    last_activity_at timestamp(0) without time zone,
    exams_completed_count integer DEFAULT 0 NOT NULL,
    overall_average numeric(5,2),
    birth_date date,
    parent_name character varying(255),
    parent_email character varying(255),
    group_code character varying(255),
    adecuacion_type public.adecuacion_type,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    learning_style public.learning_style
);


--
-- Name: study_resources; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.study_resources (
    id uuid NOT NULL,
    institution_id uuid NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    resource_type public.resource_type NOT NULL,
    url character varying(255) NOT NULL,
    estimated_duration integer,
    difficulty character varying(255),
    grade_min integer,
    grade_max integer,
    language character varying(255) DEFAULT 'es'::character varying NOT NULL,
    created_by uuid,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT study_resources_difficulty_check CHECK (((difficulty)::text = ANY ((ARRAY['basic'::character varying, 'intermediate'::character varying, 'advanced'::character varying])::text[])))
);


--
-- Name: subjects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subjects (
    id uuid NOT NULL,
    institution_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id uuid NOT NULL,
    institution_id uuid,
    email character varying(255) NOT NULL,
    password_hash character varying(255) NOT NULL,
    full_name character varying(255) NOT NULL,
    user_type public.user_type NOT NULL,
    status public.user_status DEFAULT 'active'::public.user_status NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: question_options id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.question_options ALTER COLUMN id SET DEFAULT nextval('public.question_options_id_seq'::regclass);


--
-- Name: ai_chat_sessions ai_chat_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_chat_sessions
    ADD CONSTRAINT ai_chat_sessions_pkey PRIMARY KEY (id);


--
-- Name: ai_recommendations ai_recommendations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_recommendations
    ADD CONSTRAINT ai_recommendations_pkey PRIMARY KEY (id);


--
-- Name: calendar_events calendar_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.calendar_events
    ADD CONSTRAINT calendar_events_pkey PRIMARY KEY (id);


--
-- Name: exam_attempts exam_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_attempts
    ADD CONSTRAINT exam_attempts_pkey PRIMARY KEY (id);


--
-- Name: exam_targets exam_targets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_targets
    ADD CONSTRAINT exam_targets_pkey PRIMARY KEY (id);


--
-- Name: exams exams_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exams
    ADD CONSTRAINT exams_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: group_students group_students_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_students
    ADD CONSTRAINT group_students_pkey PRIMARY KEY (id);


--
-- Name: groups groups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.groups
    ADD CONSTRAINT groups_pkey PRIMARY KEY (id);


--
-- Name: institutions institutions_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.institutions
    ADD CONSTRAINT institutions_code_unique UNIQUE (code);


--
-- Name: institutions institutions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.institutions
    ADD CONSTRAINT institutions_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: question_options question_options_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.question_options
    ADD CONSTRAINT question_options_pkey PRIMARY KEY (id);


--
-- Name: questions questions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.questions
    ADD CONSTRAINT questions_pkey PRIMARY KEY (id);


--
-- Name: student_answer_options student_answer_options_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_answer_options
    ADD CONSTRAINT student_answer_options_pkey PRIMARY KEY (id);


--
-- Name: student_answers student_answers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_answers
    ADD CONSTRAINT student_answers_pkey PRIMARY KEY (id);


--
-- Name: student_progress student_progress_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_progress
    ADD CONSTRAINT student_progress_pkey PRIMARY KEY (id);


--
-- Name: student_subjects student_subjects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_subjects
    ADD CONSTRAINT student_subjects_pkey PRIMARY KEY (id);


--
-- Name: student_subjects student_subjects_student_user_id_subject_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_subjects
    ADD CONSTRAINT student_subjects_student_user_id_subject_id_unique UNIQUE (student_user_id, subject_id);


--
-- Name: students students_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_pkey PRIMARY KEY (user_id);


--
-- Name: students students_student_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_student_code_unique UNIQUE (student_code);


--
-- Name: study_resources study_resources_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.study_resources
    ADD CONSTRAINT study_resources_pkey PRIMARY KEY (id);


--
-- Name: subjects subjects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subjects
    ADD CONSTRAINT subjects_pkey PRIMARY KEY (id);


--
-- Name: group_students uniq_group_students; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_students
    ADD CONSTRAINT uniq_group_students UNIQUE (group_id, student_user_id);


--
-- Name: student_progress uniq_progress_student_subject; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_progress
    ADD CONSTRAINT uniq_progress_student_subject UNIQUE (student_user_id, subject_id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: ai_chat_sessions_student_user_id_updated_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ai_chat_sessions_student_user_id_updated_at_index ON public.ai_chat_sessions USING btree (student_user_id, updated_at);


--
-- Name: idx_ai_exam; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_exam ON public.ai_recommendations USING btree (exam_id);


--
-- Name: idx_ai_institution; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_institution ON public.ai_recommendations USING btree (institution_id);


--
-- Name: idx_ai_recs_regen_filter; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_recs_regen_filter ON public.ai_recommendations USING btree (student_user_id, exam_id, subject_id);


--
-- Name: idx_ai_student; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_student ON public.ai_recommendations USING btree (student_user_id);


--
-- Name: idx_ai_subject; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ai_subject ON public.ai_recommendations USING btree (subject_id);


--
-- Name: idx_answer_options_answer; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_answer_options_answer ON public.student_answer_options USING btree (student_answer_id);


--
-- Name: idx_answers_attempt; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_answers_attempt ON public.student_answers USING btree (attempt_id);


--
-- Name: idx_answers_question; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_answers_question ON public.student_answers USING btree (question_id);


--
-- Name: idx_answers_review_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_answers_review_status ON public.student_answers USING btree (review_status);


--
-- Name: idx_attempts_exam; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_attempts_exam ON public.exam_attempts USING btree (exam_id);


--
-- Name: idx_attempts_exam_submitted; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_attempts_exam_submitted ON public.exam_attempts USING btree (exam_id, submitted_at);


--
-- Name: idx_attempts_grade_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_attempts_grade_status ON public.exam_attempts USING btree (grade_status);


--
-- Name: idx_attempts_institution; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_attempts_institution ON public.exam_attempts USING btree (institution_id);


--
-- Name: idx_attempts_student; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_attempts_student ON public.exam_attempts USING btree (student_user_id);


--
-- Name: idx_attempts_submitted; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_attempts_submitted ON public.exam_attempts USING btree (submitted_at);


--
-- Name: idx_chat_sessions_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_chat_sessions_active ON public.ai_chat_sessions USING btree (student_user_id, ended_at);


--
-- Name: idx_exam_targets_exam; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_exam_targets_exam ON public.exam_targets USING btree (exam_id);


--
-- Name: idx_exam_targets_group; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_exam_targets_group ON public.exam_targets USING btree (group_id);


--
-- Name: idx_group_students_group; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_group_students_group ON public.group_students USING btree (group_id);


--
-- Name: idx_group_students_student; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_group_students_student ON public.group_students USING btree (student_user_id);


--
-- Name: idx_progress_institution; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_progress_institution ON public.student_progress USING btree (institution_id);


--
-- Name: idx_progress_student; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_progress_student ON public.student_progress USING btree (student_user_id);


--
-- Name: idx_progress_subject; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_progress_subject ON public.student_progress USING btree (subject_id);


--
-- Name: idx_questions_exam_order; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_questions_exam_order ON public.questions USING btree (exam_id, order_index);


--
-- Name: idx_students_grade; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_students_grade ON public.students USING btree (grade);


--
-- Name: idx_students_institution; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_students_institution ON public.students USING btree (institution_id);


--
-- Name: idx_students_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_students_status ON public.students USING btree (status);


--
-- Name: idx_users_institution; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_users_institution ON public.users USING btree (institution_id);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: password_reset_tokens_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX password_reset_tokens_created_at_index ON public.password_reset_tokens USING btree (created_at);


--
-- Name: password_reset_tokens_token_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX password_reset_tokens_token_index ON public.password_reset_tokens USING btree (token);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: student_subjects_institution_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX student_subjects_institution_id_index ON public.student_subjects USING btree (institution_id);


--
-- Name: subjects_institution_name_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX subjects_institution_name_unique ON public.subjects USING btree (institution_id, lower(btrim((name)::text)));


--
-- Name: ai_chat_sessions ai_chat_sessions_exam_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_chat_sessions
    ADD CONSTRAINT ai_chat_sessions_exam_id_foreign FOREIGN KEY (exam_id) REFERENCES public.exams(id) ON DELETE SET NULL;


--
-- Name: ai_chat_sessions ai_chat_sessions_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_chat_sessions
    ADD CONSTRAINT ai_chat_sessions_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE CASCADE;


--
-- Name: ai_chat_sessions ai_chat_sessions_student_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_chat_sessions
    ADD CONSTRAINT ai_chat_sessions_student_user_id_foreign FOREIGN KEY (student_user_id) REFERENCES public.students(user_id) ON DELETE CASCADE;


--
-- Name: ai_chat_sessions ai_chat_sessions_subject_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_chat_sessions
    ADD CONSTRAINT ai_chat_sessions_subject_id_foreign FOREIGN KEY (subject_id) REFERENCES public.subjects(id) ON DELETE SET NULL;


--
-- Name: ai_recommendations ai_recommendations_exam_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_recommendations
    ADD CONSTRAINT ai_recommendations_exam_id_foreign FOREIGN KEY (exam_id) REFERENCES public.exams(id) ON DELETE SET NULL;


--
-- Name: ai_recommendations ai_recommendations_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_recommendations
    ADD CONSTRAINT ai_recommendations_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE CASCADE;


--
-- Name: ai_recommendations ai_recommendations_student_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_recommendations
    ADD CONSTRAINT ai_recommendations_student_user_id_foreign FOREIGN KEY (student_user_id) REFERENCES public.students(user_id) ON DELETE CASCADE;


--
-- Name: ai_recommendations ai_recommendations_subject_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ai_recommendations
    ADD CONSTRAINT ai_recommendations_subject_id_foreign FOREIGN KEY (subject_id) REFERENCES public.subjects(id) ON DELETE SET NULL;


--
-- Name: calendar_events calendar_events_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.calendar_events
    ADD CONSTRAINT calendar_events_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: calendar_events calendar_events_exam_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.calendar_events
    ADD CONSTRAINT calendar_events_exam_id_foreign FOREIGN KEY (exam_id) REFERENCES public.exams(id) ON DELETE SET NULL;


--
-- Name: calendar_events calendar_events_group_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.calendar_events
    ADD CONSTRAINT calendar_events_group_id_foreign FOREIGN KEY (group_id) REFERENCES public.groups(id) ON DELETE SET NULL;


--
-- Name: calendar_events calendar_events_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.calendar_events
    ADD CONSTRAINT calendar_events_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE CASCADE;


--
-- Name: exam_attempts exam_attempts_exam_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_attempts
    ADD CONSTRAINT exam_attempts_exam_id_foreign FOREIGN KEY (exam_id) REFERENCES public.exams(id) ON DELETE CASCADE;


--
-- Name: exam_attempts exam_attempts_student_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_attempts
    ADD CONSTRAINT exam_attempts_student_user_id_foreign FOREIGN KEY (student_user_id) REFERENCES public.students(user_id) ON DELETE CASCADE;


--
-- Name: exam_targets exam_targets_exam_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_targets
    ADD CONSTRAINT exam_targets_exam_id_foreign FOREIGN KEY (exam_id) REFERENCES public.exams(id) ON DELETE CASCADE;


--
-- Name: exam_targets exam_targets_group_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_targets
    ADD CONSTRAINT exam_targets_group_id_foreign FOREIGN KEY (group_id) REFERENCES public.groups(id) ON DELETE CASCADE;


--
-- Name: exam_targets exam_targets_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exam_targets
    ADD CONSTRAINT exam_targets_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE CASCADE;


--
-- Name: exams exams_created_by_teacher_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exams
    ADD CONSTRAINT exams_created_by_teacher_id_foreign FOREIGN KEY (created_by_teacher_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: exams exams_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exams
    ADD CONSTRAINT exams_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE CASCADE;


--
-- Name: exams exams_subject_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.exams
    ADD CONSTRAINT exams_subject_id_foreign FOREIGN KEY (subject_id) REFERENCES public.subjects(id) ON DELETE CASCADE;


--
-- Name: group_students group_students_group_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_students
    ADD CONSTRAINT group_students_group_id_foreign FOREIGN KEY (group_id) REFERENCES public.groups(id) ON DELETE CASCADE;


--
-- Name: group_students group_students_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_students
    ADD CONSTRAINT group_students_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id);


--
-- Name: group_students group_students_student_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.group_students
    ADD CONSTRAINT group_students_student_user_id_foreign FOREIGN KEY (student_user_id) REFERENCES public.students(user_id) ON DELETE CASCADE;


--
-- Name: groups groups_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.groups
    ADD CONSTRAINT groups_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE CASCADE;


--
-- Name: question_options question_options_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.question_options
    ADD CONSTRAINT question_options_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE CASCADE;


--
-- Name: question_options question_options_question_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.question_options
    ADD CONSTRAINT question_options_question_id_foreign FOREIGN KEY (question_id) REFERENCES public.questions(id) ON DELETE CASCADE;


--
-- Name: questions questions_exam_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.questions
    ADD CONSTRAINT questions_exam_id_foreign FOREIGN KEY (exam_id) REFERENCES public.exams(id) ON DELETE CASCADE;


--
-- Name: questions questions_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.questions
    ADD CONSTRAINT questions_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE CASCADE;


--
-- Name: student_answer_options student_answer_options_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_answer_options
    ADD CONSTRAINT student_answer_options_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE CASCADE;


--
-- Name: student_answer_options student_answer_options_option_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_answer_options
    ADD CONSTRAINT student_answer_options_option_id_foreign FOREIGN KEY (option_id) REFERENCES public.question_options(id) ON DELETE CASCADE;


--
-- Name: student_answer_options student_answer_options_student_answer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_answer_options
    ADD CONSTRAINT student_answer_options_student_answer_id_foreign FOREIGN KEY (student_answer_id) REFERENCES public.student_answers(id) ON DELETE CASCADE;


--
-- Name: student_answers student_answers_attempt_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_answers
    ADD CONSTRAINT student_answers_attempt_id_foreign FOREIGN KEY (attempt_id) REFERENCES public.exam_attempts(id) ON DELETE CASCADE;


--
-- Name: student_answers student_answers_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_answers
    ADD CONSTRAINT student_answers_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE CASCADE;


--
-- Name: student_answers student_answers_question_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_answers
    ADD CONSTRAINT student_answers_question_id_foreign FOREIGN KEY (question_id) REFERENCES public.questions(id) ON DELETE CASCADE;


--
-- Name: student_progress student_progress_student_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_progress
    ADD CONSTRAINT student_progress_student_user_id_foreign FOREIGN KEY (student_user_id) REFERENCES public.students(user_id) ON DELETE CASCADE;


--
-- Name: student_progress student_progress_subject_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_progress
    ADD CONSTRAINT student_progress_subject_id_foreign FOREIGN KEY (subject_id) REFERENCES public.subjects(id) ON DELETE CASCADE;


--
-- Name: student_subjects student_subjects_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_subjects
    ADD CONSTRAINT student_subjects_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE CASCADE;


--
-- Name: student_subjects student_subjects_student_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_subjects
    ADD CONSTRAINT student_subjects_student_user_id_foreign FOREIGN KEY (student_user_id) REFERENCES public.students(user_id) ON DELETE CASCADE;


--
-- Name: student_subjects student_subjects_subject_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_subjects
    ADD CONSTRAINT student_subjects_subject_id_foreign FOREIGN KEY (subject_id) REFERENCES public.subjects(id) ON DELETE CASCADE;


--
-- Name: students students_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id);


--
-- Name: students students_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.students
    ADD CONSTRAINT students_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: study_resources study_resources_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.study_resources
    ADD CONSTRAINT study_resources_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: study_resources study_resources_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.study_resources
    ADD CONSTRAINT study_resources_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE CASCADE;


--
-- Name: subjects subjects_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subjects
    ADD CONSTRAINT subjects_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE CASCADE;


--
-- Name: users users_institution_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_institution_id_foreign FOREIGN KEY (institution_id) REFERENCES public.institutions(id) ON DELETE SET NULL;


--
-- Name: ai_chat_sessions; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.ai_chat_sessions ENABLE ROW LEVEL SECURITY;
--
-- Name: ai_recommendations; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.ai_recommendations ENABLE ROW LEVEL SECURITY;
--
-- Name: calendar_events; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.calendar_events ENABLE ROW LEVEL SECURITY;
--
-- Name: exam_attempts; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.exam_attempts ENABLE ROW LEVEL SECURITY;
--
-- Name: exam_targets; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.exam_targets ENABLE ROW LEVEL SECURITY;
--
-- Name: exams; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.exams ENABLE ROW LEVEL SECURITY;
--
-- Name: failed_jobs; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.failed_jobs ENABLE ROW LEVEL SECURITY;
--
-- Name: group_students; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.group_students ENABLE ROW LEVEL SECURITY;
--
-- Name: groups; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.groups ENABLE ROW LEVEL SECURITY;
--
-- Name: institutions; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.institutions ENABLE ROW LEVEL SECURITY;
--
-- Name: jobs; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.jobs ENABLE ROW LEVEL SECURITY;
--
-- Name: migrations; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.migrations ENABLE ROW LEVEL SECURITY;
--
-- Name: password_reset_tokens; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.password_reset_tokens ENABLE ROW LEVEL SECURITY;
--
-- Name: personal_access_tokens; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.personal_access_tokens ENABLE ROW LEVEL SECURITY;
--
-- Name: question_options; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.question_options ENABLE ROW LEVEL SECURITY;
--
-- Name: questions; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.questions ENABLE ROW LEVEL SECURITY;
--
-- Name: student_answer_options; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.student_answer_options ENABLE ROW LEVEL SECURITY;
--
-- Name: student_answers; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.student_answers ENABLE ROW LEVEL SECURITY;
--
-- Name: student_progress; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.student_progress ENABLE ROW LEVEL SECURITY;
--
-- Name: student_subjects; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.student_subjects ENABLE ROW LEVEL SECURITY;
--
-- Name: students; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.students ENABLE ROW LEVEL SECURITY;
--
-- Name: study_resources; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.study_resources ENABLE ROW LEVEL SECURITY;
--
-- Name: subjects; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.subjects ENABLE ROW LEVEL SECURITY;
--
-- Name: users; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
--
-- PostgreSQL database dump complete
--


