<?php

declare(strict_types=1);

use Cardapim\Controllers\AreaController;
use Cardapim\Controllers\AuthController;
use Cardapim\Controllers\CatalogoController;
use Cardapim\Controllers\HomeController;
use Cardapim\Controllers\LegalController;
use Cardapim\Controllers\MidiaController;
use Cardapim\Controllers\RestauranteController;
use Cardapim\Controllers\StatusController;
use Cardapim\Controllers\WebhookController;
use Cardapim\Core\Router;

$router = new Router();
$router->get('/', [HomeController::class, 'index']);
$router->get('/login', [AuthController::class, 'login']);
$router->post('/login', [AuthController::class, 'entrar']);
$router->get('/login/verificacao', [AuthController::class, 'verificacao']);
$router->post('/login/verificacao', [AuthController::class, 'verificar']);
$router->post('/login/verificacao/reenviar', [AuthController::class, 'reenviar']);
$router->post('/sair', [AuthController::class, 'sair']);
$router->get('/cadastro', [AuthController::class, 'register']);
$router->get('/status', [StatusController::class, 'index']);
$router->get('/catalogo', [CatalogoController::class, 'index']);
$router->get('/restaurantes', static fn (): \Cardapim\Core\Response => \Cardapim\Core\Response::redirect('/catalogo', 301));
$router->get('/privacidade', [LegalController::class, 'privacidade']);
$router->get('/politica-de-privacidade', static fn (): \Cardapim\Core\Response => \Cardapim\Core\Response::redirect('/privacidade', 301));
$router->get('/termos-de-uso', [LegalController::class, 'termos']);
$router->get('/cookies', [LegalController::class, 'cookies']);
$router->get('/exclusao-de-dados', [LegalController::class, 'exclusao']);
$router->get('/tratamento-de-dados', [LegalController::class, 'tratamento']);

// Areas autenticadas (cargo validado no servidor em AreaController/Autorizacao).
$router->get('/painel', [AreaController::class, 'painel']);
$router->get('/minha-conta', [AreaController::class, 'minhaConta']);
$router->get('/midia/logos/{arquivo}', [MidiaController::class, 'logo']);
$router->post('/webhooks/asaas/{id}', [WebhookController::class, 'asaas']);
(require __DIR__ . '/gerencia.php')($router);
$router->get('/{slug}/gerencia', [AreaController::class, 'gerencia']);
$router->get('/{slug}/gerencia/{secao}', [AreaController::class, 'gerencia']);
foreach (['caixa', 'entregas'] as $area) {
    $router->get('/{slug}/' . $area, static fn (string $slug): \Cardapim\Core\Response => (new AreaController())->restaurante($slug, $area));
}

// API JSON versionada.
(require __DIR__ . '/api.php')($router);

// Deve ser a ultima rota: endereco publico do restaurante (/slug-do-restaurante).
// Novas rotas fixas precisam ser registradas acima e o slug incluido em Shared\Slug::RESERVADOS.
$router->get('/{slug}', [RestauranteController::class, 'show']);

return $router;
