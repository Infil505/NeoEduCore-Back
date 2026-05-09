# Análisis de Brechas TFG — NeoEduCore
**Fecha:** 17 de abril de 2026 (actualizado 09 de mayo de 2026)  
**Proyecto:** NeoEduCore — Sistema web de gestión de exámenes diagnósticos con tutor virtual  
**Referencia:** CTFG-DOC-18_Guia_para_Informe_Final_TFG 2025  
**Analistas:** PM · Desarrollador Fullstack · QA · Ciberseguridad · Optimización

---

## Resumen Ejecutivo

El backend de NeoEduCore está **completo**. Se cuenta con una API REST completa en Laravel 12 (PostgreSQL, Sanctum, OpenAI, Swagger) con 16+ modelos, 20+ controladores, arquitectura multi-tenant y **142 pruebas automatizadas** (6 niveles de integración). El proyecto **carece completamente de frontend**, que es la capa de presentación exigida por el TFG (React/Next.js/TypeScript). Adicionalmente existen brechas en banco de ítems y documentación académica del informe.

**Porcentaje estimado completado:** ~65–70% del total del proyecto (backend 100% completo; frontend pendiente).

---

## 1. Perspectiva PM — Gestión de Proyecto

### Lo que cumple
| Sprint / Entregable | Estado |
|---|---|
| Sprint 2: Sistema de autenticación (registro, login, JWT) | ✅ Completo |
| Sprint 3: Gestión de sesión (logout, persistencia) | ✅ Completo |
| Sprint 4: Backend gestión de usuarios | ✅ Completo |
| Sprint 5: Funcionalidades académicas (grupos, materias, historial) | ✅ Completo |
| Sistema de exámenes con múltiples intentos y calificación | ✅ Completo |
| Pausa y reanudación de examen con temporizador | ✅ Completo |
| Tutor IA conversacional con historial de sesión | ✅ Completo |
| Analíticas agregadas (institución, materias, estudiante) | ✅ Completo |
| Integración OpenAI para recomendaciones y tutor | ✅ Completo |
| Exportación de reportes CSV | ✅ Completo |
| Documentación API con Swagger | ✅ Completo |

### Lo que falta
| Entregable | Prioridad | Notas |
|---|---|---|
| **Sprint 1: Mockups / Figma** | ALTA | Sin ningún prototipo visual entregado |
| **Frontend completo** (React/Next.js) | CRÍTICO | El TFG requiere sistema web completo |
| Banco de ítems (mínimo 60 preguntas con metadatos) | ALTA | Solo existe 1 pregunta en los seeders |
| Acta de taller de co-diseño con docentes | ALTA | Entregable de Fase 1 del TFG |
| Rúbricas para preguntas abiertas | MEDIA | Requerido en Fase 2 |
| Mini-guía para creación de nuevos ítems | MEDIA | Requerido en Fase 2 |
| Manual de usuario básico en línea | MEDIA | Requisito no funcional de usabilidad |
| Cronograma / bitácora del proyecto | MEDIA | Capítulo 11 del informe |
| Piloto con usuarios reales (docentes/estudiantes) | ALTA | Fase de validación del TFG |

---

## 2. Perspectiva Desarrollador Fullstack

### Backend — Lo que cumple
- Arquitectura multi-tenant con `institution_id` en todas las tablas
- 4 tipos de usuario (Admin, Teacher, Student, Parent) con enums y middleware `RequireRole`
- Sistema de exámenes completo: tipos de pregunta (MC, V/F, respuesta corta, ensayo), calificación automática, revisión manual, intentos múltiples
- Ventanas de disponibilidad y aleatorización de preguntas
- Adecuaciones curriculares (×1.25 tiempo, ×1.50 tiempo) aplicadas al iniciar intento
- Pausa y reanudación de examen con control de tiempo restante
- Seguimiento de progreso del estudiante por materia (`mastery_percentage`)
- Tutor IA conversacional con sesiones, historial, modos ask/explain/practice, diagnóstico
- Whitelist de recursos externos en respuestas del tutor IA (`AiOutputValidator`)
- Validación y sanitización de output de OpenAI antes de enviar al cliente
- Headers de seguridad HTTP (`SecurityHeaders` middleware)
- Rate limiting diferenciado: 20/min en `/ai/generate`, 30/min en tutor chat
- Métricas de uso del tutor para docentes (`/reports/ai/tutor-usage`)
- Integración real con OpenAI GPT-4o-mini con fallback local
- Exportación CSV con `phpspreadsheet`
- Recuperación de contraseña con email (Mailable + vista Blade)
- Validación de contraseñas con `PasswordPolicy` de dominio
- Cascada de eliminación en DB (`ON DELETE CASCADE` / `SET NULL`) — sin guards manuales
- Dominio de negocio separado (`Domain/`)

