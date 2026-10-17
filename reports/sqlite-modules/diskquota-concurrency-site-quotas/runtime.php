<?php
chdir(getenv('DISKQUOTA_TEST_ROOT'));
require 'bootstrap.php';
$app = Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $app->getServiceManager();
$admin = $services->get('Omeka\EntityManager')->getRepository(Omeka\Entity\User::class)
    ->findOneBy(['email' => 'admin@example.com']);
$services->get('Omeka\AuthenticationService')->getStorage()->write($admin);
$services->get('Omeka\Settings')->set('media_type_whitelist', ['text/plain', 'application/octet-stream']);
return $services;
