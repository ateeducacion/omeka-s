Pruebas funcionales de DiskQuota, IsolatedSites y ExeLearning — 2026-10-09

Se probaron paquetes publicados sin modificar los módulos ATE, con instalaciones
limpias y el mismo núcleo en SQLite y MariaDB. No se usaron datos de producción.

Actualización: [la validación posterior](../ate-runtime-followup/summary.md)
comprueba la corrección de DiskQuota del PR #31 y ExeLearning 4.0.6-rc.2, cuyos
arreglos ya estaban mezclados en el PR #39. Los resultados de abajo se conservan
como evidencia de los fallos de las versiones estables originales.

| Módulo | SQLite | MariaDB | Diagnóstico |
| --- | --- | --- | --- |
| DiskQuota 1.0.4 | 22/23 comprobaciones pasan | 22/23 pasan | La cuota del sitio no impide una subida real. |
| IsolatedSites 1.0.8 | Instalación bloqueada por Log | 21/21 pasan | Log 3.4.40 instala claves foráneas mediante ALTER TABLE. |
| ExeLearning 4.0.5 | 17/19 pasan | 17/19 pasan | Falla el renderizado habitual y quedan extracciones al borrar medios. |
| IsolatedSites, con esquema de Log adaptado solo para la prueba | 23/23 pasan | No aplica | Sus consultas y filtros funcionan; se conservan ambas claves foráneas. |

128 comprobaciones funcionales: 122 pasan y 6 fallan. Los seis fallos corresponden
a tres defectos reproducidos en ambos motores. Además, una instalación SQLite
queda bloqueada por una dependencia. El script termina con código 1 porque hay
fallos reales; BLOCKED no significa que IsolatedSites se haya instalado.

Entorno fijado

- Núcleo develop, Omeka S 4.3.0-alpha:
  `74a5e131f1881e9d565cf346ff090155b7ee833b`, con las correcciones recientes de
  COLLATE entre comillas y SHOW INDEX. Estos resultados no usan el núcleo antiguo
  indicado en el inventario estático.
- PHP 8.4.17 y Doctrine DBAL 2.13.9 de `alpine-omeka-s:sqlite-test`.
- Dependencias de IsolatedSites: Common 3.4.93 y Log 3.4.40.
- `image.txt`, `database-image.txt`, `core-archive-sha256.txt` y
  `packages-sha256.txt` identifican las imágenes y los archivos exactos.
- Siete instalaciones independientes: tres SQLite, tres MariaDB y una SQLite
  adicional con las claves foráneas de Log trasladadas dentro de CREATE TABLE.
  Esa adaptación afecta exclusivamente al contenedor desechable; no certifica
  que el paquete publicado de Log funcione con SQLite.

Qué se comprobó

DiskQuota: SUM/JOIN sobre media, resource e item_site; exclusión de medios sin
original; sitio vacío; cuotas por usuario/sitio y valores predeterminados;
límites inferior, exacto y superior; cuota cero ilimitada; rechazo por cuota de
usuario y aceptación de una subida multipart real. La subida que supera la cuota
del sitio se acepta indebidamente en ambos motores.

IsolatedSites: permisos leídos de site_permission; filtros de items, media,
sites, item_sets y assets tanto en contexto API como de administración; recursos
propios sin sitio; usuarios sin permisos; acceso de administrador; denegación de
edición de un item ajeno; asignación/desasignación de conjuntos a sitios y rechazo
de asignaciones a sitios no autorizados. En la variante SQLite también se
comprueban las dos claves foráneas de Log y PRAGMA foreign_key_check.

ExeLearning: subida multipart del proyecto real incluido en el paquete,
`data/fixtures/really-simple-test-project.elpx`; extracción exacta de index.html;
persistencia del hash y ajustes JSON; consulta SQL que identifica items con ELPX;
visor invocado directamente y renderizado habitual mediante media->render();
guardado por ElpFileService::replaceFile; eliminación de la extracción antigua;
rechazo de un reemplazo inválido conservando original y vista previa; borrado del
registro de media. El renderizado habitual y la limpieza de la extracción fallan
con ambos motores.

Correcciones concretas, por prioridad

