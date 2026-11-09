<?php

declare(strict_types=1);

use Cardapim\Controllers\AuthController;
use Cardapim\Controllers\HomeController;
use Cardapim\Core\Router;

$router = new Router();
$router->get('/', [HomeController::class, 'index']);
$router->get('/login', [AuthController::class, 'login']);
$router->get('/cadastro', [AuthController::class, 'register']);
return $router;
