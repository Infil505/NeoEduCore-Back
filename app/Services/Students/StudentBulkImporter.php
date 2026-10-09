<?php

namespace App\Services\Students;

use App\Enums\AdecuacionType;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Jobs\EnviarEnlaceDeAlta;
use App\Models\Academic\Group;
use App\Models\Admin\User;
use App\Models\Students\Student;
use App\Services\Imports\BulkPreview;
use App\Services\Imports\BulkTemplateService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Importa filas de la carga masiva de estudiantes: valida, crea cuentas y
 * perfiles y matricula en el aula.
 *
 * Salió de `StudentController::bulkUpload` para poder ejecutarse en segundo
 * plano (`ProcesarCargaMasivaEstudiantes`): la petición HTTP solo recibe el
 * archivo y responde; el trabajo largo corre en el worker, sin timeout.
 *
 * Necesita el contexto de tenant puesto (`app('tenant_id')`): `Group` y
 * `Student` están acotados por institución.
 */
class StudentBulkImporter
{
    // Columnas de PERFIL (tabla students) que se vuelcan al modelo Student.
    // institution_id se ignora por seguridad (lo asigna TenantScoped desde el tenant).
    //
    // `grade`, `section` y `group_code` NO están: desde el 08/08/2026 se derivan
    // del aula. Si vinieran del archivo podrían contradecirla —«aula=11B2026,
    // section=A»— y la ficha volvería a desviarse de la matrícula real, que es
    // justo el problema que la columna `aula` viene a cerrar.
    private const ALLOWED_COLUMNS = [
        'user_id', 'student_code', 'status',
        'birth_date', 'parent_name', 'parent_email', 'adecuacion_type',
    ];

    public function __construct(private readonly EnrollmentService $enrollmentService)
    {
    }

