<?php

// app/Config/Routes.php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

 $routes->group('api/debug', ['namespace' => 'App\Controllers'], function($routes) {
    $routes->get('/', 'DebugController::index');
    $routes->get('current_time', 'DebugController::current_time');
    $routes->post('headers', 'DebugController::headers');
});

$routes->group('api', ['filter' => 'apiSignature', 'namespace' => 'App\Controllers'], function($routes) {
    // Email Template Routes
    $routes->group('email-templates', ['namespace' => 'App\Controllers'], function($routes) {
        $routes->get('/', 'EmailTemplateController::index');
        $routes->get('active', 'EmailTemplateController::getActive');
        $routes->get('(:any)', 'EmailTemplateController::show/$1');
        $routes->post('/', 'EmailTemplateController::create');
        $routes->put('(:any)', 'EmailTemplateController::update/$1');
        $routes->delete('(:any)', 'EmailTemplateController::delete/$1');
    });

    // Email Queue Routes
    $routes->group('email-queue', ['namespace' => 'App\Controllers'], function($routes) {
        $routes->get('/', 'EmailQueueController::index');
        $routes->get('statistics', 'EmailQueueController::statistics');
        $routes->get('(:num)', 'EmailQueueController::show/$1');
        $routes->post('/', 'EmailQueueController::create');
        $routes->put('(:num)', 'EmailQueueController::update/$1');
        $routes->delete('(:num)', 'EmailQueueController::delete/$1');
        $routes->post('(:num)/retry', 'EmailQueueController::retry/$1');
        $routes->post('run_scheduler', 'EmailQueueController::run_scheduler');
    });
});