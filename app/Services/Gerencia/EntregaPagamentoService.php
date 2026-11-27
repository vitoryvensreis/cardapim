<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Modules\Entrega\AreaEntrega;
use Cardapim\Modules\Entrega\Distancia;
use Cardapim\Modules\Entrega\TextoLocal;
use Cardapim\Repositories\Gerencia\EntregaPagamentoRepository;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Repositories\Gerencia\TaxaPagamentoRepository;
use Cardapim\Services\Pedidos\TaxaPagamento;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Geo\Geocodificador;
use Cardapim\Shared\Numero;
use PDO;

/** Aba "Entrega e pagamento": como o restaurante recebe pedidos online, onde entrega e quanto cobra. */
final class EntregaPagamentoService
{
    public const FORMAS = [
        'pag_pix_online' => 'Pix pelo app (Asaas)',
        'pag_pix_entrega' => 'Pix na entrega',
        'pag_debito' => 'Cartão de débito',
        'pag_credito' => 'Cartão de crédito',
        'pag_dinheiro' => 'Dinheiro',
    ];
    private const ROTULOS = [
        'aceita_entrega' => 'Entrega', 'aceita_retirada' => 'Retirada', 'entrega_minutos_min' => 'Entrega (mín. minutos)',
        'entrega_minutos_max' => 'Entrega (máx. minutos)', 'retirada_minutos' => 'Retirada (minutos)', ...self::FORMAS,
    ];

    private readonly EntregaPagamentoRepository $repo;

    public function __construct(
        private readonly PDO $db,
        private readonly UsuarioLogado $autor,
        private readonly int $idRestaurante,
        private readonly Geocodificador $geo = new Geocodificador(),
    ) {
        $this->repo = new EntregaPagamentoRepository($db);
    }

    public function asaasConectado(): bool
    {
        return (new IntegracaoRepository($this->db))->buscar($this->idRestaurante, 'asaas')['status'] === 'conectado';
    }

