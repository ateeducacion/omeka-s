<?php
// This HTTP request intentionally has no multipart files.
chdir(getenv('DISKQUOTA_TEST_ROOT'));
require 'bootstrap.php';

try {
    $app = Omeka\Mvc\Application::init(require 'application/config/application.config.php');
    $services = $app->getServiceManager();
    $db = $services->get('Omeka\Connection');
    $api = $services->get('Omeka\ApiManager');
    $admin = $services->get('Omeka\EntityManager')->getRepository(Omeka\Entity\User::class)
        ->findOneBy(['email' => 'admin@example.com']);
    $services->get('Omeka\AuthenticationService')->getStorage()->write($admin);
    $services->get('Omeka\Settings')->set('media_type_whitelist', [
        'text/plain', 'application/octet-stream', 'image/png',
    ]);
    $users = $services->get('Omeka\Settings\User');
    $users->setTargetId($admin->getId());
    $users->set('diskquota_user_quota', 0);
    $sites = $services->get('Omeka\Settings\Site');
    $base = $services->get('Config')['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
    $failures = 0;
    $check = function ($valid, $name) use (&$failures) {
        echo ($valid ? 'PASS: ' : 'FAIL: ') . $name . "\n";
        $failures += $valid ? 0 : 1;
    };
    $reject = function ($item, array $extra, $name) use ($api, $db, $base, $check) {
        $before = [$db->fetchOne('SELECT COUNT(*) FROM media'), glob($base . '/original/*')];
        $rejected = false;
        try {
            $api->create('media', $extra + [
                'o:ingester' => 'url', 'ingest_url' => 'http://127.0.0.1:8766/over.txt',
                'o:item' => ['o:id' => $item->id()],
            ]);
        } catch (Omeka\Api\Exception\ValidationException $e) {
            $rejected = isset($e->getErrorStore()->getErrors()['file']);
        }
        $check($rejected, $name);
        $check(
            $before == [$db->fetchOne('SELECT COUNT(*) FROM media'), glob($base . '/original/*')],
            "$name leaves no original file or media record"
        );
    };
    $site = $api->create('sites', [
        'o:title' => 'URL quota', 'o:slug' => 'url-quota', 'o:theme' => 'default',
    ])->getContent();
    $item = $api->create('items', ['o:site' => [['o:id' => $site->id()]]])->getContent();
    $sites->setTargetId($site->id());
    $sites->set('diskquota_site_quota', 1);
    $reject($item, [], 'URL with unknown size obeys site quota');
    $other = $api->create('sites', [
        'o:title' => 'URL metadata', 'o:slug' => 'url-metadata', 'o:theme' => 'default',
    ])->getContent();
    $target = $api->create('items', ['o:site' => [['o:id' => $other->id()]]])->getContent();
    $sites->setTargetId($other->id());
    $sites->set('diskquota_site_quota', 1);
    $reject($target, ['o:size' => 1], 'forged URL size cannot bypass site quota');
    $sites->setTargetId($other->id());
    $sites->set('diskquota_site_quota', 0);
    $manager = $services->get('DiskQuota\DiskQuotaManager');
    $users->setTargetId($admin->getId());
    $users->set('diskquota_user_quota', (int) ceil($manager->getUsedDiskSpaceByUser($admin->getId()) / 1048576) + 1);
    $reject($target, [], 'URL with unknown size obeys user quota');
    $before = [
        $db->fetchOne('SELECT COUNT(*) FROM media'), $db->fetchOne('SELECT COUNT(*) FROM item'),
        glob($base . '/*/*'),
    ];
    $rejected = false;
    try {
        $api->create('items', [
            'o:site' => [['o:id' => $other->id()]],
            'o:media' => [
                ['o:ingester' => 'url', 'ingest_url' => 'http://127.0.0.1:8766/tiny.png', 'store_original' => false],
                ['o:ingester' => 'url', 'ingest_url' => 'http://127.0.0.1:8766/over.txt'],
            ],
        ]);
    } catch (Omeka\Api\Exception\ValidationException $e) {
        $rejected = isset($e->getErrorStore()->getErrors()['o:media'][0]['file']);
    }
    $check($rejected, 'URL batch rejects oversized original after thumbnail-only media');
    $check($before == [
        $db->fetchOne('SELECT COUNT(*) FROM media'), $db->fetchOne('SELECT COUNT(*) FROM item'),
        glob($base . '/*/*'),
    ], 'rejected URL batch leaves no original, thumbnails or records');
    $before = glob($base . '/original/*');
    $media = $api->create('media', [
        'o:ingester' => 'url', 'ingest_url' => 'http://127.0.0.1:8766/over.txt',
        'o:item' => ['o:id' => $target->id()], 'store_original' => false,
    ])->getContent();
    $check(
        !$media->hasOriginal() && glob($base . '/original/*') === $before,
        'URL without an original does not consume original-file quota'
    );
    $media = $api->create('media', [
        'o:ingester' => 'url', 'ingest_url' => 'http://127.0.0.1:8766/tiny.png',
        'o:item' => ['o:id' => $target->id()], 'store_original' => false,
    ])->getContent();
    $check(!$media->hasOriginal() && $media->hasThumbnails(), 'thumbnail-only URL fixture generates real thumbnails');
    $users->setTargetId($admin->getId());
    $users->set('diskquota_user_quota', 0);
    $sites->setTargetId($other->id());
    $sites->set('diskquota_site_quota', 0);
    $media = $api->create('media', [
        'o:ingester' => 'url', 'ingest_url' => 'http://127.0.0.1:8766/over.txt',
        'o:item' => ['o:id' => $target->id()],
    ])->getContent();
    $check($media->hasOriginal() && (int) $media->size() === 2097152, 'unlimited quotas allow real URL ingestion');
    echo 'AUDIT_RESULT=' . ($failures ? 'FAIL' : 'PASS') . "\n";
    if ($failures) {
        http_response_code(500);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo get_class($e) . ': ' . $e->getMessage() . "\nAUDIT_RESULT=FAIL\n";
    if ($e instanceof Omeka\Api\Exception\ValidationException) {
        echo json_encode($e->getErrorStore()->getErrors(), JSON_PRETTY_PRINT) . "\n";
    }
}
