# Informe del TFG — textos listos para pegar (correcciones críticas)

Redactado el 08/10/2026 para el checklist §6: **I-1, I-2, I-3, I-7/K11, K7, K8, K10**.
Cada bloque trae el texto propuesto y, debajo, la evidencia en el repositorio para que
cualquiera pueda comprobarlo en la defensa. Buscar el pasaje a sustituir **por su texto**:
los números de párrafo están desfasados (`ANALISIS_MODELO_DATOS_TFG.md` §10.3).

Cifras verificadas hoy contra el repositorio: 137 endpoints (`route:list`), 28 tablas
—22 de dominio y 6 de infraestructura de Laravel— con **RLS activado en las 28** (no aísla instituciones, ver I-1), 60 claves
foráneas, 778 tests en verde (`php artisan test`), OpenAPI 3.0.0 (`storage/api-docs/api-docs.json`).
Las versiones del frontend (React 19, Vite 8, TypeScript 6, Tailwind 4) salen de
`ANALISIS_MODELO_DATOS_TFG.md` §9.1 porque ese `package.json` no está en este repositorio:
**comprobarlas en el repo del frontend antes de entregar**.

---

## I-1 · Stack tecnológico (fundamentación)

**Sustituye** los epígrafes «Node.js y PostgreSQL» y «Next.js», y las menciones a JWT,
Render y Railway.

> **Backend y acceso a datos.** El servidor está construido con Laravel 12 sobre PHP 8.4.
> La aplicación accede a PostgreSQL directamente, mediante el ORM Eloquent y PDO; no existe
> una capa intermedia adicional. El servidor de aplicación es Laravel Octane sobre FrankenPHP,
> que mantiene la aplicación cargada en memoria entre peticiones y sustenta el requisito de
> concurrencia.
>
> **Base de datos.** PostgreSQL 17 gestionado por Supabase. Se usa el *pooler* en modo
> *session*: el modo *transaction* rompe los *prepared statements* de PDO. El aislamiento
> entre instituciones lo hace la aplicación, no la base de datos: cada modelo con datos de
> una institución se filtra por ella automáticamente (`TenantScoped`). El *Row Level
> Security* de Supabase está activado en las 28 tablas para cerrar el acceso directo por
> la API pública de Supabase, pero no es lo que separa a unas instituciones de otras: la
> aplicación se conecta con un rol que lo omite y no hay políticas por institución.
>
> **Autenticación.** Laravel Sanctum: tokens opacos almacenados en base de datos, revocables
> y con caducidad. No se usa JWT. Un token caduca tras 60 minutos **sin uso**, con un tope
> absoluto de 12 horas; la caducidad es por inactividad y no absoluta porque un examen puede
> durar hasta 300 minutos y no debe expulsar al estudiante a mitad de la prueba.
>
> **Procesos en segundo plano.** Las colas son las de Laravel (`QUEUE_CONNECTION=database`,
> proceso `queue:work`). Se usan para el análisis de IA de los resultados, las respuestas
> asíncronas del tutor y el envío de enlaces de alta y recuperación de contraseña. Sin el
> *worker* en ejecución esas funciones no completan.
>
> **Frontend.** Aplicación de página única (SPA) en React 19, TypeScript, Vite y Tailwind CSS,
> sin renderizado del lado del servidor.
>
> **Despliegue.** Contenedor Docker desplegado con Coolify sobre DigitalOcean.

**Evidencia:** `composer.json` (`laravel/framework ^12`, `laravel/sanctum ^4.2`,
`laravel/octane ^2.17`; sin `tymon/jwt-auth` ni `firebase/php-jwt`), `.env`
(`QUEUE_CONNECTION=database`), `docs/DEPLOY_COOLIFY.md`, `AuthController::login`.

---

## I-2 · Relación docente↔estudiante (`teacher_assignments`)

> **Alcance del docente.** Un docente solo accede a los datos de los estudiantes que el
> administrador de su institución le ha asignado. La asignación es una relación explícita
> (docente, grupo, materia) en la tabla `teacher_assignments`, que solo el administrador
> puede crear o retirar. El alcance del docente se lee siempre de esa tabla y no de los
> exámenes que haya creado: así un docente no puede ampliar su propio acceso.
>
> El acceso exige la fila completa: estar asignado a un grupo en *Matemáticas* no habilita
> a ver ni a dirigir exámenes de *Lengua* a ese grupo. Además, el acceso sigue a la matrícula
> activa: cuando un estudiante cambia de sección, deja de ser visible para el docente de la
> anterior, incluido su historial.
>
> La regla vive en un único componente (`AcotaAlDocente`) que usan todos los controladores
> que exponen datos de estudiantes, para que un endpoint nuevo no pueda omitirla.

**Para el capítulo de pruebas/seguridad (opcional, es el hallazgo más ilustrativo):** el
sistema original derivaba «mis estudiantes» de los exámenes que el docente había creado, de
modo que bastaba crear un examen en borrador dirigido a cualquier grupo para pasar a leer
sus datos. La ausencia de la entidad en el modelo no era solo una omisión documental: el
sistema la sustituía por algo que la propia parte a limitar podía controlar.

**Evidencia:** `app/Http/Controllers/Concerns/AcotaAlDocente.php`,
`TeacherAssignmentsTest`, `ANALISIS_MODELO_DATOS_TFG.md` §9.8.5.

---

## I-3 · Capítulo del tutor: qué hace el sistema

