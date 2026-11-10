<?php

declare(strict_types=1);

use Cardapim\Controllers\AuthController;
use Cardapim\Controllers\HomeController;
use Cardapim\Controllers\LegalController;
use Cardapim\Controllers\StatusController;
use Cardapim\Core\Router;

$router = new Router();
$router->get('/', [HomeController::class, 'index']);
$router->get('/login', [AuthController::class, 'login']);
$router->get('/cadastro', [AuthController::class, 'register']);
$router->get('/status', [StatusController::class, 'index']);
$router->get('/privacidade', [LegalController::class, 'privacidade']);
$router->get('/politica-de-privacidade', static fn (): \Cardapim\Core\Response => \Cardapim\Core\Response::redirect('/privacidade', 301));
$router->get('/termos-de-uso', [LegalController::class, 'termos']);
$router->get('/cookies', [LegalController::class, 'cookies']);
$router->get('/exclusao-de-dados', [LegalController::class, 'exclusao']);
$router->get('/tratamento-de-dados', [LegalController::class, 'tratamento']);
return $router;
