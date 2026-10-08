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
     lugar adecuado. Un fallo también en MariaDB requiere revisar la preparación.

Primeras pruebas realizadas
  Se han comprobado instalaciones limpias de Collecting 1.13.0, CSV Import
  2.6.2 y Code Snippets 1.0.3, en los dos motores. runtime/results.tsv contiene
  el código de salida y el estado activo consultado directamente en la tabla
  module; runtime/*.log contiene los errores completos. runtime/summary.txt
  distingue estos fallos confirmados de las coincidencias del escaneo estático.
  Se usa el núcleo fijado en core_commit del inventario, superpuesto sobre una
  imagen Alpine de prueba. No se modifica el repositorio alpine-omeka-s.

Repetir estas seis instalaciones:
  git archive --format=tar 87b4ef845b83bb4b889fda7b8ce7e718f2090341 -o /tmp/omeka-sqlite-audit-core.tar
  mkdir -p /tmp/omeka-sqlite-audit-packages
  cp /tmp/omeka-module-audit-cache/cd4b0e5de4ebba26.archive /tmp/omeka-sqlite-audit-packages/Collecting.zip
  cp /tmp/omeka-module-audit-cache/aeab175fe456fc76.archive /tmp/omeka-sqlite-audit-packages/CSVImport.zip
  cp /tmp/omeka-module-audit-cache/ecdc3b4a7ef67855.archive /tmp/omeka-sqlite-audit-packages/CodeSnippets.zip
  bash scripts/test_sqlite_module_installation.sh \
    /tmp/omeka-sqlite-audit-core.tar /tmp/omeka-sqlite-audit-packages \
    reports/sqlite-modules/runtime

El script necesita Docker y la imagen local alpine-omeka-s:sqlite-test.
OMEKA_AUDIT_IMAGE permite usar la misma imagen identificada en runtime/image.txt.
MariaDB usa mariadb:lts; runtime/database-image.txt identifica la revisión usada.
Se crean bases, contenedores y una red desechables, sin puertos publicados.
No usar el estado del contenedor como sustituto de installed_active.

Pendiente: Common, Easy Admin, Advanced Search, operaciones reales y migraciones.
