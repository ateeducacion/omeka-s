#!/usr/bin/env python3
"""Read-only SQLite audit of published Omeka S modules; never extracts or executes them."""
import argparse
import base64
from collections import Counter
import concurrent.futures
import csv
import hashlib
from html.parser import HTMLParser
import io
import json
from pathlib import Path
import re
import subprocess
import tarfile
import time
from urllib.parse import quote, urlparse
from urllib.request import Request, urlopen
import zipfile


class Catalog(HTMLParser):
    def __init__(self, source):
        super().__init__()
        self.source = source
        self.records = []
        self.record = None
        self.depth = 0
        self.field = None
        self.cell = None

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        classes = attrs.get('class', '').split()
        if self.source == 'official':
            if tag == 'div' and 'addon-entry' in classes:
                self.record = {'sources': [self.source]}
                self.depth = 0
            if self.record is None:
                return
            if tag == 'div':
                self.depth += 1
            if tag == 'h4':
                self.field = 'name'
            if tag == 'span' and 'version' in classes:
                self.field = 'version'
            if tag == 'a' and 'button' in classes:
                self.record['archive_url'] = attrs.get('href', '')
        else:
            if tag == 'tr':
                self.record = {'sources': [self.source], 'cells': []}
            if self.record is None:
                return
            if tag == 'td':
                self.cell = {'class': attrs.get('class', ''), 'text': ''}
                self.record['cells'].append(self.cell)
            if tag == 'a' and 'addon-link' in classes:
                self.record['repository'] = attrs.get('href', '')
                self.field = 'name'

    def handle_data(self, data):
        if self.record is None:
            return
        if self.field:
            self.record[self.field] = self.record.get(self.field, '') + data
        if self.cell is not None:
            self.cell['text'] += data

    def handle_endtag(self, tag):
        if self.record is None:
            return
        if self.source == 'official':
            if tag in ('h4', 'span'):
                self.field = None
            if tag == 'div':
                self.depth -= 1
                if self.depth == 0:
                    self.finish()
        else:
            if tag == 'a':
                self.field = None
            if tag == 'td':
                self.cell = None
            if tag == 'tr':
                self.finish()

    def finish(self):
        record = self.record
        self.record = None
        self.field = None
        if not record.get('name'):
            return
        record['name'] = ' '.join(record['name'].split())
        record['archived'] = 'archived' in record['name'].lower()
        if self.source == 'official':
            record['version'] = record.get('version', '').replace('Latest Version:', '').strip()
            url = record.get('archive_url', '')
            record['repository'] = re.split(r'/releases/|/-/archive/|/archive/', url)[0]
        else:
            for cell in record.pop('cells'):
                if cell['class'] == 'addon-updated':
                    version = re.search(r'\(v\.([^\)]+)\)', cell['text'])
                    record['version'] = version[1].strip() if version else ''
                if cell['class'] == 'addon-target':
                    record['catalog_omeka_constraint'] = ' '.join(cell['text'].split())
                if 'Gone' in cell['text']:
                    record['archived'] = True
        self.records.append(record)


def repository_key(url):
    parsed = urlparse(url.rstrip('/'))
    return (parsed.hostname or '').lower() + parsed.path.removesuffix('.git').lower()


def inventory(catalogs):
    records = {}
    for source, html in catalogs:
        parser = Catalog(source)
        parser.feed(html)
        for record in parser.records:
            key = repository_key(record['repository'])
            if key in records:
                existing = records[key]
                existing['sources'].extend(record['sources'])
                existing['catalog_omeka_constraint'] = record.get('catalog_omeka_constraint', '')
                if record.get('version') != existing.get('version'):
                    existing['alternate_catalog_version'] = record.get('version', '')
            else:
                record['id'] = hashlib.sha256(key.encode()).hexdigest()[:16]
                records[key] = record
    return sorted(records.values(), key=lambda r: r['name'].lower())


