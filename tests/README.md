# API Tests Guide

Este proyecto contiene tests feature completos para todos los endpoints de la API REST.

## Estructura de Tests

```
tests/
├── Feature/
│   ├── Auth/
│   │   ├── AuthSessionTest.php       (GET /auth/me, POST /auth/logout)
│   │   ├── LoginRegisterTest.php     (Register, Login)
│   │   └── PasswordResetTest.php     (Password reset & change)
│   ├── Crud/
│   │   ├── StudentsCrudTest.php      (All student endpoints)
│   │   ├── GroupsCrudTest.php        (All group endpoints)
│   │   ├── SubjectsTest.php          (All subject endpoints)
│   │   ├── ExamsCrudTest.php         (All exam endpoints)
│   │   ├── QuestionsCrudTest.php     (All question endpoints)
│   │   ├── ExamAttemptsTest.php      (Exam attempt endpoints)
│   │   ├── StudentAnswersTest.php    (Student answer endpoints)
│   │   ├── StudyResourcesTest.php    (Study resource endpoints)
│   │   ├── CalendarEventsTest.php    (Calendar event endpoints)
│   │   ├── StudentProgressTest.php   (Student progress endpoints)
│   │   ├── UsersTest.php             (User management endpoints)
│   │   ├── InstitutionsTest.php      (Institution endpoints)
│   │   ├── AiRecommendationsTest.php (AI recommendation endpoints)
│   │   └── ReportsTest.php           (Report endpoints)
│   └── HealthTest.php                (GET /ping)
├── Traits/
│   ├── ApiAuth.php                   (signInTeacher, signInAdmin helpers)
│   └── UsesPostgresSchema.php        (Database setup)
└── TestCase.php                      (Base test class)
```

## Ejecución

### Todos los tests
```bash
php artisan test
```

### Tests específicos
```bash
# Auth tests
php artisan test tests/Feature/Auth/

# CRUD tests
php artisan test tests/Feature/Crud/

# Test específico
php artisan test tests/Feature/Crud/StudentsCrudTest.php
```

### Tests con coverage
```bash
php artisan test --coverage
```

### Tests en modo verbose
```bash
php artisan test --verbose
```

## Endpoints Cubiertos

### Autenticación (13 tests)
- ✅ POST /register
- ✅ POST /auth/login
- ✅ GET /auth/me
- ✅ POST /auth/logout
- ✅ POST /password/forgot
- ✅ POST /password/verify
- ✅ POST /password/reset
- ✅ POST /password/change

### Health Check (1 test)
- ✅ GET /ping

### Estudiantes (6 tests)
- ✅ GET /students
- ✅ GET /students/{id}
- ✅ GET /students/me
- ✅ PUT /students/{id}
- ✅ PATCH /students/{id}/status

### Grupos (5 tests)
- ✅ GET /groups
- ✅ POST /groups
- ✅ GET /groups/{id}
- ✅ PUT /groups/{id}
- ✅ DELETE /groups/{id}

### Asignaturas (5 tests)
- ✅ GET /subjects
- ✅ POST /subjects
- ✅ GET /subjects/{id}
- ✅ PUT /subjects/{id}
- ✅ DELETE /subjects/{id}

### Exámenes (5 tests)
- ✅ GET /exams
- ✅ POST /exams
- ✅ GET /exams/{id}
- ✅ PUT /exams/{id}
- ✅ DELETE /exams/{id}

### Preguntas (4 tests)
- ✅ GET /exams/{exam}/questions
- ✅ POST /exams/{exam}/questions
- ✅ PUT /questions/{id}
- ✅ DELETE /questions/{id}

### Intentos de Examen (3 tests)
- ✅ POST /exams/{exam}/attempts/start
- ✅ POST /exams/{exam}/attempts/{attempt}/submit
- ✅ GET /exams/{exam}/attempts/{attempt}

### Respuestas de Estudiantes (2 tests)
- ✅ GET /exam-attempts/{attempt}/answers
- ✅ PATCH /student-answers/{id}/review

### Progreso de Estudiante (4 tests)
- ✅ GET /student-progress
- ✅ GET /student-progress/me
- ✅ POST /student-progress
- ✅ POST /student-progress/recalc

### Recursos de Estudio (5 tests)
- ✅ GET /study-resources
- ✅ POST /study-resources
- ✅ GET /study-resources/{id}
- ✅ PUT /study-resources/{id}
- ✅ DELETE /study-resources/{id}

### Eventos de Calendario (5 tests)
- ✅ GET /calendar-events
- ✅ POST /calendar-events
- ✅ GET /calendar-events/{id}
- ✅ PUT /calendar-events/{id}
- ✅ DELETE /calendar-events/{id}

### Recomendaciones de IA (4 tests)
- ✅ GET /ai-recommendations
- ✅ GET /ai-recommendations/me
- ✅ GET /ai-recommendations/{id}
- ✅ POST /exam-attempts/{attempt}/recommendations/regenerate

### Usuarios (5 tests)
- ✅ GET /users
- ✅ GET /users/{id}
- ✅ PUT /users/{id}
- ✅ PATCH /users/{id}/status
- ✅ PATCH /users/{id}/reset-password

### Instituciones (4 tests)
- ✅ GET /institutions
- ✅ GET /institutions/{id}
- ✅ PUT /institutions/{id}
- ✅ PATCH /institutions/{id}/toggle

### Reportes (3 tests)
- ✅ GET /reports/exams/{exam}/results
- ✅ GET /reports/exams/{exam}/results.csv
- ✅ GET /reports/students/{student}/history

**Total: 80+ tests**

## Helpers de Autenticación

En `tests/Traits/ApiAuth.php` hay helpers útiles:

```php
// Sign in como profesor
$teacher = $this->signInTeacher(['institution_id' => $institution->id]);

// Sign in como admin
$admin = $this->signInAdmin(['institution_id' => $institution->id]);

// Actuar como usuario específico
$this->actingAs($user, 'sanctum');
```

## Notas Importantes

1. **Base de datos de test**: Los tests usan PostgreSQL. Asegúrate de tener configurado el `.env.testing`.

2. **Factories**: Se utilizan factories de Laravel para crear datos de prueba. Asegúrate de que todas las factories estén correctamente configuradas.

3. **Autenticación**: Los tests usan Laravel Sanctum para autenticación. Los endpoints protegidos ya incluyen la autenticación automáticamente.

4. **Tenant Scoping**: Algunos modelos usan el trait `TenantScoped`. Los tests manejan esto automáticamente mediante el contenedor de la aplicación.

5. **Base de datos**: Los tests limpian la base de datos automáticamente entre ejecuciones.

## Agregar Nuevos Tests

Para agregar tests de nuevos endpoints:

1. Crear un archivo en `tests/Feature/Crud/`
2. Extender `TestCase` e importar `ApiAuth` trait
3. Usar los helpers de autenticación
4. Seguir el patrón nombre_endpoint_operacion:

```php
public function test_create_resource(): void
{
    $this->signInTeacher();
    
    $res = $this->postJson('/api/endpoint', [...]);
    
    $res->assertCreated();
    $this->assertDatabaseHas('table', [...]);
}
```