1. DiskQuota: corregir el módulo, sin parchear el núcleo. Module.php:157–162
   registra checkSiteQuotaBeforeUpload en api.create.pre. Ese evento contiene
   request, pero no errorStore (Omeka Api/Manager.php:294–299). El listener detecta
   la cuota excedida y escribe el aviso, pero rejectSiteUpload no puede añadir
   errores y la subida continúa. Ejecutar la validación en la fase de hidratación
   que proporciona errorStore, y cubrir también los medios incluidos en items.
   El cálculo SQL y la cuota por usuario ya funcionan con SQLite.

2. Log: proponer un PR al módulo de Daniel-KM que declare las dos claves foráneas
   directamente en CREATE TABLE. Su data/install/schema.sql:19–20 usa ALTER TABLE
   ADD CONSTRAINT FOREIGN KEY, rechazado por el adaptador SQLite. La variante de
   prueba demuestra que IsolatedSites funciona con las restricciones presentes.
   No omitir las restricciones ni añadir una excepción al núcleo que las ignore.
   La corrección publicada de Log necesitará validar instalación en ambos motores
   y revisar por separado su desinstalación y sus migraciones.

3. ExeLearning, renderizado: corregir el módulo. Module.php:538 guarda
   `exelearning_renderer` en media.renderer, pero config/module.config.php:51–63
   lo registra solo en file_renderers. El renderizado habitual usa media_renderers
   y devuelve el renderer de respaldo vacío. Conservar el renderer de medios
   `file`, que ya selecciona el visor por MIME/extensión, y migrar los registros
   existentes que tengan el nombre incorrecto. El visor directo sí funciona.

4. ExeLearning, limpieza: corregir el módulo. Module.php:178–182 escucha
   api.delete.pre, pero handleMediaDelete:634–638 espera un parámetro entity que
   ese evento no proporciona. Además getDataPath:99–102 apunta a
   modules/ExeLearning/data/exelearning, mientras ElpFileService extrae en
   files/exelearning. Usar el evento de eliminación de la entidad y la ruta real
   del servicio, incluyendo el borrado de medios al borrar su item.

Los defectos de DiskQuota y ExeLearning no son incompatibilidades SQL específicas
de SQLite: MariaDB reproduce exactamente los mismos fallos. No se han modificado
sus fuentes ni se han abierto PRs como parte de estas pruebas.

Reproducción

Conservar los ZIP cuyos hashes figuran en packages-sha256.txt. Están disponibles
en las releases [DiskQuota 1.0.4](https://github.com/ateeducacion/omeka-s-DiskQuota/releases/tag/1.0.4),
[IsolatedSites 1.0.8](https://github.com/ateeducacion/omeka-s-IsolatedSites/releases/tag/1.0.8),
[ExeLearning 4.0.5](https://github.com/exelearning/omeka-s-exelearning/releases/tag/v4.0.5),
[Common 3.4.93](https://github.com/Daniel-KM/Omeka-S-module-Common/releases/tag/3.4.93)
y [Log 3.4.40](https://github.com/Daniel-KM/Omeka-S-module-Log/releases/tag/3.4.40).

```sh
git archive --format=tar 74a5e131f1881e9d565cf346ff090155b7ee833b -o /tmp/omeka-ate-core.tar
bash scripts/test_ate_sqlite_modules.sh \
  /tmp/omeka-ate-core.tar /tmp/omeka-sqlite-audit-packages /tmp/omeka-ate-results
```

El directorio de paquetes debe contener DiskQuota.zip, IsolatedSites.zip,
ExeLearning.zip, Common.zip y Log.zip. Se requieren Docker, curl/unzip/PHP en la
imagen de Omeka y Python 3 en el host. El script crea una red sin puertos
publicados y bases temporales; elimina contenedores y red al terminar. Se ha
verificado que no quedan contenedores ni redes de estas pruebas.

Límites: se prueba develop con esta combinación PHP/DBAL. No se han ejecutado las
ramas 4.1/4.2, migraciones desde instalaciones antiguas, interfaz del editor en
un navegador, endpoint HTTP de guardado/CSRF, exportaciones ni PHP-WASM. El
guardado se prueba mediante el servicio real usado por el módulo. Estos casos
no certifican todas las funciones ni todos los roles y escenarios de permisos.
CAS queda fuera del alcance, según lo indicado por el usuario.
