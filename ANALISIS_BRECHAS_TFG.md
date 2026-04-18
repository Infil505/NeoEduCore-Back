# Análisis de Brechas TFG — NeoEduCore
**Fecha:** 17 de abril de 2026  
**Proyecto:** NeoEduCore — Sistema web de gestión de exámenes diagnósticos con tutor virtual  
**Referencia:** CTFG-DOC-18_Guia_para_Informe_Final_TFG 2025  
**Analistas:** PM · Desarrollador Fullstack · QA · Ciberseguridad · Optimización

---

## Resumen Ejecutivo

El backend de NeoEduCore está **sólido y avanzado**. Se cuenta con una API REST completa en Laravel 12 (PostgreSQL, Sanctum, OpenAI, Swagger) con 15 modelos, 19 controladores, arquitectura multi-tenant y 30 pruebas automatizadas. Sin embargo, el proyecto **carece completamente de frontend**, que es la capa de presentación exigida por el TFG (React/Next.js/TypeScript). Adicionalmente existen brechas en seguridad avanzada, banco de ítems, y documentación académica del informe.

**Porcentaje estimado completado:** ~45–50% del total del proyecto.

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
| Integración OpenAI para recomendaciones | ✅ Completo |
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
- 4 tipos de usuario (Admin, Teacher, Student, Parent) con enums
- Sistema de exámenes completo: tipos de pregunta (MC, V/F, respuesta corta, ensayo), calificación automática, revisión manual, intentos múltiples
- Ventanas de disponibilidad y aleatorización de preguntas
- Seguimiento de progreso del estudiante por materia (`mastery_percentage`)
- Integración real con OpenAI GPT-4o-mini con fallback local
- Exportación CSV con `phpspreadsheet`
- Recuperación de contraseña con email (Mailable + vista Blade)
- Validación de contraseñas con `PasswordPolicy` de dominio
- Dominio de negocio separado (`Domain/`)

### Backend — Lo que falta
| Elemento | Descripción | Archivo afectado |
|---|---|---|
| **Whitelist de recursos externos** | El tutor virtual debe sugerir URLs solo de una lista verificada | `AiRecommendationService.php` |
| **Opciones del tutor**: "No entendí" / "Quiero practicar" | El chatbot debe tener flujos de reformulación y ejercicios guiados | Nuevo endpoint en `AiController` |
| **Métricas agregadas para docentes** | Temas más recomendados, niveles de dificultad, uso del tutor | Nuevo endpoint en `ReportController` |
| **Log de incidentes del tutor** | Registro de respuestas de IA que activen validaciones de seguridad | Nueva tabla + servicio |
| **Validación automática de respuestas IA** | Verificar legibilidad y ausencia de datos personales en output de OpenAI | `AiRecommendationService.php` |
| **RBAC explícito** | No hay middleware de autorización por rol visible; la lógica está implícita | Nuevo `RoleMiddleware` |
| **Email template faltante** | `resources/views/emails/password-reset.blade.php` no existe | Crear vista |
| **Eliminar código muerto** | `NameController.php` y `StoreNameRequest.php` sin uso | Limpiar |
| **Adecuaciones en exámenes** | `adecuacion_type` en Student no se usa durante la evaluación | `ExamAttemptRulesService` |
| **Backups cifrados de BD** | No hay scripts ni documentación de respaldo | Infraestructura |

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
- 30 pruebas automatizadas (Feature + Unit) con PHPUnit
- Cobertura de flujos: auth, CRUD principal, calificación, reportes, rutas públicas/protegidas
- Tests de integridad de esquema de BD
- Prueba de calculadora de calificaciones (unit)
- Reglas de negocio testeadas (`QuestionRulesTest`)

### Lo que falta
| Elemento | Prioridad | Descripción |
|---|---|---|
| **Medición de cobertura** | ALTA | El TFG exige mínimo 70% de cobertura; no hay reporte generado |
| **Tests de carga** | ALTA | Validar ≤2 s con 50 usuarios; soportar 200 concurrentes |
| **Tests del servicio IA** | ALTA | `AiRecommendationService` no tiene tests unitarios |
| **Tests de la whitelist** | MEDIA | Una vez implementada, debe ser testeada |
| **Tests E2E (frontend)** | ALTA | Cypress o Playwright cuando exista frontend |
| **Tests de accesibilidad** | MEDIA | Requerimiento no funcional (contraste, teclado) |
| **Pruebas de usuario reales** | ALTA | Piloto con docentes y estudiantes del centro educativo |
| **Reporte de 1000 estudiantes en <5 s** | MEDIA | Validar con datos de volumen |
| **Tests de roles/permisos** | ALTA | No se verifican restricciones por rol en los tests existentes |
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
| **RBAC con middleware** | ALTA | No hay `RoleMiddleware`; los controladores no verifican rol explícitamente |
| **Backups cifrados** | ALTA | No existe script de backup ni documentación |
| **Validación de output IA** | ALTA | Las respuestas de OpenAI no se validan/sanitizan antes de enviarse al cliente |
| **Log de incidentes de tutor** | ALTA | Sin auditoría de respuestas problemáticas del tutor virtual |
| **Rate limiting en `/ai/generate`** | ALTA | El endpoint de IA es público y sin throttle |
| **Headers de seguridad HTTP** | MEDIA | No se configura `Content-Security-Policy`, `X-Frame-Options`, etc. |
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
| **Eager loading** | ALTO | Revisar N+1 queries en listados de exámenes/intentos/respuestas |
| **Caché de respuestas** | ALTO | Reportes y progreso de estudiantes deberían cachearse (Redis) |
| **Índices de BD** | ALTO | Agregar índices en `student_user_id`, `exam_id`, `institution_id` si no existen |
| **Queue para IA** | ALTO | Las llamadas a OpenAI bloquean el request; deben moverse a colas (`jobs`) |
| **Paginación obligatoria** | MEDIO | Los endpoints de listado no tienen paginación forzada |
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

5. Implementar whitelist de recursos + validación de output IA
6. Agregar RBAC con middleware explícito
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
Autenticación              ████████   ░░░░░░░░    ███░░░   ░░░
Gestión de Usuarios        ████████   ░░░░░░░░    ███░░░   ░░░
Módulo Académico           ████████   ░░░░░░░░    ████░░   ░░░
Sistema de Exámenes        ████████   ░░░░░░░░    █████░   ░░░
Tutor Virtual (IA)         █████░░░   ░░░░░░░░    ██░░░░   ░░░
Reportes y Analytics       ██████░░   ░░░░░░░░    ████░░   ░░░
Seguridad Avanzada         █████░░░   ░░░░░░░░    ██░░░░   ░░░
Banco de Ítems             ██░░░░░░   N/A         ░░░░░░   ░░░
Informe TFG                N/A        N/A         N/A      ██░░

█ = completado   ░ = pendiente
```

---

*Documento generado el 17/04/2026. Revisar al finalizar cada sprint.*