# These are candidates, not SQL parsing or a declaration of module compatibility.
RULES = [
    ('alter_mysql', 'unsupported', r'\bALTER\s+TABLE\b[^;]{0,180}?\b(?:CONVERT\s+TO|(?:DEFAULT\s+)?CHARSET)\b'),
    ('mysql_join_write', 'unsupported', r'\bUPDATE\s+[`\w.]+(?:\s+\w+)?\s+RIGHT\s+(?:OUTER\s+)?JOIN\b|\bDELETE\s+[`\w]+\s*,\s*[`\w]+\s+FROM\b'),
    ('information_schema', 'unsupported', r'\binformation_schema\s*\.'),
    ('server_variable', 'unsupported', r'@@(?:\w+\.)?\w+'),
    ('show_mysql', 'unsupported', r'\bSHOW\s+(?:CREATE|TABLE\s+STATUS|STATUS|WARNINGS|DATABASES|ENGINES|PROCESSLIST)\b'),
    ('mysql_functions', 'unsupported', r'\b(?:DATEDIFF|TIMESTAMPDIFF|TIMESTAMPADD|CONVERT_TZ|DAYOFWEEK|DAYOFYEAR|WEEKDAY|YEARWEEK|MONTHNAME|DAYNAME|LPAD|RPAD|UUID|UUID_SHORT|INET_ATON|INET_NTOA|INET6_ATON|INET6_NTOA|REGEXP_LIKE|REGEXP_INSTR|JSON_CONTAINS|JSON_CONTAINS_PATH|JSON_SEARCH|JSON_TABLE|JSON_OBJECTAGG|JSON_ARRAYAGG|ST_\w+)\s*\('),
    ('mysql_expression', 'unsupported', r'\bSQL_CALC_FOUND_ROWS\b|\bLOCK\s+TABLES\b|\bUNLOCK\s+TABLES\b|\bCONVERT\s*\([^;]{0,150}?\bUSING\b|(?<!,)(?<!,\s)\bINTERVAL\s+(?:\d+|\?|:\w+)\s+\w+'),
    ('connection_bypass', 'review', r'\bnew\s+\\?PDO\s*\(|->(?:getWrappedConnection|getNativeConnection)\s*\(|\bmysqli_(?:connect|query|prepare)\s*\('),
    ('mysql_driver', 'review', r'\bpdo_mysql\b|\b(?:MySqlPlatform|MySQLPlatform|MariaDb\w*Platform)\b'),
    ('mysql_collation', 'review', r'\bCOLLATE\s+[\'"`]?\w*(?:utf8|latin1|unicode|general)\w*'),
    ('mysql_enum', 'unsupported', r'(?<!->)(?<!::)(?<!\.)\b(?:ENUM|SET)\s*\(\s*[\'\"]'),
    ('set_statement', 'review', r'\bSET\s+(?:SESSION\s+|GLOBAL\s+)?(?:SQL_MODE|GROUP_CONCAT_MAX_LEN|FOREIGN_KEY_CHECKS|NAMES|UNIQUE_CHECKS|TIME_ZONE)\b'),
    # Translated by SqliteCompatConnection; update with the translator.
    ('alter_foreign_key', 'covered_if_wrapper', r'\bALTER\s+TABLE\b[^;]{0,450}?\b(?:ADD\s+(?:CONSTRAINT\s+[^;]{1,100}?\s+)?|DROP\s+)FOREIGN\s+KEY\b'),
    ('fulltext', 'covered_if_wrapper', r'\bFULLTEXT\s+(?:KEY|INDEX)\b|\bMATCH\s*\([^;]{1,250}?\)\s*AGAINST\b'),
    ('group_concat', 'covered_if_wrapper', r'\bGROUP_CONCAT\s*\('),
    ('alter_column', 'covered_if_wrapper', r'\bALTER\s+TABLE\b[^;]{0,180}?\b(?:CHANGE|MODIFY)\b'),
    ('update_join', 'covered_if_wrapper', r'\bUPDATE\s+[`\w.]+(?:\s+\w+)?\s+(?:(?:INNER|CROSS|LEFT(?:\s+OUTER)?)\s+)?JOIN\b'),
    ('alter_index', 'covered_if_wrapper', r'\bALTER\s+TABLE\b[^;]{0,180}?\b(?:ADD|DROP)\s+(?:UNIQUE\s+)?(?:INDEX|KEY)\b'),
    ('upsert', 'covered_if_wrapper', r'\bON\s+DUPLICATE\s+KEY\s+UPDATE\b'),
    ('delete_join', 'covered_if_wrapper', r'\bDELETE\s+[`\w]+\s+FROM\b'),
    ('translated_syntax', 'covered_if_wrapper', r'\bINSERT\s+IGNORE\b|\bSHOW\s+(?:FULL\s+)?TABLES\b|\bSHOW\s+COLUMNS\b|\bSHOW\s+(?:INDEX(?:ES)?|KEYS)\b|\bSHOW\s+(?:(?:GLOBAL|SESSION)\s+)?VARIABLES\b|\bTRUNCATE\s+(?:TABLE\s+)?[`\w]|\bSELECT\b[^;]{0,800}?\b(?:FOR\s+(?:UPDATE|SHARE)|LOCK\s+IN\s+SHARE\s+MODE)\b'),
    ('translated_functions', 'covered_if_wrapper', r'\b(?:NOW|CURDATE|CURTIME|UTC_TIMESTAMP|UTC_DATE|UTC_TIME|FROM_UNIXTIME|UNIX_TIMESTAMP|DATE_FORMAT|IF|MD5|CONCAT|CONCAT_WS|FIELD|FIND_IN_SET|GREATEST|LEAST|REGEXP|SUBSTRING_INDEX|STR_TO_DATE|LAST_DAY|YEAR|MONTH|DAY|HOUR|MINUTE|SECOND|RAND|CRC32|SHA1|SHA2|REGEXP_REPLACE|REGEXP_SUBSTR|JSON_UNQUOTE|DATE_ADD|DATE_SUB|ADDDATE|SUBDATE)\s*\('),
]
COMPILED_RULES = [(name, status, re.compile(pattern, re.I)) for name, status, pattern in RULES]


