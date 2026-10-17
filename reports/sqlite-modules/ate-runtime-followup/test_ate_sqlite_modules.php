<?php
// Executed only inside a disposable Omeka container through a real multipart request.
chdir('/var/www/html');
require 'bootstrap.php';

$checks = 0;
$failures = 0;
function check($condition, string $name): void
{
    global $checks, $failures;
    ++$checks;
    if (!$condition) {
        ++$failures;
        echo "FAIL: $name\n";
        return;
    }
    echo "PASS: $name\n";
}
function ids($response): array
{
    $ids = array_map(static function ($resource) { return $resource->id(); }, $response->getContent());
    sort($ids);
    return $ids;
}
function expectedIds(array $ids): array
{
    sort($ids);
    return $ids;
}
function denied(callable $operation, string $name): void
{
    try {
        $operation();
    } catch (Omeka\Api\Exception\PermissionDeniedException $e) {
        check(true, $name);
        return;
    }
    check(false, $name);
}
function rejected(callable $operation, string $name): void
{
    try {
        $operation();
    } catch (Omeka\Api\Exception\ValidationException $e) {
        check(isset($e->getErrorStore()->getErrors()['file']), $name);
        return;
    }
    check(false, $name);
}

try {
    $app = Omeka\Mvc\Application::init(require 'application/config/application.config.php');
    $services = $app->getServiceManager();
    $em = $services->get('Omeka\EntityManager');
    $db = $services->get('Omeka\Connection');
    $api = $services->get('Omeka\ApiManager');
    $auth = $services->get('Omeka\AuthenticationService');
    $admin = $em->getRepository(Omeka\Entity\User::class)->findOneBy(['email' => 'admin@example.com']);
    $auth->getStorage()->write($admin);
    $route = new Laminas\Router\RouteMatch([]);
    $route->setMatchedRouteName('api');
    $app->getMvcEvent()->setRouteMatch($route);
    $module = getenv('AUDIT_MODULE');
    check((bool) $db->fetchOne('SELECT is_active FROM module WHERE id = ?', [$module]), 'published module installed and active');
    $files = $services->get('Request')->getFiles()->toArray();
    $site = $api->create('sites', ['o:title' => 'Granted site', 'o:slug' => 'granted', 'o:theme' => 'default'])->getContent();
    $otherSite = $api->create('sites', ['o:title' => 'Other site', 'o:slug' => 'other', 'o:theme' => 'default'])->getContent();
    $item = $api->create('items', ['o:site' => [['o:id' => $site->id()]]])->getContent();

    if ($module === 'DiskQuota') {
        $manager = $services->get('DiskQuota\DiskQuotaManager');
        $userSettings = $services->get('Omeka\Settings\User');
        $siteSettings = $services->get('Omeka\Settings\Site');
        $settings = $services->get('Omeka\Settings');
        // Controlled media sizes exercise SUM/JOIN, including ignored non-originals.
        foreach ([[2097152, true], [999999, false]] as [$size, $original]) {
            $media = $api->create('media', ['o:item' => ['o:id' => $item->id()], 'o:ingester' => 'html', 'html' => '<p>Quota fixture</p>'])->getContent();
            $entity = $em->find(Omeka\Entity\Media::class, $media->id());
            $entity->setSize($size);
            $entity->setHasOriginal($original);
        }
        $em->flush();
        check($manager->getUsedDiskSpaceByUser($admin->getId()) === 2097152, 'user usage sums originals only');
        check($manager->getUsedDiskSpaceBySite($site->id()) === 2097152, 'site usage joins item_site correctly');
        check($manager->getUsedDiskSpaceBySite($otherSite->id()) === 0, 'empty site usage is zero');
        $userSettings->setTargetId($admin->getId());
        $userSettings->set('diskquota_user_quota', 3);
        $siteSettings->setTargetId($site->id());
        $siteSettings->set('diskquota_site_quota', 3);
        check($manager->getUserQuota($admin->getId()) === 3145728, 'user quota persists in MiB');
        check($manager->getSiteQuota($site->id()) === 3145728, 'site quota persists in MiB');
        foreach ([0, 1048575, 1048576, 1048577] as $size) {
            check($manager->isQuotaExceeded($admin->getId(), $size) === ($size > 1048576), "user quota boundary $size");
            check($manager->isSiteQuotaExceeded($site->id(), $size) === ($size > 1048576), "site quota boundary $size");
        }
        $upload = ['o:item' => ['o:id' => $item->id()], 'o:ingester' => 'upload', 'file_index' => 0];
        $userSettings->set('diskquota_user_quota', 2);
        rejected(static function () use ($api, $upload, $files) { $api->create('media', $upload, $files); }, 'actual upload rejected by user quota');
        $userSettings->set('diskquota_user_quota', 0);
        $siteSettings->set('diskquota_site_quota', 0);
        check(!$manager->isQuotaExceeded($admin->getId(), PHP_INT_MAX), 'zero user quota is unlimited');
        check(!$manager->isSiteQuotaExceeded($site->id(), PHP_INT_MAX), 'zero site quota is unlimited');
        $acceptedUpload = $upload;
        $acceptedUpload['file_index'] = 1;
        $accepted = $api->create('media', $acceptedUpload, $files)->getContent();
        check((int) $accepted->size() === 1024 && $accepted->hasOriginal(), 'real multipart upload accepted with unlimited quota');
        check($manager->getUsedDiskSpaceByUser($admin->getId()) === 2098176, 'user usage includes accepted upload');
        check($manager->getUsedDiskSpaceBySite($site->id()) === 2098176, 'site usage includes accepted upload');
        $settings->set('diskquota_default_user_quota', 4);
        $settings->set('diskquota_default_site_quota', 5);
        $userSettings->delete('diskquota_user_quota');
        $siteSettings->delete('diskquota_site_quota');
        check($manager->getUserQuota($admin->getId()) === 4194304, 'default user quota fallback');
        check($manager->getSiteQuota($site->id()) === 5242880, 'default site quota fallback');
        $siteSettings->set('diskquota_site_quota', 2);
        $beforeMedia = (int) $db->fetchOne('SELECT COUNT(*) FROM media');
        $beforeItems = (int) $db->fetchOne('SELECT COUNT(*) FROM item');
        $originals = glob('/var/www/html/files/original/*');
        rejected(static function () use ($api, $upload, $files) { $api->create('media', $upload, $files); }, 'actual upload rejected by site quota');
        check((int) $db->fetchOne('SELECT COUNT(*) FROM media') === $beforeMedia, 'rejected direct upload creates no media record');
        foreach (['create', 'update'] as $operation) {
            $nested = ['o:media' => [['o:ingester' => 'upload', 'file_index' => 2]]];
            if ($operation === 'create') {
                $nested['o:site'] = [['o:id' => $site->id()]];
            } else {
                $nested['o:media'] = array_merge(array_map(static function ($media) {
                    return ['o:id' => $media->id()];
                }, $api->read('items', $item->id())->getContent()->media()), $nested['o:media']);
            }
            try {
                if ($operation === 'create') {
                    $api->create('items', $nested, $files);
                } else {
                    $api->update('items', $item->id(), $nested, $files, ['isPartial' => true]);
                }
                check(false, "site quota rejects nested item $operation upload");
            } catch (Omeka\Api\Exception\ValidationException $e) {
                check(isset($e->getErrorStore()->getErrors()['o:media'][0]['file']), "site quota rejects nested item $operation upload");
            }
        }
        check((int) $db->fetchOne('SELECT COUNT(*) FROM media') === $beforeMedia, 'rejected nested uploads create no media records');
        check((int) $db->fetchOne('SELECT COUNT(*) FROM item') === $beforeItems, 'rejected nested creation creates no item record');
        check(glob('/var/www/html/files/original/*') === $originals, 'rejected uploads leave no original files');
        check(is_uploaded_file($files['file'][2]['tmp_name']), 'rejected upload remains an untouched temporary file');
        $siteSettings->set('diskquota_site_quota', 0);
        $createdItem = $api->create('items', ['o:site' => [['o:id' => $site->id()]],
            'o:media' => [['o:ingester' => 'upload', 'file_index' => 2]]], $files)->getContent();
        check(count($createdItem->media()) === 1 && $createdItem->media()[0]->hasOriginal(), 'nested item creation succeeds with unlimited quota');
    } elseif ($module === 'IsolatedSites') {
        $editor = $api->create('users', ['o:email' => 'editor@example.com', 'o:name' => 'Editor', 'o:role' => 'site_editor', 'o:is_active' => true,
            'o-module-isolatedsites:limit_to_granted_sites' => true, 'o-module-isolatedsites:limit_to_own_assets' => true])->getContent();
        $noSites = $api->create('users', ['o:email' => 'nosites@example.com', 'o:name' => 'No sites', 'o:role' => 'site_editor', 'o:is_active' => true])->getContent();
        $db->insert('site_permission', ['site_id' => $site->id(), 'user_id' => $editor->id(), 'role' => 'editor']);
        $granted = $services->get(IsolatedSites\Service\GrantedSites::class);
        check($granted->forUser($editor->id()) === [$site->id()], 'granted sites read from real database');
        check($granted->forUser($noSites->id()) === [], 'no site permissions returns empty list');
        $foreign = $api->create('items', ['o:site' => [['o:id' => $otherSite->id()]]])->getContent();
        $owned = $api->create('items', ['o:site' => [], 'o:owner' => ['o:id' => $editor->id()]])->getContent();
        $noSitesOwned = $api->create('items', ['o:site' => [], 'o:owner' => ['o:id' => $noSites->id()]])->getContent();
        $mediaIds = [];
        foreach ([$item, $foreign, $owned, $noSitesOwned] as $parent) {
            $mediaIds[] = $api->create('media', ['o:item' => ['o:id' => $parent->id()], 'o:ingester' => 'html', 'html' => '<p>Media</p>'])->getContent()->id();
        }
        $setIds = [];
        foreach ([$site, $otherSite] as $position => $targetSite) {
            $set = $api->create('item_sets', [])->getContent();
            $setIds[] = $set->id();
            $db->insert('site_item_set', ['site_id' => $targetSite->id(), 'item_set_id' => $set->id(), 'position' => 1]);
        }
        $ownSet = $api->create('item_sets', ['o:owner' => ['o:id' => $editor->id()]])->getContent();
        $ownAsset = new Omeka\Entity\Asset();
        $ownAsset->setOwner($em->find(Omeka\Entity\User::class, $editor->id()));
        $ownAsset->setName('Owned asset');
        $ownAsset->setStorageId('audit-owned');
        $ownAsset->setExtension('png');
        $ownAsset->setMediaType('image/png');
        $em->persist($ownAsset);
        $foreignAsset = new Omeka\Entity\Asset();
        $foreignAsset->setOwner($admin);
        $foreignAsset->setName('Other asset');
        $foreignAsset->setStorageId('audit-other');
        $foreignAsset->setExtension('png');
        $foreignAsset->setMediaType('image/png');
        $em->persist($foreignAsset);
        $em->flush();
        $auth->getStorage()->write($em->find(Omeka\Entity\User::class, $editor->id()));
        foreach (['api', 'admin/item'] as $context) {
            $route->setMatchedRouteName($context);
            check(ids($api->search('items')) === expectedIds([$item->id(), $owned->id()]), "$context items restricted to granted site or owner");
            check(ids($api->search('media')) === expectedIds([$mediaIds[0], $mediaIds[2]]), "$context media restricted through parent item");
            check(ids($api->search('sites')) === [$site->id()], "$context sites restricted to granted site");
            check(ids($api->search('item_sets')) === expectedIds([$setIds[0], $ownSet->id()]), "$context item sets restricted");
            check(ids($api->search('assets')) === [$ownAsset->getId()], "$context assets restricted to owner");
        }
        $route->setMatchedRouteName('api');
        denied(static function () use ($api, $foreign) { $api->update('items', $foreign->id(), [], [], ['isPartial' => true]); }, 'editing another site item denied');
        $createdSet = $api->create('item_sets', ['o:site' => [['o:id' => $site->id()], ['o:id' => $otherSite->id()]]])->getContent();
        check(array_map('intval', $db->fetchFirstColumn('SELECT site_id FROM site_item_set WHERE item_set_id = ?', [$createdSet->id()])) === [$site->id()], 'item set assignment excludes unauthorized site');
        $api->update('item_sets', $createdSet->id(), ['o:site' => []], [], ['isPartial' => true]);
        check((int) $db->fetchOne('SELECT COUNT(*) FROM site_item_set WHERE item_set_id = ?', [$createdSet->id()]) === 0, 'item set site assignment can be removed');
        $auth->getStorage()->write($em->find(Omeka\Entity\User::class, $noSites->id()));
        check(ids($api->search('items')) === [$noSitesOwned->id()], 'user without sites sees own items only');
        check(ids($api->search('media')) === [$mediaIds[3]], 'user without sites sees own item media only');
        check(ids($api->search('sites')) === [], 'user without sites sees no sites');
        $auth->getStorage()->write($admin);
        check(ids($api->search('items')) === expectedIds([$item->id(), $foreign->id(), $owned->id(), $noSitesOwned->id()]), 'administrator sees every item');
        check(ids($api->search('sites')) === expectedIds([$site->id(), $otherSite->id()]), 'administrator sees every site');
        if (getenv('AUDIT_INLINE_LOG_FKS')) {
            check(count($db->fetchAllAssociative('PRAGMA foreign_key_list(log)')) === 2, 'test-only Log schema retains both foreign keys');
            check($db->fetchAllAssociative('PRAGMA foreign_key_check') === [], 'test-only schema has no foreign key violations');
        }
    } elseif ($module === 'ExeLearning') {
        $media = $api->create('media', ['o:item' => ['o:id' => $item->id()], 'o:ingester' => 'upload', 'file_index' => 0], $files)->getContent();
        $service = $services->get(ExeLearning\Service\ElpFileService::class);
        $em->refresh($em->find(Omeka\Entity\Media::class, $media->id()));
        $media = $api->read('media', $media->id())->getContent();
        $hash = $service->getMediaHash($media);
        check($media->hasOriginal() && $media->extension() === 'elpx', 'real multipart elpx upload stored');
        check((bool) preg_match('/^[a-f0-9]{40}$/', (string) $hash), 'creation hook extracted course and persisted hash');
        check($service->hasPreview($media), 'preview flag persisted in media JSON');
        $path = dirname(dirname($service->getMediaFilePath($media))) . '/exelearning/' . $hash;
        check(is_file($path . '/index.html'), 'preview HTML extracted');
        $fixture = new ZipArchive();
        $fixture->open('modules/ExeLearning/data/fixtures/really-simple-test-project.elpx');
        check(file_get_contents($path . '/index.html') === $fixture->getFromName('index.html'), 'published real project preview content intact');
        $fixture->close();
        $view = $services->get('ViewRenderer');
        $renderer = $services->get('Omeka\Media\FileRenderer\Manager')->get('exelearning_renderer');
        $html = $renderer->render($view, $media);
        check(strpos($html, '<iframe') !== false && strpos($html, $hash) !== false, 'actual renderer produces iframe for course');
        $html = $media->render();
        check(strpos($html, '<iframe') !== false && strpos($html, $hash) !== false, 'standard Omeka media rendering produces course iframe');
        foreach (['0', '1'] as $visible) {
            $api->update('media', $media->id(), ['exelearning_teacher_mode_visible' => $visible], [], ['isPartial' => true]);
            $em->refresh($em->find(Omeka\Entity\Media::class, $media->id()));
            $media = $api->read('media', $media->id())->getContent();
            check(($media->mediaData()['exelearning_teacher_mode_visible'] ?? null) === $visible, "teacher mode setting $visible persists in JSON");
        }
        $replacement = '/tmp/replacement.elpx';
        $zip = new ZipArchive();
        $zip->open($replacement, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('content.xml', '<content>Updated course</content>');
        $zip->addFromString('index.html', '<!doctype html><p>Updated course</p>');
        $zip->close();
        $result = $service->replaceFile($media, $replacement);
        $media = $api->read('media', $media->id())->getContent();
        check($service->getMediaHash($media) === $result['hash'] && $result['hash'] !== $hash, 'saved replacement hash persists');
        check(strpos(file_get_contents($result['extractPath'] . '/index.html'), 'Updated course') !== false, 'updated preview content saved');
        check(!is_dir($path), 'old extraction removed after successful replacement');
        file_put_contents('/tmp/invalid.elpx', 'not a zip');
        $before = hash_file('sha256', $service->getMediaFilePath($media));
        try {
            $service->replaceFile($media, '/tmp/invalid.elpx');
            check(false, 'invalid replacement rejected');
        } catch (Exception $e) {
            check($e->getMessage() === 'Invalid eXeLearning file', 'invalid replacement rejected');
        }
        check(hash_file('sha256', $service->getMediaFilePath($media)) === $before && is_dir($result['extractPath']), 'invalid save preserves original and preview');
        $mediaId = $media->id();
        $api->delete('media', $mediaId);
        check(!is_dir($result['extractPath']), 'media deletion removes extracted preview');
        check((int) $db->fetchOne('SELECT COUNT(*) FROM media WHERE id = ?', [$mediaId]) === 0, 'media deletion persisted');
    } else {
        throw new RuntimeException('Unknown module');
    }
    echo "AUDIT_CHECKS=$checks\nAUDIT_FAILURES=$failures\nAUDIT_RESULT=" . ($failures ? 'FAIL' : 'PASS') . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    if ($e instanceof Omeka\Api\Exception\ValidationException) {
        echo json_encode($e->getErrorStore()->getErrors(), JSON_PRETTY_PRINT) . "\n";
    }
    echo "AUDIT_CHECKS=$checks\nAUDIT_RESULT=FAIL\n";
}