    /**
     * @param array<int,array<string,mixed>> $rows registros por número de fila del archivo
     * @return array<string,mixed> el resumen de la carga, o la vista previa si `$simular`
     */
    public function importar(array $rows, string $institutionId, bool $simular = false): array
    {
        $enrollment = $this->enrollmentService;
        $totalRows  = count($rows);

        // Aulas de la institución indexadas por código en mayúsculas: una sola
        // consulta en lugar de una por fila.
        $grupos = Group::query()->get();
        $aulasPorCodigo = $grupos
            ->filter(fn ($g) => trim((string) $g->group_code) !== '')
            ->keyBy(fn ($g) => Str::upper(trim($g->group_code)));

        // Por sección puede haber más de un grupo (la misma «6-1» en dos años):
        // se prefiere el del año en curso; si aun así hay varios, la fila es
        // ambigua y se rechaza en vez de adivinar.
        $anioActual = (string) now()->year;
        $aulasPorSeccion = $grupos
            ->filter(fn ($g) => trim((string) $g->section) !== '')
            ->groupBy(fn ($g) => Str::upper(trim((string) $g->section)))
            ->map(function ($candidatos) use ($anioActual) {
                $delAnio = $candidatos->filter(fn ($g) => (string) $g->year === $anioActual);

                return $delAnio->isNotEmpty() ? $delAnio->values() : $candidatos->values();
            });

        $created         = 0;
        $updated         = 0;
        $usersCreated    = 0;
        $matriculados    = 0; // altas de matrícula (estudiante nuevo en su aula)
        $reasignados     = 0; // cambios de aula sobre estudiantes que ya existían
        $aulasTocadas    = []; // group_id → recuento de student_count al final
        $newUsers        = []; // usuarios creados → reciben enlace de contraseña tras el commit
        $errors          = [];
        $acciones        = []; // fila → 'crear' | 'actualizar' (para la vista previa)
        $correosVistos   = []; // correo → primera fila donde aparece
        $validAdeValues  = array_map(fn($c) => $c->value, AdecuacionType::cases());
        $validStatValues = array_map(fn($c) => $c->value, StudentStatus::cases());

        // Todo lo creado pertenece a la institución del usuario autenticado.

        // Hash de una contraseña aleatoria que nadie conoce: deja las cuentas nuevas
        // inservibles hasta que su dueño fije la suya con el enlace del correo.
        // Se calcula UNA vez por carga y no por fila: con BCRYPT_ROUNDS=12 cada hash
        // cuesta ~180 ms, y 300 filas tardaban casi un minuto —el navegador cortaba
        // la petición antes de terminar—. Compartirlo no debilita nada: el secreto
        // se descarta aquí mismo y no hay forma de entrar con él.
        $hashInservible = Hash::make(Str::random(40));

        // --- Precarga ---
        //
        // Todo lo que el bucle necesita saber de la base se lee aquí, en un
        // número FIJO de consultas, y el bucle valida contra mapas en memoria.
        // Antes cada fila hacía entre 6 y 9 consultas (buscar usuario, buscar
        // estudiante, comprobar código y correo, matrícula actual…) y, con la
        // base remota a ~430 ms por viaje, 300 filas tardaban minutos. Las
        // altas y los cambios de aula también se acumulan y se escriben en
        // bloques al final (ver `$vaciarEscrituras`).
        $limpio  = fn ($v) => is_string($v) ? trim($v) : $v;
        $correos = [];
        $ids     = [];
        $codigos = [];

        foreach ($rows as $row) {
            $c = $limpio($row['email'] ?? null);
            if (is_string($c) && $c !== '') {
                $correos[] = Str::lower($c);
            }

            $u = (string) $limpio($row['user_id'] ?? '');
            if ($u !== '' && Str::isUuid($u)) {
                $ids[] = $u;
            }

            $k = (string) $limpio($row['student_code'] ?? '');
            if ($k !== '') {
                $codigos[] = $k;
            }
        }

        $correos = array_values(array_unique($correos));
        $ids     = array_values(array_unique($ids));
        $codigos = array_values(array_unique($codigos));

        // Usuarios del centro que el archivo menciona (por id o por correo).
        $usuariosPorId = User::where('institution_id', $institutionId)
            ->where(fn ($q) => $q->whereIn('id', $ids)->orWhereIn('email', $correos))
            ->get()
            ->keyBy('id');

        // Correos ya registrados en CUALQUIER centro: el correo es único global.
        $correosEnUso = array_flip(
            User::whereIn('email', $correos)->pluck('email')->map(fn ($e) => Str::lower($e))->all()
        );

        // Perfiles existentes: por usuario o por código (el código es único por centro).
        $estudiantesPorUsuario = [];
        $estudiantesPorCodigo  = [];
        $registrar = function (Student $s) use (&$estudiantesPorUsuario, &$estudiantesPorCodigo) {
            $estudiantesPorUsuario[$s->user_id] = $s;
            if ($s->student_code !== null && $s->student_code !== '') {
                $estudiantesPorCodigo[$s->student_code] = $s;
            }
        };

        Student::query()
            ->where(fn ($q) => $q->whereIn('user_id', $usuariosPorId->keys()->all())->orWhereIn('student_code', $codigos))
            ->get()
            ->each($registrar);

        // Dueños de los perfiles hallados por código que aún no se cargaron.
        $faltan = array_values(array_diff(array_keys($estudiantesPorUsuario), $usuariosPorId->keys()->all()));
        if ($faltan !== []) {
            $usuariosPorId = $usuariosPorId->union(
                User::where('institution_id', $institutionId)->whereIn('id', $faltan)->get()->keyBy('id')
            );
        }

        $usuariosPorCorreo = $usuariosPorId->keyBy(fn ($u) => Str::lower($u->email));

        // Aula en la que está matriculado cada uno ahora (matrícula abierta).
        $aulaInicial = $estudiantesPorUsuario === [] ? [] : DB::table('group_students')
            ->where('institution_id', $institutionId)
            ->whereNull('left_at')
            ->whereIn('student_user_id', array_keys($estudiantesPorUsuario))
            ->pluck('group_id', 'student_user_id')
            ->all();
        $aulaDe    = $aulaInicial;   // se va actualizando fila a fila
        $matricula = [];             // student_user_id → aula final (se escribe al terminar)

        $nuevosEstudiantes = []; // user_id → Student sin guardar
        $porActualizar     = []; // user_id → Student existente con cambios

        $procesar = function () use (
            $rows, $validAdeValues, $validStatValues, $institutionId, $aulasPorCodigo, $aulasPorSeccion,
            &$created, &$updated, &$errors, &$usersCreated, &$newUsers,
            &$matriculados, &$reasignados, &$aulasTocadas, $enrollment, &$acciones, $simular, &$correosVistos, $hashInservible,
            &$usuariosPorId, &$usuariosPorCorreo, &$correosEnUso, &$estudiantesPorUsuario, &$estudiantesPorCodigo,
            &$aulaInicial, &$aulaDe, &$matricula, &$nuevosEstudiantes, &$porActualizar, $registrar
        ) {
            foreach ($rows as $idx => $row) {
                $lineNumber = $idx; // los parsers indexan por fila del archivo

                $row = Arr::map($row, fn($v) => is_string($v) ? trim($v) : $v);

                // --- adecuacion_type ---
                if (!empty($row['adecuacion_type'])) {
                    $val = Str::lower(Str::ascii($row['adecuacion_type'])); // «evaluación» = «evaluacion»
                    if (!in_array($val, $validAdeValues, true)) {
                        $errors[] = "Fila {$lineNumber}: «Tipo de adecuación» inválido «{$row['adecuacion_type']}». Valores aceptados: " . implode(', ', $validAdeValues) . '.';
                        continue;
                    }
                    $row['adecuacion_type'] = $val;
                } else {
                    $row['adecuacion_type'] = null;
                }

                // --- status (activo/inactivo/suspendido, o en inglés) ---
                if (!empty($row['status'])) {
                    $estado = BulkTemplateService::statusValue((string) $row['status']);
                    if (!in_array($estado, $validStatValues, true)) {
                        $errors[] = "Fila {$lineNumber}: «Estado» inválido «{$row['status']}». Valores aceptados: " . implode(', ', array_keys(BulkTemplateService::STATUS_LABELS)) . '.';
                        continue;
                    }
                    $row['status'] = $estado;
                }

                // --- aula (obligatoria) ---
                //
                // El grupo tiene que existir ya: la carga masiva no crea aulas.
                // Un typo en el código crearía un grupo fantasma con un alumno
                // dentro, invisible para el docente que sí tiene asignada la
                // buena. Las aulas las crea el admin con POST /api/groups.
                if (array_key_exists('seccion', $row)) {
                    $seccion = Str::upper(trim((string) ($row['seccion'] ?? '')));

                    if ($seccion === '') {
                        $errors[] = "Fila {$lineNumber}: «Sección» es obligatoria (por ejemplo 6-1).";
                        continue;
                    }

                    $candidatos = $aulasPorSeccion->get($seccion);

                    if (!$candidatos) {
                        $disponibles = $aulasPorSeccion->keys()->take(10)->implode(', ');
                        $errors[] = "Fila {$lineNumber}: la sección «{$row['seccion']}» no existe en tu institución. Secciones disponibles: {$disponibles}.";
                        continue;
                    }

                    if ($candidatos->count() > 1) {
                        $errors[] = "Fila {$lineNumber}: la sección «{$row['seccion']}» corresponde a varias aulas ("
                            . $candidatos->pluck('name')->implode(', ') . '). Revisa las secciones en Académico.';
                        continue;
                    }

                    $aula = $candidatos->first();
                } else {
                    $codigoAula = Str::upper(trim((string) ($row['aula'] ?? '')));

                    if ($codigoAula === '') {
                        $errors[] = "Fila {$lineNumber}: «Aula» es obligatoria. Indica el código del grupo (por ejemplo 61).";
                        continue;
                    }

                    $aula = $aulasPorCodigo->get($codigoAula);

                    if (!$aula) {
                        $disponibles = $aulasPorCodigo->keys()->take(8)->implode(', ');
                        $errors[] = "Fila {$lineNumber}: el aula «{$row['aula']}» no existe en tu institución. Aulas disponibles: {$disponibles}.";
                        continue;
                    }
                }

                // --- parent_email ---
                if (!empty($row['parent_email']) && !filter_var($row['parent_email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Fila {$lineNumber}: «Correo del tutor» inválido «{$row['parent_email']}».";
                    continue;
                }

                // --- birth_date ---
                if (!empty($row['birth_date'])) {
                    $d = \DateTime::createFromFormat('Y-m-d', $row['birth_date']);
                    if (!$d || $d->format('Y-m-d') !== $row['birth_date']) {
                        $errors[] = "Fila {$lineNumber}: «Fecha de nacimiento» inválida «{$row['birth_date']}». Formato esperado: AAAA-MM-DD.";
                        continue;
                    }
                }

                // --- email (si viene) ---
                $email = !empty($row['email']) ? Str::lower($row['email']) : null;
                if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Fila {$lineNumber}: «Correo institucional» inválido «{$row['email']}».";
                    continue;
                }

                // El mismo correo dos veces en el archivo es un error de quien
                // lo armó: la segunda fila encontraba la cuenta recién creada
                // por la primera y la «actualizaba», cambiándola de aula sin aviso.
                if ($email !== null && isset($correosVistos[$email])) {
                    $errors[] = "Fila {$lineNumber}: el correo «{$email}» está repetido en el archivo (fila {$correosVistos[$email]}).";
                    continue;
                }
                if ($email !== null) {
                    $correosVistos[$email] = $lineNumber;
                }

                // --- Resolver el USUARIO dueño del perfil (siempre dentro del tenant) ---
                // (Todo sale de los mapas de la precarga, ya acotados al centro.)
                $user = null;
                if (!empty($row['user_id'])) {
                    $user = $usuariosPorId[(string) $row['user_id']] ?? null;
                    if (!$user) {
                        $errors[] = "Fila {$lineNumber}: el «ID de usuario» «{$row['user_id']}» no existe en tu institución.";
                        continue;
                    }
                } elseif ($email) {
                    $user = $usuariosPorCorreo[$email] ?? null;
                }

                // --- Buscar estudiante existente (la precarga ya está scoped por tenant) ---
                $student = null;
                if ($user) {
                    $student = $estudiantesPorUsuario[$user->id] ?? null;
                }
                if (!$student && !empty($row['student_code'])) {
                    $student = $estudiantesPorCodigo[$row['student_code']] ?? null;
                    if ($student && !$user) {
                        $user = $usuariosPorId[$student->user_id] ?? null;
                    }
                }

                // --- student_code único ---
                //
                // Se comprueba ANTES de crear la cuenta: si se hiciera después,
                // una fila rechazada por código duplicado dejaría un usuario
                // huérfano —sin perfil de estudiante, capaz de autenticarse y
                // con el email ya consumido—.
                //
                // Acotado al tenant, que es lo que exige la constraint
                // `students_institucion_codigo_unique (institution_id,
                // student_code)`. Hasta el 08/08/2026 la constraint era global y
                // esta comprobación no: un código ya usado por otro centro
                // pasaba el filtro y reventaba contra la base, y como en
                // PostgreSQL una violación aborta la transacción entera se
                // perdía el archivo completo. Si se vuelven a separar, el
                // síntoma es ese — comprobación y constraint tienen que hablar
                // del mismo alcance.
                if (!empty($row['student_code'])) {
                    $duenio = $estudiantesPorCodigo[$row['student_code']] ?? null;
                    if ($duenio && (!$student || $duenio->user_id !== $student->user_id)) {
                        $errors[] = "Fila {$lineNumber}: el «Código de estudiante» «{$row['student_code']}» ya está en uso.";
                        continue;
                    }
                }

                // --- Si no hay usuario ni estudiante: crear cuenta nueva (requiere email + full_name) ---
                if (!$user && !$student) {
                    if (!$email) {
                        $errors[] = "Fila {$lineNumber}: para crear un estudiante nuevo se requiere el «Correo institucional» (o un «ID de usuario» existente).";
                        continue;
                    }
                    $fullName = trim((string) ($row['full_name'] ?? ''));
                    if ($fullName === '') {
                        $errors[] = "Fila {$lineNumber}: «Nombre completo» es obligatorio para crear el usuario.";
                        continue;
                    }
                    if (isset($correosEnUso[$email])) {
                        $errors[] = "Fila {$lineNumber}: el correo «{$email}» ya está en uso.";
                        continue;
                    }

                    // Sin guardar: se inserta en bloque al final. El id se
                    // genera aquí para poder referirlo desde el perfil y la
                    // matrícula antes de que exista la fila.
                    $user = new User([
                        'institution_id' => $institutionId,
                        'full_name'      => $fullName,
                        'email'          => $email,
                        // Marcador inservible: la contraseña TEMPORAL real la genera
                        // y manda por correo el job `EnviarEnlaceDeAlta` (en el
                        // worker, donde el hash bcrypt no bloquea la carga).
                        'password_hash'  => $hashInservible,
                        'user_type'      => UserType::Student->value,

                        // Activa desde el alta y obligada a cambiar la clave en su
                        // primer acceso (decisión del 09/10/2026). Hasta que el job
                        // le ponga la temporal no puede entrar: el hash no coincide
                        // con nada. Antes nacía `inactive` y se activaba al definir
                        // la contraseña desde un enlace.
                        'status'         => UserStatus::Active->value,
                        'must_change_password' => true,
                    ]);
                    $user->id = (string) Str::orderedUuid();

                    $usersCreated++;
                    $newUsers[] = $user;
                    $usuariosPorId[$user->id] = $user;
                }

                // institution_id nunca se toma del archivo — lo asigna TenantScoped.
                // El user_id del perfil siempre proviene del usuario resuelto/creado.
                $data = Arr::only($row, self::ALLOWED_COLUMNS);
                // Quitar celdas vacías: las columnas nullable quedan en NULL (no en '')
                // y las que tienen default (status, exams_completed_count) lo aplican.
                $data = array_filter($data, fn($v) => $v !== '' && $v !== null);
                $data['user_id'] = $user?->id ?? $student->user_id;

                // Los campos desnormalizados de la ficha salen del aula, nunca
                // del archivo: así no pueden contradecir la matrícula.
                $data['grade']      = $aula->grade;
                $data['section']    = $aula->section;
                $data['group_code'] = $aula->group_code;

                if ($student) {
                    // No reasignar la PK (user_id) al actualizar
                    $codigoPrevio = $student->student_code;
                    $student->fill(Arr::except($data, ['user_id']));

                    // Si el código cambió, el mapa tiene que seguirlo: la fila
                    // siguiente compara contra el código nuevo, no el viejo.
                    if ($student->student_code !== $codigoPrevio) {
                        if ($codigoPrevio !== null) {
                            unset($estudiantesPorCodigo[$codigoPrevio]);
                        }
                        $estudiantesPorCodigo[$student->student_code] = $student;
                    }

                    // Un perfil aún sin guardar (alta de esta misma carga) se
                    // inserta ya con sus valores finales; uno existente se
                    // guarda una sola vez aunque aparezca en varias filas.
                    if (!isset($nuevosEstudiantes[$student->user_id])) {
                        $porActualizar[$student->user_id] = $student;
                    }
                    $updated++;
                    $acciones[$lineNumber] = 'actualizar';
                } else {
                    // Sin guardar: se inserta en bloque al final.
                    $student = new Student($data);
                    $student->institution_id = $institutionId;
                    $nuevosEstudiantes[$student->user_id] = $student;
                    $registrar($student);
                    $created++;
                    $acciones[$lineNumber] = 'crear';
                }

                // --- Matrícula en el aula ---
                //
                // Se decide aquí, contra el mapa de la precarga (matrículas
                // abiertas del centro, así una de otra institución no
                // cuenta), y se escribe al final en bloque.
                $studentUserId = $student->user_id;
                $aulaActual    = $aulaDe[$studentUserId] ?? null;

                if ($aulaActual === $aula->id) {
                    // Ya está donde debe: nada que hacer.
                } elseif ($aulaActual === null) {
                    $aulasTocadas[$aula->id] = true;
                    $matriculados++;
                } else {
                    // Cambio de aula. Solo puede ocurrir sobre un estudiante que
                    // ya estaba matriculado: se cierra su fila abierta y se abre
                    // la del aula nueva.
                    $aulasTocadas[$aula->id]   = true;
                    $aulasTocadas[$aulaActual] = true;
                    $reasignados++;
                }

                if ($aulaActual !== $aula->id) {
                    $aulaDe[$studentUserId]    = $aula->id;
                    $matricula[$studentUserId] = $aula->id;
                }

                unset($row, $data);
            }

            // --- Escritura en bloque ---
            //
            // Con las filas ya validadas, todo lo que había que crear o
            // mover se escribe en unas pocas sentencias grandes. La vista
            // previa no escribe nada: no hay nada que deshacer.
            if ($simular) {
                return;
            }

            $lote  = max(1, (int) config('bulk.insert_batch_size'));
            $ahora = now();

            foreach (array_chunk($newUsers, $lote) as $bloque) {
                DB::table('users')->insert(array_map(function (User $u) use ($ahora) {
                    $u->setCreatedAt($ahora)->setUpdatedAt($ahora);

                    return $u->getAttributes();
                }, $bloque));
            }

            // Todas las filas del INSERT necesitan las mismas columnas.
            $columnas = [
                'user_id', 'institution_id', 'student_code', 'grade', 'section', 'group_code',
                'status', 'birth_date', 'parent_name', 'parent_email', 'adecuacion_type',
            ];

            foreach (array_chunk(array_values($nuevosEstudiantes), $lote) as $bloque) {
                DB::table('students')->insert(array_map(function (Student $s) use ($columnas, $ahora) {
                    $atributos = $s->getAttributes();
                    $fila = [];
                    foreach ($columnas as $c) {
                        $fila[$c] = $atributos[$c] ?? null;
                    }

                    $fila['status'] ??= StudentStatus::Active->value;

                    return $fila + [
                        'exams_completed_count' => 0,
                        'created_at'            => $ahora,
                        'updated_at'            => $ahora,
                    ];
                }, $bloque));
            }

            // Perfiles existentes: `save()` no consulta si no hay cambios
            // (una recarga del mismo archivo no escribe nada).
            foreach ($porActualizar as $existente) {
                $existente->save();
            }

            $aTrasladar = array_keys(array_filter(
                $matricula,
                fn ($aulaNueva, $estudianteId) => isset($aulaInicial[$estudianteId]) && $aulaInicial[$estudianteId] !== $aulaNueva,
                ARRAY_FILTER_USE_BOTH
            ));
            $enrollment->cerrarMatriculas($aTrasladar, $institutionId);
            $enrollment->abrirMatriculas($matricula, $institutionId);
        };

        // La vista previa corre las mismas validaciones pero sin transacción
        // ni escrituras (ver BulkPreview).
        if ($simular) {
            $procesar();

            return BulkPreview::respuesta($rows, $errors, $acciones, ['full_name', 'email', 'seccion', 'aula']);
        }

        DB::transaction($procesar);

        // Recuento de las aulas afectadas (RN-STU-012). Una sola pasada al
        // final: durante el bucle el contador cambiaría en cada fila.
        foreach (array_keys($aulasTocadas) as $groupId) {
            $enrollment->recontarAula($groupId, $institutionId);
        }

        // Encolar el enlace de "establece tu contraseña" a los usuarios creados.
        // FUERA de la transacción: el envío real lo hace el worker; la request no se bloquea.
        $emailsQueued  = 0;
        $emailFailures = [];
        // Cada enlace se prepara en cola (ver EnviarEnlaceDeAlta): hacerlo aquí
        // costaba un hash bcrypt por cuenta y la petición superaba el minuto.
        // En UN solo INSERT: con la cola en base de datos remota cada `dispatch`
        // son ~430 ms, y 209 cuentas pasaban de 90 s (el navegador cortaba antes).
        if ($newUsers !== []) {
            Queue::bulk(array_map(fn ($u) => new EnviarEnlaceDeAlta($u->id), $newUsers));
        }
        $emailsQueued = count($newUsers);

        return [
            'total_rows'     => $totalRows,
            'created'        => $created,
            'updated'        => $updated,
            'users_created'  => $usersCreated,
            // Matrícula: altas nuevas y traslados. Se informan por separado
            // porque un traslado no anunciado es un cambio silencioso de a qué
            // docente pasa a ver el expediente del estudiante.
            'matriculados'   => $matriculados,
            'reasignados'    => $reasignados,
            'aulas_afectadas' => count($aulasTocadas),
            'emails_queued'  => $emailsQueued,
            'email_failures' => $emailFailures,
            'skipped'        => count($errors),
            'errors'         => $errors,
        ];
    }
}
