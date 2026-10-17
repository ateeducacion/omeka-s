<?php
// Run through PHP's HTTP server against a disposable, installed Omeka instance.
$root = getenv('DISKQUOTA_TEST_ROOT');
if (!$root) {
    throw new RuntimeException('Set DISKQUOTA_TEST_ROOT to the disposable Omeka installation');
}
chdir($root);
require 'bootstrap.php';

try {
    $app = Omeka\Mvc\Application::init(require 'application/config/application.config.php');
    $services = $app->getServiceManager();
    $em = $services->get('Omeka\EntityManager');
    $db = $services->get('Omeka\Connection');
    $api = $services->get('Omeka\ApiManager');
    $admin = $em->getRepository(Omeka\Entity\User::class)->findOneBy(['email' => 'admin@example.com']);
    if (!$admin || !$db->fetchOne('SELECT is_active FROM module WHERE id = ?', ['DiskQuota'])) {
        throw new RuntimeException('Install Omeka with admin@example.com and activate DiskQuota first');
    }
    $services->get('Omeka\AuthenticationService')->getStorage()->write($admin);
    $users = $services->get('Omeka\Settings\User');
    $users->setTargetId($admin->getId());
    $users->set('diskquota_user_quota', 0);
    $sites = $services->get('Omeka\Settings\Site');
    $full = $api->create('sites', [
        'o:title' => 'Full', 'o:slug' => 'quota-full', 'o:theme' => 'default',
    ])->getContent();
    $free = $api->create('sites', [
        'o:title' => 'Free', 'o:slug' => 'quota-free', 'o:theme' => 'default',
    ])->getContent();
    $target = $api->create('items', ['o:site' => [['o:id' => $full->id()]]])->getContent();
    $parent = $api->create('items', ['o:site' => [['o:id' => $free->id()]]])->getContent();
    $files = $services->get('Request')->getFiles()->toArray();
    $media = $api->create('media', ['o:ingester' => 'upload', 'file_index' => 0,
        'o:item' => ['o:id' => $target->id()]], $files)->getContent();
    if ((int) $media->size() !== 1048576) {
        throw new RuntimeException('file[0] must contain exactly 1 MiB');
    }
    $sites->setTargetId($full->id());
    $sites->set('diskquota_site_quota', 1);
    $sites->setTargetId($free->id());
    $sites->set('diskquota_site_quota', 0);
    $before = $db->fetchOne('SELECT COUNT(*) FROM media');
    $beforeItems = $db->fetchOne('SELECT COUNT(*) FROM item');
    $base = $services->get('Config')['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
    $originals = glob($base . '/original/*');
    $reject = function (array $data, string $name, ?int $itemId = null) use ($api, $files) {
        try {
            if ($itemId === null) {
                $api->create('items', $data, $files);
            } else {
                $api->update('items', $itemId, $data, $files, ['isPartial' => true]);
            }
        } catch (Omeka\Api\Exception\ValidationException $e) {
            if (isset($e->getErrorStore()->getErrors()['o:media'][0]['file'])) {
                echo "PASS: $name\n";
                return;
            }
            throw $e;
        }
        throw new RuntimeException("Quota bypass: $name");
    };
    $upload = ['o:ingester' => 'upload', 'file_index' => 1, 'o:item' => ['o:id' => $target->id()]];
    $reject(['o:site' => [['o:id' => $free->id()]], 'o:media' => [$upload]], 'new parent with contradictory o:item');
    $upload['file_index'] = 2;
    $reject(['o:media' => [$upload]], 'existing parent with contradictory o:item', $parent->id());
    $upload['o:item']['o:id'] = $parent->id();
    $reject(
        ['o:site' => [['o:id' => $full->id()]], 'o:media' => [$upload]],
        'matching o:item honors pending site assignment',
        $parent->id()
    );
    if ($db->fetchOne('SELECT COUNT(*) FROM media') != $before
        || $db->fetchOne('SELECT COUNT(*) FROM item') != $beforeItems
        || glob($base . '/original/*') !== $originals
    ) {
        throw new RuntimeException('Rejected uploads left orphan records or files');
    }
    echo "PASS: rejected uploads leave no orphan records or files\nAUDIT_RESULT=PASS\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo get_class($e) . ': ' . $e->getMessage() . "\nAUDIT_RESULT=FAIL\n";
}
