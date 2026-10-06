<?php

namespace Database\Seeders;

use App\Enums\UserType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Aula completa de 4.º de primaria, para el taller de simulación.
 *
 * **Vacía la base y la vuelve a llenar.** Lo único que respeta es la cuenta de
 * superadministrador: es la que administra instituciones y solo se crea por
 * consola, así que borrarla dejaría a quien dirija el taller fuera de su propia
 * plataforma.
 *
 * Lo que siembra está pensado para que **ninguna pantalla salga vacía**: hay
 * alumnado con notas repartidas para que los histogramas tengan forma, temas
 * etiquetados para que el diagnóstico por tema diga algo, y un docente sin
 * asignaciones para poder enseñar en vivo que no ve a nadie.
 *
 * Reproducible: los identificadores y las notas salen de una secuencia fija, no
 * de `fake()`. Dos ejecuciones dan el mismo aula, que es lo que hace falta para
 * preparar un guion de taller y que no se mueva bajo los pies.
 *
 *     php artisan db:seed --class=TallerAulaSeeder
 */
class TallerAulaSeeder extends Seeder
{
    /** Contraseña única para todas las cuentas del taller. */
    public const CLAVE = 'Taller2026';

    private const DOMINIO = 'nuevaesperanza.ed.cr';

    /** Orden de borrado: de las hojas a las raíces. */
    private const TABLAS_A_VACIAR = [
        'student_answer_options', 'student_answers',
        'ai_recommendations', 'ai_tutor_incidents', 'ai_chat_sessions',
        'exam_attempts', 'student_progress', 'student_subjects', 'group_students',
        'exam_targets', 'question_options', 'questions',
        'calendar_events', 'exams', 'teacher_assignments', 'study_resource_groups', 'study_resources',
        'students',
    ];

    /** 28 alumnos: un aula de verdad, no tres de muestra. */
    private const ALUMNADO = [
        ['Ariana Solís Vargas', 'visual'],      ['Bryan Mora Jiménez', 'auditivo'],
        ['Camila Rojas Núñez', 'lector'],       ['Diego Álvarez Cordero', 'visual'],
        ['Elena Castro Salas', 'lector'],       ['Fabián Herrera Vega', 'auditivo'],
        ['Gabriela Ureña Picado', 'visual'],    ['Hugo Montero Brenes', 'visual'],
        ['Isabela Quirós Ramírez', 'lector'],   ['Joaquín Vargas Soto', 'auditivo'],
        ['Karla Fernández Arias', 'visual'],    ['Luis Chaves Delgado', 'lector'],
        ['Mariana Campos Rivera', 'visual'],    ['Nicolás Guzmán Peña', 'auditivo'],
        ['Olivia Sánchez Mora', 'lector'],      ['Pablo Zúñiga Blanco', 'visual'],
        ['Quirós Adrián Leiva', 'auditivo'],    ['Raquel Obando Fuentes', 'lector'],
        ['Samuel Navarro Cruz', 'visual'],      ['Tamara Esquivel Rojas', 'auditivo'],
        ['Ulises Bonilla Marín', 'lector'],     ['Valeria Segura Acuña', 'visual'],
        ['Wálter Jiménez Loría', 'auditivo'],   ['Ximena Retana Solano', 'lector'],
        ['Yerlin Araya Céspedes', 'visual'],    ['Zaid Portillo Umaña', 'auditivo'],
        ['Andrés Villalobos Paz', 'lector'],    ['Bianca Sandí Trejos', 'visual'],
    ];

    /** 6 alumnos de la segunda aula, 4-B: para ver que cada estudiante solo ve lo de la suya. */
    private const ALUMNADO_B = [
        ['Beatriz Cordero Vílchez', 'visual'],  ['Cristian Lobo Mena', 'lector'],
        ['Daniela Porras Elizondo', 'auditivo'], ['Esteban Mata Brenes', 'visual'],
        ['Fiorella Quesada Arce', 'lector'],    ['Gerald Monge Zamora', 'auditivo'],
    ];