def scan_text(path, text):
    comments = r'--[^\n]*|/\*.*?\*/' if path.lower().endswith('.sql') else r'//[^\n]*|/\*.*?\*/|^[ \t]*\#[^\n]*'
    tokens = re.compile(r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"|`[^`]*`|" + comments, re.S | re.M)
    text = tokens.sub(lambda m: re.sub(r'[^\n\r]', ' ', m[0]) if m[0].lstrip().startswith(('//', '/*', '--', '#')) else m[0], text)
    sql_regions = None
    if path.lower().endswith('.php'):
        sql_regions = [(m.start(), m.end()) for m in tokens.finditer(text) if m[0].startswith(("'", '"'))]
        heredocs = re.compile(r"<<<[ \t]*['\"]?(\w+)['\"]?\r?\n.*?^[ \t]*\1(?:[;,)\s]|$)", re.S | re.M)
        sql_regions.extend((m.start(), m.end()) for m in heredocs.finditer(text))
    findings = []
    for rule, classification, pattern in COMPILED_RULES:
        for match in pattern.finditer(text):
            # ponytail: SQL split across PHP strings needs AST analysis to catch.
            if sql_regions is not None and rule not in {'connection_bypass', 'mysql_driver'}:
                if not any(start <= match.start() < end for start, end in sql_regions):
                    continue
            line = text.count('\n', 0, match.start()) + 1
            first = text.rfind('\n', 0, match.start()) + 1
            last = text.find('\n', match.end())
            snippet = ' '.join(text[first:last if last >= 0 else len(text)].split())[:300]
            findings.append({'file': path, 'line': line, 'rule': rule,
                             'classification': classification, 'snippet': snippet})
    return findings


def read_url(url, limit=80 * 1024 * 1024):
    if not url.startswith('https://'):
        raise ValueError('Only HTTPS downloads are supported')
    with urlopen(Request(url, headers={'User-Agent': 'Omeka-S-SQLite-compatibility-audit'}), timeout=30) as response:
        data = response.read(limit + 1)
    if len(data) > limit:
        raise ValueError('Download exceeds the 80 MiB limit')
    return data


def github_api(path):
    result = subprocess.run(['gh', 'api', path], capture_output=True, text=True, timeout=35)
    if result.returncode:
        raise RuntimeError(result.stderr.strip()[:220])
    return json.loads(result.stdout)


def asset_score(name, project):
    stem = project.rsplit('/', 1)[-1].lower()
    stem = re.sub(r'^omeka[-_]?s[-_]?module[-_]?|^omeka[-_]?s[-_]?', '', stem)
    normalized = re.sub(r'[^a-z0-9]', '', name.lower())
    module_name = re.sub(r'[^a-z0-9]', '', stem)
    return (not normalized.startswith(module_name), 'source' in name.lower(), not name.lower().endswith('.zip'), name)


