<?php

declare(strict_types=1);

namespace Cardapim\Services\Plataforma;

use Cardapim\Core\RateLimiter;
use Cardapim\Modules\Integracoes\GoogleRoutesApi;
use Cardapim\Repositories\Plataforma\GoogleMapsRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Seguranca\Cripto;
use PDO;
use Throwable;

/** Configuracao e ativacao GLOBAL da Routes API, exclusiva do adm_master. */
final class GoogleMapsService
{
    private readonly GoogleMapsRepository $repo;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $usuario)
    {
        $this->repo = new GoogleMapsRepository($db);
    }

    /** Nunca retorna a chave inteira. @return array{configurado:bool,ativo:bool,testado_em:string,salvo_em:string,salvo_por:string,chave_final:string} */
    public function estado(): array
    {
        $dados = $this->repo->buscar();
        return [
            'configurado' => $dados !== null,
            'ativo' => $dados !== null && $dados['ativo'],
            'testado_em' => $dados['testado_em'] ?? '',
            'salvo_em' => $dados['salvo_em'] ?? '',
            'salvo_por' => $dados['salvo_por'] ?? '',
            'chave_final' => $dados !== null ? '••••' . substr($dados['chave'], -4) : '',
        ];
    }

    public function salvar(string $chave): Resultado
    {
        $chave = trim($chave);
        $atual = $this->repo->buscar();
        if ($chave === '' && $atual !== null) {
            return Resultado::sucesso('Chave anterior mantida.');
        }
        if (preg_match('/\A[A-Za-z0-9_-]{20,256}\z/', $chave) !== 1) {
            return Resultado::falha('Cole a chave de API do Google Cloud (sem espaços).', ['chave' => 'Chave inválida.']);
        }
        if (!Cripto::disponivel()) {
            return Resultado::falha('A criptografia do servidor não está disponível.');
        }
        // Trocar a chave desliga a API até um novo teste. Nunca alterar as taxas em uso silenciosamente.
        $this->repo->salvar([
            'chave' => $chave,
            'ativo' => $atual !== null && hash_equals($atual['chave'], $chave) ? $atual['ativo'] : false,
            'testado_em' => $atual !== null && hash_equals($atual['chave'], $chave) ? $atual['testado_em'] : '',
            'salvo_em' => date('Y-m-d H:i:s'),
            'salvo_por' => trim($this->usuario->nome . ' ' . $this->usuario->sobrenome),
        ]);
        Auditoria::registrar($this->usuario, 'Google Routes: chave salva', $atual === null ? 'Primeiro cadastro.' : 'Chave mantida ou substituida (sem registrar segredo).');
        return Resultado::sucesso('Chave salva com segurança. Teste a conexão antes de ativar.');
    }

    public function testar(): Resultado
    {
        $dados = $this->repo->buscar();
        if ($dados === null) {
            return Resultado::falha('Cadastre a chave antes de testar.');
        }
        if (!RateLimiter::padrao(20, 3600)->permitir('google-routes-teste|' . $this->usuario->id)) {
            return Resultado::falha('Limite de testes por hora atingido.');
        }
        try {
            $km = (new GoogleRoutesApi($dados['chave']))->distanciaKm(
                -3.728246, -38.527416, 'Avenida Beira Mar, 1000, Meireles, Fortaleza, CE, Brasil'
            );
            if ($km <= 0) {
                return Resultado::falha('O Google não encontrou uma rota de teste válida.');
            }
            $dados['testado_em'] = date('Y-m-d H:i:s');
            $this->repo->salvar($dados);
            Auditoria::registrar($this->usuario, 'Google Routes: teste concluído', 'Conexão e cálculo de rota válidos.');
            return Resultado::sucesso('Conexão confirmada. A Routes API calculou a rota de teste. Agora você pode ativar.');
        } catch (Throwable $erro) {
            error_log('[Cardapim] Teste Google Routes falhou: ' . get_class($erro));
            return Resultado::falha('O teste falhou: ' . $erro->getMessage());
        }
    }

    public function ativar(bool $ativo): Resultado
    {
        $dados = $this->repo->buscar();
        if ($dados === null) {
            return Resultado::falha('Cadastre a chave primeiro.');
        }
        if ($ativo && $dados['testado_em'] === '') {
            return Resultado::falha('Teste a conexão com sucesso antes de ativar.');
        }
        $dados['ativo'] = $ativo;
        $this->repo->salvar($dados);
        Auditoria::registrar($this->usuario, $ativo ? 'Google Routes ativado' : 'Google Routes desativado',
            $ativo ? 'Calculo de distancia pelas ruas habilitado globalmente.' : 'Calculo Haversine restaurado globalmente.');
        return Resultado::sucesso($ativo
            ? 'Google Routes ativado para as faixas por distância de todos os restaurantes.'
            : 'Google Routes desligado. O cálculo anterior voltou a funcionar.');
    }

    public function remover(): Resultado
    {
        if ($this->repo->buscar() === null) {
            return Resultado::falha('Não há chave cadastrada.');
        }
        $this->repo->remover();
        Auditoria::registrar($this->usuario, 'Google Routes: configuração removida', 'Chave removida e Haversine restaurado.');
        return Resultado::sucesso('Chave removida. O cálculo em linha reta voltou a funcionar.');
    }
}