### Backend — Lo que falta
| Elemento | Descripción | Archivo afectado |
|---|---|---|
| ~~Whitelist de recursos externos~~ ✅ | Implementada en `AiOutputValidator` | Resuelto 09/05 |
| ~~Métricas agregadas para docentes~~ ✅ | `GET /reports/ai/tutor-usage` implementado | Resuelto 09/05 |
| ~~Validación automática de respuestas IA~~ ✅ | `AiOutputValidator` valida output antes de enviarlo | Resuelto 09/05 |
| ~~RBAC explícito~~ ✅ | Middleware `RequireRole` + rutas protegidas por rol | Resuelto 09/05 |
| ~~Eliminar código muerto~~ ✅ | `CalendarTargetType.php`, `NameController.php` eliminados | Resuelto |
| ~~Adecuaciones en exámenes~~ ✅ | `adecuacion_type` aplicada al calcular tiempo disponible | Resuelto 09/05 |
| **Log de incidentes del tutor** | Registro de respuestas de IA que activen validaciones | Nueva tabla + servicio |
| **Backups cifrados de BD** | No hay scripts ni documentación de respaldo | Infraestructura |
| **Queue para IA** | Llamadas a OpenAI bloquean el request; mover a colas | Jobs/Queue |

### Frontend — Lo que falta (CRÍTICO)
Todo el frontend está pendiente. Las vistas requeridas por módulo:

| Módulo | Vistas requeridas |
|---|---|
| **Autenticación** | Login, Registro, Recuperar contraseña, Cambiar contraseña |
| **Dashboard** | Panel por rol (Admin / Docente / Estudiante) |
| **Usuarios** | Listado, Crear/Editar, Importar CSV, Cambiar estado |
| **Grupos y Materias** | CRUD completo |
| **Exámenes** | Crear/Editar examen, Banco de preguntas, Vista de examen para estudiante |
| **Intentos** | Tomar examen, Ver resultados, Revisión de respuestas |
| **Tutor Virtual** | Chat/widget de recomendaciones con opciones interactivas |
| **Progreso** | Gráficas de dominio por materia, historial |
| **Reportes** | Dashboard con analytics, exportar PDF/CSV |
| **Calendario** | Vista de eventos académicos |
| **Recursos de Estudio** | Listado y filtros |

**Stack requerido:** React · Next.js · TypeScript · Context API

---

## 3. Perspectiva QA — Calidad y Pruebas

### Lo que cumple
- **142 pruebas automatizadas** (Feature + Integration) con PHPUnit — 423 assertions
- Cobertura de flujos: auth, CRUD completo, calificación, reportes, rutas públicas/protegidas
- **6 niveles de tests de integración:** flujo completo examen, RBAC/IDOR, ciclo de vida estudiante, tutor IA, analíticas, configuración del sistema
- Tests de RBAC e IDOR (Level 2): acceso cruzado de intentos, roles, cross-tenant
- Tests del tutor IA (Level 4): chat, sesiones, modos ask/explain/practice, diagnóstico
- Tests de whitelist de recursos incluidos en Level 4
- Tests de reglas de negocio (`QuestionRulesTest`)

### Lo que falta
| Elemento | Prioridad | Descripción |
|---|---|---|
| **Medición de cobertura** | ALTA | El TFG exige mínimo 70% de cobertura; no hay reporte generado |
| **Tests de carga** | ALTA | Validar ≤2 s con 50 usuarios; soportar 200 concurrentes |
| **Tests E2E (frontend)** | ALTA | Cypress o Playwright cuando exista frontend |
| **Tests de accesibilidad** | MEDIA | Requerimiento no funcional (contraste, teclado) |
| **Pruebas de usuario reales** | ALTA | Piloto con docentes y estudiantes del centro educativo |
| **Reporte de 1000 estudiantes en <5 s** | MEDIA | Validar con datos de volumen |
| **Test del email de reset** | MEDIA | `PasswordResetMail` no está cubierta con tests |