def resolve_archive(record):
    if record.get('archive_url') and 'official' in record['sources']:
        return record['archive_url'], 'release_asset', record.get('version', '')
    parsed = urlparse(record['repository'])
    project = parsed.path.strip('/').removesuffix('.git')
    version = record.get('version', '').removeprefix('v')
    if parsed.hostname == 'github.com' and re.fullmatch(r'[\w.-]+/[\w.-]+', project):
        releases = github_api(f'repos/{project}/releases?per_page=100')
        release = next((r for r in releases if r['tag_name'].removeprefix('v') == version), None)
        if not version:
            release = next((r for r in releases if not r['draft'] and not r['prerelease']), None)
        if release:
            assets = [a for a in release['assets'] if a['name'].lower().endswith(('.zip', '.tar.gz', '.tgz'))]
            if assets:
                assets.sort(key=lambda a: asset_score(a['name'], project))
                return assets[0]['browser_download_url'], 'release_asset', release['tag_name']
            return release['tarball_url'], 'release_source', release['tag_name']
        if version:
            tags = github_api(f'repos/{project}/tags?per_page=100')
            tag = next((t for t in tags if t['name'].removeprefix('v') == version), None)
            if tag:
                return f"https://codeload.github.com/{project}/tar.gz/{tag['commit']['sha']}", 'tag_source', tag['name']
        commit = github_api(f'repos/{project}/commits?per_page=1')[0]['sha']
        return f'https://codeload.github.com/{project}/tar.gz/{commit}', 'unreleased_commit', commit
    if parsed.hostname == 'gitlab.com' and project:
        api = 'https://gitlab.com/api/v4/projects/' + quote(project, safe='')
        releases = json.loads(read_url(api + '/releases?per_page=100', 4 * 1024 * 1024))
        release = next((r for r in releases if r['tag_name'].removeprefix('v') == version), None)
        if not version and releases:
            release = releases[0]
        if release:
            links = [a for a in release['assets'].get('links', []) if a['url'].lower().endswith(('.zip', '.tar.gz', '.tgz'))]
            if links:
                links.sort(key=lambda a: asset_score(a.get('name', a['url'].rsplit('/', 1)[-1]), project))
                return links[0].get('direct_asset_url', links[0]['url']), 'release_asset', release['tag_name']
        tags = json.loads(read_url(api + '/repository/tags?per_page=100', 4 * 1024 * 1024))
        tag = next((t for t in tags if t['name'].removeprefix('v') == version), None)
        if tag:
            sha = tag['commit']['id']
            return api + '/repository/archive.tar.gz?sha=' + sha, 'tag_source', tag['name']
        commit = json.loads(read_url(api + '/repository/commits?per_page=1', 4 * 1024 * 1024))[0]['id']
        return api + '/repository/archive.tar.gz?sha=' + commit, 'unreleased_commit', commit
    raise RuntimeError('Repository provider not supported for automatic downloads')


def archive_files(data):
    if zipfile.is_zipfile(io.BytesIO(data)):
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            for entry in archive.infolist():
                if not entry.is_dir() and entry.file_size <= 2 * 1024 * 1024:
                    yield entry.filename, lambda entry=entry: archive.read(entry)
    else:
        with tarfile.open(fileobj=io.BytesIO(data), mode='r:*') as archive:
            for entry in archive:
                if entry.isfile() and entry.size <= 2 * 1024 * 1024:
                    yield entry.name, lambda entry=entry: archive.extractfile(entry).read()


def scan_archive(data):
    findings, manifests, source_hashes, files_scanned, bytes_scanned, excluded = [], [], [], 0, 0, 0
    for path, read in archive_files(data):
        parts = {part.lower() for part in Path(path).parts}
        if parts.intersection({'vendor', 'node_modules', 'tests', 'test', '.git'}):
            excluded += 1
            continue
        if path.lower().endswith('config/module.ini'):
            manifests.append((path, read().decode('utf-8', 'replace')))
        if not path.lower().endswith(('.php', '.sql')):
            continue
        content = read()
        bytes_scanned += len(content)
        if bytes_scanned > 64 * 1024 * 1024:
            raise ValueError('Own source code exceeds the 64 MiB scan limit')
        files_scanned += 1
        source_hashes.append((path, hashlib.sha256(content).hexdigest()))
        findings.extend(scan_text(path, content.decode('utf-8', 'replace')))
    metadata = {}
    if manifests:
        text = min(manifests, key=lambda m: len(Path(m[0]).parts))[1]
        for key in ['name', 'version', 'omeka_version_constraint']:
            match = re.search(r'^\s*' + key + r'\s*=\s*([^\n\r]+)', text, re.M)
            if match:
                metadata[key] = match[1].strip().strip('"\'')
        metadata['dependencies'] = re.findall(r'^\s*dependencies\[\]\s*=\s*["\']?([^\r\n"\']+)', text, re.M)
    roots = {path.split('/')[0] for path, _ in source_hashes}
    own_source = [(path.partition('/')[2] if len(roots) == 1 and '/' in path else path, digest) for path, digest in source_hashes]
    metadata['source_sha256'] = hashlib.sha256(json.dumps(sorted(own_source)).encode()).hexdigest()
    return findings, metadata, files_scanned, excluded