    public function run(): void
    {
        $this->command?->warn('Vaciando la base (se conserva la cuenta de superadmin)...');
        $this->vaciar();

        $institutionId = $this->institucion();

        // El tenant scope se alimenta del contenedor; aquí se inserta por DB::table,
        // pero se fija por si algún modelo interviene.
        app()->instance('tenant_id', $institutionId);

        $this->command?->info('Sembrando las aulas 4-A y 4-B...');

        $admin    = $this->admin($institutionId);
        $docentes = $this->docentes($institutionId);
        $materias = $this->materias($institutionId);
        $grupo    = $this->grupo($institutionId, '4-A 2026', 'A', '4A2026', count(self::ALUMNADO));
        $grupoB   = $this->grupo($institutionId, '4-B 2026', 'B', '4B2026', count(self::ALUMNADO_B));

        // 4-A cursa las cuatro materias; 4-B, solo Español (la única con docente asignado allí).
        $alumnos  = $this->alumnado($institutionId, $grupo, array_values($materias), self::ALUMNADO, 'A', '4A2026', 1);
        $alumnosB = $this->alumnado($institutionId, $grupoB, [$materias['Español']], self::ALUMNADO_B, 'B', '4B2026', 101);

        $this->asignaciones($institutionId, $docentes, $grupo, $grupoB, $materias);

        $examen = $this->examenDeMatematicas($institutionId, $docentes['mate'], $materias['Matemáticas'], $grupo);
        $this->entregas($institutionId, $examen, $alumnos, $materias['Matemáticas']);

        $this->recursos($institutionId, $materias, $docentes, $grupo, $grupoB);
        $this->calendario($institutionId, $grupo, $grupoB, $examen, $docentes);

        app()->forgetInstance('tenant_id');

        $this->resumen($alumnos, $alumnosB, $docentes, $admin);
    }

    /* =========================================================
     | Vaciado
     ========================================================= */

    private function vaciar(): void
    {
        foreach (self::TABLAS_A_VACIAR as $tabla) {
            DB::table($tabla)->delete();
        }

        // Los usuarios, uno a uno por rol: el superadmin se queda.
        DB::table('users')->where('user_type', '!=', UserType::SuperAdmin->value)->delete();

        DB::table('groups')->delete();
        DB::table('subjects')->delete();
        DB::table('institutions')->delete();
    }

    /* =========================================================
     | Siembra
     ========================================================= */

