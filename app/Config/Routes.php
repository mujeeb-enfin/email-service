<?php

// app/Config/Routes.php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// Email Template Routes
$routes->group('api/email-templates', ['namespace' => 'App\Controllers'], function($routes) {
    $routes->get('current_time', 'EmailTemplateController::current_time');
    $routes->get('/', 'EmailTemplateController::index');
    $routes->get('active', 'EmailTemplateController::getActive');
    $routes->get('by-code/(:segment)', 'EmailTemplateController::getByCode/$1');
    $routes->get('(:num)', 'EmailTemplateController::show/$1');
    $routes->post('/', 'EmailTemplateController::create');
    $routes->put('(:num)', 'EmailTemplateController::update/$1');
    $routes->delete('(:num)', 'EmailTemplateController::delete/$1');
});

// Email Queue Routes
$routes->group('api/email-queue', ['namespace' => 'App\Controllers'], function($routes) {
    $routes->get('/', 'EmailQueueController::index');
    $routes->get('statistics', 'EmailQueueController::statistics');
    $routes->get('(:num)', 'EmailQueueController::show/$1');
    $routes->post('/', 'EmailQueueController::create');
    $routes->put('(:num)', 'EmailQueueController::update/$1');
    $routes->delete('(:num)', 'EmailQueueController::delete/$1');
    $routes->post('(:num)/retry', 'EmailQueueController::retry/$1');
    $routes->post('run_scheduler', 'EmailQueueController::run_scheduler');
});