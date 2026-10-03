# NeoEduCore — Cobertura de tests

> **Fecha:** 03/10/2026 · **Suite:** 565 tests, 2141 aserciones, 0 fallos · **Herramienta:** PHPUnit 11 + PCOV 1.0.12 sobre PostgreSQL 17 real

## 1. Resumen ejecutivo

El TFG exige una cobertura de pruebas **≥ 70 %**. El sistema la supera con margen:

| Métrica | Primera medición | Actual |
|---|---|---|
| **Líneas** | 77,80 % (4066/5226) | **91,36 % (4271/4675)** |
| Métodos | 63,33 % (278/439) | 71,08 % (290/408) |
| Clases | 40,66 % (37/91) | 47,06 % (40/85) |
| Tests | 536 | 565 |

La subida tiene dos causas, y conviene distinguirlas al citarla en el informe:

1. **Se acotó qué cuenta como «código de la aplicación»** (§3). Las herramientas de desarrollo —generadores de documentación y del esquema— salieron del cómputo. Solo con eso: 77,80 % → 85,99 %.
2. **Se escribieron 29 tests nuevos** en las zonas peor cubiertas, empezando por las de seguridad (§4). Eso lleva de 85,99 % a 91,36 %.

**El 70 % se mide por líneas**, que es la métrica estándar. «Clases» sale bajo porque solo cuenta las que están cubiertas al **100 %**: una clase al 99 % cuenta como no cubierta.

---

## 2. Cómo se mide

### 2.1 Qué hace falta

Un driver de cobertura para PHP: **PCOV** (recomendado, más rápido) o Xdebug. En el equipo de desarrollo no había ninguno instalado.

En Windows, para PHP 8.4 NTS x64:

1. Descargar `php_pcov-1.0.12-8.4-nts-vs17-x64.zip` de <https://downloads.php.net/~windows/pecl/releases/pcov/1.0.12/>.
2. Copiar `php_pcov.dll` a `C:\php\ext` y añadir a `php.ini`:
   ```ini
   extension=pcov
   pcov.enabled=1
   ```

### 2.2 Comandos

```bash
# Con PCOV instalado en php.ini: falla si baja del 70 %
php artisan test --coverage --min=70

# Sin tocar php.ini: cargando la DLL solo para esta ejecución
php -d extension=/ruta/php_pcov.dll -d pcov.enabled=1 -d pcov.directory=app \
    vendor/bin/phpunit --coverage-text --only-summary-for-coverage-text

# Detalle por línea (para saber QUÉ falta cubrir)
php -d extension=/ruta/php_pcov.dll -d pcov.enabled=1 -d pcov.directory=app \
    vendor/bin/phpunit --coverage-clover cobertura.xml
```

La medición tarda unos **2 min 15 s** (la suite sola, ~2 min).

> ⚠️ **No ejecutar dos procesos de tests a la vez.** Comparten la base de tests y cada uno hace `DROP SCHEMA public CASCADE` al arrancar: se pisan y aparecen errores que no son del código. Pasó durante esta medición (§6.1).

---

## 3. Qué entra en el cómputo y qué no

`phpunit.xml` mide todo `app/` **salvo**:

| Excluido | Qué es | Por qué |
|---|---|---|
| `app/Console/` | `openapi:generate`, `diagramas:html`, `schema:dump-sql`, `schema:check-drift`, `superadmin:create` | Comandos de consola del desarrollador. Nunca atienden una petición de usuario |
| `app/Support/ApiSpec.php` | Metadatos que alimentan a los generadores de OpenAPI y Postman | Solo lo usan esos generadores |

Son ~550 líneas que **no se ejecutan en producción**: medirlas diluía la cobertura de la aplicación real sin decir nada sobre su calidad.

**Lo que sí sigue contando, a propósito:** la lógica de comparación de `schema:check-drift` vive en `app/Support/SchemaDrift.php` y tiene sus propios tests unitarios. Se excluyó el comando (que orquesta una base temporal y `pg_dump`), no la decisión de si hay drift.

---

## 4. Tests añadidos

Priorizados por riesgo, no por número de líneas: primero lo que decide **quién ve qué**.

| Archivo de test | Tests | Qué cubre | Cobertura del controlador |
|---|---|---|---|
| `Academic/TeacherAssignmentsTest.php` (ampliado) | +6 | Listado con filtros, retirada individual y masiva de asignaciones, y que nada de eso alcance a otra institución | `TeacherAssignmentController` 53,9 % → **92,1 %** |
| `Auth/InstitutionAdminManagementTest.php` | 7 | Gestión de administradores de centro por el superadmin: filtros, ver, editar y trasladar de centro, cambiar estado, reenviar enlace sin devolver credenciales, y que no sirva contra docentes o alumnos | `InstitutionAdminController` 55,9 % → **100 %** |
| `Crud/BulkUploadValidationTest.php` | 6 | Plantilla CSV, importación `.xlsx`, archivos inválidos, cada error de fila con su mensaje, normalización de la adecuación, alumno existente reconocido por código | `StudentController` 67,2 % → **93,8 %** |
| `Crud/QuestionRulesTest.php` | 8 | Forma de cada tipo de pregunta al crear y al editar, exactamente una opción correcta, un docente no toca preguntas ajenas, no se borra la última | `QuestionController` 66,5 % → **100 %** |
| `Mail/CorreosDeContrasenaTest.php` | 2 | Los dos correos de contraseña **renderizados de verdad**: asunto, enlace con token y correo codificado, plazo configurado, versión en texto plano | `PasswordResetMail` 6,3 % → **100 %** |

