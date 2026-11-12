<?php

declare(strict_types=1);

namespace Cardapim\Services\Ifood;

use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use PDO;

/**
 * A loja do iFood ligada a um restaurante: loja escolhida, opcoes do gerente e acesso valido
 * (renova sozinho com o refresh token). Usada pelas telas, pelo caixa e pela rotina bin/ifood.php.
 */
final class IfoodConta
{
    /** Opcoes do gerente (ficam em integracoes.dados) e o valor padrao. */
    public const OPCOES = [
        'aceitar_automatico' => false,
        'sincronizar_estoque' => true,
    ];

    private readonly IntegracaoRepository $integracoes;

    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly PDO $db, public readonly int $idRestaurante, private readonly IfoodApi $api = new IfoodApi())
    {
        $this->integracoes = new IntegracaoRepository($db);
    }

    /** @return list<int> restaurantes com o iFood conectado e loja escolhida */
    public static function restaurantesConectados(PDO $db): array
    {
        $q = $db->query("SELECT i.id_restaurante FROM integracoes i JOIN restaurantes r ON r.id = i.id_restaurante
            WHERE i.provedor = 'ifood' AND i.status IN ('conectado', 'erro') AND i.id_restaurante IS NOT NULL
              AND r.status_rest IN ('ativo', 'pendente') ORDER BY i.id_restaurante");
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed> */
    public function integracao(): array
    {
        return $this->cache ??= $this->integracoes->buscar($this->idRestaurante, 'ifood');
    }

    public function conectada(): bool
    {
        $i = $this->integracao();
        return in_array($i['status'], ['conectado', 'erro'], true) && $this->loja() !== null;
    }

    public function loja(): ?string
    {
        $id = $this->integracao()['dados']['loja_id'] ?? null;
        return is_string($id) && $id !== '' ? $id : null;
    }

    public function opcao(string $nome): bool
    {
        $valor = $this->integracao()['dados'][$nome] ?? self::OPCOES[$nome] ?? false;
        return (bool) $valor;
    }

    /** @param array<string,bool> $opcoes */
    public function salvarOpcoes(array $opcoes): void
    {
        $dados = $this->integracao()['dados'];
        foreach (self::OPCOES as $nome => $padrao) {
            if (array_key_exists($nome, $opcoes)) {
                $dados[$nome] = (bool) $opcoes[$nome];
            }
        }
        $this->integracoes->salvar($this->idRestaurante, 'ifood', ['dados' => $dados]);
        $this->cache = null;
    }

    /** Acesso valido para chamar o iFood (renova quando vence). */
    public function token(): string
    {
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'ifood');
        if (!empty($cred['acesso']) && (int) ($cred['expira'] ?? 0) > time()) {
            return (string) $cred['acesso'];
        }
        if (empty($cred['renovacao'])) {
            throw new IntegracaoErro('O acesso ao iFood venceu. Desconecte e conecte de novo.');
        }
        $tokens = $this->api->renovar((string) $cred['renovacao']);
        $this->integracoes->salvar($this->idRestaurante, 'ifood', ['credenciais' => [
            'acesso' => $tokens['acesso'],
            'renovacao' => $tokens['renovacao'] !== '' ? $tokens['renovacao'] : (string) $cred['renovacao'],
            'expira' => $tokens['expira'],
        ]]);
        return $tokens['acesso'];
    }

    /** Rotina falou com o iFood agora (mostra na tela "ultima consulta"). Nao mexe nas opcoes do gerente. */
    public function registrarConsulta(): void
    {
        $this->db->prepare("UPDATE integracoes SET verificado_em = ?, ultimo_erro = NULL, status = 'conectado' WHERE escopo = ? AND provedor = 'ifood' AND status IN ('conectado', 'erro')")
            ->execute([date('Y-m-d H:i:s'), $this->idRestaurante]);
        $this->cache = null;
    }

    /** Falha ao falar com o iFood: aparece para o gerente na tela da integracao. */
    public function registrarFalha(string $mensagem): void
    {
        $this->db->prepare("UPDATE integracoes SET ultimo_erro = ?, status = 'erro' WHERE escopo = ? AND provedor = 'ifood' AND status IN ('conectado', 'erro')")
            ->execute([mb_substr($mensagem, 0, 255), $this->idRestaurante]);
        $this->cache = null;
    }

    public function api(): IfoodApi
    {
        return $this->api;
    }
}