def filtered_github_source(record):
    project = urlparse(record['repository']).path.strip('/')
    tag_match = re.search(r'/releases/download/([^/]+)/', record['archive_url'])
    reference = tag_match[1] if tag_match else record.get('resolved_tag', record.get('version', ''))
    commit = github_api(f'repos/{project}/commits/{quote(reference, safe="")}')['sha']
    tree_url = f'repos/{project}/git/trees/{commit}?recursive=1'
    tree = github_api(tree_url)
    if tree.get('truncated'):
        raise ValueError('GitHub source tree is truncated; coverage cannot be established')
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, 'w', zipfile.ZIP_DEFLATED) as archive:
        for entry in tree['tree']:
            path = entry['path']
            if entry['type'] != 'blob' or entry.get('size', 0) > 2 * 1024 * 1024:
                continue
            if not path.lower().endswith(('.php', '.sql', 'config/module.ini')):
                continue
            if set(Path(path).parts).intersection({'vendor', 'node_modules', 'tests', 'test'}):
                continue
            blob = github_api(f'repos/{project}/git/blobs/{entry["sha"]}')
            archive.writestr('source-' + commit + '/' + path, base64.b64decode(blob['content']))
    return buffer.getvalue(), 'https://api.github.com/' + tree_url, commit


def audit_record(record, cache, rescan=False):
    record = dict(record)
    record.pop('same_source_as', None)
    try:
        if rescan:
            if not (cache / (record['id'] + '.archive')).exists():
                if record.get('status') == 'scanned':
                    raise FileNotFoundError('Previously scanned archive is missing from cache')
                return record, []
            url, kind, tag = record['archive_url'], record['artifact_kind'], record['resolved_tag']
        else:
            url, kind, tag = resolve_archive(record)
        record.update(archive_url=url, artifact_kind=kind, resolved_tag=tag)
        cached = cache / (record['id'] + '.archive')
        # ponytail: cache is keyed by URL; archive SHA pins this scan, not future upstream tags.
        marker = cache / (record['id'] + '.url')
        if rescan:
            data = cached.read_bytes()
            if record.get('sha256') != hashlib.sha256(data).hexdigest():
                raise ValueError('Cached archive does not match the recorded SHA256')
        elif cached.exists() and marker.exists() and marker.read_text() == url:
            data = cached.read_bytes()
        else:
            try:
                data = read_url(url)
            except ValueError as error:
                if '80 MiB' not in str(error) or urlparse(record['repository']).hostname != 'github.com':
                    raise
                record['published_archive_url'] = url
                data, url, tag = filtered_github_source(record)
                kind = 'filtered_release_source'
                record.update(archive_url=url, artifact_kind=kind, resolved_tag=tag, source_commit=tag)
            cached.write_bytes(data)
            marker.write_text(url)
        record['sha256'] = hashlib.sha256(data).hexdigest()
        if kind == 'unreleased_commit':
            record['source_commit'] = tag
        findings, metadata, count, excluded = scan_archive(data)
        record.update(source_sha256=metadata.pop('source_sha256'), manifest=metadata, files_scanned=count, excluded_entries=excluded, finding_count=len(findings))
        record['version_matches_manifest'] = record.get('version', '').removeprefix('v') == metadata.get('version', '').removeprefix('v')
        record['status'] = 'scanned' if count else 'no_source_files'
        record.pop('error', None)
        for finding in findings:
            finding.update(module=record['name'], module_id=record['id'], version=metadata.get('version', tag), repository=record['repository'])
        return record, findings
    except Exception as error:
        record.update(status='unavailable', files_scanned=0, finding_count=0, error=f'{type(error).__name__}: {error}')
        return record, []


