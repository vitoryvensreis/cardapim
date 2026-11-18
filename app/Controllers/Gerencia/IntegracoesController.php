<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Integracoes\WppConnectApi;
use Cardapim\Services\Pedidos\AvisosWhatsApp;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Gerencia\Integracoes\AsaasService;
use Cardapim\Services\Gerencia\Integracoes\BaseIntegracao;
use Cardapim\Services\Gerencia\Integracoes\IfoodLojaService;
use Cardapim\Services\Gerencia\Integracoes\IfoodService;
use Cardapim\Services\Gerencia\Integracoes\WhatsAppService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Ifood\BotaoLojaIfood;
use Cardapim\Services\Ifood\IfoodCatalogo;
use Cardapim\Services\Ifood\IfoodConta;
use Cardapim\Services\Ifood\IfoodDiagnostico;
use Cardapim\Shared\Seguranca\Cripto;

/** Aba Integracoes: iFood, WhatsApp (WPPConnect) e Asaas. Rotas em /{slug}/gerencia/integracoes/... */
final class IntegracoesController extends BaseGerencia
{
    public function inicio(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->tela('gerencia/integracoes/inicio', 'integracoes', 'Integrações', [
            'integracoes' => (new IntegracaoRepository($this->db))->todas($this->restaurante['id']),
            'ifoodDisponivel' => IfoodApi::disponivel(),
            'whatsappDisponivel' => WppConnectApi::disponivel(),
        ]);
    }

    /* ---------- Asaas ---------- */

    public function asaas(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->telaAsaas([], []);
    }

