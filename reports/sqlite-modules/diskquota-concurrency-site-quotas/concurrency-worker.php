<?php
$config = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$services = require __DIR__ . '/runtime.php';
$api = $services->get('Omeka\ApiManager');
$events = $services->get('SharedEventManager');
$pause = function () use ($config) {
    file_put_contents($config['entered'], 'accepted');
    if (!$config['hold']) {
        return;
    }
    $deadline = microtime(true) + 15;
    while (!file_exists($config['release'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Concurrency test release timed out');
        }
        usleep(10000);
    }
};
if ($config['action'] === 'upload') {
    $events->attach('Omeka\File\TempFile', 'media.ingest_file.pre', $pause, -2000);
} else {
    $events->attach('Omeka\Api\Adapter\ItemAdapter', 'api.hydrate.post', $pause, -999);
}
if ($config['preload']) {
    $api->read('items', $config['item'])->getContent()->sites();
}
file_put_contents($config['started'], 'started');
try {
    if ($config['action'] === 'upload') {
        $api->create('media', [
            'o:item' => ['o:id' => $config['item']], 'o:ingester' => 'url',
            'ingest_url' => 'http://127.0.0.1:8766/large.txt',
        ]);
    } else {
        $api->update('items', $config['item'], ['o:site' => [['o:id' => $config['site']]]], [], ['isPartial' => true]);
    }
    file_put_contents($config['result'], 'accepted');
} catch (Omeka\Api\Exception\ValidationException $e) {
    $errors = $e->getErrorStore()->getErrors();
    $result = isset($errors['file']) || isset($errors['o:site']) ? 'rejected' : 'other error';
    file_put_contents($config['result'], $result);
} catch (Throwable $e) {
    file_put_contents($config['result'], 'error: ' . get_class($e) . ': ' . $e->getMessage());
    exit(1);
}
