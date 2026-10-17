import io
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import zipfile

from audit_sqlite_modules import asset_score, audit_record, inventory, scan_archive, scan_text


class AuditTests(unittest.TestCase):
    def test_catalog_merge_keeps_official_release(self):
        official = '''<div class="module addon-entry"><div><h4><a>Example</a></h4></div>
        <div><a class="button" href="https://github.com/example/module/releases/download/2.0/Example.zip">Download</a>
        <span class="version">Latest Version: 2.0</span></div></div>'''
        daniel = '''<table><tr><td><a class="addon-link" href="https://github.com/example/module">Example</a></td>
        <td class="addon-updated">2026-01-01 (v.1.0)</td><td class="addon-target">^4.0.0</td></tr></table>'''
        modules = inventory([('official', official), ('daniel', daniel)])
        self.assertEqual(1, len(modules))
        self.assertEqual('2.0', modules[0]['version'])
        self.assertEqual('1.0', modules[0]['alternate_catalog_version'])
        self.assertEqual(['official', 'daniel'], modules[0]['sources'])
        self.assertEqual('^4.0.0', modules[0]['catalog_omeka_constraint'])

    def test_multiline_sql_reports_start_line(self):
        matches = scan_text('schema.sql', '\nALTER TABLE child\nADD CONSTRAINT fk FOREIGN KEY (parent_id) REFERENCES parent(id);')
        foreign_key = next(m for m in matches if m['rule'] == 'alter_foreign_key')
        self.assertEqual(2, foreign_key['line'])
        self.assertEqual('covered_if_wrapper', foreign_key['classification'])

    def test_translated_writes_are_covered_and_the_rest_unsupported(self):
        covered = scan_text('schema.sql', 'ALTER TABLE t DROP FOREIGN KEY fk; ALTER TABLE t ADD UNIQUE INDEX i (a); SHOW INDEX FROM t;'
                            ' INSERT INTO t VALUES (1) ON DUPLICATE KEY UPDATE a = VALUES(a); DELETE v FROM value v JOIN r ON r.id = v.r;'
                            ' SELECT SUBSTRING_INDEX(a, ".", 1), YEAR(b), RAND() FROM t;')
        self.assertEqual({'covered_if_wrapper'}, {m['classification'] for m in covered})
        unsupported = scan_text('schema.sql', 'ALTER TABLE t CHANGE a b INT; UPDATE value v JOIN r ON r.id = v.r SET v.a = 1;'
                                ' SELECT DATE_SUB(NOW(), INTERVAL 1 DAY), ST_AsGeoJSON(g) FROM t;')
        self.assertEqual({'alter_mysql', 'mysql_join_write', 'mysql_functions', 'mysql_expression'},
                         {m['rule'] for m in unsupported if m['classification'] == 'unsupported'})

    def test_covered_syntax_is_not_reported_as_unsupported(self):
        matches = scan_text('schema.sql', 'INSERT IGNORE INTO example VALUES (CONCAT(?, ?));')
        self.assertEqual({'covered_if_wrapper'}, {m['classification'] for m in matches})
        self.assertEqual(2, len(matches))

    def test_missing_upsert_and_connection_bypass_are_distinct(self):
        matches = scan_text('Module.php', "new \\PDO('mysql:dbname=external'); $sql = 'ON DUPLICATE KEY UPDATE value=VALUES(value)';")
        self.assertEqual({'upsert', 'connection_bypass'}, {m['rule'] for m in matches})

    def test_php_if_is_not_a_mysql_function(self):
        self.assertEqual([], scan_text('Module.php', 'if ($enabled) { return; }'))

    def test_archive_scans_own_sources_and_records_manifest(self):
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w') as archive:
            archive.writestr('Example/Module.php', "$sql = 'ON DUPLICATE KEY UPDATE value=1';")
            archive.writestr('Example/vendor/driver.php', 'SHOW DATABASES')
            archive.writestr('Example/tests/Test.php', 'SHOW DATABASES')
            archive.writestr('Example/config/module.ini', '[info]\nname="Example"\nversion="2.0"\ndependencies[]="Common"\nomeka_version_constraint="^4.0.0"')
        findings, manifest, scanned, excluded = scan_archive(buffer.getvalue())
        self.assertEqual(1, scanned)
        self.assertEqual(2, excluded)
        self.assertEqual(1, len(findings))
        self.assertEqual('Example', manifest['name'])
        self.assertEqual(['Common'], manifest['dependencies'])
        self.assertEqual('2.0', manifest['version'])

    def test_php_comments_and_set_method_are_not_sql(self):
        source = "$settings->set('example', $value); // SELECT FOR UPDATE\n/* ON DUPLICATE KEY UPDATE */"
        self.assertEqual([], scan_text('Module.php', source))
        self.assertEqual(1, len(scan_text('Module.php', '$sql = "SELECT * FROM resource FOR UPDATE";')))

    def test_module_asset_is_preferred_to_bundled_library(self):
        project = 'Daniel-KM/Omeka-S-module-Sparql'
        names = ['yasgui-4.2.28.zip', 'Sparql-3.4.8.zip']
        self.assertEqual('Sparql-3.4.8.zip', min(names, key=lambda name: asset_score(name, project)))

    def test_mirrored_archives_have_the_same_source_fingerprint(self):
        def package(root):
            buffer = io.BytesIO()
            with zipfile.ZipFile(buffer, 'w') as archive:
                archive.writestr(root + '/Module.php', 'SHOW TABLES')
                archive.writestr(root + '/config/module.ini', '[info]\nname="Example"')
            return buffer.getvalue()
        first = scan_archive(package('Example'))[1]['source_sha256']
        second = scan_archive(package('repository-commit'))[1]['source_sha256']
        self.assertEqual(first, second)

    def test_native_php_function_is_not_sql_but_heredoc_is(self):
        source = "<?php $hash = md5($value);\n$sql = <<<'SQL'\nSELECT MD5(value) FROM resource;\nSQL;"
        findings = scan_text('Module.php', source)
        self.assertEqual(1, len(findings))
        self.assertEqual(3, findings[0]['line'])
        self.assertEqual('translated_functions', findings[0]['rule'])

    def test_offline_missing_cache_cannot_preserve_success(self):
        with tempfile.TemporaryDirectory() as directory, patch('audit_sqlite_modules.read_url') as download:
            record, findings = audit_record({'id': 'example', 'status': 'scanned'}, Path(directory), rescan=True)
        self.assertEqual('unavailable', record['status'])
        self.assertEqual([], findings)
        download.assert_not_called()

    def test_offline_modified_cache_cannot_download_or_report_success(self):
        with tempfile.TemporaryDirectory() as directory, patch('audit_sqlite_modules.read_url') as download:
            (Path(directory) / 'example.archive').write_bytes(b'changed')
            record, findings = audit_record({'id': 'example', 'status': 'scanned', 'archive_url': 'https://example.org/source.zip',
                                             'artifact_kind': 'release_asset', 'resolved_tag': '1.0', 'sha256': 'original'},
                                            Path(directory), rescan=True)
        self.assertEqual('unavailable', record['status'])
        self.assertIn('SHA256', record['error'])
        self.assertEqual([], findings)
        download.assert_not_called()


if __name__ == '__main__':
    unittest.main()