> **Recomendaciones tras un examen.** Al entregar un examen, el sistema guarda de inmediato
> recomendaciones de plantilla calculadas a partir del porcentaje obtenido. La primera vez
> que el estudiante abre sus resultados, se encola un análisis con un modelo de lenguaje
> (`gpt-4o-mini`) que lee las respuestas falladas, con su tema, indicador y dificultad, y
> sustituye las plantillas por recomendaciones redactadas sobre ese caso. El análisis no se
> lanza en la entrega a propósito: el pico real de carga es una clase entera entregando a la
> vez, y así solo se consume el servicio externo por quien va a leer el análisis.
> La respuesta indica en qué estado está (`preparing`, `ready`, `failed`).
>
> **Cuando el servicio de IA no responde.** El sistema degrada sin romperse. Tras tres
> reintentos, las recomendaciones quedan en su versión de plantilla y el estado pasa a
> `failed`; el estudiante puede pedir una regeneración. En el chat del tutor, un fallo del
> proveedor se contesta con un mensaje de reserva y queda registrado como incidencia.
>
> **Chat del tutor.** Conversación con historial limitado, tres modos (preguntar, explicar,
> practicar) y registro del lenguaje ajustado al grado del estudiante. Cada respuesta incluye
> un aviso (`ai_notice`) de que procede de un modelo automatizado; lo emite el sistema y no
> el frontend, de modo que el compromiso sobrevive a cualquier cliente. Las respuestas se
> filtran antes de entregarse (datos personales, longitud, enlaces fuera de lista blanca) y
> los intentos de inyección de instrucciones se contestan sin llamar al modelo.
>
> **Medición.** Cada respuesta bloqueada o fallida se registra en `ai_tutor_incidents`. El
> porcentaje de mensajes que superan la validación —el criterio del 75 %— se calcula con
> `GET /platform/ai-tutor-metrics`, accesible solo al superadmin. Cuentan los fallos de
> validación (datos personales y longitud); no cuentan el enlace bloqueado, porque la
> respuesta sí se entrega, ni el fallo del proveedor, que es disponibilidad y no calidad.
>
> **Privacidad.** El docente no ve los mensajes individuales del chat; ve recomendaciones y
> métricas agregadas.

**Límite que conviene declarar:** el tema de cada pregunta es texto libre normalizado
(mayúsculas y espacios), así que agrupa variantes de escritura pero **no sinónimos**. La
personalización por área concreta solo es tan buena como el etiquetado que haga el
profesorado.

**Evidencia:** `GenerateAiRecommendations`, `ExamAttemptController::recommendations`,
`AiTutorService`, `AiOutputValidator`, `AiIncidentLogger`, `config/openai.php`.

---

## I-7 / K11 · Los cuatro roles

**Sustituye** «admin, docente, estudiante».

> El sistema define cuatro roles. **Estudiante**: realiza exámenes y consulta sus resultados
> y su tutor. **Docente**: gestiona sus exámenes y consulta el progreso del alumnado que se
> le ha asignado. **Administrador**: gestiona usuarios, grupos, materias y asignaciones de
> *su* institución. **Superadministrador**: operador de la plataforma; da de alta
> instituciones y a sus administradores y consulta las métricas globales del tutor, y nada más.
>
> El superadministrador no pertenece a ninguna institución (`institution_id` nulo). Esa
> ausencia es el mecanismo de aislamiento: sin institución no se enlaza ningún inquilino, y
> todos los modelos académicos exigen uno, de modo que aunque una ruta académica se abriera
> a ese rol por descuido, la consulta fallaría. Ninguna ruta de la API crea un
> superadministrador; se da de alta con `php artisan superadmin:create`, lo que exige acceso
> al servidor.

**Evidencia:** `app/Enums/UserType.php`, `SetTenantFromAuth`, `ANALISIS_MODELO_DATOS_TFG.md` §9.6.

---

## K7 · «2–4 oraciones» frente al prompt

**Opción recomendada: ajustar el informe**, porque el prompt está calibrado y probado.

> Las respuestas del tutor son breves: un máximo de cuatro párrafos (600 tokens, 800 en el
> modo práctica).

Alternativa: cambiar el prompt (`AiTutorService`, líneas «Máximo 4 párrafos» y «Sé conciso
(máximo 4 párrafos)») a «2–4 oraciones». Hay que repasar los tests del tutor si se opta por esto.

---

## K8 · Modelo de lenguaje

**Sustituye** «modelos GPT-4 (variante ligera…)».

> Se utiliza `gpt-4o-mini`, la variante ligera y de menor coste de la familia GPT-4o de
> OpenAI. El modelo es configurable mediante la variable de entorno `OPENAI_MODEL`.

**Evidencia:** `config/openai.php` (`'model' => env('OPENAI_MODEL', 'gpt-4o-mini')`).

---

## K10 · Versión de OpenAPI

**Sustituye** «OpenAPI 5.0» (esa versión no existe).

> La API se documenta con OpenAPI 3.0. El documento se genera con
> `php artisan openapi:generate` a partir de las rutas reales, por lo que no se desincroniza
> al añadir o quitar endpoints, y se publica con Swagger UI.

**Evidencia:** `storage/api-docs/api-docs.json` (`"openapi": "3.0.0"`).

---

## Pendiente de tu decisión antes de pegar

1. **K7:** ¿se ajusta el informe (recomendado) o el prompt?
2. **I-1, versiones del frontend:** confirmar en el repo del frontend.
3. **I-3, párrafo «Cuando el servicio de IA no responde»:** describe el comportamiento real,
   que es una reserva y no una caída. Si prefieres que el chat devuelva un error explícito
   (503) en lugar del mensaje de reserva, hay que cambiar el código y este párrafo.
