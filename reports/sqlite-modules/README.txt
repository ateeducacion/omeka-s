Auditoría estática de compatibilidad SQLite de los módulos de Omeka S

Resultados
  summary.txt: cobertura, reglas, límites y prioridades revisadas manualmente.
  modules.csv: inventario por repositorio, versión, dependencias y candidatos.
  findings.csv / findings.json: candidatos con archivo, línea y contexto.
  inventory.json: catálogos, URLs, versiones, manifiestos y hashes de fuentes.

Este informe detecta candidatos; no certifica compatibilidad ni confirma fallos.
Las prioridades revisadas manualmente corresponden a las versiones indicadas
en el informe inicial, no a futuras versiones de los mismos módulos.

Requisitos: Python 3.9 o posterior y GitHub CLI (gh) autenticado para resolver
repositorios de GitHub. No se añaden dependencias Python. El escáner no extrae,
instala ni ejecuta los módulos descargados.

Actualizar desde los catálogos (ejecutar desde la raíz del repositorio):

  curl -fL https://omeka.org/s/modules/ -o /tmp/omeka-modules-catalog.html
  curl -fL https://daniel-km.github.io/UpgradeToOmekaS/en/omeka_s_modules.html -o /tmp/omeka-modules-daniel.html
  python3 -B scripts/audit_sqlite_modules.py \
    --official /tmp/omeka-modules-catalog.html \
    --daniel /tmp/omeka-modules-daniel.html \
    --output reports/sqlite-modules \
    --cache /tmp/omeka-module-audit-cache

Añadir --inventory-only genera solamente el inventario.
Añadir --rescan vuelve a analizar los archivos guardados sin acceso a la red.
Añadir --retry-unavailable conserva los éxitos y reintenta las fuentes ausentes.
Para repetir exactamente el escaneo hay que conservar los dos HTML y la caché
de archivos .archive/.url; los hashes del informe permiten comprobarlos.
Para descargar otra vez las fuentes actuales conviene usar una caché nueva:
las URLs de paquetes publicados pueden cambiar de contenido sin cambiar de URL.

Comprobar el escáner:
  python3 -B -m unittest discover -s scripts -p 'test_audit_sqlite_modules.py'

Interpretación de los candidatos
  rejected: construcciones que el adaptador actual rechaza expresamente.
  unsupported: SQL específico de MySQL sin traducción detectada en el núcleo.
  review: depende de la consulta completa, de la conexión o de su propósito.
  covered_if_wrapper: solo está cubierto si pasa por la conexión de Omeka.

Las categorías se solapan y pueden incluir funciones opcionales, migraciones
antiguas o consultas a bases externas. No ejecutar correcciones masivas a partir
de las coincidencias. Revisar cada llamada y reproducirla primero.

Pruebas de ejecución propuestas
  1. Fijar la misma revisión del núcleo en SQLite y MariaDB.
  2. Usar instalaciones limpias independientes para cada módulo, con las mismas
     versiones, dependencias y datos de ejemplo.
  3. Empezar por Collecting y CSV Import; después Common + Easy Admin y
     Advanced Search con sus dependencias. Separar las pruebas de instalación,
     actualización y uso: no ejecutan el mismo SQL.
  4. Comprobar estado instalado, integridad referencial y una operación real:
     recoger un envío, importar un CSV, limpiar sesiones o buscar un recurso.
     Que arranque el contenedor no demuestra que se haya instalado el módulo;
     el entrypoint de alpine-omeka-s puede continuar tras una instalación fallida.
  5. Un fallo solo en SQLite identifica una posible carencia del soporte o del
     módulo. Guardar consulta y error, añadir una prueba pequeña y corregir el
     lugar adecuado. Un fallo también en MariaDB puede deberse a la preparación
     o a un defecto del módulo independiente del motor; hay que distinguirlos.

Primeras pruebas realizadas
  Se han comprobado instalaciones limpias de Collecting 1.13.0, CSV Import
  2.6.2 y Code Snippets 1.0.3, en los dos motores. runtime/results.tsv contiene
  el código de salida y el estado activo consultado directamente en la tabla
  module; runtime/*.log contiene los errores completos. runtime/summary.txt
  distingue estos fallos confirmados de las coincidencias del escaneo estático.
  Se usa el núcleo fijado en core_commit del inventario, superpuesto sobre una
  imagen Alpine de prueba. No se modifica el repositorio alpine-omeka-s.

Estas primeras pruebas se hicieron con una versión anterior del script; su
interfaz ha cambiado y runtime/ se conserva solo como registro histórico.

Pruebas de instalación y uso (2026-10-17)
  runtime-modules/summary.txt resume las pruebas de 19 módulos en las imágenes
  publicadas de alpine-omeka-s (develop y 4.1), con MariaDB como control.
  Cada cadena de módulos se instala en limpio, ejecuta operaciones reales
  (scripts/test_sqlite_module_operations.php), se desinstala y, en SQLite,
  pasa PRAGMA foreign_key_check. Todos dan los mismos resultados que en
  MariaDB salvo Mapping (doctrine-spatial no admite SQLite).

Repetir:
  bash scripts/test_sqlite_module_installation.sh <paquetes> <salida> \
    Common Common,EasyAdmin Common,AdvancedSearch Common,Log,Mapper,BulkImport ...

  Cada argumento es una cadena de módulos en orden de instalación; el último
  es el que se prueba. <paquetes> contiene un <Módulo>.zip por módulo, por
  ejemplo copiado de la caché de la auditoría (los hashes usados están en
  runtime-modules/*/packages-sha256.txt).
  OMEKA_AUDIT_IMAGE elige la imagen (por defecto erseco/alpine-omeka-s:develop).
  OMEKA_AUDIT_CORE acepta un tar con ficheros del núcleo que se superponen a
  la imagen, para probar cambios aún no publicados.

El script necesita Docker. MariaDB usa mariadb:lts; database-image.txt
identifica la revisión usada. Se crean bases, contenedores y una red
desechables, sin puertos publicados. El resultado de instalación se toma de
la tabla module, no del estado del contenedor.

Pendiente: actualizaciones desde versiones anteriores y datos reales.

Pruebas funcionales de módulos ATE (2026-10-09)
  ate-runtime/summary.md y sus logs contienen pruebas de DiskQuota 1.0.4,
  IsolatedSites 1.0.8 y ExeLearning 4.0.5 en SQLite y MariaDB, con el núcleo
  74a5e131f1881e9d565cf346ff090155b7ee833b. Incluyen subidas multipart reales,
  cuotas, filtros de recursos y permisos, extracción/renderizado/guardado de ELPX
  y persistencia JSON. El informe distingue fallos comunes a ambos motores del
  bloqueo SQLite de la dependencia Log. No modifica los resultados históricos
  de runtime/ ni certifica compatibilidad completa.
  scripts/test_ate_sqlite_modules.sh y scripts/test_ate_sqlite_modules.php permiten
  repetir estos casos; véanse los requisitos y límites en ese informe.

Revisión posterior del PR #31 de DiskQuota (2026-10-09)
  diskquota-pr-review/summary.md documenta la reproducción y corrección de la
  resolución de o:item contradictorio en medios anidados. Incluye pruebas reales
  en SQLite/MariaDB y conserva los resultados anteriores, sin sobrescribirlos.
