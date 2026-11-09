<?php

declare(strict_types=1);

use Cardapim\Controllers\AuthController;
use Cardapim\Controllers\HomeController;
use Cardapim\Controllers\StatusController;
use Cardapim\Core\Router;

$router = new Router();
$router->get('/', [HomeController::class, 'index']);
$router->get('/login', [AuthController::class, 'login']);
$router->get('/cadastro', [AuthController::class, 'register']);
$router->get('/status', [StatusController::class, 'index']);
return $router;
