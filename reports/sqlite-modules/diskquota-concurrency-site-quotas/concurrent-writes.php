<?php
$services = require __DIR__ . '/runtime.php';
$api = $services->get('Omeka\ApiManager');
$manager = $services->get('DiskQuota\DiskQuotaManager');
$settings = $services->get('Omeka\Settings');
$settings->set('diskquota_default_site_quota', 0);
$users = $services->get('Omeka\Settings\User');
$users->setTargetId(1);
$users->set('diskquota_user_quota', 0);
$sites = $services->get('Omeka\Settings\Site');
$db = $services->get('Omeka\Connection');
$base = $services->get('Config')['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
$failures = 0;
$check = function ($valid, $name) use (&$failures) {
    echo ($valid ? 'PASS: ' : 'FAIL: ') . $name . "\n";
    $failures += $valid ? 0 : 1;
};
$wait = function ($path) {
    $deadline = microtime(true) + 15;
    while (!file_exists($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("Concurrency marker missing: $path");
        }
        usleep(10000);
    }
};
foreach (['user', 'site', 'assign-upload', 'assign-assign', 'move-upload'] as $case) {
    $scratch = sys_get_temp_dir() . '/diskquota-race-' . bin2hex(random_bytes(8));
    mkdir($scratch, 0700);
    $workers = [];
    try {
        $owner = $api->create('users', [
            'o:name' => $case, 'o:email' => "$case@example.com", 'o:role' => 'researcher', 'o:is_active' => true,
        ])->getContent();
        $users->setTargetId($owner->id());
        $users->set('diskquota_user_quota', 0);
        $site = $api->create('sites', [
            'o:title' => $case, 'o:slug' => "race-$case", 'o:theme' => 'default',
        ])->getContent();
        $sites->setTargetId($site->id());
        $sites->set('diskquota_site_quota', 0);
        $items = [];
        $actions = $case === 'assign-assign' ? ['assign', 'assign']
            : (in_array($case, ['assign-upload', 'move-upload']) ? ['assign', 'upload'] : ['upload', 'upload']);
        foreach ($actions as $index => $action) {
            if ($case === 'move-upload' && $index === 1) {
                $items[] = $items[0];
                continue;
            }
            $items[] = $api->create('items', [
                'o:owner' => ['o:id' => $owner->id()],
                'o:site' => $action === 'upload' && $case !== 'user' ? [['o:id' => $site->id()]] : [],
                'o:media' => $action === 'assign' ? [[
                    'o:ingester' => 'url',
                    'ingest_url' => 'http://127.0.0.1:8766/' . ($case === 'move-upload' ? 'small' : 'large') . '.txt',
                ]] : [],
            ])->getContent();
        }
        if ($case === 'user') {
            $users->setTargetId($owner->id());
            $users->set('diskquota_user_quota', 1);
        } else {
            $sites->setTargetId($site->id());
            $sites->set('diskquota_site_quota', 1);
        }
        $before = [(int) $db->fetchOne('SELECT COUNT(*) FROM media'), count(glob($base . '/original/*'))];
        foreach ($actions as $index => $action) {
            $config = [
                'preload' => $case === 'move-upload' && $index === 1,
                'item' => $items[$index]->id(), 'site' => $site->id(), 'action' => $action, 'hold' => $index === 0,
                'started' => "$scratch/$index-started", 'entered' => "$scratch/$index-entered",
                'result' => "$scratch/$index-result", 'release' => "$scratch/release",
            ];
            $workers[] = proc_open([PHP_BINARY, __DIR__ . '/concurrency-worker.php', json_encode($config)], [
                0 => ['file', '/dev/null', 'r'], 1 => ['file', "$scratch/$index.log", 'w'],
                2 => ['file', "$scratch/$index.log", 'a'],
            ], $pipes);
            $wait($config['started']);
            if ($index === 0) {
                $wait($config['entered']);
            }
        }
        usleep(1000000);
        $check(
            !file_exists("$scratch/1-entered") && !file_exists("$scratch/1-result"),
            "$case: second writer waits until first persistence completes"
        );
        file_put_contents("$scratch/release", 'release');
        foreach ($workers as $worker) {
            proc_close($worker);
        }
        $workers = [];
        $first = file_get_contents("$scratch/0-result");
        $second = file_get_contents("$scratch/1-result");
        $check(
            $first === 'accepted' && $second === 'rejected',
            "$case: only one quota-increasing operation succeeds"
        );
        $usage = $case === 'user' ? $manager->getUsedDiskSpaceByUser($owner->id())
            : $manager->getUsedDiskSpaceBySite($site->id());
        $added = $actions[0] === 'upload' ? 1 : 0;
        $check([(int) $db->fetchOne('SELECT COUNT(*) FROM media'), count(glob($base . '/original/*'))] === [
            $before[0] + $added, $before[1] + $added,
        ], "$case: rejected operation leaves no orphan original or media record");
        $check(
            $usage === ($case === 'move-upload' ? 409600 : 819200),
            "$case: final usage stays below the 1 MiB quota"
        );
    } catch (Throwable $e) {
        $check(false, "$case: " . $e->getMessage());
    } finally {
        file_put_contents("$scratch/release", 'release');
        foreach ($workers as $worker) {
            proc_terminate($worker);
            proc_close($worker);
        }
        foreach (glob("$scratch/*") as $file) {
            unlink($file);
        }
        rmdir($scratch);
    }
}
echo 'AUDIT_RESULT=' . ($failures ? 'FAIL' : 'PASS') . "\n";
exit($failures ? 1 : 0);