def write_summary(output, records, findings, stamp, support):
    by_id = {record['id']: record for record in records}
    problematic = {f['module_id'] for f in findings if f['classification'] in {'unsupported', 'rejected'}}
    review = {f['module_id'] for f in findings if f['classification'] == 'review'}
    any_findings = {f['module_id'] for f in findings}
    statuses = Counter(record.get('status', 'not_scanned') for record in records)
    official = Counter(record.get('status', 'not_scanned') for record in records if 'official' in record['sources'])
    types = Counter(record.get('artifact_kind', '') for record in records if record.get('status') == 'scanned')
    rules = Counter(f['rule'] for f in findings)
    unique = {record['source_sha256'] for record in records if record.get('status') == 'scanned'}
    source_candidates = {by_id[module]['source_sha256'] for module in problematic}
    source_representatives = {}
    for record in sorted(records, key=lambda r: ('official' not in r['sources'], r['archived'], r['name'].lower())):
        if record.get('status') == 'scanned':
            digest = record['source_sha256']
            if digest in source_representatives:
                record['same_source_as'] = source_representatives[digest]
            else:
                source_representatives[digest] = record['id']
    lines = [
        'AUDITORIA ESTATICA DE MODULOS OMEKA S / SQLITE',
        f'Fecha UTC: {stamp}', f'Commit del nucleo: {support}', '',
        'Catalogos: https://omeka.org/s/modules/ y https://daniel-km.github.io/UpgradeToOmekaS/en/omeka_s_modules.html',
        f'Repositorios distintos inventariados: {len(records)}',
        f'Repositorios escaneados: {statuses["scanned"]}',
        f'Fuentes PHP/SQL distintas (sin duplicar mirrors identicos): {len(unique)}',
        f'Catalogo oficial escaneado: {official["scanned"]}/{sum(official.values())}',
        f'No disponibles: {statuses["unavailable"]}; archivos sin codigo propio: {statuses["no_source_files"]}',
        f'Paquetes publicados: {types["release_asset"]}; fuentes de release/tag: {types["release_source"] + types["tag_source"] + types["filtered_release_source"]}; commits sin release coincidente: {types["unreleased_commit"]}',
        '',
        f'Repositorios con candidatos SQL no traducidos o rechazados: {len(problematic)} ({len(source_candidates)} fuentes distintas)',
        f'Repositorios con aspectos para revisar: {len(review)}',
        f'Repositorios escaneados sin indicios segun estas reglas: {sum(r.get("status") == "scanned" and r["id"] not in any_findings for r in records)}',
        'Las categorias se solapan. Ninguna cifra representa fallos confirmados en ejecucion ni certifica compatibilidad.',
        '', 'CANDIDATOS POR REGLA (coincidencias / repositorios / fuentes distintas)',
    ]
    for rule, count in rules.most_common():
        modules = {f['module_id'] for f in findings if f['rule'] == rule}
        sources = {by_id[module]['source_sha256'] for module in modules}
        lines.append(f'{rule}: {count} / {len(modules)} / {len(sources)}')
    lines += ['', 'ALCANCE Y LIMITES',
              'El escaner no instala ni ejecuta codigo de los modulos; las pruebas de ejecucion requieren un informe separado.',
              'Se escanea PHP/SQL propio; se excluyen vendor, node_modules, tests/test, comentarios y archivos de mas de 2 MiB.',
              'Las coincidencias pueden estar protegidas por condiciones o usarse en bases externas; hay que revisar el contexto.',
              'En PHP se buscan consultas dentro de strings/heredocs; las llamadas PHP a funciones homonimas no se consideran SQL.',
              'Las consultas construidas concatenando fragmentos pueden escapar a las expresiones regulares.',
              'covered_if_wrapper depende de que el modulo use la conexion de Omeka y no demuestra igualdad de resultados.',
              'unreleased_commit es una revision fijada por SHA, no un paquete publicado ni necesariamente la version del catalogo.',
              'filtered_release_source contiene solo el codigo de un tag publicado; no incluye datos ni dependencias del paquete.',
              'Las dependencias se leen de config/module.ini; pueden existir otras comprobaciones dinamicas.',
              'Las discrepancias entre version de catalogo y manifiesto se registran en version_matches_manifest.',
              '', 'CONFIRMACION DE LOS CANDIDATOS',
              'Probar estas versiones por separado en SQLite y MariaDB, con las mismas dependencias y datos, y verificar instalacion y una operacion representativa.']
    if (output / 'runtime/summary.txt').exists():
        lines.append('Las primeras pruebas de instalacion y sus limites estan documentados por separado en runtime/summary.txt.')
    (output / 'summary.txt').write_text('\n'.join(lines) + '\n')
    with (output / 'modules.csv').open('w', newline='') as file:
        fields = ['module', 'catalog_version', 'manifest_version', 'artifact_kind', 'status', 'omeka_constraint', 'dependencies', 'unsupported_candidates', 'review_candidates', 'files_scanned', 'repository', 'error', 'module_id']
        writer = csv.DictWriter(file, fields, lineterminator='\n')
        writer.writeheader()
        for record in records:
            module_findings = [f for f in findings if f['module_id'] == record['id']]
            manifest = record.get('manifest', {})
            writer.writerow(dict(zip(fields, [record['name'], record.get('version', ''), manifest.get('version', ''), record.get('artifact_kind', ''), record.get('status', 'not_scanned'), manifest.get('omeka_version_constraint', record.get('catalog_omeka_constraint', '')), ', '.join(manifest.get('dependencies', [])), sum(f['classification'] in {'unsupported', 'rejected'} for f in module_findings), sum(f['classification'] == 'review' for f in module_findings), record.get('files_scanned', 0), record['repository'], record.get('error', ''), record['id']])))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--official', type=Path, required=True)
    parser.add_argument('--daniel', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--cache', type=Path, required=True)
    parser.add_argument('--core', type=Path, default=Path('.'), help='Checkout of the Omeka S core whose SQLite translator the rules describe')
    parser.add_argument('--inventory-only', action='store_true')
    parser.add_argument('--retry-unavailable', action='store_true', help='Retry missing archives, using pinned unreleased commits when no published version exists')
    parser.add_argument('--rescan', action='store_true', help='Rescan cached archives from the completed inventory without network requests')
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    args.cache.mkdir(parents=True, exist_ok=True)
    records = json.loads((args.output / 'inventory.json').read_text())['modules'] if (args.rescan or args.retry_unavailable) else inventory([('official', args.official.read_text()), ('daniel', args.daniel.read_text())])
    print(f'Inventory: {len(records)} distinct repositories', flush=True)
    findings = []
    if not args.inventory_only:
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            pending = [pool.submit(audit_record, record, args.cache, args.rescan or (args.retry_unavailable and record.get('status') == 'scanned')) for record in records]
            records = []
            for future in concurrent.futures.as_completed(pending):
                record, matches = future.result()
                records.append(record)
                findings.extend(matches)
                if len(records) % 10 == 0:
                    print(f'Scanned/resolved {len(records)}/{len(pending)}; unavailable: {sum(r["status"] == "unavailable" for r in records)}', flush=True)
        records.sort(key=lambda r: r['name'].lower())
    stamp = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
    support = subprocess.run(['git', '-C', str(args.core), 'rev-parse', 'HEAD'], capture_output=True, text=True, check=True).stdout.strip()
    report = {'generated_at': stamp, 'core_commit': support,
              'scanner_sha256': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
              'catalog_urls': ['https://omeka.org/s/modules/', 'https://daniel-km.github.io/UpgradeToOmekaS/en/omeka_s_modules.html'],
              'catalog_sha256': {source: hashlib.sha256(path.read_bytes()).hexdigest() for source, path in [('official', args.official), ('daniel', args.daniel)]},
              'scope': 'Static candidates in PHP strings/heredocs and SQL files; vendor, tests and files >2 MiB excluded; no module code executed; no compatibility certification.',
              'modules': records}
    write_summary(args.output, records, findings, stamp, support)
    (args.output / 'inventory.json').write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
    (args.output / 'findings.json').write_text(json.dumps(findings, indent=2, ensure_ascii=False) + '\n')
    if findings:
        with (args.output / 'findings.csv').open('w', newline='') as file:
            fields = ['module', 'version', 'classification', 'rule', 'file', 'line', 'snippet', 'repository', 'module_id']
            writer = csv.DictWriter(file, fields, lineterminator='\n')
            writer.writeheader()
            writer.writerows(sorted(findings, key=lambda f: (f['module'].lower(), f['file'], f['line'], f['rule'])))
    print(f'Done: {len(records)} modules, {len(findings)} candidates. Reports: {args.output}', flush=True)


if __name__ == '__main__':
    main()