**Comando para generar reporte de cobertura:**
```bash
php artisan test --coverage --min=70
```

---

## 4. Perspectiva Ciberseguridad

### Lo que cumple
- Autenticación con Laravel Sanctum (tokens de API)
- `PasswordPolicy` con mínimo 8 caracteres y tipos mezclados
- Throttling en endpoints sensibles (5/min en login, reset)
- No se transmiten datos personales al backend de IA (por diseño en `AiController`)
- Sanitización implícita de Eloquent (protección contra SQL injection)
- CORS configurado (`config/cors.php`)

### Lo que falta
| Requisito | Gravedad | Descripción |
|---|---|---|
| **HTTPS / TLS** | CRÍTICO | No hay configuración de HTTPS (solo infraestructura, pero debe documentarse) |
| **Expiración de sesión** | ALTA | Sanctum configurado pero no se verifica el tiempo de inactividad de 60 min explícitamente |
| ~~RBAC con middleware~~ ✅ | ~~ALTA~~ | `RequireRole` middleware implementado; rutas protegidas por rol en `api.php` |
| **Backups cifrados** | ALTA | No existe script de backup ni documentación |
| ~~Validación de output IA~~ ✅ | ~~ALTA~~ | `AiOutputValidator` valida y sanitiza respuestas de OpenAI; whitelist de URLs |
| **Log de incidentes de tutor** | ALTA | Sin auditoría de respuestas problemáticas del tutor virtual |
| ~~Rate limiting en `/ai/generate`~~ ✅ | ~~ALTA~~ | `throttle:20,1` en `/ai/generate`; `throttle:30,1` en tutor chat |
| ~~Headers de seguridad HTTP~~ ✅ | ~~MEDIA~~ | `SecurityHeaders` middleware añade CSP, X-Frame-Options, HSTS, etc. |
| **Validación de CSV en bulk-upload** | MEDIA | Importación de archivos sin validación de tipo MIME ni límite de tamaño |
| **Secretos en `.env`** | INFO | `.env` está en `.gitignore` ✓ pero `.env.testing` podría exponer datos de prueba |
| **Política de contraseñas en reset** | MEDIA | El endpoint `resetPassword` debe aplicar la misma `PasswordPolicy` |

---

## 5. Perspectiva Optimización

### Lo que cumple
- Arquitectura en capas: Controllers → Services → Domain (separación de responsabilidades)
- Eloquent con relaciones lazy/eager (optimizable)
- UUID en tablas principales (portable, sin colisiones en SaaS)
- `phpspreadsheet` para exportación eficiente
- OpenAI con fallback local (resiliencia)
- Pint para estilo de código

### Lo que falta / debe mejorarse
| Elemento | Impacto | Descripción |
|---|---|---|
| **Eager loading / N+1** | ~~ALTO~~ ✅ | Eliminados N+1 en grading loop, `recalcFromAttempts`, `syncStudentStats`, `addStudents` |
| **Índices de BD** | ~~ALTO~~ ✅ | Fase 1 y Fase 2 de índices aplicados (review_status, grade_status, ai_recs, chat_sessions) |
| **Aggregaciones en SQL** | ~~ALTO~~ ✅ | AVG y COUNT movidos a SQL; ya no se cargan colecciones en RAM para calcular |
| **Caché de respuestas** | ALTO | Reportes y analíticas frecuentes podrían cachearse con Redis |
| **Queue para IA** | ALTO | Las llamadas a OpenAI bloquean el request; deben moverse a colas (`jobs`) |
| **Paginación obligatoria** | MEDIO | Los endpoints de listado ya tienen `paginate(20)`; revisar consistencia |
| **Monitoreo / observabilidad** | MEDIO | Sin Telescope, Sentry, o sistema de alertas |
| **Optimización de exportación masiva** | MEDIO | Reportes de 1000+ estudiantes deben usar `chunk()` y streaming |
| **Horizontal scaling** | MEDIO | Sin configuración Docker/cloud (Sail disponible pero no documentado para producción) |
| **Compresión de respuestas** | BAJO | No se usa Gzip/Brotli en respuestas API |

