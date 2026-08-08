# Pruebas de API con Postman — NeoEduCore

Colección lista para importar que cubre **todas y cada una de las rutas** de la API, generada
automáticamente desde `php artisan route:list`. No se edita a mano: **se regenera** (ver más abajo),
así que el recuento de endpoints es siempre el que imprima el generador.

## Archivos

| Archivo | Qué es |
|---|---|
| `NeoEduCore.postman_collection.json` | La colección con todos los endpoints. **Importar en Postman.** |
| `NeoEduCore.postman_environment.json` | Environment con `base_url`, credenciales del seeder y variables de ids. **Importar y seleccionar.** |
| `generate_postman_collection.php` | Generador. Vuelve a crear la colección desde las rutas reales. |

## Puesta en marcha (una vez)

1. **Levantar el backend** con la base sembrada:
   ```bash
   php artisan migrate --seed      # crea el admin y datos de demo
   php artisan serve               # o: composer run dev
   ```
   Credenciales del seeder (todas con contraseña `password123`):
   - `admin@neoeducore.edu.co` (admin)
   - `profesor1@neoeducore.edu.co` (teacher)
   - `estudiante1@neoeducore.edu.co` (student)

2. **Importar en Postman** los dos JSON (`Import` → arrastra ambos archivos).
3. Arriba a la derecha, **seleccionar el environment** `NeoEduCore · Local`.

## Flujo de prueba

1. Carpeta **`01 · Auth y Sesión` → `POST /api/auth/login`** y pulsa *Send*.
   - El body ya trae `{{admin_email}}` / `{{admin_password}}`.
   - Un *test script* guarda el token en `{{token}}` automáticamente; el resto de peticiones lo usan vía Bearer heredado.
2. Ejecuta los **`GET` de listado** (exámenes, materias, grupos, estudiantes, usuarios...). Cada uno **autocaptura el primer id** en su variable (`exam_id`, `subject_id`, `group_id`, `student_user_id`, ...), de modo que los endpoints con `{{...}}` en la URL ya quedan resueltos.
3. Prueba el resto de peticiones. Los `POST/PUT/PATCH` traen un **body de ejemplo** basado en las validaciones reales del backend.

### Probar con otro rol

Cambia el body del login por las credenciales del rol deseado (o usa `{{teacher_email}}`/`{{student_email}}`) y vuelve a *Send*. El nuevo token reemplaza al anterior. Recuerda que el RBAC devuelve **403** si el rol no tiene permiso sobre la ruta (es el comportamiento esperado).

## Regenerar la colección

Si agregas o cambias rutas en `routes/api.php`, regenera para no desincronizar:

```bash
php postman/generate_postman_collection.php
```

Lee la lista real de rutas, así que siempre refleja el estado actual del sistema. Los bodies de ejemplo se editan dentro del propio script (mapa `$bodies`).

## Notas

- `base_url` por defecto: `http://localhost:8000/api`. Si usas otro host/puerto, edítalo en el environment.
- `POST /api/students/bulk-upload` usa **form-data** (campo `file`): adjunta un CSV/XLSX manualmente en Postman.
- Endpoints de IA (`/api/ai/generate`, `/api/ai/tutor/*`) requieren `OPENAI_API_KEY`; sin ella responden con el *fallback*.
- Los correos (`/api/password/forgot`, alta masiva) se encolan: necesitas el worker (`php artisan queue:work` o `composer run dev`) para que se envíen.
