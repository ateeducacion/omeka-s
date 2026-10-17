<?php
$services = require __DIR__ . '/runtime.php';
$api = $services->get('Omeka\ApiManager');
$db = $services->get('Omeka\Connection');
$manager = $services->get('DiskQuota\DiskQuotaManager');
$users = $services->get('Omeka\Settings\User');
$users->setTargetId(1);
$users->set('diskquota_user_quota', 0);
$services->get('Omeka\Settings')->set('diskquota_default_site_quota', 0);
$sites = $services->get('Omeka\Settings\Site');
$failures = 0;
$check = function ($valid, $name) use (&$failures) {
    echo ($valid ? 'PASS: ' : 'FAIL: ') . $name . "\n";
    $failures += $valid ? 0 : 1;
};
$site = function ($slug, $quota) use ($api, $sites) {
    $site = $api->create('sites', ['o:title' => $slug, 'o:slug' => $slug, 'o:theme' => 'default'])->getContent();
    $sites->setTargetId($site->id());
    $sites->set('diskquota_site_quota', $quota);
    return $site;
};
$item = function ($site, $file) use ($api) {
    return $api->create('items', [
        'o:site' => [['o:id' => $site->id()]],
        'o:media' => [['o:ingester' => 'url', 'ingest_url' => "http://127.0.0.1:8766/$file.txt"]],
    ])->getContent();
};
$reject = function ($operation, $name) use ($check) {
    $rejected = false;
    try {
        $operation();
    } catch (Omeka\Api\Exception\ValidationException $e) {
        $rejected = isset($e->getErrorStore()->getErrors()['o:site']);
    }
    $check($rejected, $name);
};
try {
    $source = $site('assignment-source', 0);
    $target = $site('assignment-full', 1);
    $item($target, 'large');
    $moving = $item($source, 'small');
    $before = $db->fetchAll('SELECT * FROM item_site WHERE item_id = ?', [$moving->id()]);
    $reject(fn () => $api->update('items', $moving->id(), ['o:site' => [['o:id' => $target->id()]]], [], [
        'isPartial' => true,
    ]), 'moving existing originals cannot exceed the destination site quota');
    $check(
        $before == $db->fetchAll('SELECT * FROM item_site WHERE item_id = ?', [$moving->id()]),
        'rejected reassignment preserves the original site membership'
    );
    $reject(fn () => $api->update('items', $moving->id(), ['o:site' => [['o:id' => $target->id()]]], [], [
        'isPartial' => true, 'collectionAction' => 'append',
    ]), 'appending a site also checks existing original-file usage');
    $check(
        $before == $db->fetchAll('SELECT * FROM item_site WHERE item_id = ?', [$moving->id()]),
        'rejected append preserves existing memberships'
    );
    $exact = $site('assignment-exact', 1);
    $whole = $item($source, 'full');
    $api->update('items', $whole->id(), ['o:site' => [['o:id' => $exact->id()]]], [], ['isPartial' => true]);
    $check($manager->getUsedDiskSpaceBySite($exact->id()) === 1048576, 'reassignment accepts exactly the quota');
    $api->update('items', $whole->id(), ['o:site' => [['o:id' => $exact->id()]]], [], ['isPartial' => true]);
    $check($manager->getUsedDiskSpaceBySite($exact->id()) === 1048576, 'unchanged membership is not charged twice');
    $api->update('items', $whole->id(), ['o:site' => []], [], ['isPartial' => true]);
    $check($manager->getUsedDiskSpaceBySite($exact->id()) === 0, 'removing membership frees quota');
    $batchSite = $site('assignment-batch', 1);
    $first = $item($source, 'large');
    $second = $item($source, 'large');
    $reject(fn () => $api->batchUpdate('items', [$first->id(), $second->id()], [
        'o:site' => [['o:id' => $batchSite->id()]],
    ]), 'batch reassignment includes other pending membership changes');
    $check($manager->getUsedDiskSpaceBySite($batchSite->id()) === 0, 'rejected batch leaves no destination usage');
    $api->batchUpdate('items', [$first->id(), $second->id()], [
        'o:site' => [['o:id' => $batchSite->id()]],
    ], ['continueOnError' => true]);
    $check(
        $manager->getUsedDiskSpaceBySite($batchSite->id()) === 819200,
        'continueOnError assigns only the item that fits'
    );
    $retrySite = $site('assignment-retry', 1);
    $api->update('items', $second->id(), ['o:site' => [['o:id' => $retrySite->id()]]], [], ['isPartial' => true]);
    $check(
        $manager->getUsedDiskSpaceBySite($retrySite->id()) === 819200
        && $manager->getUsedDiskSpaceBySite($batchSite->id()) === 819200,
        'retry releases rejected reservations and preserves the accepted batch item'
    );
    echo 'AUDIT_RESULT=' . ($failures ? 'FAIL' : 'PASS') . "\n";
    exit($failures ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
