<?php

declare(strict_types=1);

namespace Cardapim\Services\Ifood;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Integracoes\IfoodLojaService;
use PDO;
use Throwable;

/**
 * Botao "Abrir loja no iFood" do topo (gerencia e modo caixa).
 * A tela consulta a situacao por JSON e abre a loja sem recarregar. A situacao fica 20 s na sessao
 * para nao chamar o iFood a cada troca de tela.
 */
final class BotaoLojaIfood
{
    private const CACHE_SEGUNDOS = 20;

    /**
     * Enderecos do botao, ou null quando o iFood nao esta conectado neste restaurante (o botao nem aparece).
     * @return array{situacao:string, abrir:string}|null
     */
    public static function config(int $idRestaurante, string $base): ?array
    {
        try {
            if (!IfoodApi::disponivel() || !(new IfoodConta(Database::connect(), $idRestaurante))->conectada()) {
                return null;
            }
        } catch (Throwable) {
            return null; // o topo da tela nunca quebra por causa do iFood
        }
        return ['situacao' => $base . '/situacao', 'abrir' => $base . '/abrir'];
    }

    /** GET: situacao atual. */
    public static function situacao(PDO $db, UsuarioLogado $usuario, int $idRestaurante): Response
    {
        $chave = self::chave($idRestaurante);
        $guardado = Session::get($chave);
        if (is_array($guardado) && ($guardado['ate'] ?? 0) > time() && is_array($guardado['dados'] ?? null)) {
            return Guard::semCache(Response::json($guardado['dados']));
        }
        $dados = (new IfoodLojaService($db, $usuario, $idRestaurante))->situacaoAgora();
        Session::set($chave, ['ate' => time() + self::CACHE_SEGUNDOS, 'dados' => $dados]);
        return Guard::semCache(Response::json($dados));
    }

    /** POST (X-CSRF-Token): abre a loja e devolve a situacao nova. */
    public static function abrir(PDO $db, UsuarioLogado $usuario, int $idRestaurante): Response
    {
        if (!Session::csrfValido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            return Response::json(['ok' => false, 'mensagem' => 'Sua sessão expirou. Recarregue a página.'], 419);
        }
        $servico = new IfoodLojaService($db, $usuario, $idRestaurante);
        $resultado = $servico->abrirLoja();
        Session::remover(self::chave($idRestaurante));
        $situacao = $servico->situacaoAgora();
        if ($resultado->ok && $situacao['estado'] !== 'aberta') {
            // A pausa saiu, mas o iFood pode levar alguns segundos para mostrar a loja aberta.
            $situacao = ['estado' => 'aberta', 'texto' => 'Loja aberta', 'detalhe' => 'Pausa removida. O iFood pode levar alguns segundos para mostrar a loja aberta.', 'pode_abrir' => false];
        }
        Session::set(self::chave($idRestaurante), ['ate' => time() + self::CACHE_SEGUNDOS, 'dados' => $situacao]);
        return Guard::semCache(Response::json(['ok' => $resultado->ok, 'mensagem' => $resultado->mensagem, 'situacao' => $situacao], $resultado->ok ? 200 : 422));
    }

    /** Esquece a situacao guardada (depois de criar ou remover pausa na tela Loja no iFood). */
    public static function esquecer(int $idRestaurante): void
    {
        Session::remover(self::chave($idRestaurante));
    }

    /** Sessao vencida em uma chamada JSON. */
    public static function semSessao(): Response
    {
        return Response::json(['ok' => false, 'estado' => 'sessao', 'mensagem' => 'Sua sessão expirou. Recarregue a página.'], 401);
    }

    private static function chave(int $idRestaurante): string
    {
        return 'ifood_botao_loja_' . $idRestaurante;
    }
}
