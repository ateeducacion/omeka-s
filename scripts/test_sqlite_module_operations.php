<?php
/**
 * Real operations run by test_sqlite_module_installation.sh inside the
 * container, after the modules given as arguments are installed.
 *
 * Prints one "AUDIT_OP <name> ok|fail <detail>" line per operation. A failure
 * only on SQLite points to a gap of the SQLite support; a failure on both
 * engines points to this script or to the module.
 */
require 'bootstrap.php';

$application = Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$entityManager = $services->get('Omeka\EntityManager');
// Jobs and API calls may clear the entity manager: log in again before use.
$login = function () use ($services, $entityManager): void {
    $services->get('Omeka\AuthenticationService')->getStorage()
        ->write($entityManager->getRepository(Omeka\Entity\User::class)->findOneBy([]));
};
$login();
$api = $services->get('Omeka\ApiManager');
$plugins = $services->get('ControllerPluginManager');
$modules = array_slice($argv, 1);

$run = function (string $name, callable $operation): void {
    try {
        $detail = json_encode($operation());
        echo "AUDIT_OP $name ok $detail", PHP_EOL;
    } catch (Throwable $e) {
        $cause = $e->getPrevious() ? ' | ' . strtok($e->getPrevious()->getMessage(), "\n") : '';
        echo "AUDIT_OP $name fail ", get_class($e), ': ', strtok($e->getMessage(), "\n"), $cause, PHP_EOL;
    }
};
$job = function (string $class, array $args) use ($services, $login): string {
    $login();
    $job = $services->get('Omeka\Job\Dispatcher')
        ->dispatch($class, $args, $services->get('Omeka\Job\DispatchStrategy\Synchronous'));
    if ($job->getStatus() !== 'completed') {
        throw new RuntimeException($job->getStatus() . ': ' . substr((string) $job->getLog(), -300));
    }
    return $job->getStatus();
};
$value = function (string $term, string $text) use ($api): array {
    $property = $api->search('properties', ['term' => $term])->getContent()[0];
    return [['type' => 'literal', 'property_id' => $property->id(), '@value' => $text]];
};

$ids = [];
$run('item create', function () use ($api, $value, &$ids) {
    foreach (['Cultural heritage' => 'History', 'Culture club' => 'Music', 'Maps of islands' => 'History'] as $title => $subject) {
        $ids[] = $api->create('items', ['dcterms:title' => $value('dcterms:title', $title), 'dcterms:subject' => $value('dcterms:subject', $subject)])->getContent()->id();
    }
    return $ids;
});
$run('item fulltext search', function () use ($api) {
    return $api->search('items', ['fulltext_search' => 'heritage'])->getTotalResults();
});
$run('item property search', function () use ($api) {
    return $api->search('items', ['property' => [['property' => 'dcterms:subject', 'type' => 'eq', 'text' => 'History']], 'sort_by' => 'title'])->getTotalResults();
});
$run('item update', function () use ($api, $value, &$ids) {
    return $api->update('items', $ids[0], ['dcterms:title' => $value('dcterms:title', " Cultural heritage\t")], [], ['isPartial' => true])->getContent()->displayTitle();
});

foreach ($modules as $module) {
    switch ($module) {
        case 'AdvancedSearch':
            $run('advanced search relevance', function () use ($api) {
                return $api->search('items', ['fulltext_search' => 'cultural', 'sort_by' => 'relevance'])->getTotalResults();
            });
            $run('advanced search filter', function () use ($api) {
                return $api->search('items', ['filter' => [['join' => 'and', 'field' => 'dcterms:title', 'type' => 'in', 'val' => 'island']]])->getTotalResults();
            });
            break;
        case 'Reference':
            $run('reference list', function () use ($plugins) {
                return $plugins->get('references')(['dcterms:subject'], [], ['output' => 'associative'])->list();
            });
            break;
        case 'BulkEdit':
            $run('bulk edit trim values', function () use ($plugins, $api, &$ids) {
                $plugins->get('trimValues')($ids);
                return $api->read('items', $ids[0])->getContent()->displayTitle();
            });
            break;
        case 'EasyAdmin':
            $run('easy admin session check', function () use ($job) {
                return $job(EasyAdmin\Job\DbSession::class, ['process' => 'db_session_check', 'days' => 1]);
            });
            $run('easy admin value clean', function () use ($job) {
                return $job(EasyAdmin\Job\DbValueClean::class, ['process' => 'db_value_clean']);
            });
            $run('easy admin resource title check', function () use ($job) {
                return $job(EasyAdmin\Job\DbResourceTitle::class, ['process' => 'db_resource_title_check']);
            });
            break;
        case 'Log':
            $run('log write and read', function () use ($services, $api) {
                $services->get('Omeka\Logger')->err('SQLite audit log entry');
                return $api->search('logs', ['message' => 'SQLite audit'])->getTotalResults();
            });
            break;
        case 'Table':
            $run('table create and delete', function () use ($api) {
                $id = $api->create('tables', ['o:title' => 'Audit table', 'o:codes' => [['o:code' => 'a', 'o:label' => 'Alpha']]])->getContent()->id();
                $api->delete('tables', $id);
                return $api->search('tables')->getTotalResults();
            });
            break;
        case 'Mapping':
            $run('mapping feature create', function () use ($api, &$ids) {
                return $api->create('mapping_features', ['o:item' => ['o:id' => $ids[2]], 'o-module-mapping:geography-type' => 'Point', 'o-module-mapping:geography-coordinates' => [-15.4, 28.1]])->getContent()->id();
            });
            $run('mapping feature read', function () use ($api, &$ids) {
                return $api->search('mapping_features', ['item_id' => $ids[2]])->getTotalResults();
            });
            break;
    }
}

$run('item delete', function () use ($api, &$ids) {
    $api->delete('items', $ids[2]);
    return $api->search('items')->getTotalResults();
});
