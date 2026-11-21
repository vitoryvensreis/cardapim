<?php

declare(strict_types=1);

namespace Cardapim\Services\Garcom;

use Cardapim\Repositories\Gerencia\PermissoesGarcomRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use PDO;

/**
 * Permissoes do perfil garcom, decididas pelo gerente (aba Entrega e pagamento, card "Perfil garçom"):
 * - remover_itens: tirar da comanda um item que ja foi lancado;
 * - cancelar_comanda: cancelar a comanda inteira.
 * Padrao: bloqueado. A regra so vale para o cargo "garcom": gerente e adm_master sempre podem.
 * Conferido no backend (GarcomController) e usado na tela so para esconder os botoes.
 */
final class PermissoesGarcom
{
    public const ROTULOS = [
        'remover_itens' => 'Garçom pode remover itens já lançados',
        'cancelar_comanda' => 'Garçom pode cancelar comanda',
    ];

    private readonly PermissoesGarcomRepository $repo;
    /** @var array{remover_itens:int,cancelar_comanda:int}|null */
    private ?array $cache = null;

    public function __construct(PDO $db, private readonly int $idRestaurante)
    {
        $this->repo = new PermissoesGarcomRepository($db);
    }

    /** @return array{remover_itens:int,cancelar_comanda:int} */
    public function config(): array
    {
        return $this->cache ??= $this->repo->buscar($this->idRestaurante);
    }

    public function podeRemoverItens(UsuarioLogado $usuario): bool
    {
        return self::livre($usuario) || $this->config()['remover_itens'] === 1;
    }

    public function podeCancelarComanda(UsuarioLogado $usuario): bool
    {
        return self::livre($usuario) || $this->config()['cancelar_comanda'] === 1;
    }

    /** @param array<string,mixed> $e */
    public function salvar(array $e, UsuarioLogado $autor): Resultado
    {
        $novo = [];
        foreach (PermissoesGarcomRepository::CAMPOS as $campo) {
            $novo[$campo] = ($e[$campo] ?? '') === '1' ? 1 : 0;
        }
        $antes = $this->config();
        try {
            $this->repo->salvar($this->idRestaurante, $novo);
        } catch (\PDOException) {
            return Resultado::falha('O sistema está sendo atualizado. Tente salvar de novo em alguns minutos.');
        }
        $this->cache = $novo;
        $legivel = static fn (array $x): array => array_map(static fn (int $v): bool => $v === 1, $x);
        if ($antes !== $novo) {
            Auditoria::registrar($autor, 'Permissões do garçom alteradas', Auditoria::diferencas($legivel($antes), $legivel($novo), self::ROTULOS));
        }
        $liberado = array_keys(array_filter($novo));
        $mensagem = match (true) {
            $liberado === [] => 'Salvo. O garçom não pode remover itens lançados nem cancelar comandas. Peça ao gerente ou ao caixa.',
            count($liberado) === 2 => 'Salvo. O garçom pode remover itens lançados e cancelar comandas.',
            $novo['remover_itens'] === 1 => 'Salvo. O garçom pode remover itens lançados, mas não cancelar comandas.',
            default => 'Salvo. O garçom pode cancelar comandas, mas não remover itens lançados.',
        };
        return Resultado::sucesso($mensagem);
    }

    /** Quem nao e garcom (gerente, adm_master) nao depende desta configuracao. */
    private static function livre(UsuarioLogado $usuario): bool
    {
        return $usuario->cargo !== 'garcom';
    }
}