    /** @param array<string,mixed> $e */
    public function salvar(array $e): Resultado
    {
        $erros = [];
        $marcado = static fn (string $c): int => ($e[$c] ?? '') === '1' ? 1 : 0;
        $c = ['aceita_entrega' => $marcado('aceita_entrega'), 'aceita_retirada' => $marcado('aceita_retirada')];
        foreach (array_keys(self::FORMAS) as $forma) {
            $c[$forma] = $marcado($forma);
        }
        $minutos = static function (string $campo) use ($e, &$erros): ?int {
            $v = trim((string) ($e[$campo] ?? ''));
            if ($v === '') {
                return null;
            }
            if (!ctype_digit($v) || (int) $v < 1 || (int) $v > 600) {
                $erros[$campo] = 'Digite os minutos (de 1 a 600).';
                return null;
            }
            return (int) $v;
        };
        $c['entrega_minutos_min'] = $minutos('entrega_minutos_min');
        $c['entrega_minutos_max'] = $minutos('entrega_minutos_max');
        $c['retirada_minutos'] = $minutos('retirada_minutos');
        if ($c['entrega_minutos_min'] !== null && $c['entrega_minutos_max'] !== null && $c['entrega_minutos_max'] < $c['entrega_minutos_min']) {
            $erros['entrega_minutos_max'] = 'O tempo máximo precisa ser igual ou maior que o mínimo.';
        }
        if ($c['entrega_minutos_min'] !== null && $c['entrega_minutos_max'] === null) {
            $c['entrega_minutos_max'] = $c['entrega_minutos_min'];
        }
        $algumModo = $c['aceita_entrega'] === 1 || $c['aceita_retirada'] === 1;
        $algumaForma = array_sum(array_intersect_key($c, self::FORMAS)) > 0;
        if ($algumModo && !$algumaForma) {
            $erros['formas'] = 'Escolha pelo menos uma forma de pagamento para receber pedidos.';
        }
        if ($c['pag_pix_online'] === 1 && !$this->asaasConectado()) {
            $erros['formas'] = 'Para aceitar Pix pelo app, conecte primeiro sua conta Asaas em Integrações.';
        }
        // Taxa opcional de cada forma (porcentagem OU valor fixo). So mexe quando o bloco veio na tela.
        $taxas = null;
        if (($e['taxas_enviado'] ?? '') === '1') {
            $taxas = [];
            $tipos = is_array($e['taxa_tipo'] ?? null) ? $e['taxa_tipo'] : [];
            $valores = is_array($e['taxa_valor'] ?? null) ? $e['taxa_valor'] : [];
            foreach (array_keys(TaxaPagamento::FORMAS) as $forma) {
                $lido = TaxaPagamento::ler(is_string($tipos[$forma] ?? null) ? $tipos[$forma] : '', is_string($valores[$forma] ?? null) ? $valores[$forma] : '');
                if ($lido['erro'] !== null) {
                    $erros['taxa_' . $forma] = $lido['erro'];
                } elseif ($lido['regra'] !== null) {
                    $taxas[$forma] = $lido['regra'];
                }
            }
        }
        // Com Pix pelo app ligado, o Pix na entrega desliga: o cliente paga o Pix antes, pelo app.
        $pixEntregaDesligado = $c['pag_pix_online'] === 1 && $c['pag_pix_entrega'] === 1;
        if ($c['pag_pix_online'] === 1) {
            $c['pag_pix_entrega'] = 0;
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        $antes = $this->repo->config($this->idRestaurante);
        $taxasRepo = new TaxaPagamentoRepository($this->db);
        $taxasAntes = $taxasRepo->regras($this->idRestaurante);
        $this->repo->salvarConfig($this->idRestaurante, $c);
        if ($taxas !== null) {
            $taxasRepo->salvar($this->idRestaurante, $taxas);
            $texto = static function (array $regras): string {
                $partes = [];
                foreach ($regras as $forma => $r) {
                    $partes[] = TaxaPagamento::rotulo($forma, $r) . ($forma === 'pix_entrega' ? ' na entrega' : ($forma === 'pix_online' ? ' pelo app' : ($forma === 'pix_comprovante' ? ' com comprovante' : '')));
                }
                return $partes === [] ? 'nenhuma' : implode(', ', $partes);
            };
            if ($taxas != $taxasAntes) {
                Auditoria::registrar($this->autor, 'Taxas das formas de pagamento alteradas', 'Antes: ' . $texto($taxasAntes) . '. Agora: ' . $texto($taxas) . '.');
            }
        }
        $legivel = static function (array $x): array {
            foreach ($x as $campo => $v) {
                if (is_int($v) && !str_contains((string) $campo, 'minutos')) {
                    $x[$campo] = $v === 1;
                }
            }
            return $x;
        };
        Auditoria::registrar($this->autor, 'Entrega e pagamento alterados', Auditoria::diferencas($legivel($antes), $legivel($c), self::ROTULOS));
        if (!$algumModo) {
            return Resultado::sucesso('Salvo. Os pedidos pelo cardápio online estão desligados.');
        }
        return Resultado::sucesso('Pronto! Formas de pagamento e modos de pedido salvos.'
            . ($pixEntregaDesligado ? ' O Pix na entrega foi desligado porque o Pix pelo app está ligado.' : ''));
    }

    /** @param array<string,mixed> $e */
    public function entregadorVeValores(): bool
    {
        return $this->repo->entregadorVeValores($this->idRestaurante);
    }

    /** Liga ou desliga, no painel do entregador, o quanto ele ganhou em taxas de entrega. */
    public function salvarPainelEntregador(array $e): Resultado
    {
        $ve = ($e['entregador_ve_valores'] ?? '') === '1';
        $antes = $this->repo->entregadorVeValores($this->idRestaurante);
        try {
            $this->repo->salvarEntregadorVeValores($this->idRestaurante, $ve);
        } catch (\PDOException) {
            return Resultado::falha('Esta opção ainda está sendo preparada no servidor. Tente de novo em alguns minutos.');
        }
        if ($antes !== $ve) {
            Auditoria::registrar($this->autor, 'Painel do entregador alterado',
                'Valores das taxas de entrega: ' . ($antes ? 'visíveis' : 'escondidos') . ' -> ' . ($ve ? 'visíveis' : 'escondidos') . '.');
        }
        return Resultado::sucesso($ve
            ? 'Pronto: o entregador volta a ver quanto ganhou em taxas.'
            : 'Pronto: o entregador não vê mais os valores das taxas. Ele continua vendo quanto deve receber do cliente.');
    }

    public function salvarArea(array $e, ?int $idArea = null): Resultado
    {
        $existente = $idArea !== null ? $this->repo->area($this->idRestaurante, $idArea) : null;
        if ($idArea !== null && $existente === null) {
            return Resultado::falha('Área de entrega não encontrada.');
        }
        $tipo = $existente['tipo'] ?? (($e['tipo'] ?? '') === 'raio' ? 'raio' : 'bairro');
        $erros = [];
        $taxa = Numero::dinheiroParaDecimal((string) ($e['taxa'] ?? '')) ?? (trim((string) ($e['taxa'] ?? '')) === '' ? '0.00' : null);
        if ($taxa === null || Numero::centavos($taxa) > 99999) {
            $erros['taxa'] = 'Digite a taxa de entrega (ou deixe vazio para entrega grátis).';
        }
        $minTexto = trim((string) ($e['pedido_minimo'] ?? ''));
        $minimo = $minTexto === '' ? null : Numero::dinheiroParaDecimal($minTexto);
        if ($minTexto !== '' && ($minimo === null || Numero::centavos($minimo) > 9999999)) {
            $erros['pedido_minimo'] = 'Digite o valor mínimo do pedido ou deixe vazio.';
        }
        $bairro = $cidade = $raio = null;
        if ($tipo === 'bairro') {
            $bairro = TextoLocal::limpo((string) ($e['bairro'] ?? ''), 100);
            $cidade = TextoLocal::limpo((string) ($e['cidade'] ?? ''), 100);
            if (mb_strlen($bairro) < 2) {
                $erros['bairro'] = 'Digite o nome do bairro.';
            }
            if (mb_strlen($cidade) < 2) {
                $erros['cidade'] = 'Digite a cidade do bairro.';
            }
            $chave = TextoLocal::chave($bairro) . '|' . TextoLocal::chave($cidade);
        } else {
            $km = str_replace(',', '.', trim((string) ($e['raio_km'] ?? '')));
            if (preg_match('/\A\d{1,3}(\.\d{1,2})?\z/', $km) !== 1 || (float) $km < 0.1 || (float) $km > 100) {
                $erros['raio_km'] = 'Digite a distância em km (de 0,1 a 100).';
            } else {
                $raio = number_format((float) $km, 2, '.', '');
            }
            $chave = (string) $raio;
        }
        if (!isset($erros['bairro']) && !isset($erros['raio_km']) && $this->repo->chaveExiste($this->idRestaurante, $tipo, $chave, $idArea ?? 0)) {
            $erros[$tipo === 'bairro' ? 'bairro' : 'raio_km'] = $tipo === 'bairro' ? 'Esse bairro já está na lista.' : 'Essa faixa de km já está na lista.';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        $dados = ['tipo' => $tipo, 'bairro' => $bairro, 'cidade' => $cidade, 'raio_km' => $raio, 'chave' => $chave, 'taxa' => (string) $taxa, 'pedido_minimo' => $minimo];
        $descricao = AreaEntrega::descricao($dados) . ' · taxa ' . Numero::reais($taxa) . ($minimo !== null ? ' · mínimo ' . Numero::reais($minimo) : '');
        if ($existente === null) {
            $id = $this->repo->criarArea($this->idRestaurante, $dados);
            Auditoria::registrar($this->autor, 'Área de entrega cadastrada', $descricao . '.');
            return Resultado::sucesso('Área adicionada: ' . AreaEntrega::descricao($dados) . '.', $id);
        }
        $this->repo->atualizarArea($this->idRestaurante, (int) $idArea, $dados);
        Auditoria::registrar($this->autor, 'Área de entrega editada', AreaEntrega::descricao($existente) . ' -> ' . $descricao . '.');
        return Resultado::sucesso('Área atualizada: ' . AreaEntrega::descricao($dados) . '.', $idArea);
    }

    public function alternarArea(int $idArea): Resultado
    {
        $a = $this->repo->area($this->idRestaurante, $idArea);
        if ($a === null) {
            return Resultado::falha('Área de entrega não encontrada.');
        }
        $ativo = $a['ativo'] !== 1;
        $this->repo->ativarArea($this->idRestaurante, $idArea, $ativo);
        Auditoria::registrar($this->autor, $ativo ? 'Área de entrega reativada' : 'Área de entrega pausada', AreaEntrega::descricao($a) . '.');
        return Resultado::sucesso($ativo ? 'Voltou a entregar em: ' . AreaEntrega::descricao($a) . '.' : 'Entregas pausadas em: ' . AreaEntrega::descricao($a) . '.');
    }

    public function excluirArea(int $idArea): Resultado
    {
        $a = $this->repo->area($this->idRestaurante, $idArea);
        if ($a === null) {
            return Resultado::falha('Área de entrega não encontrada.');
        }
        $this->repo->excluirArea($this->idRestaurante, $idArea);
        Auditoria::registrar($this->autor, 'Área de entrega excluída', AreaEntrega::descricao($a) . ' · taxa ' . Numero::reais((string) $a['taxa']) . '.');
        return Resultado::sucesso('Área removida: ' . AreaEntrega::descricao($a) . '.');
    }

    /** Localizacao do restaurante: pelo aparelho (GPS) ou buscando o endereco do Perfil. @param array<string,mixed> $e */
    public function salvarLocal(array $e): Resultado
    {
        $antes = $this->repo->local($this->idRestaurante);
        if (($e['origem'] ?? '') === 'endereco') {
            if ($antes['logradouro'] === '' || $antes['cidade'] === '') {
                return Resultado::falha('Preencha rua e cidade no Perfil do restaurante antes de buscar pelo endereço.');
            }
            $achado = $this->geo->coordenadas($antes['logradouro'], $antes['numero'], $antes['bairro'], $antes['cidade'], $antes['estado'], $antes['cep']);
            if ($achado === null) {
                return Resultado::falha('Não encontramos esse endereço no mapa. Abra esta tela no celular, dentro do restaurante, e toque em "Usar a localização deste aparelho".');
            }
            $lat = $achado['latitude'];
            $lon = $achado['longitude'];
            $como = 'pelo endereço do Perfil';
        } else {
            $lat = str_replace(',', '.', trim((string) ($e['latitude'] ?? '')));
            $lon = str_replace(',', '.', trim((string) ($e['longitude'] ?? '')));
            if (!Distancia::coordenadaValida($lat, $lon) || (float) $lat < -34.5 || (float) $lat > 6 || (float) $lon < -74.5 || (float) $lon > -28) {
                return Resultado::falha('Não foi possível ler a localização do aparelho. Permita o acesso à localização e tente de novo.');
            }
            $lat = round((float) $lat, 7);
            $lon = round((float) $lon, 7);
            $como = 'pela localização do aparelho';
        }
        $this->repo->salvarLocal($this->idRestaurante, $lat, $lon);
        Auditoria::registrar($this->autor, 'Localização do restaurante definida', 'Definida ' . $como . '. ' . ($antes['latitude'] !== null ? 'Antes: ' . $antes['latitude'] . ', ' . $antes['longitude'] . '. ' : '') . 'Agora: ' . $lat . ', ' . $lon . '.');
        return Resultado::sucesso('Localização do restaurante salva ' . $como . '. Confira no mapa se está certa.');
    }

    public function removerLocal(): Resultado
    {
        $this->repo->salvarLocal($this->idRestaurante, null, null);
        Auditoria::registrar($this->autor, 'Localização do restaurante removida', 'As entregas por distância ficam sem funcionar até definir de novo.');
        return Resultado::sucesso('Localização removida.');
    }
}