    public function asaasConectar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        if (!Cripto::disponivel()) {
            return $this->voltar($this->url('integracoes/asaas'), 'erro', 'A criptografia do servidor não está disponível. Avise o suporte.');
        }
        $resultado = $this->asaasServico()->conectar($this->post('ambiente'), (string) ($_POST['chave'] ?? ''), $this->post('email_avisos'));
        if ($resultado->ok) {
            return $this->voltar($this->url('integracoes/asaas'), 'sucesso', $resultado->mensagem);
        }
        return $this->telaAsaas($resultado->erros + ['_geral' => $resultado->mensagem], ['ambiente' => $this->post('ambiente'), 'email_avisos' => $this->post('email_avisos')], 422);
    }

    public function asaasWebhook(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->asaasServico()->configurarWebhook(), 'integracoes/asaas');
    }

    public function asaasTestar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->asaasServico()->testar(), 'integracoes/asaas');
    }

    public function asaasDesconectar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->asaasServico()->desconectar(), 'integracoes/asaas');
    }

    /* ---------- WhatsApp ---------- */

    public function whatsapp(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $integracao = (new IntegracaoRepository($this->db))->buscar($this->restaurante['id'], 'whatsapp');
        AvisosWhatsApp::agendarEnvio((int) $this->restaurante['id']); // tenta de novo o que ficou na fila
        return $this->tela('gerencia/integracoes/whatsapp', 'integracoes', 'WhatsApp', [
            'integracao' => $integracao,
            'disponivel' => WppConnectApi::disponivel(),
            'erros' => [],
            'valores' => ['mensagem' => 'Olá! Esta é uma mensagem de teste do ' . $this->restaurante['nome'] . ' pelo ' . marca() . '.'],
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ] + $this->dadosAvisos());
    }

    public function whatsappConectar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->whatsappServico()->conectar(), 'integracoes/whatsapp');
    }

    /** JSON consultado pela tela enquanto o QR Code esta aberto. */
    public function whatsappEstado(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return Response::json(['estado' => 'sessao', 'mensagem' => 'Sua sessão expirou. Recarregue a página.'], 401);
        }
        return Guard::semCache(Response::json($this->whatsappServico()->estado()));
    }

    public function whatsappTeste(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $resultado = $this->whatsappServico()->enviarTeste($this->post('telefone'), (string) ($_POST['mensagem'] ?? ''));
        if ($resultado->ok || $resultado->erros === []) {
            return $this->responder($resultado, $this->url('integracoes/whatsapp') . '#teste');
        }
        return $this->tela('gerencia/integracoes/whatsapp', 'integracoes', 'WhatsApp', [
            'integracao' => (new IntegracaoRepository($this->db))->buscar($this->restaurante['id'], 'whatsapp'),
            'disponivel' => WppConnectApi::disponivel(),
            'erros' => $resultado->erros,
            'valores' => ['telefone' => $this->post('telefone'), 'mensagem' => (string) ($_POST['mensagem'] ?? '')],
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ] + $this->dadosAvisos(), 422);
    }

    /** Quais avisos de pedido o WhatsApp do restaurante manda para os clientes. */
    public function whatsappAvisos(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $escolhidos = array_values(array_filter((array) ($_POST['avisos'] ?? []), 'is_string'));
        $antes = AvisosWhatsApp::ligados($this->db, (int) $this->restaurante['id']);
        AvisosWhatsApp::salvarLigados($this->db, (int) $this->restaurante['id'], $escolhidos);
        $depois = AvisosWhatsApp::ligados($this->db, (int) $this->restaurante['id']);
        $nomes = static fn (array $l): string => $l === [] ? 'nenhum' : implode(', ', array_map(static fn (string $e): string => AvisosWhatsApp::EVENTOS[$e][0], $l));
        \Cardapim\Services\Auditoria::registrar($this->usuario, 'WhatsApp: avisos aos clientes', 'Antes: ' . $nomes($antes) . '. Agora: ' . $nomes($depois) . '.');
        return $this->voltar($this->url('integracoes/whatsapp') . '#avisos', 'sucesso', $depois === []
            ? 'Avisos desligados. Os clientes não vão receber mensagens automáticas.'
            : 'Avisos salvos. Os clientes vão receber: ' . mb_strtolower($nomes($depois)) . '.');
    }

    /** @return array{avisosLigados:list<string>,avisosRecentes:list<array<string,mixed>>} */
    private function dadosAvisos(): array
    {
        return [
            'avisosLigados' => AvisosWhatsApp::ligados($this->db, (int) $this->restaurante['id']),
            'avisosRecentes' => AvisosWhatsApp::recentes($this->db, (int) $this->restaurante['id']),
        ];
    }

    public function whatsappDesconectar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->whatsappServico()->desconectar(), 'integracoes/whatsapp');
    }

    /* ---------- iFood ---------- */

    public function ifood(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return Response::redirect($this->url('integracoes/ifood/status'));
    }

    public function ifoodStatus(string $slug): Response
    {
        return $this->abrirAbaIfood($slug, 'status');
    }

    public function ifoodAbaLoja(string $slug): Response
    {
        return $this->abrirAbaIfood($slug, 'loja');
    }

    public function ifoodAbaCardapio(string $slug): Response
    {
        return $this->abrirAbaIfood($slug, 'cardapio');
    }

    public function ifoodDados(string $slug): Response
    {
        return $this->abrirAbaIfood($slug, 'dados');
    }

    private function abrirAbaIfood(string $slug, string $aba): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->telaIfood($aba);
    }

    /** Renderiza somente os dados da aba selecionada. */
    private function telaIfood(string $aba = 'status', array $erros = [], array $valores = [], int $status = 200): Response
    {
        $conta = new IfoodConta($this->db, (int) $this->restaurante['id']);
        $catalogo = new IfoodCatalogo($this->db, (int) $this->restaurante['id']);
        $mostrarCatalogo = $conta->conectada() && $aba === 'cardapio';
        $erroCatalogo = $mostrarCatalogo ? $this->atualizarCatalogoIfood($catalogo, $conta) : null;
        $itens = $mostrarCatalogo ? $catalogo->itens() : [];
        $produtosLocais = $mostrarCatalogo ? $catalogo->produtos() : [];
        $pedidosHoje = 0;
        if ($aba === 'status') {
            $hoje = $this->db->prepare("SELECT COUNT(*) FROM pedidos WHERE id_restaurante = ? AND origem = 'ifood' AND criado_em >= ?");
            $hoje->execute([$this->restaurante['id'], date('Y-m-d 00:00:00')]);
            $pedidosHoje = (int) $hoje->fetchColumn();
        }
        return $this->tela('gerencia/integracoes/ifood', 'integracoes', 'iFood', [
            'integracao' => (new IntegracaoRepository($this->db))->buscar($this->restaurante['id'], 'ifood'),
            'disponivel' => IfoodApi::disponivel(),
            'opcoes' => ['aceitar_automatico' => $conta->opcao('aceitar_automatico'), 'sincronizar_estoque' => $conta->opcao('sincronizar_estoque')],
            'resumoCardapio' => [
                'locais' => count($produtosLocais),
                'total' => count($itens),
                'ligados' => count(array_filter($itens, static fn (array $i): bool => $i['id_produto'] !== null && (int) $i['produto_ativo'] === 1)),
                'arquivados' => count(array_filter($itens, static fn (array $i): bool => $i['id_produto'] !== null && (int) $i['produto_ativo'] !== 1)),
                'erros' => count(array_filter($itens, static fn (array $i): bool => $i['ultimo_erro'] !== null)),
                'confirmado' => $mostrarCatalogo && $erroCatalogo === null,
            ],
            'erroCatalogo' => $erroCatalogo,
            'abaIfood' => $aba,
            'pedidosHoje' => $pedidosHoje,
            'painel' => $conta->conectada() && $aba !== 'cardapio' ? $this->ifoodLojaServico()->painel() : null,
            'diagnosticoMerchant' => $conta->conectada() && $aba === 'dados' ? IfoodDiagnostico::recentes((int) $this->restaurante['id'], 30) : [],
            'erros' => $erros,
            'valores' => $valores,
            'pageStyles' => ['css/ifood.css'],
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ], $status);
    }

    public function ifoodIniciar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->iniciar(), 'integracoes/ifood/status');
    }

    public function ifoodConcluir(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->concluir($this->post('codigo')), 'integracoes/ifood/status');
    }

    public function ifoodLoja(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->escolherLoja($this->post('loja')), 'integracoes/ifood/status');
    }

    public function ifoodTestar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->testar(), 'integracoes/ifood/loja');
    }

    public function ifoodCancelar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->cancelar(), 'integracoes/ifood/status');
    }

    public function ifoodDesconectar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->desconectar(), 'integracoes/ifood/status');
    }

    public function ifoodOpcoes(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->salvarOpcoes(isset($_POST['aceitar_automatico']), isset($_POST['sincronizar_estoque'])), 'integracoes/ifood/cardapio');
    }

    /** Cardapio do iFood: cada item ligado a um produto (estoque e disponibilidade). */
    public function ifoodCardapio(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $conta = new IfoodConta($this->db, (int) $this->restaurante['id']);
        if (!$conta->conectada()) {
            return $this->voltar($this->url('integracoes/ifood/status'), 'erro', 'Conecte o iFood primeiro.');
        }
        $catalogo = new IfoodCatalogo($this->db, (int) $this->restaurante['id']);
        $erroCatalogo = $this->atualizarCatalogoIfood($catalogo, $conta);
        $filtro = in_array($_GET['filtro'] ?? '', ['soltos', 'erros'], true) ? (string) $_GET['filtro'] : 'todos';
        $itens = $catalogo->itens();
        return $this->tela('gerencia/integracoes/ifood-cardapio', 'integracoes', 'Cardápio do iFood', [
            'itens' => $itens,
            'erroCatalogo' => $erroCatalogo,
            'filtro' => $filtro,
            'produtos' => $catalogo->produtos(),
            'sincronizar' => $conta->opcao('sincronizar_estoque'),
            'pageStyles' => ['css/ifood.css'],
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ]);
    }

    /** Consulta o catalogo real; em falha de API preserva o ultimo cache. */
    private function atualizarCatalogoIfood(IfoodCatalogo $catalogo, IfoodConta $conta): ?string
    {
        try {
            $catalogo->importar($conta, false);
            return null;
        } catch (IntegracaoErro $erro) {
            return $erro->getMessage();
        }
    }

    public function ifoodCardapioBuscar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->buscarCardapio(), 'integracoes/ifood/cardapio/vinculos');
    }

    public function ifoodCardapioLigar(string $slug): Response
    {
        $escolhas = is_array($_POST['produto'] ?? null) ? $_POST['produto'] : [];
        $volta = 'integracoes/ifood/cardapio/vinculos' . (in_array($_POST['filtro'] ?? '', ['soltos', 'erros'], true) ? '?filtro=' . $_POST['filtro'] : '');
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->salvarLigacoes($escolhas), $volta);
    }

    public function ifoodSincronizar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->sincronizarAgora(), 'integracoes/ifood/cardapio/vinculos');
    }

    /* ---------- Cardapio publicado no iFood (Catalog) ---------- */

    public function ifoodPublicar(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $conta = new IfoodConta($this->db, (int) $this->restaurante['id']);
        if (!$conta->conectada()) {
            return $this->voltar($this->url('integracoes/ifood/status'), 'erro', 'Conecte o iFood primeiro.');
        }
        $pronto = \Cardapim\Services\Ifood\IfoodPublicacao::disponivel($this->db);
        return $this->tela('gerencia/integracoes/ifood-publicar', 'integracoes', 'Publicar no iFood', [
            'pronto' => $pronto,
            'categorias' => $pronto ? (new \Cardapim\Services\Ifood\IfoodPublicacao($this->db, (int) $this->restaurante['id']))->painel() : [],
            'pageStyles' => ['css/ifood.css'],
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ]);
    }

    public function ifoodPublicarEnviar(string $slug): Response
    {
        $ids = is_array($_POST['produtos'] ?? null) ? array_values($_POST['produtos']) : [];
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->publicarCardapio($ids), 'integracoes/ifood/publicar');
    }

    public function ifoodPublicarSincronizar(string $slug): Response
    {
        return $this->acao($slug, fn (): Resultado => $this->ifoodServico()->sincronizarPublicacao(), 'integracoes/ifood/publicar');
    }

    /* ---------- Loja no iFood (Merchant): dados, pausas e horarios ---------- */

    public function ifoodMinhaLoja(string $slug): Response
    {
        // Compatibilidade com links antigos: a mesma pagina unica, sem painel duplicado.
        return $this->ifood($slug);
    }

    /** Cria pausa. Pela tela (fetch) devolve o card de pausas pronto, com o aviso dentro dele; sem JS, volta para a pagina. */
    public function ifoodPausaCriar(string $slug): Response
    {
        $porJs = self::pedidoPorJs();
        if ($r = $this->entrar($slug, !$porJs)) {
            return $porJs ? BotaoLojaIfood::semSessao() : $r;
        }
        if ($porJs && !\Cardapim\Core\Session::csrfValido($_POST['_csrf'] ?? null)) {
            return Response::json(['ok' => false, 'mensagem' => 'Sua sessão expirou. Recarregue a página.'], 419);
        }
        $valores = ['descricao' => $this->post('descricao'), 'inicio' => $this->post('inicio'), 'fim' => $this->post('fim')];
        $servico = $this->ifoodLojaServico();
        $res = $servico->criarPausa($valores['descricao'], $valores['inicio'], $valores['fim']);
        BotaoLojaIfood::esquecer($this->restaurante['id']);
        if ($porJs) {
            return $this->cardPausas($servico, $res, $res->ok ? IfoodLojaService::novaPausa($servico->fusoDaLoja()) : $valores);
        }
        if ($res->ok || $res->erros === []) {
            return $this->responder($res, $this->url('integracoes/ifood/status#pausas'));
        }
        return $this->telaLojaIfood(['pausa' => $res->erros + ['_geral' => $res->mensagem]], ['pausa' => $valores], 422);
    }

    public function ifoodPausaRemover(string $slug): Response
    {
        $porJs = self::pedidoPorJs();
        if (!$porJs) {
            return $this->acao($slug, function (): Resultado {
                BotaoLojaIfood::esquecer($this->restaurante['id']);
                return $this->ifoodLojaServico()->removerPausa($this->post('pausa'));
            }, 'integracoes/ifood/status#pausas');
        }
        if ($this->entrar($slug)) {
            return BotaoLojaIfood::semSessao();
        }
        if (!\Cardapim\Core\Session::csrfValido($_POST['_csrf'] ?? null)) {
            return Response::json(['ok' => false, 'mensagem' => 'Sua sessão expirou. Recarregue a página.'], 419);
        }
        $servico = $this->ifoodLojaServico();
        $res = $servico->removerPausa($this->post('pausa'));
        BotaoLojaIfood::esquecer($this->restaurante['id']);
        return $this->cardPausas($servico, $res, IfoodLojaService::novaPausa($servico->fusoDaLoja()));
    }

    /** GET de confirmacao: remover depende somente da ausencia do ID em GET /interruptions. */
    public function ifoodPausaEstado(string $slug): Response
    {
        if ($this->entrar($slug)) {
            return BotaoLojaIfood::semSessao();
        }
        $id = (string) ($_GET['pausa'] ?? '');
        $acao = (string) ($_GET['acao'] ?? '');
        if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $id) !== 1 || !in_array($acao, ['criar', 'remover'], true)) {
            return Response::json(['ok' => false, 'mensagem' => 'Identificador da pausa inválido.'], 422);
        }
        $servico = $this->ifoodLojaServico();
        $estado = $servico->acompanharPausa($acao !== 'remover');
        $lista = $estado['pausas'];
        $erros = $estado['erros'];
        $presente = in_array($id, array_column($lista, 'id'), true);
        // [] devolvido com HTTP OK confirma que nao ha interrupcoes.
        // [] por falha na API NAO confirma exclusao.
        $listaConsultada = !isset($erros['pausas']) && !isset($erros['geral']);
        $registroConfirmado = $listaConsultada && ($acao === 'criar' ? $presente : !$presente);
        $pausaVigente = false;
        foreach ($lista as $pausa) {
            if ($pausa['id'] !== $id) {
                continue;
            }
            $ini = IfoodApi::data((string) ($pausa['inicio'] ?? ''))?->getTimestamp();
            $fim = IfoodApi::data((string) ($pausa['fim'] ?? ''))?->getTimestamp();
            $pausaVigente = $ini !== null && $fim !== null && $ini <= time() && $fim > time();
            break;
        }
        $operacoes = $estado['situacao'];
        $situacaoConsultada = $operacoes !== [] && !isset($erros['situacao']) && !isset($erros['geral']);

        // Na remocao, a ausencia do ID no GET /interruptions continua sendo
        // a UNICA confirmacao. Apenas depois dela buscamos GET /status para
        // atualizar o card e o snapshot MySQL que alimenta o botao do topo.
        if ($acao === 'remover' && $registroConfirmado) {
            $atualizacao = $servico->atualizarDisponibilidadeConfirmada($lista);
            $operacoes = $atualizacao['situacao'];
            $situacaoConsultada = $atualizacao['erro'] === null;
            if ($atualizacao['erro'] !== null) {
                $erros['situacao'] = $atualizacao['erro'];
            }
        } elseif ($acao === 'criar' && $listaConsultada && $situacaoConsultada) {
            // Um GET validado apos criar pausa atualiza o cabecalho sem aguardar o cron.
            \Cardapim\Services\Ifood\EstadoLoja::reconciliar(
                $this->db, (int) $this->restaurante['id'], $operacoes, $lista
            );
        }

        $disponivel = $situacaoConsultada
            ? !\Cardapim\Services\Ifood\EstadoLoja::resumoOperacional($operacoes, $lista)['fechado'] : null;
        $estado['situacao'] = $operacoes;
        $estado['erros'] = $erros;
        $estado['divergencia'] = $listaConsultada && $situacaoConsultada
            && \Cardapim\Services\Ifood\EstadoLoja::resumoOperacional($operacoes, $lista)['divergente'];

        $botao = null;
        if ($listaConsultada && $situacaoConsultada) {
            BotaoLojaIfood::esquecer((int) $this->restaurante['id']);
            $botao = $servico->situacaoAgora();
        } elseif ($acao === 'remover' && $registroConfirmado) {
            $botao = [
                'estado' => 'erro', 'texto' => 'Verificando iFood',
                'detalhe' => 'A pausa foi removida, mas o iFood ainda não confirmou a disponibilidade.',
                'pode_abrir' => false,
            ];
        }

        // A mesma pausa pode ser devolvida com horas diferentes do que foi solicitado.
        // Confirmar o periodo no fuso fixo antes de informar agendamento correto.
        $inicioEsperado = (string) ($_GET['inicio'] ?? '');
        $fimEsperado = (string) ($_GET['fim'] ?? '');
        $periodoConfirmado = $acao === 'remover' || ($inicioEsperado === '' && $fimEsperado === '');
        if ($acao === 'criar' && $inicioEsperado !== '' && $fimEsperado !== ''
            && preg_match('/\\A\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}(?::\\d{2})?\\z/', $inicioEsperado) === 1
            && preg_match('/\\A\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}(?::\\d{2})?\\z/', $fimEsperado) === 1) {
            $periodoConfirmado = false;
            foreach ($lista as $pausa) {
                if ($pausa['id'] !== $id) {
                    continue;
                }
                $inicio = IfoodApi::data((string) ($pausa['inicio'] ?? ''));
                $fim = IfoodApi::data((string) ($pausa['fim'] ?? ''));
                $zona = new \DateTimeZone(\Cardapim\Services\Ifood\FusoLojaIfood::PADRAO);
                $formatoInicio = strlen($inicioEsperado) === 19 ? 'Y-m-d\\TH:i:s' : 'Y-m-d\\TH:i';
                $formatoFim = strlen($fimEsperado) === 19 ? 'Y-m-d\\TH:i:s' : 'Y-m-d\\TH:i';
                $periodoConfirmado = $inicio !== null && $fim !== null
                    && $inicio->setTimezone($zona)->format($formatoInicio) === $inicioEsperado
                    && $fim->setTimezone($zona)->format($formatoFim) === $fimEsperado;
                break;
            }
        }
        $fuso = $servico->fusoDaLoja();
        $htmlPausas = \Cardapim\Core\View::render('gerencia/integracoes/_ifood-pausas', [
            'pausas' => $lista, 'erroLista' => $erros['pausas'] ?? ($erros['geral'] ?? null),
            'eP' => [], 'vP' => IfoodLojaService::novaPausa($fuso),
            'nota' => null, 'fusoLoja' => $fuso,
            'base' => $this->url(), 'csrf' => \Cardapim\Core\Session::tokenCsrf(),
        ], 'layouts/fragmento');
        $escape = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $aviso = static fn (?string $msg): string => $msg
            ? '<div class="notice notice-warning"><div><p>' . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></div></div>' : '';
        // Ao confirmar a remocao, renderiza disponibilidade atual obtida do
        // Merchant (ou o erro da consulta); nao reutiliza HTML anterior ao DELETE.
        $htmlStatus = ($acao === 'criar' || $registroConfirmado)
            ? \Cardapim\Core\View::render('gerencia/integracoes/_ifood-situacao', [
                'p' => $estado, 'e' => $escape, 'aviso' => $aviso,
            ], 'layouts/fragmento')
            : null;
        return \Cardapim\Services\Auth\Guard::semCache(Response::json([
            'ok' => true, 'lista_consultada' => $listaConsultada, 'registro_confirmado' => $registroConfirmado,
            'vigente' => $pausaVigente,
            'periodo_confirmado' => $periodoConfirmado,
            'situacao_consultada' => $situacaoConsultada,
            'disponivel' => $disponivel,
            'html' => $htmlPausas, 'html_status' => $htmlStatus,
            'situacao_botao' => $botao,
            'erro_consulta' => $erros !== [],
        ]));
    }

    /** Horario no iFood: o do Perfil (padrao) ou um horario diferente so para o iFood. */
    public function ifoodHorarios(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        if (($_POST['horario_proprio'] ?? '') !== '1') {
            return $this->responder($this->ifoodLojaServico()->usarHorarioDoPerfil(), $this->url('integracoes/ifood/loja#horarios'));
        }
        $entrada = is_array($_POST['h'] ?? null) ? $_POST['h'] : [];
        $res = $this->ifoodLojaServico()->salvarHorarios($entrada);
        if ($res->ok || $res->erros === []) {
            return $this->responder($res, $this->url('integracoes/ifood/loja#horarios'));
        }
        return $this->telaLojaIfood(['horarios' => $res->erros + ['_geral' => $res->mensagem]], ['horarios' => $entrada, 'horario_proprio' => true], 422);
    }

    /** Botao "Abrir loja no iFood" do topo: situacao (JSON). */
    public function ifoodBotaoSituacao(string $slug): Response
    {
        if ($this->entrar($slug)) {
            return BotaoLojaIfood::semSessao();
        }
        return BotaoLojaIfood::situacao($this->db, $this->usuario, $this->restaurante['id']);
    }

    /** Botao "Abrir loja no iFood" do topo: abre a loja (JSON, X-CSRF-Token). */
    public function ifoodBotaoAbrir(string $slug): Response
    {
        if ($this->entrar($slug)) {
            return BotaoLojaIfood::semSessao();
        }
        return BotaoLojaIfood::abrir($this->db, $this->usuario, $this->restaurante['id']);
    }

    /* ---------- Internos ---------- */

    /** Envio feito pelo JS da tela (fetch com X-Requested-With: fetch). */
    private static function pedidoPorJs(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    }

    /** JSON com o card de pausas atualizado e o aviso dentro dele. @param array<string,string> $valores */
    private function cardPausas(IfoodLojaService $servico, Resultado $res, array $valores): Response
    {
        $lista = $servico->pausasAgora();
        $pendente = $res->ok && ($lista['erro'] !== null || !$servico->alteracaoConfirmada($lista['pausas']));
        $html = \Cardapim\Core\View::render('gerencia/integracoes/_ifood-pausas', [
            'pausas' => $lista['pausas'],
            'erroLista' => $lista['erro'],
            'eP' => $res->ok ? [] : $res->erros,
            'vP' => $valores,
            'fusoLoja' => $servico->fusoDaLoja(),
            'nota' => [
                'tipo' => $res->ok ? ($pendente ? 'pendente' : 'sucesso') : 'erro',
                'mensagem' => $pendente
                    ? $res->mensagem . ' Ainda não foi possível confirmar a alteração na consulta ao iFood.'
                    : ($res->ok || $res->erros === [] ? $res->mensagem : 'Confira os campos destacados.'),
            ],
            'base' => $this->url(),
            'csrf' => \Cardapim\Core\Session::tokenCsrf(),
        ], 'layouts/fragmento');
        return \Cardapim\Services\Auth\Guard::semCache(Response::json([
            'ok' => $res->ok,
            'mensagem' => $res->mensagem,
            'html' => $html,
            'id_pausa' => $res->ok ? ($servico->idCriado() ?? $servico->idRemovido()) : null,
            'acao' => $servico->idCriado() !== null ? 'criar' : 'remover',
            'registro_confirmado' => $res->ok && !$pendente,
        ], $res->ok ? 200 : 422));
    }

    /** @param array<string,array<string,string>> $erros @param array<string,mixed> $valores */
    private function telaLojaIfood(array $erros, array $valores, int $status = 200): Response
    {
        return $this->telaIfood(isset($erros['horarios']) ? 'loja' : 'status', $erros, $valores, $status);
    }

    private function ifoodLojaServico(): IfoodLojaService
    {
        return new IfoodLojaService($this->db, $this->usuario, $this->restaurante['id']);
    }


    /** POST simples: CSRF, executa e volta com o aviso. @param callable():Resultado $executar */
    private function acao(string $slug, callable $executar, string $voltarPara): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        if (!Cripto::disponivel()) {
            return $this->voltar($this->url($voltarPara), 'erro', 'A criptografia do servidor não está disponível. Avise o suporte.');
        }
        return $this->responder($executar(), $this->url($voltarPara));
    }

    /** @param array<string,string> $erros @param array<string,string> $valores */
    private function telaAsaas(array $erros, array $valores, int $status = 200): Response
    {
        $repo = new IntegracaoRepository($this->db);
        $integracao = $repo->buscar($this->restaurante['id'], 'asaas');
        return $this->tela('gerencia/integracoes/asaas', 'integracoes', 'Asaas', [
            'integracao' => $integracao,
            'urlWebhook' => $integracao['webhook_id'] !== null ? BaseIntegracao::urlPublica('/webhooks/asaas/' . $integracao['webhook_id']) : null,
            'eventos' => $repo->ultimosEventos($this->restaurante['id'], 'asaas'),
            'erros' => $erros,
            'valores' => $valores + ['ambiente' => $integracao['ambiente'] ?? 'producao', 'email_avisos' => (string) ($integracao['dados']['email_avisos'] ?? '')],
            'tokenWebhook' => $integracao['status'] !== 'desconectado' && empty($integracao['dados']['webhook_asaas_id'])
                ? (string) ($repo->credenciais($this->restaurante['id'], 'asaas')['token_webhook'] ?? '') : null,
            'pageScripts' => ['js/forms.js', 'js/integracoes.js'],
        ], $status);
    }

    private function asaasServico(): AsaasService
    {
        return new AsaasService($this->db, $this->usuario, $this->restaurante['id']);
    }

    private function whatsappServico(): WhatsAppService
    {
        return new WhatsAppService($this->db, $this->usuario, $this->restaurante['id']);
    }

    private function ifoodServico(): IfoodService
    {
        return new IfoodService($this->db, $this->usuario, $this->restaurante['id']);
    }
}