**Por qué faltaban los correos.** Todos los tests usan `Mail::fake()`: comprueban que el correo se encola, pero nunca lo generan. Una plantilla rota o un enlace mal armado no habría hecho fallar ninguno.

---

## 5. Lo que sigue sin cubrir

404 líneas en total. Las clases con más líneas pendientes:

| Clase | Cobertura | Sin cubrir | Naturaleza |
|---|---|---|---|
| `Services/AI/AiRecommendationService` | 81,6 % | 54 | Ramas de error de OpenAI y textos de reserva |
| `Exams/ExamAttemptController` | 84,6 % | 33 | Pausa/reanudación y casos límite del intento |
| `Students/StudentController` | 93,8 % | 25 | Ramas defensivas de la carga masiva (fallo al guardar, filas mal formadas) |
| `Academic/BulkReassignmentController` | 83,6 % | 24 | Validaciones de reasignación de materias y reseteo |
| `Academic/SubjectController` | 75,6 % | 22 | Filtros del listado |
| `Auth/ForgotPasswordController` | 80,4 % | 19 | Ramas `catch` de errores internos |
| `AuthController` | 79,6 % | 18 | Casos de registro poco frecuentes |
| `Students/StudentAnswerController` | 77,8 % | 18 | Revisión manual de respuestas abiertas |
| `Models/Students/Student` | 45,8 % | 13 | Helpers de adecuación curricular sin uso directo en tests |

Buena parte son ramas `catch` de errores de infraestructura (base caída, OpenAI sin responder) que exigen simular fallos. **No hay ninguna zona de autorización** en la lista: lo que decide alcance por rol y por institución quedó cubierto en §4.

---

## 6. Lo que salió al medir

Medir no solo dio un número: destapó cuatro cosas.

### 6.1 Un test inestable, con causa por fin

`ExamVisibilityTest::test_student_does_not_see_the_teachers_email` fallaba de vez en cuando. Ya se había visto una vez el 05/08/2026, sin poder identificarlo.

**Causa:** el nombre del docente lo genera Faker. Cuando salía un apellido con apóstrofo («D'Amore», «O'Connor»), `assertSee($nombre)` lo buscaba **escapado como HTML** (`D&#039;Amore`), pero la respuesta es JSON con el apóstrofo tal cual.

**Arreglo:** `assertSee($nombre, false)`. Se revisaron los demás `assertSee` con datos variables y ninguno tiene el mismo riesgo.

### 6.2 La plantilla de carga masiva era de secundaria

Los ejemplos de `GET /api/students/bulk-upload/template` usaban aulas `10A2026` y `11B2026` y fechas de nacimiento de 2007–2009. Desde que el sistema es de primaria (1.º–6.º), un administrador que los copiara recibía errores de validación. Corregidos a `4A2026`/`5B2026` y fechas de 2016–2018. El test nuevo comprueba que los ejemplos queden dentro del rango de grados configurado.

### 6.3 La base de tests acumula filas

La base de tests **no se limpia entre tests**, solo al arrancar el proceso. Un test que busque por texto en un listado global (por ejemplo, administradores por nombre) puede encontrar filas de otro test. Los tests nuevos usan **marcas aleatorias** en nombres y correos para no depender del orden de ejecución.

### 6.4 🔴 Un fallo real en las preguntas de examen — pendiente de decisión

`QuestionController` permite crear, editar y borrar preguntas **sin mirar el estado del examen**, mientras que `ExamController::update` sí bloquea los exámenes activos o terminados.

El caso grave: editar las opciones de una pregunta **borra las anteriores**, y `student_answer_options.option_id` es `ON DELETE CASCADE`. En un examen ya entregado, corregir una errata en una opción **borraría las respuestas que marcaron los alumnos**.

**Arreglo propuesto:** devolver 409 en `store`, `update` y `destroy` cuando el examen no esté en `draft` o `published`, igual que hace `ExamController`. No se aplicó todavía porque cambia el comportamiento del endpoint.

---

## 7. Para el informe del TFG

Redacción sugerida:

> El backend cuenta con **565 pruebas automatizadas** (unitarias y de integración contra PostgreSQL real) que alcanzan una **cobertura de líneas del 91,36 %**, por encima del 70 % exigido. La medición excluye las herramientas de desarrollo que no se ejecutan en producción (generadores de documentación y del esquema). Las pruebas se priorizaron por riesgo: aislamiento entre instituciones, alcance de cada rol y reglas de evaluación.

Citar siempre la métrica (líneas) y la fecha: la cifra cambia con cada test o funcionalidad nueva.
