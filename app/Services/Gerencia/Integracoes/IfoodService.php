<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia\Integracoes;

use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Ifood\IfoodCatalogo;
use Cardapim\Services\Ifood\IfoodConta;
use PDO;

/**
 * iFood: o restaurante autoriza o app do Cardapim no Portal do Parceiro (codigo de vinculo),
 * cola o codigo de autorizacao e escolhe a loja. Tokens ficam cifrados e sao renovados sozinhos.
 * Depois de conectado: opcoes (aceitar sozinho, enviar disponibilidade) e o cardapio do iFood ligado aos produtos.
 */
final class IfoodService extends BaseIntegracao
{
    public function __construct(PDO $db, UsuarioLogado $autor, ?int $idRestaurante, private readonly IfoodApi $api = new IfoodApi())
    {
        parent::__construct($db, $autor, $idRestaurante);
    }

    /** Passo 1: pede ao iFood um codigo de vinculo para o restaurante digitar no Portal do Parceiro. */
    public function iniciar(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'ifood');
        if ($atual['status'] === 'conectado') {
            return Resultado::falha('O iFood já está conectado. Desconecte antes de vincular outra conta.');
        }
        try {
            $vinculo = $this->api->codigoVinculo();
        } catch (IntegracaoErro $erro) {
            return Resultado::falha($erro->getMessage());
        }
        $this->integracoes->salvar($this->idRestaurante, 'ifood', [
            'status' => 'pendente',
            'credenciais' => ['verificador' => $vinculo['verificador']],
            'dados' => ['codigo' => $vinculo['codigo'], 'url' => $vinculo['url'], 'expira' => $vinculo['expira']],
            'ultimo_erro' => null,
        ]);
        $this->log('iFood: vínculo iniciado', 'Código de vínculo gerado (vale 10 minutos).');
        return Resultado::sucesso('Código gerado. Siga os passos abaixo no Portal do Parceiro iFood.');
    }

    /** Passo 2: troca o codigo de autorizacao (colado pelo gerente) pelo acesso e busca as lojas. */
    public function concluir(string $codigoAutorizacao): Resultado
    {
        $codigo = strtoupper(trim($codigoAutorizacao));
        if (preg_match('/\A[A-Z0-9-]{4,40}\z/', $codigo) !== 1) {
            return Resultado::falha('Cole o código de autorização que o Portal do Parceiro mostrou.', ['codigo' => 'Código inválido. Exemplo: ABCD-EFGH']);
        }
        $atual = $this->integracoes->buscar($this->idRestaurante, 'ifood');
        $cred = $this->integracoes->credenciais($this->idRestaurante, 'ifood');
        if ($atual['status'] !== 'pendente' || empty($cred['verificador'])) {
            return Resultado::falha('Gere um código de vínculo primeiro.');
        }
        try {
            $tokens = $this->api->trocarCodigo($codigo, (string) $cred['verificador']);
            $lojas = $this->api->lojas($tokens['acesso']);
        } catch (IntegracaoErro $erro) {
            $this->integracoes->salvar($this->idRestaurante, 'ifood', ['ultimo_erro' => $erro->getMessage()]);
            return Resultado::falha($erro->getMessage(), ['codigo' => $erro->getMessage()]);
        }
        if ($lojas === []) {
            return Resultado::falha('O iFood não mostrou nenhuma loja autorizada. Confira se você autorizou o ' . marca() . ' com a conta da loja.');
        }
        $this->integracoes->salvar($this->idRestaurante, 'ifood', [
            'credenciais' => ['acesso' => $tokens['acesso'], 'renovacao' => $tokens['renovacao'], 'expira' => $tokens['expira']],
            'dados' => ['lojas' => $lojas],
            'ultimo_erro' => null,
        ]);
        if (count($lojas) === 1) {
            return $this->escolherLoja($lojas[0]['id']);
        }
        return Resultado::sucesso('Autorização aceita. Agora escolha qual loja do iFood é deste restaurante.');
    }

    /** Passo 3: define a loja do iFood deste restaurante. */
    public function escolherLoja(string $idLoja): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'ifood');
        $lojas = is_array($atual['dados']['lojas'] ?? null) ? $atual['dados']['lojas'] : [];
        $loja = null;
        foreach ($lojas as $l) {
            if (($l['id'] ?? null) === $idLoja) {
                $loja = $l;
            }
        }
        if ($loja === null) {
            return Resultado::falha('Escolha uma das lojas da lista.');
        }
        $this->integracoes->salvar($this->idRestaurante, 'ifood', [
            'status' => 'conectado',
            'dados' => ['loja_id' => $loja['id'], 'loja_nome' => $loja['nome']],
            'conectado_em' => self::agora(),
            'verificado_em' => self::agora(),
            'ultimo_erro' => null,
        ]);
        $this->log('Integração iFood conectada', 'Loja: ' . $loja['nome'] . '.');
        $extra = '';
        try {
            $r = (new IfoodCatalogo($this->db, (int) $this->idRestaurante))->importar($this->conta());
            $extra = $r['total'] > 0 ? ' Encontramos ' . $r['total'] . ' itens no cardápio do iFood (' . $r['ligados'] . ' ligados aos seus produtos).' : '';
        } catch (IntegracaoErro) {
            $extra = ' Busque o cardápio do iFood em "Cardápio do iFood" para ligar os itens aos produtos.';
        }
        return Resultado::sucesso('iFood conectado à loja "' . $loja['nome'] . '".' . $extra);
    }

    public function testar(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'ifood');
        $idLoja = $atual['dados']['loja_id'] ?? null;
        if ($atual['status'] === 'desconectado' || !is_string($idLoja)) {
            return Resultado::falha('Conecte o iFood primeiro.');
        }
        try {
            $status = $this->api->statusLoja($this->tokenValido(), $idLoja);
        } catch (IntegracaoErro $erro) {
            $this->integracoes->salvar($this->idRestaurante, 'ifood', ['status' => 'erro', 'ultimo_erro' => $erro->getMessage()]);
            return Resultado::falha($erro->getMessage());
        }
        $this->integracoes->salvar($this->idRestaurante, 'ifood', ['status' => 'conectado', 'ultimo_erro' => null, 'verificado_em' => self::agora()]);
        return Resultado::sucesso('Conexão com o iFood funcionando. ' . $status['mensagem']);
    }

    public function cancelar(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'ifood');
        if ($atual['status'] !== 'pendente') {
            return Resultado::falha('Não há vínculo em andamento.');
        }
        $this->integracoes->limpar($this->idRestaurante, 'ifood');
        return Resultado::sucesso('Vínculo cancelado.');
    }

    public function desconectar(): Resultado
    {
        $atual = $this->integracoes->buscar($this->idRestaurante, 'ifood');
        if ($atual['status'] === 'desconectado') {
            return Resultado::falha('O iFood já está desconectado.');
        }
        $this->integracoes->limpar($this->idRestaurante, 'ifood');
        $this->log('Integração iFood desconectada', 'Acesso apagado do ' . marca() . (isset($atual['dados']['loja_nome']) ? ' (loja ' . $atual['dados']['loja_nome'] . ')' : '') . '.');
        return Resultado::sucesso('iFood desconectado. Para remover o ' . marca() . ' de vez, tire a autorização também no Portal do Parceiro.');
    }

    /** Opcoes do gerente: aceitar pedidos sozinho e enviar a disponibilidade pelo estoque. */
    public function salvarOpcoes(bool $aceitarAutomatico, bool $sincronizarEstoque): Resultado
    {
        $conta = $this->conta();
        if (!$conta->conectada()) {
            return Resultado::falha('Conecte o iFood primeiro.');
        }
        $antes = [$conta->opcao('aceitar_automatico'), $conta->opcao('sincronizar_estoque')];
        $conta->salvarOpcoes(['aceitar_automatico' => $aceitarAutomatico, 'sincronizar_estoque' => $sincronizarEstoque]);
        $sim = static fn (bool $v): string => $v ? 'ligado' : 'desligado';
        $this->log('iFood: opções', sprintf('Aceitar sozinho: %s (antes %s). Disponibilidade pelo estoque: %s (antes %s).',
            $sim($aceitarAutomatico), $sim($antes[0]), $sim($sincronizarEstoque), $sim($antes[1])));
        return Resultado::sucesso('Opções do iFood salvas.');
    }

    /** Busca os itens do cardapio no iFood e liga sozinho os que tiverem o mesmo codigo ou nome. */
    public function buscarCardapio(): Resultado
    {
        $conta = $this->conta();
        if (!$conta->conectada()) {
            return Resultado::falha('Conecte o iFood primeiro.');
        }
        try {
            $r = (new IfoodCatalogo($this->db, (int) $this->idRestaurante))->importar($conta);
        } catch (IntegracaoErro $erro) {
            return Resultado::falha($erro->getMessage());
        }
        if ($r['total'] === 0) {
            return Resultado::falha('O iFood não mostrou nenhum item no cardápio da loja. Confira o cardápio no Portal do Parceiro.');
        }
        $this->log('iFood: cardápio buscado', sprintf('%d itens (%d novos); %d ligados automaticamente.', $r['total'], $r['novos'], $r['ligados']));
        return Resultado::sucesso(sprintf('Cardápio atualizado: %d itens do iFood%s.', $r['total'], $r['ligados'] > 0 ? ', ' . $r['ligados'] . ' ligados sozinhos pelo código ou pelo nome' : ''));
    }

    /** Liga cada item do iFood ao produto escolhido (vazio = solta). @param array<mixed> $escolhas id do item => id do produto */
    public function salvarLigacoes(array $escolhas): Resultado
    {
        $catalogo = new IfoodCatalogo($this->db, (int) $this->idRestaurante);
        $atuais = [];
        foreach ($catalogo->itens() as $i) {
            $atuais[(int) $i['id']] = $i['id_produto'] !== null ? (int) $i['id_produto'] : null;
        }
        $mudados = 0;
        $recusados = 0;
        foreach ($escolhas as $idItem => $idProduto) {
            $idItem = (int) $idItem;
            if (!array_key_exists($idItem, $atuais) || !is_scalar($idProduto)) {
                continue;
            }
            $novo = preg_match('/\A[1-9][0-9]{0,18}\z/', (string) $idProduto) === 1 ? (int) $idProduto : null;
            if ($novo === $atuais[$idItem]) {
                continue;
            }
            $catalogo->ligar($idItem, $novo) ? $mudados++ : $recusados++;
        }
        if ($mudados === 0 && $recusados === 0) {
            return Resultado::sucesso('Nada mudou nas ligações.');
        }
        $this->log('iFood: ligações do cardápio', $mudados . ' item(ns) alterado(s).');
        return $recusados > 0
            ? Resultado::falha($mudados . ' ligação(ões) salva(s). ' . $recusados . ' produto(s) não encontrado(s): recarregue a página.')
            : Resultado::sucesso($mudados . ' ligação(ões) salva(s). A disponibilidade vai para o iFood em até 1 minuto.');
    }

    /** Envia agora a disponibilidade de todos os itens ligados. */
    public function sincronizarAgora(): Resultado
    {
        $conta = $this->conta();
        if (!$conta->conectada()) {
            return Resultado::falha('Conecte o iFood primeiro.');
        }
        try {
            $r = (new IfoodCatalogo($this->db, (int) $this->idRestaurante))->sincronizar($conta, true);
        } catch (IntegracaoErro $erro) {
            return Resultado::falha($erro->getMessage());
        }
        if ($r['erros'] > 0) {
            return Resultado::falha(sprintf('%d item(ns) enviado(s), %d com erro. Último erro: %s', $r['enviados'], $r['erros'], (string) $r['ultimo_erro']));
        }
        return $r['enviados'] === 0
            ? Resultado::falha('Nenhum item ligado a produto ainda. Ligue os itens abaixo e tente de novo.')
            : Resultado::sucesso($r['enviados'] . ' item(ns) atualizado(s) no iFood.');
    }

    private function conta(): IfoodConta
    {
        return new IfoodConta($this->db, (int) $this->idRestaurante, $this->api);
    }

    /** Token de acesso valido (renova com o refresh token quando vence). */
    private function tokenValido(): string
    {
        return $this->conta()->token();
    }
}
