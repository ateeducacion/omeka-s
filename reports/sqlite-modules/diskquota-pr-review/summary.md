# DiskQuota: corrección de la revisión del PR #31

Fecha: 2026-10-09. PR: https://github.com/ateeducacion/omeka-s-DiskQuota/pull/31
Commit corregido: `9b8c270a6344c0231755d6d1ced729460eef01c2`.

## Integración automática y fusión posterior

El PR se fusionó con squash tras automatizar la regresión SQLite y superar las
11 comprobaciones de su revisión final `693347861a076de7af204dc2e54e3cffb83e2896`.
Commit en `main`: `50563317e297aa5481ed102224aa1c8b68cf59e1`. La rama del PR se
eliminó localmente y en GitHub; el clon del módulo está limpio y sincronizado.

El job `sqlite_integration` prepara Omeka y el módulo, y ejecuta las cuatro
comprobaciones reales en PHP 8.1.34. Ejecución verificable:
https://github.com/ateeducacion/omeka-s-DiskQuota/actions/runs/37937235971/job/113842256157

`ci-automation/` conserva su log, los checks y los scripts finales. El runner
final también pasa localmente y devuelve error al reinstaurar el listener
defectuoso `72de488`. CI instala ImageMagick, necesario para la subida real.
La instancia desechable acepta los dos MIME detectados para la fixture generada;
la validación de archivos permanece activa. Los resultados anteriores que
siguen debajo corresponden a la etapa previa a esta automatización.

Se confirmó el hallazgo 1 en SQLite y MariaDB contra el anterior commit
`72de4885855f028ad1c2238c435a146464801eca`: los medios anidados bajo un ítem A
con cuota libre terminaban asociados al ítem B con cuota agotada cuando el
contenido incluía un `o:item` contradictorio. Los logs `before/` muestran el
`item_id` realmente persistido, tanto en creación como en actualización.

La corrección comprueba el ítem explícito cuando sustituye al padre preparado
por ItemAdapter. Si no hay sustitución, utiliza los sitios hidratados del padre,
incluidas las asignaciones todavía no persistidas. Se conserva el comportamiento
de cuotas ilimitadas y no se necesita cambiar el núcleo ni el esquema.

Validación del commit corregido:

- 104 pruebas unitarias, 236 aserciones; cobertura de líneas 97,26 %, por encima
  del mínimo del 90 %. PSR2 correcto. Logs `unit-coverage.log` y `lint.log`.
- Las nueve comprobaciones de GitHub han terminado correctamente para el nuevo
  commit, incluidas CI, CodeQL, PHPMD y Codecov. Evidencia en `ci.log`.
- `after/`: 33 comprobaciones funcionales correctas por motor, 66 en total.
- `integration/`: cuatro comprobaciones adicionales por motor; creación y
  actualización con ítem contradictorio, asignación pendiente de sitio cuando
  el ID coincide y ausencia de registros/archivos huérfanos tras el rechazo.
  El script y sus instrucciones también están incluidos en el PR del módulo.
- Contenedores y bases desechables, sin modificar datos de producción. Núcleo
  `74a5e131f1881e9d565cf346ff090155b7ee833b`, PHP 8.4.17, DBAL 2.13.9.
  Pruebas unitarias y lint en PHP 8.5.11. Los subdirectorios conservan hashes de
  paquetes e imágenes y resultados por motor.

Repetir las pruebas funcionales desde la raíz del repositorio, con los paquetes
identificados en `after/packages-sha256.txt` y el archivo del núcleo fijado:

```sh
bash reports/sqlite-modules/diskquota-pr-review/test_ate_sqlite_modules.sh \
  /tmp/omeka-sqlite-metadata-core.tar /tmp/omeka-pr-test-packages /tmp/diskquota-review-repeat
```

El script requiere Docker, la imagen Alpine de pruebas y MariaDB. Consulta
`integration/README.md` para ejecutar separadamente la regresión incluida en el PR.

Límite del registro anterior: después de reproducir ambos bypass, el último
caso positivo del arnés `before/` falla también porque el listener cambia el
sitio objetivo del servicio compartido de ajustes. Por eso esos logs sirven
como evidencia del bypass y no como una medición completa de fallos anteriores.
La ejecución `after/` termina correctamente con cero fallos.

Los hallazgos 2 y 3 quedan documentados en el PR pero no corregidos: acumulación
de bytes pendientes y resolución del tamaño de cada archivo en lotes; ingestión
desde URL sin tamaño previo. No se han probado aquí esas vías, concurrencia ni
reasignaciones de sitios de ítems con medios existentes. La cuota aún no puede
considerarse un límite estricto para todas las modalidades de ingestión.
