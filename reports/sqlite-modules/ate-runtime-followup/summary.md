Validación de las correcciones de DiskQuota y ExeLearning — 2026-10-09

Esta continuación distingue los fallos de las versiones estables auditadas de
las correcciones ya presentes en los repositorios. No se han mezclado PRs ni
actualizado instalaciones de producción.

| Módulo probado | SQLite | MariaDB | Estado |
| --- | --- | --- | --- |
| DiskQuota 1.0.4 con la corrección `72de4885855f028ad1c2238c435a146464801eca` | 31/31 | 31/31 | [PR #31 abierto](https://github.com/ateeducacion/omeka-s-DiskQuota/pull/31). |
| ExeLearning 4.0.6-rc.2, paquete publicado | 17/17 | 17/17 | Los arreglos están en el [PR #39, ya mezclado](https://github.com/exelearning/omeka-s-exelearning/pull/39). |

96 comprobaciones funcionales pasan. Los dos casos de la consulta SQL privada
getExeLearningItemIds de 4.0.5 no se repiten en la versión nueva: ese método ya
ha sido eliminado, por lo que ExeLearning tiene 17 comprobaciones en lugar de 19.

DiskQuota

El PR cambia el evento de validación de api.create.pre a api.hydrate.pre, que
incluye errorStore. Para medios incluidos en un item, obtiene los sitios del
item ya hidratado; para peticiones directas mantiene la consulta item_site.
Se comprueban subidas multipart directas, creación y edición de items con medios,
límites exactos y cuota ilimitada. Los rechazos no dejan registros de media,
items nuevos ni archivos originales huérfanos.

Dos pruebas de regresión fallaron antes de la corrección. Después pasan las
100 pruebas unitarias (228 aserciones), el linter PSR2 y el umbral de cobertura:
97.26%, con mínimo exigido del 90%. Rama hotfix/enforce-site-upload-quotas.
El paquete de prueba conserva 1.0.4 y sustituye exclusivamente Module.php y
src/Listener/UploadQuotaListener.php por los del commit del PR.

ExeLearning

El PR #39 se mezcló el 2026-09-18 y ya corrige la resolución del renderer de
medios y la limpieza mediante entity.remove.post, usando la ruta real del
servicio. Se ha probado el paquete publicado 4.0.6-rc.2 y pasan el renderizado
habitual de Omeka, subida y extracción del proyecto ELPX real incluido, ajustes
JSON, guardado/reemplazo, conservación del original ante un reemplazo inválido
y limpieza al borrar el medio.

Además, main (`0070fee956d42702215c8b1b1b3069dde609a7f9`) pasa 578 pruebas
unitarias, 1175 aserciones, lint y el umbral de cobertura con 96.20%.
No se abre un PR duplicado para cambios ya incorporados. 4.0.6-rc.2 sigue siendo
una prerelease; 4.0.5 es la última versión marcada como estable.

Entorno y reproducción

El núcleo probado es develop, commit
74a5e131f1881e9d565cf346ff090155b7ee833b, con PHP 8.4.17 y DBAL 2.13.9 de la
imagen de auditoría. Las pruebas unitarias y lint se ejecutaron con PHP 8.5.11.
Los archivos image.txt, database-image.txt y los SHA256 identifican las imágenes
y los paquetes exactos. Los cuatro contenedores usan instalaciones independientes
con bases de datos y red temporales, sin puertos publicados ni datos reales.

```sh
bash reports/sqlite-modules/ate-runtime-followup/test_ate_sqlite_modules.sh \
  /tmp/omeka-sqlite-metadata-core.tar /tmp/omeka-pr-test-packages /tmp/omeka-pr-results
```

Preparar DiskQuota.zip a partir del ZIP estable 1.0.4 sustituyendo los dos archivos
PHP indicados por los del commit del PR. ExeLearning.zip debe ser el ZIP oficial
de 4.0.6-rc.2. El directorio de paquetes contiene también Common.zip y Log.zip
para registrar sus hashes, aunque estos dos casos no los instalan.
El script y el PHP usados se conservan junto al informe y los logs.

Límites: no se han probado navegador, endpoint HTTP de guardado/CSRF, migraciones,
Omeka 3, ramas del núcleo 4.1/4.2 ni borrado de items con medios en cascada en el
test de integración. DiskQuota mantiene el comportamiento existente de medir
el primer archivo; no se han cambiado ni validado cuotas agregadas de lotes o
subidas concurrentes. ExeLearning conserva sus pruebas unitarias de cascadas.
