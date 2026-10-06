# Taller de simulación — dos aulas de 4.º de primaria

**Sembrado el 14 de septiembre de 2026** con `TallerAulaSeeder`; **ajustado el 05/10/2026** a las reglas de
roles nuevas (segunda aula, recursos y avisos enviados a aulas, autoría por materia).

```bash
php artisan db:seed --class=TallerAulaSeeder --force
```

> ⚠️ **El seeder vacía la base antes de sembrar.** Lo único que respeta es la cuenta de
> superadministrador: administra las instituciones y solo se crea por consola, así que
> borrarla dejaría fuera a quien dirige el taller. Todo lo demás —instituciones,
> usuarios, materias, exámenes, intentos— se borra y se vuelve a crear.
>
> Es **reproducible**: los nombres, las notas y los fallos salen de listas fijas, no de
> `fake()`. Dos ejecuciones dan el mismo aula, que es lo que permite preparar un guion y
> que no se mueva bajo los pies.

---

## Acceso

**Contraseña de todas las cuentas: `Taller2026`**

Todas nacen **activas**: en un taller sin correo real configurado, el flujo de
activación por enlace dejaría a todo el mundo fuera.

| Rol | Correo | Para qué sirve en el taller |
|---|---|---|
| Superadmin | *(la cuenta existente)* | Instituciones y métricas de plataforma |
| Admin | `direccion@nuevaesperanza.ed.cr` | Ve el centro entero; asigna docentes |
| Docente | `rodrigo.pineda@nuevaesperanza.ed.cr` | Matemáticas y Ciencias de 4-A → **alcanza a los 28**; **no ve 4-B** |
| Docente | `lucia.vindas@nuevaesperanza.ed.cr` | Español y Estudios Sociales de 4-A + Español de 4-B → **34 alumnos y dos aulas**: al enviar un recurso o aviso **elige aula** |
| Docente | `andres.calvo@nuevaesperanza.ed.cr` | **Sin asignación → no ve estudiantes, ni materias, ni aulas** |
| Alumnado 4-A | `nombre.apellido@nuevaesperanza.ed.cr` | 28 cuentas; p. ej. `ariana.solis@…` |
| Alumnado 4-B | `beatriz.cordero@nuevaesperanza.ed.cr` | 6 cuentas; ven solo lo enviado a 4-B |

---

## Qué hay dentro

**Escuela Nueva Esperanza**, grupos **4-A 2026** (28 estudiantes de 9–10 años) y **4-B 2026** (6).

| | |
|---|---|
| Materias | Español, Matemáticas, Ciencias, Estudios Sociales |
| Asignaciones docentes | 5: Rodrigo (Matemáticas, Ciencias → 4-A); Lucía (Español, Estudios Sociales → 4-A; Español → 4-B); Andrés, ninguna |
| Examen | «Prueba corta: fracciones y decimales», 8 ítems, `active`, 2 intentos |
| Entregas | **24 de 28** — cuatro sin entregar, como en un aula real |
| Recursos | 6, con materia y rango de grado; **cada uno lo crea el docente que da esa materia y lo envía a aulas suyas** (el de lectura, a 4-A y 4-B) |
| Avisos del calendario | 4: de Rodrigo a 4-A (la prueba y el repaso) y de Lucía a 4-A y 4-B (la reunión: un evento por aula) |
| Adecuaciones curriculares | 2 estudiantes con adecuación de acceso |

### Las notas están repartidas a propósito

| Nota | Alumnos |
|---|---|
| 8/8 | 3 |
| 7/8 | 3 |
| 6/8 | 5 |
| 5/8 | 4 |
| 4/8 | 4 |
| 3/8 | 3 |
| 2/8 | 2 |

Así el histograma y los cuatro niveles de desempeño tienen forma, en vez de una sola
barra. Si todos sacaran lo mismo, los gráficos del informe no enseñarían nada.

### Y los fallos se concentran en un tema

| Tema | Aciertos |
|---|---|
| **Decimales** | **28 %** |
| Fracciones equivalentes | 81 % |
| Resta de fracciones | 92 % |
| Suma de fracciones | 100 % |

No es aleatorio: cada ítem lleva `topic`, `indicator` y `difficulty`, y los errores se
sembraron cargados hacia decimales. Gracias a eso el diagnóstico del tutor y
`GET /reports/topics` señalan **algo real** en lugar de ruido estadístico.

---

## Guion sugerido

1. **La frontera del docente.** Entrar como `andres.calvo` → `/api/students`,
   `/api/subjects` y `/api/groups` devuelven **0**. Entrar como `rodrigo.pineda` →
   28 alumnos y 1 aula; como `lucia.vindas` → 34 alumnos y 2 aulas. El permiso sale de
   `teacher_assignments`, no de haber creado un examen.
   *Entre docentes no se ve lo que tiene cada uno:* `GET /api/exams` con Lucía no
   muestra el examen de Rodrigo (y al revés), aunque compartan aula.
2. **El aislamiento por institución.** Con el admin, pedir cualquier id de otro centro:
   404, no 403 — un 403 confirmaría que existe.
3. **El aula por dentro.** `GET /groups/{id}` con el docente asignado: lista nominal.
   Con el otro docente: 403.
4. **Los reportes.** `GET /reports/exams/{examen}/summary` para los gráficos, y
   `results.xlsx` para la exportación con tipos reales.
5. **Los temas a reforzar.** `GET /reports/topics` → decimales arriba del todo, agregado
   y **sin un solo nombre de alumno**.
6. **El tutor.** Entrar como `ariana.solis` (8/8) y como `ulises.bonilla` (2/8) y
   comparar el diagnóstico: el mismo sistema, dos lecturas distintas.
7. **El análisis de IA en vivo.** Ningún intento tiene `ai_recommendations_status`, así
   que la **primera** vez que un alumno abra
   `GET /exam-attempts/{intento}/recommendations` se encola el análisis y se ve el
   estado pasar de `preparing` a `ready`.
   ⚠️ Necesita el worker corriendo: `php artisan queue:work`.
8. **La plataforma.** Con el superadmin, `GET /platform/ai-tutor-metrics`.
9. **Recursos y avisos por aula.** Con `lucia.vindas`, `POST /api/study-resources` o
   `POST /api/calendar-events` **sin** `group_ids` → 422 con la lista de sus dos aulas;
   con `group_ids` de una sola → llega solo a esa. Con `rodrigo.pineda` (una sola aula)
   no hace falta elegir. El administrador, en cambio, **no puede crearlos** (403).
10. **Cada alumno ve lo de su aula.** `ariana.solis` (4-A) ve los 6 recursos; `beatriz.cordero`
    (4-B) solo el de lectura, y ningún examen (el activo es de 4-A).

---

## Antes del taller

- [ ] `php artisan queue:work` corriendo, o el paso 7 se queda en `preparing`.
- [ ] `OPENAI_API_KEY` con saldo, si se va a enseñar el tutor de verdad.
- [ ] Si el taller es sobre el despliegue y no en local, hace falta **P1** hecho.