    private function institucion(): string
    {
        $id = (string) Str::uuid();

        DB::table('institutions')->insert([
            'id'         => $id,
            'code'       => 'ENE-001',
            'name'       => 'Escuela Nueva Esperanza',
            'address'    => 'Barrio San José, Cartago',
            'phone'      => '2550-1234',
            'email'      => 'direccion@' . self::DOMINIO,
            'is_active'  => true,
            'settings'   => json_encode(['timezone' => 'America/Costa_Rica', 'passing_percentage' => 70]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function admin(string $institutionId): array
    {
        return $this->usuario($institutionId, 'Directora Marta Quesada Ulloa', 'direccion', UserType::Admin);
    }

    /** @return array<string, array{id:string,email:string,full_name:string}> */
    private function docentes(string $institutionId): array
    {
        return [
            'mate'      => $this->usuario($institutionId, 'Docente Rodrigo Pineda Salas', 'rodrigo.pineda', UserType::Teacher),
            'espanol'   => $this->usuario($institutionId, 'Docente Lucía Vindas Ramírez', 'lucia.vindas', UserType::Teacher),
            // A propósito sin asignaciones: sirve para mostrar en vivo que un
            // docente sin grupo asignado no ve a ningún estudiante.
            'sinasignar' => $this->usuario($institutionId, 'Docente Nuevo Andrés Calvo', 'andres.calvo', UserType::Teacher),
        ];
    }

    /** @return array<string,string> nombre => id */
    private function materias(string $institutionId): array
    {
        $materias = [];

        foreach (['Español', 'Matemáticas', 'Ciencias', 'Estudios Sociales'] as $nombre) {
            $id = (string) Str::uuid();

            DB::table('subjects')->insert([
                'id'             => $id,
                'institution_id' => $institutionId,
                'name'           => $nombre,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            $materias[$nombre] = $id;
        }

        return $materias;
    }

    private function grupo(string $institutionId, string $nombre, string $seccion, string $codigo, int $alumnos): string
    {
        $id = (string) Str::uuid();

        DB::table('groups')->insert([
            'id'             => $id,
            'institution_id' => $institutionId,
            'name'           => $nombre,
            'grade'          => 4,
            'section'        => $seccion,
            'year'           => 2026,
            'group_code'     => $codigo,
            'student_count'  => $alumnos,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return $id;
    }

    /**
     * Un aula entera: cuenta, perfil de estudiante, matrícula en el grupo y en
     * las materias que ahí se cursan.
     *
     * @param  array<int,string>  $materias  ids de las materias que cursa el aula
     * @param  array<int,array{0:string,1:string}>  $lista  [nombre, estilo de aprendizaje]
     * @param  int  $primerCodigo  para que los códigos EST-xxxx no choquen entre aulas
     * @return array<int, array{id:string,nombre:string}>
     */
    private function alumnado(
        string $institutionId, string $grupoId, array $materias,
        array $lista, string $seccion, string $codigoAula, int $primerCodigo
    ): array {
        $alumnos = [];

        foreach ($lista as $i => [$nombre, $estilo]) {
            $correo = Str::of($nombre)->lower()->ascii()->explode(' ')->take(2)->implode('.');
            $user   = $this->usuario($institutionId, $nombre, $correo, UserType::Student);

            DB::table('students')->insert([
                'user_id'               => $user['id'],
                'institution_id'        => $institutionId,
                'student_code'          => sprintf('EST-%04d', $primerCodigo + $i),
                'grade'                 => 4,
                'section'               => $seccion,
                'year'                  => 2026,
                'status'                => 'active',
                'group_code'            => $codigoAula,
                'enrolled_at'           => now()->subMonths(6),
                'learning_style'        => $estilo,
                // 4.º de primaria: entre 9 y 10 años.
                'birth_date'            => now()->subYears(10)->subDays($i * 11)->toDateString(),
                'exams_completed_count' => 0,
                // Dos adecuaciones curriculares en el aula, que es lo normal y
                // hace visible el tiempo extra de examen.
                'adecuacion_type'       => $seccion === 'A' && in_array($i, [3, 17], true) ? 'acceso' : null,
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

            DB::table('group_students')->insert([
                'id'              => (string) Str::uuid(),
                'institution_id'  => $institutionId,
                'group_id'        => $grupoId,
                'student_user_id' => $user['id'],
                'joined_at'       => now()->subMonths(6),
            ]);

            foreach ($materias as $materiaId) {
                DB::table('student_subjects')->insert([
                    'id'              => (string) Str::uuid(),
                    'institution_id'  => $institutionId,
                    'student_user_id' => $user['id'],
                    'subject_id'      => $materiaId,
                    'enrolled_at'     => now()->subMonths(6),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }

            $alumnos[] = ['id' => $user['id'], 'nombre' => $nombre];
        }

        return $alumnos;
    }

    /**
     * Sin esto ningún docente ve a ningún estudiante: el permiso sale de aquí,
     * no de haber creado un examen.
     *
     * Rodrigo da clase solo en 4-A. Lucía da Español también en 4-B: con dos aulas,
     * al enviar un recurso o un aviso **tiene que elegir** a cuál(es), y el
     * alumnado de 4-B ve lo suyo y no lo de 4-A.
     */
    private function asignaciones(string $institutionId, array $docentes, string $grupoA, string $grupoB, array $materias): void
    {
        // [docente, aula, materia]
        $reparto = [
            [$docentes['mate']['id'],    $grupoA, 'Matemáticas'],
            [$docentes['mate']['id'],    $grupoA, 'Ciencias'],
            [$docentes['espanol']['id'], $grupoA, 'Español'],
            [$docentes['espanol']['id'], $grupoA, 'Estudios Sociales'],
            [$docentes['espanol']['id'], $grupoB, 'Español'],
        ];

        foreach ($reparto as [$docenteId, $grupoId, $nombre]) {
            DB::table('teacher_assignments')->insert([
                'id'              => (string) Str::uuid(),
                'institution_id'  => $institutionId,
                'teacher_user_id' => $docenteId,
                'group_id'        => $grupoId,
                'subject_id'      => $materias[$nombre],
                'assigned_at'     => now()->subMonths(6),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    /* =========================================================
     | Examen
     ========================================================= */

    /**
     * Examen de fracciones con **metadatos curriculares** en cada ítem: es lo
     | que alimenta el diagnóstico por tema y el reporte de temas a reforzar.
     *
     * @return array{id:string, preguntas:array<int,array{id:string,topic:string,correcta:int}>}
     */
    private function examenDeMatematicas(string $institutionId, array $docente, string $materiaId, string $grupoId): array
    {
        $examenId = (string) Str::uuid();

        DB::table('exams')->insert([
            'id'                            => $examenId,
            'institution_id'                => $institutionId,
            'created_by_teacher_id'         => $docente['id'],
            'title'                         => 'Prueba corta: fracciones y decimales',
            'subject_id'                    => $materiaId,
            'grade'                         => 4,
            'instructions'                  => 'Leé cada pregunta con calma. Podés usar papel para hacer las operaciones.',
            'duration_minutes'              => 40,
            'status'                        => 'active',
            'max_attempts'                  => 2,
            'show_results_immediately'      => true,
            'allow_review_after_submission' => true,
            'randomize_questions'           => false,
            'available_from'                => now()->subDays(7),
            'available_until'               => now()->addDays(14),
            'created_at'                    => now()->subDays(8),
            'updated_at'                    => now()->subDays(8),
        ]);

        DB::table('exam_targets')->insert([
            'id'             => (string) Str::uuid(),
            'institution_id' => $institutionId,
            'exam_id'        => $examenId,
            'group_id'       => $grupoId,
        ]);

        // [enunciado, tema, indicador, dificultad, opciones, índice de la correcta]
        $banco = [
            ['¿Cuánto es 1/2 + 1/4?', 'Fracciones equivalentes', 'MAT.4.2 Suma fracciones de distinto denominador', 'intermediate',
                ['3/4', '2/6', '1/6', '2/4'], 0],
            ['¿Cuál fracción es equivalente a 2/4?', 'Fracciones equivalentes', 'MAT.4.1 Reconoce fracciones equivalentes', 'basic',
                ['1/2', '2/3', '3/4', '1/4'], 0],
            ['¿Cuánto es 3/5 + 1/5?', 'Suma de fracciones', 'MAT.4.2 Suma fracciones de igual denominador', 'basic',
                ['4/5', '4/10', '3/10', '2/5'], 0],
            ['¿Cuánto es 7/8 − 3/8?', 'Resta de fracciones', 'MAT.4.3 Resta fracciones de igual denominador', 'basic',
                ['4/8', '10/8', '4/16', '3/8'], 0],
            ['¿Cómo se escribe 0,25 como fracción?', 'Decimales', 'MAT.4.5 Convierte decimales a fracciones', 'intermediate',
                ['1/4', '1/2', '2/5', '25/10'], 0],
            ['¿Cuál número decimal es mayor: 0,7 u 0,07?', 'Decimales', 'MAT.4.6 Compara números decimales', 'basic',
                ['0,7', '0,07', 'Son iguales', 'No se puede saber'], 0],
            ['Si repartís una pizza en 8 partes iguales y te comés 3, ¿qué fracción quedó?', 'Fracciones equivalentes', 'MAT.4.1 Interpreta fracciones en contexto', 'intermediate',
                ['5/8', '3/8', '8/3', '5/3'], 0],
            ['¿Cuánto es 2,5 + 1,25?', 'Decimales', 'MAT.4.7 Suma números decimales', 'advanced',
                ['3,75', '3,7', '4,75', '2,75'], 0],
        ];

        $preguntas = [];

        foreach ($banco as $orden => [$texto, $tema, $indicador, $dificultad, $opciones, $correcta]) {
            $preguntaId = (string) Str::uuid();

            DB::table('questions')->insert([
                'id'             => $preguntaId,
                'institution_id' => $institutionId,
                'exam_id'        => $examenId,
                'question_text'  => $texto,
                'question_type'  => 'multiple_choice',
                'points'         => 1,
                'order_index'    => $orden + 1,
                'topic'          => $tema,
                'indicator'      => $indicador,
                'difficulty'     => $dificultad,
                'created_at'     => now()->subDays(8),
                'updated_at'     => now()->subDays(8),
            ]);

            foreach ($opciones as $i => $opcion) {
                DB::table('question_options')->insert([
                    'institution_id' => $institutionId,
                    'question_id'    => $preguntaId,
                    'option_index'   => $i,
                    'option_text'    => $opcion,
                    'is_correct'     => $i === $correcta,
                ]);
            }

            $preguntas[] = ['id' => $preguntaId, 'topic' => $tema, 'orden' => $orden];
        }

        return ['id' => $examenId, 'preguntas' => $preguntas];
    }

    /**
     * Entregas con notas repartidas a propósito.
     *
     * 24 de 28 entregaron —cuatro sin entregar, que es lo que pasa en un aula
     * real y hace que el reporte tenga a quién echar en falta—. Las notas van de
     * 2 a 8 sobre 8 recorriendo todos los tramos, para que el histograma y los
     * cuatro niveles de desempeño tengan forma en vez de una sola barra.
     *
     * Los fallos no son aleatorios: se concentran en **decimales**, de modo que
     * el diagnóstico por tema y `GET /reports/topics` señalen algo real en el
     * taller en lugar de ruido.
     */
    private function entregas(string $institutionId, array $examen, array $alumnos, string $materiaId): void
    {
        // Aciertos de cada alumno, sobre 8. Repartidos por todos los tramos.
        $aciertosPorAlumno = [8, 8, 7, 7, 7, 6, 6, 6, 6, 5, 5, 5, 5, 4, 4, 4, 3, 3, 3, 2, 2, 8, 6, 4];

        // Orden en que se van fallando las preguntas: primero las de decimales
        // (índices 4, 5, 7), luego el resto.
        $ordenDeFallo = [7, 4, 5, 0, 6, 3, 2, 1];

        foreach ($aciertosPorAlumno as $i => $aciertos) {
            $alumno    = $alumnos[$i];
            $intentoId = (string) Str::uuid();
            $entregado = now()->subDays(5)->addMinutes($i * 7);

            $falladas = array_slice($ordenDeFallo, 0, 8 - $aciertos);

            DB::table('exam_attempts')->insert([
                'id'                        => $intentoId,
                'institution_id'            => $institutionId,
                'exam_id'                   => $examen['id'],
                'student_user_id'           => $alumno['id'],
                'attempt_number'            => 1,
                'started_at'                => $entregado->copy()->subMinutes(35),
                'submitted_at'              => $entregado,
                'score'                     => $aciertos,
                'max_score'                 => 8,
                'grade_status'              => 'graded',
                'total_paused_seconds'      => 0,
                // Nadie ha abierto todavía sus resultados: así el taller puede
                // enseñar en vivo cómo se encola el análisis de IA al abrirlos.
                'ai_recommendations_status' => null,
                'created_at'                => $entregado,
                'updated_at'                => $entregado,
            ]);

            foreach ($examen['preguntas'] as $pregunta) {
                $acerto = !in_array($pregunta['orden'], $falladas, true);

                DB::table('student_answers')->insert([
                    'id'             => (string) Str::uuid(),
                    'institution_id' => $institutionId,
                    'attempt_id'     => $intentoId,
                    'question_id'    => $pregunta['id'],
                    'is_correct'     => $acerto,
                    'points_awarded' => $acerto ? 1 : 0,
                    'answered_at'    => $entregado,
                    'review_status'  => 'auto_graded',
                    'created_at'     => $entregado,
                    'updated_at'     => $entregado,
                ]);
            }

            DB::table('students')->where('user_id', $alumno['id'])->update([
                'exams_completed_count' => 1,
                'overall_average'       => round($aciertos / 8 * 100, 2),
                'last_activity_at'      => $entregado,
            ]);

            DB::table('student_progress')->insert([
                'id'                 => (string) Str::uuid(),
                'institution_id'     => $institutionId,
                'student_user_id'    => $alumno['id'],
                'subject_id'         => $materiaId,
                'mastery_percentage' => round($aciertos / 8 * 100, 2),
                'updated_at'         => $entregado,
            ]);
        }
    }

    /* =========================================================
     | Material de apoyo
     ========================================================= */

    /**
     * Cada recurso lo crea **el docente que imparte esa materia** y lo envía a
     * aulas suyas: sin envío a un aula, ningún estudiante lo vería. El de lectura
     * de Lucía va a las dos aulas donde da Español.
     */
    private function recursos(string $institutionId, array $materias, array $docentes, string $grupoA, string $grupoB): void
    {
        $mate = $docentes['mate'];
        $esp  = $docentes['espanol'];

        // [título, tipo, url, materia, dificultad, minutos, autor, aulas]
        $catalogo = [
            ['Fracciones equivalentes explicadas', 'video', 'https://es.khanacademy.org/math/fracciones-equivalentes', 'Matemáticas', 'basic', 8, $mate, [$grupoA]],
            ['Práctica de suma de fracciones', 'exercise', 'https://www.geogebra.org/m/fracciones-suma', 'Matemáticas', 'basic', 15, $mate, [$grupoA]],
            ['Decimales paso a paso', 'video', 'https://www.youtube.com/watch?v=decimales-primaria', 'Matemáticas', 'intermediate', 10, $mate, [$grupoA]],
            ['Comprensión de lectura: cuentos cortos', 'article', 'https://es.wikipedia.org/wiki/Comprensión_lectora', 'Español', 'basic', 12, $esp, [$grupoA, $grupoB]],
            ['El ciclo del agua', 'video', 'https://www.youtube.com/watch?v=ciclo-del-agua', 'Ciencias', 'basic', 6, $mate, [$grupoA]],
            ['Provincias de Costa Rica', 'article', 'https://www.mep.go.cr/recursos/provincias', 'Estudios Sociales', 'basic', 10, $esp, [$grupoA]],
        ];

        foreach ($catalogo as [$titulo, $tipo, $url, $materia, $dificultad, $minutos, $docente, $aulas]) {
            $recursoId = (string) Str::uuid();

            DB::table('study_resources')->insert([
                'id'                 => $recursoId,
                'institution_id'     => $institutionId,
                'subject_id'         => $materias[$materia],
                'title'              => $titulo,
                'description'        => 'Material de apoyo para 4.º grado.',
                'resource_type'      => $tipo,
                'url'                => $url,
                'estimated_duration' => $minutos,
                'difficulty'         => $dificultad,
                'grade_min'          => 3,
                'grade_max'          => 6,
                'language'           => 'es',
                'created_by'         => $docente['id'],
                'created_at'         => now()->subDays(20),
                'updated_at'         => now()->subDays(20),
            ]);

            // El envío a las aulas: de aquí sale quién lo ve.
            foreach ($aulas as $aulaId) {
                DB::table('study_resource_groups')->insert([
                    'id'                => (string) Str::uuid(),
                    'institution_id'    => $institutionId,
                    'study_resource_id' => $recursoId,
                    'group_id'          => $aulaId,
                ]);
            }
        }
    }

    /**
     * Avisos del calendario: siempre con aula y de un docente asignado a ella. La
     * reunión de Lucía va a las dos aulas, así que son **dos eventos** (uno por aula).
     */
    private function calendario(string $institutionId, string $grupoA, string $grupoB, array $examen, array $docentes): void
    {
        // [título, tipo, cuándo, examen, autor, aula]
        $eventos = [
            ['Prueba corta de fracciones', 'exam', now()->subDays(5), $examen['id'], $docentes['mate'], $grupoA],
            ['Repaso de decimales', 'activity', now()->addDays(3), null, $docentes['mate'], $grupoA],
            ['Reunión de padres y madres', 'meeting', now()->addDays(10), null, $docentes['espanol'], $grupoA],
            ['Reunión de padres y madres', 'meeting', now()->addDays(10), null, $docentes['espanol'], $grupoB],
        ];

        foreach ($eventos as [$titulo, $tipo, $cuando, $examenId, $docente, $aulaId]) {
            DB::table('calendar_events')->insert([
                'id'             => (string) Str::uuid(),
                'institution_id' => $institutionId,
                'title'          => $titulo,
                'description'    => null,
                'start_at'       => $cuando,
                'end_at'         => $cuando->copy()->addHour(),
                'event_type'     => $tipo,
                'exam_id'        => $examenId,
                'group_id'       => $aulaId,
                'created_by'     => $docente['id'],
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    /* =========================================================
     | Apoyo
     ========================================================= */

    /** @return array{id:string,email:string,full_name:string} */
    private function usuario(string $institutionId, string $nombre, string $usuario, UserType $rol): array
    {
        $id     = (string) Str::uuid();
        $correo = Str::of($usuario)->ascii()->lower()->toString() . '@' . self::DOMINIO;

        DB::table('users')->insert([
            'id'             => $id,
            'institution_id' => $institutionId,
            'email'          => $correo,
            'password_hash'  => Hash::make(self::CLAVE),
            'full_name'      => $nombre,
            'user_type'      => $rol->value,
            // Activas a propósito: en un taller sin correo real, el flujo de
            // activación por enlace dejaría a todo el mundo fuera.
            'status'         => 'active',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return ['id' => $id, 'email' => $correo, 'full_name' => $nombre];
    }

    private function resumen(array $alumnos, array $alumnosB, array $docentes, array $admin): void
    {
        $this->command?->newLine();
        $this->command?->info('Aulas listas. Contraseña para todas las cuentas: ' . self::CLAVE);
        $this->command?->newLine();
        $this->command?->table(
            ['Rol', 'Correo', 'Qué enseña en el taller'],
            [
                ['Admin', $admin['email'], 'Ve el centro entero; asigna docentes a aulas y materias'],
                ['Docente', $docentes['mate']['email'], 'Matemáticas y Ciencias de 4-A: ve a los 28; no ve a 4-B'],
                ['Docente', $docentes['espanol']['email'], 'Español en 4-A y 4-B: al enviar un recurso o aviso elige aula'],
                ['Docente', $docentes['sinasignar']['email'], 'SIN asignación: no ve estudiantes ni materias'],
                ['Alumno 4-A', $alumnos[0]['nombre'] . ' (nota 8/8)', 'El mejor resultado del aula'],
                ['Alumno 4-A', $alumnos[20]['nombre'] . ' (nota 2/8)', 'El que más apoyo necesita'],
                ['Alumno 4-B', $alumnosB[0]['nombre'], 'Ve solo lo enviado a 4-B (no el examen ni los avisos de 4-A)'],
            ]
        );
    }
}