---

## 6. Brechas en el Informe Final TFG (Documento Académico)

El documento del TFG tiene 11 capítulos requeridos. Los pendientes son:

| Capítulo | Estado | Acción requerida |
|---|---|---|
| Cap. 1: Introducción (antecedentes, justificación, problema) | ⚠️ Parcial | Revisar y completar con contexto del centro educativo |
| Cap. 2: Marco Teórico | ⚠️ Parcial | Completar revisión bibliográfica (estado del arte en tutores virtuales) |
| Cap. 3: Metodología | ❌ Pendiente | Describir metodología (Scrum, instrumentos de recolección) |
| Cap. 4: Resultados / Propuesta (diagramas, diseño) | ⚠️ Parcial | Completar diagramas de arquitectura actualizados |
| Cap. 5: Conclusiones y Recomendaciones | ❌ Pendiente | Solo al finalizar implementación |
| Cap. 6: Detalles de implementación | ⚠️ Parcial | Incluir frontend cuando esté listo |
| Cap. 7: Validación y resultados del piloto | ❌ Pendiente | Requiere piloto con usuarios reales |
| Cap. 8: Discusión de resultados | ❌ Pendiente | Post-piloto |
| Cap. 9: Aspectos éticos, legales y de privacidad | ❌ Pendiente | GDPR/privacidad de menores, LOPD si aplica |
| Cap. 10: Trabajo futuro y escalabilidad | ❌ Pendiente | Definir roadmap post-TFG |
| Cap. 11: Gestión del proyecto (cronograma, riesgos) | ❌ Pendiente | Sprints, hitos, riesgos identificados |
| **Anexo 1:** Encuestas docentes y estudiantes | ❌ Pendiente | Diseñar y aplicar encuestas |
| **Anexo 2:** Guía de entrevistas semi-estructuradas | ❌ Pendiente | Diseñar y aplicar entrevistas |

---

## 7. Plan de Acción Priorizado

### Fase inmediata (crítico para entregar el TFG)

1. **Iniciar frontend** con Next.js/TypeScript — módulos en este orden:
   - Auth (login/register)
   - Dashboard por rol
   - Módulo de exámenes (tomar examen, ver resultados)
   - Tutor virtual (widget con opciones)

2. **Banco de ítems** — agregar mínimo 60 preguntas reales con metadatos (tema, indicador, dificultad)

3. **Piloto con usuarios** — coordinar con docentes del centro para pruebas

4. **Capítulos del informe** — redactar metodología, ética/privacidad y gestión del proyecto

### Fase media (calidad y completitud)

5. ~~Implementar whitelist de recursos + validación de output IA~~ ✅
6. ~~Agregar RBAC con middleware explícito~~ ✅
7. Mover llamadas OpenAI a colas (Jobs/Queue)
8. Generar reporte de cobertura ≥70%
9. Completar diagramas actualizados (arquitectura real vs diseño inicial)

### Fase final (preparación para defensa)

10. Load testing (50 y 200 usuarios concurrentes)
11. Documentar configuración HTTPS y backups
12. Redactar conclusiones y trabajo futuro
13. Preparar presentación con métricas del piloto

---

## Resumen Visual de Brechas

```
MÓDULO                     BACKEND    FRONTEND    TESTS    DOCS
─────────────────────────────────────────────────────────────
Autenticación              ████████   ░░░░░░░░    ███████  ░░░
Gestión de Usuarios        ████████   ░░░░░░░░    ███████  ░░░
Módulo Académico           ████████   ░░░░░░░░    ████████ ░░░
Sistema de Exámenes        ████████   ░░░░░░░░    ████████ ░░░
Tutor Virtual (IA)         ████████   ░░░░░░░░    ███████  ░░░
Reportes y Analytics       ████████   ░░░░░░░░    ███████  ░░░
Seguridad Avanzada         ████████   ░░░░░░░░    ███████  ░░░
Banco de Ítems             ██░░░░░░   N/A         ░░░░░░   ░░░
Informe TFG                N/A        N/A         N/A      ██░░

█ = completado   ░ = pendiente
(Actualizado 09/05/2026 — 142 tests / 423 assertions)
```

---

*Documento generado el 17/04/2026. Última actualización: 09/05/2026. Revisar al finalizar cada sprint.*
