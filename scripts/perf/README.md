# Auditoría de consultas con datos de volumen

Mide **cada ruta GET de la API, con cada rol**, contra una base LOCAL con un centro
realista (1.500 estudiantes, 40 aulas, 200 exámenes, ~13.000 intentos, ~260.000
respuestas, 15.000 recomendaciones, 18.000 notificaciones). Por ruta da tiempo, número de
consultas, tamaño de la respuesta y, de las consultas ≥ 3 ms, su plan `EXPLAIN (ANALYZE)`.

**Nunca contra Supabase ni producción**: el script solo se conecta a `localhost` y a la
base `neoeducoreperf`.

```powershell
# 1. Base desechable con el esquema del proyecto
psql -U postgres -h localhost -c "create database neoeducoreperf"
psql -U postgres -h localhost -d neoeducoreperf -f database/sql/01_schema.sql

# 2. Datos de volumen (tarda ~1 min)
psql -U postgres -h localhost -d neoeducoreperf -f scripts/perf/volumen.sql

# 3. Auditoría (la clave de postgres local, como en .env.testing)
$env:PERF_DB_PASSWORD = "..."
php scripts/perf/auditoria_consultas.php
```

Cómo leer el resultado: «consultas» alto o «Seq Scan … descartadas=» grande en una tabla
que crece cada año son los candidatos. Con datos pequeños casi todo parece rápido: por eso
se mide con volumen.
