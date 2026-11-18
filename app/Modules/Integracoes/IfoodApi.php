<?php

declare(strict_types=1);

namespace Cardapim\Modules\Integracoes;

use Cardapim\Core\Config;
use Cardapim\Services\Ifood\IfoodDiagnostico;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Http\ErroHttp;
use Cardapim\Shared\Http\RespostaHttp;

/**
 * API do iFood (Merchant API) no fluxo de APLICATIVO DISTRIBUIDO:
 * o Cardapim tem um app no Portal do Desenvolvedor (Client ID/Secret cadastrados no painel da plataforma ou,
 * no modo antigo, IFOOD_CLIENT_ID/SECRET no shared/.env: ver AplicativosParceiros) e cada
 * restaurante autoriza esse app no Portal do Parceiro com um codigo de vinculo.
 */
class IfoodApi
{
    private ?int $restauranteDiagnostico = null;

    public function identificarRestaurante(int $idRestaurante): void
    {
        $this->restauranteDiagnostico = $idRestaurante > 0 ? $idRestaurante : null;
    }

    public function __construct(private readonly ClienteHttp $http = new ClienteHttp())
    {
    }

    public static function disponivel(): bool
    {
        return AplicativosParceiros::disponivel('ifood');
    }

    public static function urlBase(): string
    {
        return rtrim(Config::get('IFOOD_BASE_URL', 'https://merchant-api.ifood.com.br'), '/');
    }

    /** @return array{codigo:string, verificador:string, url:string, expira:int} */
    public function codigoVinculo(): array
    {
        $r = $this->post('/authentication/v1.0/oauth/userCode', ['clientId' => AplicativosParceiros::clientId('ifood')]);
        $d = $this->exigirOk($r, 'gerar o código de vínculo');
        $codigo = (string) ($d['userCode'] ?? '');
        $verificador = (string) ($d['authorizationCodeVerifier'] ?? '');
        if ($codigo === '' || $verificador === '') {
            throw new IntegracaoErro('O iFood não devolveu o código de vínculo. Tente de novo em instantes.');
        }
        $url = (string) ($d['verificationUrlComplete'] ?? $d['verificationUrl'] ?? 'https://portal.ifood.com.br/apps/code');
        return [
            'codigo' => mb_substr($codigo, 0, 20),
            'verificador' => $verificador,
            'url' => str_starts_with($url, 'https://') ? $url : 'https://portal.ifood.com.br/apps/code',
            'expira' => time() + max(60, min(3600, (int) ($d['expiresIn'] ?? 600))),
        ];
    }

    /** @return array{acesso:string, renovacao:string, expira:int} */
    public function trocarCodigo(string $codigoAutorizacao, string $verificador): array
    {
        return $this->tokens($this->post('/authentication/v1.0/oauth/token', [
            'grantType' => 'authorization_code',
            'clientId' => AplicativosParceiros::clientId('ifood'),
            'clientSecret' => AplicativosParceiros::clientSecret('ifood'),
            'authorizationCode' => $codigoAutorizacao,
            'authorizationCodeVerifier' => $verificador,
        ]), 'confirmar o código de autorização');
    }

    /** @return array{acesso:string, renovacao:string, expira:int} */
    public function renovar(string $tokenRenovacao): array
    {
        return $this->tokens($this->post('/authentication/v1.0/oauth/token', [
            'grantType' => 'refresh_token',
            'clientId' => AplicativosParceiros::clientId('ifood'),
            'clientSecret' => AplicativosParceiros::clientSecret('ifood'),
            'refreshToken' => $tokenRenovacao,
        ]), 'renovar o acesso');
    }

    /** @return list<array{id:string,nome:string}> lojas que autorizaram o app */
    public function lojas(string $tokenAcesso): array
    {
        $d = $this->exigirOk($this->get('/merchant/v1.0/merchants', $tokenAcesso), 'listar as lojas');
        $lojas = [];
        foreach ($d as $loja) {
            if (is_array($loja) && is_string($loja['id'] ?? null) && preg_match('/\A[A-Za-z0-9-]{8,64}\z/', $loja['id']) === 1) {
                $lojas[] = ['id' => $loja['id'], 'nome' => mb_substr((string) ($loja['name'] ?? $loja['corporateName'] ?? $loja['id']), 0, 120)];
            }
        }
        return $lojas;
    }

    /** Situacao da loja no iFood. @return array{aberta:?bool, mensagem:string} */
    public function statusLoja(string $tokenAcesso, string $idLoja): array
    {
        $d = $this->exigirOk($this->get('/merchant/v1.0/merchants/' . rawurlencode($idLoja) . '/status', $tokenAcesso), 'consultar a loja');
        $item = isset($d[0]) && is_array($d[0]) ? $d[0] : $d;
        $estado = strtoupper((string) ($item['state'] ?? ''));
        $titulo = (string) ($item['message']['title'] ?? '');
        return [
            'aberta' => $estado === '' ? null : in_array($estado, ['OK', 'WARNING'], true) && ($item['available'] ?? true) !== false,
            'mensagem' => mb_substr($titulo !== '' ? $titulo : ($estado !== '' ? 'Situação: ' . $estado : 'Loja encontrada no iFood.'), 0, 160),
        ];
    }

    /* ---------- Pedidos (polling, confirmacao de leitura, detalhes e acoes) ---------- */

    /**
     * Avisos novos da loja. O iFood exige consulta a cada 30 s (mantem a loja aberta) e nunca mais rapido.
     * @return list<array<string,mixed>>
     */
    public function eventos(string $token, string $idLoja): array
    {
        $r = $this->requisicao('GET', '/events/v1.0/events:polling', $token, null, ['x-polling-merchants' => $idLoja]);
        if ($r->status === 204) {
            return [];
        }
        $d = $this->exigirOk($r, 'buscar os pedidos novos');
        return array_values(array_filter($d, static fn (mixed $ev): bool => is_array($ev) && is_string($ev['id'] ?? null)));
    }

    /** Confirma ao iFood que os avisos foram recebidos (obrigatorio; ate 2000 por vez). @param list<string> $ids */
    public function confirmarEventos(string $token, array $ids): void
    {
        foreach (array_chunk(array_values(array_unique($ids)), 2000) as $lote) {
            $r = $this->requisicao('POST', '/events/v1.0/events/acknowledgment', $token, array_map(static fn (string $id): array => ['id' => $id], $lote));
            $this->exigirOk($r, 'confirmar o recebimento dos avisos');
        }
    }

    /** Detalhes completos do pedido. @return array<string,mixed> */
    public function pedido(string $token, string $idPedido): array
    {
        return $this->exigirOk($this->requisicao('GET', '/order/v1.0/orders/' . self::id($idPedido), $token), 'buscar o pedido');
    }

    /**
     * Acao no pedido: confirm, startPreparation, readyToPickup, dispatch ou requestCancellation.
     * @param array<string,mixed>|null $corpo
     */
    public function acaoPedido(string $token, string $idPedido, string $acao, ?array $corpo = null): void
    {
        if (!in_array($acao, ['confirm', 'startPreparation', 'readyToPickup', 'dispatch', 'requestCancellation'], true)) {
            throw new IntegracaoErro('Ação do iFood inválida.');
        }
        $r = $this->requisicao('POST', '/order/v1.0/orders/' . self::id($idPedido) . '/' . $acao, $token, $corpo);
        $this->exigirOk($r, 'atualizar o pedido');
    }

    /** Posicao e previsao do entregador do iFood (null enquanto o iFood nao tem). @return array<string,mixed>|null */
    public function rastreio(string $token, string $idPedido): ?array
    {
        $r = $this->requisicao('GET', '/order/v1.0/orders/' . self::id($idPedido) . '/tracking', $token);
        if ($r->status === 404 || $r->status === 204) {
            return null;
        }
        return $this->exigirOk($r, 'buscar o entregador');
    }

    /* ---------- Loja (Merchant): detalhes, situacao, pausas e horarios ---------- */

    /** Dias da semana no formato do iFood, na ordem de segunda a domingo. */
    public const DIAS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

    /**
     * Data/hora vinda do iFood, no fuso do sistema. O iFood trabalha em UTC e as vezes devolve sem o fuso
     * ("2026-11-17T12:49:00"): sem fuso, e UTC (nunca o horario local).
     */
    public static function data(string $valor): ?\DateTimeImmutable
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }
        $temFuso = preg_match('/(Z|[+-]\d{2}:?\d{2})\z/i', $valor) === 1;
        try {
            return (new \DateTimeImmutable($valor, $temFuso ? null : new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }
    }

    /** Detalhes completos da loja, como o iFood devolve (nome, razao social, endereco, operacoes e canais). @return array<string,mixed> */
    public function detalhesLoja(string $token, string $idLoja): array
    {
        return $this->exigirOk($this->requisicao('GET', '/merchant/v1.0/merchants/' . self::id($idLoja), $token), 'buscar os dados da loja');
    }

    /**
     * Situacao da loja por operacao (delivery, retirada...).
     * @return list<array{operacao:string, canal:string, disponivel:bool, estado:string, mensagem:string, validacoes:list<string>, reabrir:?string}>
     */
    public function situacaoLoja(string $token, string $idLoja): array
    {
        $d = $this->exigirOk($this->requisicao('GET', '/merchant/v1.0/merchants/' . self::id($idLoja) . '/status', $token), 'consultar a loja');
        $lista = isset($d['operation']) || isset($d['state']) ? [$d] : $d;
        $saida = [];
        foreach ($lista as $item) {
            if (!is_array($item)) {
                continue;
            }
            $validacoes = [];
            foreach (is_array($item['validations'] ?? null) ? $item['validations'] : [] as $v) {
                if (is_array($v)) {
                    $texto = trim((string) ($v['message']['title'] ?? $v['message']['description'] ?? $v['code'] ?? ''));
                    if ($texto !== '') {
                        $validacoes[] = mb_substr($texto . (isset($v['state']) ? ' (' . $v['state'] . ')' : ''), 0, 160);
                    }
                }
            }
            $msg = $item['message'] ?? [];
            $saida[] = [
                'operacao' => mb_substr((string) ($item['operation'] ?? '-'), 0, 40),
                'canal' => mb_substr((string) ($item['salesChannel'] ?? ''), 0, 40),
                'disponivel' => ($item['available'] ?? false) === true,
                'estado' => mb_substr(strtoupper((string) ($item['state'] ?? '')), 0, 20),
                'mensagem' => mb_substr(trim((string) (is_array($msg) ? ($msg['title'] ?? '') . ' ' . ($msg['subtitle'] ?? '') : $msg)), 0, 200),
                'validacoes' => $validacoes,
                'reabrir' => is_string($item['reopenable']['identifier'] ?? null) ? mb_substr($item['reopenable']['identifier'], 0, 64) : null,
            ];
        }
        return $saida;
    }

    /** Pausas (interrupcoes) da loja. @return list<array{id:string, descricao:string, inicio:string, fim:string}> */
    public function pausas(string $token, string $idLoja, ?string $fusoLocal = null): array
    {
        $d = $this->exigirOk($this->requisicao('GET', '/merchant/v1.0/merchants/' . self::id($idLoja) . '/interruptions', $token), 'listar as pausas');
        $saida = [];
        foreach ($d as $p) {
            if (is_array($p) && is_string($p['id'] ?? null) && $p['id'] !== '') {
                $saida[] = [
                    'id' => mb_substr($p['id'], 0, 64),
                    'descricao' => mb_substr((string) ($p['description'] ?? ''), 0, 200),
                    'inicio' => self::normalizarPausa((string) ($p['start'] ?? ''), $fusoLocal),
                    'fim' => self::normalizarPausa((string) ($p['end'] ?? ''), $fusoLocal),
                ];
            }
        }
        return $saida;
    }

    /**
     * Datas sem offset no GET de interrupcoes sao tratadas como horario de
     * parede da loja, apenas quando seu fuso e conhecido. Datas com Z/offset
     * continuam representando instantes universais. Nunca afetar datas de pedidos.
     */
    public static function normalizarPausa(string $iso, ?string $fusoLocal): string
    {
        if ($fusoLocal === null || $iso === '' || preg_match('/(Z|[+-]\\d{2}:?\\d{2})\\z/i', $iso)) {
            return $iso;
        }
        try {
            return (new \DateTimeImmutable($iso, new \DateTimeZone($fusoLocal)))->format('Y-m-d\\TH:i:sP');
        } catch (\Throwable) {
            return $iso;
        }
    }

    /** Cria uma pausa na loja (fecha no iFood entre inicio e fim). @return string id da pausa */
    public function criarPausa(string $token, string $idLoja, string $descricao, \DateTimeImmutable $inicio, \DateTimeImmutable $fim): string
    {
        $r = $this->requisicao('POST', '/merchant/v1.0/merchants/' . self::id($idLoja) . '/interruptions', $token, [
            'description' => $descricao,
            // Confirmado na loja de homologacao: a API aceita o horario local ISO 8601
            // sem offset e aplica a interrupcao. Nao somar ou subtrair horas ao relogio.
            'start' => $inicio->format('Y-m-d\TH:i:s'),
            'end' => $fim->format('Y-m-d\TH:i:s'),
        ]);
        $d = $this->exigirOk($r, 'criar a pausa');
        if ($r->status !== 201) {
            throw new IntegracaoErro('O iFood não confirmou a criação da pausa (HTTP ' . $r->status . '). Consulte a lista antes de tentar novamente.');
        }
        $id = $d['id'] ?? null;
        if (!is_string($id) || preg_match('~^[A-Za-z0-9-]{1,64}$~D', $id) !== 1) {
            throw new IntegracaoErro('O iFood não devolveu o identificador da pausa. Consulte a lista antes de tentar novamente para evitar duplicidade.');
        }
        return $id;
    }

    public function removerPausa(string $token, string $idLoja, string $idPausa): void
    {
        $r = $this->requisicao('DELETE', '/merchant/v1.0/merchants/' . self::id($idLoja) . '/interruptions/' . self::id($idPausa), $token);
        $this->exigirOk($r, 'remover a pausa');
        if ($r->status !== 204) {
            throw new IntegracaoErro('O iFood não confirmou a remoção da pausa (HTTP ' . $r->status . '). Consulte a lista antes de tentar novamente.');
        }
    }

    /** Horario de funcionamento no iFood. @return list<array{dia:string, inicio:string, duracao:int}> inicio "HH:MM", duracao em minutos */
    public function horarios(string $token, string $idLoja): array
    {
        $d = $this->exigirOk($this->requisicao('GET', '/merchant/v1.0/merchants/' . self::id($idLoja) . '/opening-hours', $token), 'consultar os horários');
        $saida = [];
        foreach (is_array($d['shifts'] ?? null) ? $d['shifts'] : [] as $t) {
            if (!is_array($t) || !in_array($t['dayOfWeek'] ?? '', self::DIAS, true) || preg_match('/\A(\d{2}):(\d{2})/', (string) ($t['start'] ?? ''), $m) !== 1) {
                continue;
            }
            $saida[] = ['dia' => (string) $t['dayOfWeek'], 'inicio' => $m[1] . ':' . $m[2], 'duracao' => max(0, (int) ($t['duration'] ?? 0))];
        }
        usort($saida, static fn (array $a, array $b): int => [array_search($a['dia'], self::DIAS, true), $a['inicio']] <=> [array_search($b['dia'], self::DIAS, true), $b['inicio']]);
        return $saida;
    }

    /** Substitui o horario de funcionamento da loja no iFood. @param list<array{dia:string, inicio:string, duracao:int}> $turnos */
    public function salvarHorarios(string $token, string $idLoja, array $turnos): void
    {
        $shifts = array_map(static fn (array $t): array => ['dayOfWeek' => $t['dia'], 'start' => $t['inicio'] . ':00', 'duration' => $t['duracao']], $turnos);
        $r = $this->requisicao('PUT', '/merchant/v1.0/merchants/' . self::id($idLoja) . '/opening-hours', $token, ['storeId' => $idLoja, 'shifts' => $shifts]);
        $this->exigirOk($r, 'salvar os horários');
    }

    /** Identificador aleatorio (UUID v4), usado nos cadastros enviados ao iFood. */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /* ---------- Cardapio (itens e disponibilidade) ---------- */

    /** @return list<string> ids dos cardapios da loja */
    public function catalogos(string $token, string $idLoja): array
    {
        $d = $this->exigirOk($this->requisicao('GET', '/catalog/v2.0/merchants/' . self::id($idLoja) . '/catalogs', $token), 'buscar o cardápio');
        if (!array_is_list($d)) {
            throw new IntegracaoErro('O iFood devolveu uma lista de cardápios em formato inesperado. Nada foi alterado.');
        }
        $ids = [];
        foreach ($d as $c) {
            if (is_array($c) && is_string($c['catalogId'] ?? null) && strtoupper((string) ($c['status'] ?? 'AVAILABLE')) !== 'DELETED') {
                $ids[] = $c['catalogId'];
            }
        }
        return $ids;
    }

    /**
     * Itens de um cardapio da loja, ja simplificados.
     * @return list<array{id:string, produto:?string, nome:string, codigo:?string, categoria:?string, preco:?float, status:?string}>
     */
    public function itensCatalogo(string $token, string $idLoja, string $idCatalogo): array
    {
        $r = $this->requisicao('GET', '/catalog/v2.0/merchants/' . self::id($idLoja) . '/catalogs/' . self::id($idCatalogo) . '/categories?includeItems=true', $token);
        $d = $this->exigirOk($r, 'buscar os itens do cardápio');
        if (!array_is_list($d)) {
            throw new IntegracaoErro('O iFood devolveu os itens em formato inesperado. Nada foi alterado.');
        }
        $itens = [];
        foreach ($d as $categoria) {
            if (!is_array($categoria) || strtoupper((string) ($categoria['status'] ?? 'AVAILABLE')) === 'DELETED') {
                continue;
            }
            foreach (is_array($categoria['items'] ?? null) ? $categoria['items'] : [] as $item) {
                if (!is_array($item) || !is_string($item['id'] ?? null) || $item['id'] === ''
                    || strtoupper((string) ($item['status'] ?? 'AVAILABLE')) === 'DELETED') {
                    continue;
                }
                $preco = $item['price']['value'] ?? $item['price'] ?? null;
                $itens[] = [
                    'id' => mb_substr($item['id'], 0, 64),
                    'produto' => is_string($item['productId'] ?? null) ? mb_substr($item['productId'], 0, 64) : null,
                    'nome' => mb_substr(trim((string) ($item['name'] ?? $item['description'] ?? 'Item sem nome')), 0, 180),
                    'codigo' => is_scalar($item['externalCode'] ?? null) && trim((string) $item['externalCode']) !== '' ? mb_substr(trim((string) $item['externalCode']), 0, 80) : null,
                    'categoria' => is_string($categoria['name'] ?? null) ? mb_substr($categoria['name'], 0, 120) : null,
                    'preco' => is_numeric($preco) ? (float) $preco : null,
                    'status' => is_string($item['status'] ?? null) ? mb_substr(strtoupper($item['status']), 0, 20) : null,
                ];
            }
        }
        return $itens;
    }

    /** Liga ou desliga a venda do item no iFood. */
    public function disponibilidadeItem(string $token, string $idLoja, string $idItem, bool $disponivel): void
    {
        $r = $this->requisicao('PATCH', '/catalog/v2.0/merchants/' . self::id($idLoja) . '/items/status', $token,
            ['itemId' => $idItem, 'status' => $disponivel ? 'AVAILABLE' : 'UNAVAILABLE']);
        $this->exigirOk($r, 'mudar a disponibilidade do item');
    }

    /* ---------- Cardapio publicado pelo sistema (categoria, item, grupos, complementos, foto, preco e status) ---------- */

    /** Cria a categoria no cardapio do iFood. @return string id da categoria no iFood */
    public function criarCategoria(string $token, string $idLoja, string $idCatalogo, string $nome, string $codigo, int $ordem): string
    {
        $r = $this->requisicao('POST', '/catalog/v2.0/merchants/' . self::id($idLoja) . '/catalogs/' . self::id($idCatalogo) . '/categories', $token,
            ['name' => $nome, 'status' => 'AVAILABLE', 'template' => 'DEFAULT', 'externalCode' => $codigo, 'index' => $ordem]);
        $d = $this->exigirOk($r, 'criar a categoria');
        $id = (string) ($d['id'] ?? $d['categoryId'] ?? '');
        if ($id === '') {
            throw new IntegracaoErro('O iFood não devolveu o código da categoria criada.');
        }
        return mb_substr($id, 0, 64);
    }

    /** Envia uma foto (JPG) para o iFood. @return string caminho da imagem no iFood (usado em imagePath) */
    public function enviarImagem(string $token, string $idLoja, string $jpeg): string
    {
        $r = $this->requisicao('POST', '/catalog/v2.0/merchants/' . self::id($idLoja) . '/image/upload', $token,
            ['image' => 'data:image/jpeg;base64,' . base64_encode($jpeg)]);
        $d = $this->exigirOk($r, 'enviar a foto');
        $caminho = (string) ($d['path'] ?? $d['imagePath'] ?? '');
        if ($caminho === '') {
            throw new IntegracaoErro('O iFood não devolveu o endereço da foto enviada.');
        }
        return mb_substr($caminho, 0, 500);
    }

    /**
     * Cria ou atualiza um item completo (item, produtos, grupos de complementos e complementos) no iFood.
     * @param array{item:array<string,mixed>, products:list<array<string,mixed>>, optionGroups:list<array<string,mixed>>, options:list<array<string,mixed>>} $cadastro
     */
    public function salvarItem(string $token, string $idLoja, array $cadastro): void
    {
        $this->exigirOk($this->requisicao('PUT', '/catalog/v2.0/merchants/' . self::id($idLoja) . '/items', $token, $cadastro), 'salvar o item no cardápio');
    }

    /** Exclui um produto criado por esta integracao (nao usa em produtos de outras origens). 404 = ja removido. */
    public function excluirProduto(string $token, string $idLoja, string $idProduto): void
    {
        $r = $this->requisicao('DELETE', '/catalog/v2.0/merchants/' . self::id($idLoja) . '/products/' . self::id($idProduto), $token);
        if ($r->status !== 404) {
            $this->exigirOk($r, 'excluir o produto do cardápio');
        }
    }

    /** Preco do item (PATCH /items/price). */
    public function precoItem(string $token, string $idLoja, string $idItem, float $valor): void
    {
        $r = $this->requisicao('PATCH', '/catalog/v2.0/merchants/' . self::id($idLoja) . '/items/price', $token,
            ['itemId' => $idItem, 'price' => ['value' => round($valor, 2), 'originalValue' => round($valor, 2)]]);
        $this->exigirOk($r, 'mudar o preço do item');
    }

    /** Preco do complemento (PATCH /options/price). */
    public function precoComplemento(string $token, string $idLoja, string $idOpcao, float $valor): void
    {
        $r = $this->requisicao('PATCH', '/catalog/v2.0/merchants/' . self::id($idLoja) . '/options/price', $token,
            ['optionId' => $idOpcao, 'price' => ['value' => round($valor, 2), 'originalValue' => round($valor, 2)]]);
        $this->exigirOk($r, 'mudar o preço do complemento');
    }

    /** Pausa ou libera o complemento (PATCH /options/status). */
    public function statusComplemento(string $token, string $idLoja, string $idOpcao, bool $disponivel): void
    {
        $r = $this->requisicao('PATCH', '/catalog/v2.0/merchants/' . self::id($idLoja) . '/options/status', $token,
            ['optionId' => $idOpcao, 'status' => $disponivel ? 'AVAILABLE' : 'UNAVAILABLE']);
        $this->exigirOk($r, 'mudar a disponibilidade do complemento');
    }

    /** Quantidade em estoque do produto no iFood (no zero o iFood para de vender sozinho). */
    public function estoqueProduto(string $token, string $idLoja, string $idProduto, int $quantidade): void
    {
        $r = $this->requisicao('POST', '/catalog/v2.0/merchants/' . self::id($idLoja) . '/inventory', $token,
            ['productId' => $idProduto, 'amount' => max(0, $quantidade)]);
        $this->exigirOk($r, 'atualizar o estoque do item');
    }

    /**
     * @param array<mixed>|null $corpo JSON
     * @param array<string,string> $extras cabecalhos extras
     */
    private function requisicao(string $metodo, string $caminho, string $token, ?array $corpo = null, array $extras = []): RespostaHttp
    {
        $inicio = hrtime(true);
        try {
            $resposta = $this->http->enviar($metodo, self::urlBase() . $caminho, ['Authorization' => 'Bearer ' . $token] + $extras, $corpo);
            $this->registrarMerchant($metodo, $caminho, $corpo, $resposta, null, $inicio);
            return $resposta;
        } catch (ErroHttp $erro) {
            $this->registrarMerchant($metodo, $caminho, $corpo, null, $erro->getMessage(), $inicio);
            throw new IntegracaoErro('Não foi possível falar com o iFood agora: ' . $erro->getMessage());
        } catch (\JsonException) {
            $this->registrarMerchant($metodo, $caminho, $corpo, null, 'JSON invalido', $inicio);
            throw new IntegracaoErro('Dados inválidos para o iFood.');
        }
    }

    /**
     * Log exclusivo das chamadas Merchant monitoradas. Nenhum cabecalho ou token e encaminhado.
     * @param array<mixed>|null $corpo
     */
    private function registrarMerchant(string $metodo, string $caminho, ?array $corpo, ?RespostaHttp $resposta, ?string $erro, int $inicio): void
    {
        if ($this->restauranteDiagnostico !== null) {
            IfoodDiagnostico::registrar($this->restauranteDiagnostico, $metodo, $caminho, $corpo, $resposta, $erro, (int) ((hrtime(true) - $inicio) / 1_000_000));
        }
    }

    /** Id vindo do iFood usado no endereco: so letras, numeros e hifen. */
    private static function id(string $id): string
    {
        if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $id) !== 1) {
            throw new IntegracaoErro('Código do iFood inválido.');
        }
        return $id;
    }

    /** @return array{acesso:string, renovacao:string, expira:int} */
    private function tokens(RespostaHttp $r, string $acao): array
    {
        $d = $this->exigirOk($r, $acao);
        $acesso = (string) ($d['accessToken'] ?? $d['access_token'] ?? '');
        if ($acesso === '') {
            throw new IntegracaoErro('O iFood não devolveu o acesso. Gere um novo código e tente de novo.');
        }
        return [
            'acesso' => $acesso,
            'renovacao' => (string) ($d['refreshToken'] ?? $d['refresh_token'] ?? ''),
            'expira' => time() + max(300, (int) ($d['expiresIn'] ?? $d['expires_in'] ?? 10800)) - 120,
        ];
    }

    /** @param array<string,string> $campos */
    private function post(string $caminho, array $campos): RespostaHttp
    {
        if (!self::disponivel()) {
            throw new IntegracaoErro('A integração com o iFood ainda não está liberada neste servidor. Avise o suporte do ' . marca() . '.');
        }
        try {
            return $this->http->formulario(self::urlBase() . $caminho, $campos);
        } catch (ErroHttp $erro) {
            throw new IntegracaoErro('Não foi possível falar com o iFood agora: ' . $erro->getMessage());
        }
    }

    private function get(string $caminho, string $token): RespostaHttp
    {
        try {
            return $this->requisicao('GET', $caminho, $token);
        } catch (ErroHttp $erro) {
            throw new IntegracaoErro('Não foi possível falar com o iFood agora: ' . $erro->getMessage());
        }
    }

    /** @return array<mixed> */
    private function exigirOk(RespostaHttp $r, string $acao): array
    {
        if ($r->ok()) {
            return $r->json();
        }
        if (in_array($r->status, [400, 401, 403], true) && str_contains($acao, 'código')) {
            throw new IntegracaoErro('O iFood recusou ao ' . $acao . '. Se você acabou de colar o código, confira se copiou certo e se ele não venceu (vale 5 minutos).', $r->status);
        }
        if ($r->status === 401 || $r->status === 403) {
            throw new IntegracaoErro('O iFood recusou o acesso ao ' . $acao . '. Se a autorização foi removida no Portal do Parceiro, conecte de novo.', $r->status);
        }
        $detalhe = self::detalheErro($r);
        throw new IntegracaoErro('O iFood não respondeu como esperado ao ' . $acao . ' (código ' . $r->status . ').'
            . ($detalhe !== '' ? ' Resposta do iFood: ' . $detalhe : ' Tente de novo em instantes.'), $r->status);
    }

    /** Mensagem de erro que o iFood mandou (curta, sem quebras), para o gerente e o suporte entenderem o motivo. */
    private static function detalheErro(RespostaHttp $r): string
    {
        try {
            $d = $r->json();
        } catch (\Throwable) {
            return '';
        }
        $partes = [];
        foreach ([$d['message'] ?? null, $d['error']['message'] ?? null, $d['error_description'] ?? null, is_string($d['error'] ?? null) ? $d['error'] : null] as $m) {
            if (is_string($m) && trim($m) !== '') {
                $partes[] = trim($m);
            }
        }
        foreach (is_array($d['error']['details'] ?? null) ? array_slice($d['error']['details'], 0, 3) : [] as $det) {
            if (is_array($det) && is_string($det['message'] ?? null)) {
                $partes[] = trim(($det['field'] ?? '') !== '' ? $det['field'] . ': ' . $det['message'] : $det['message']);
            }
        }
        return mb_substr((string) preg_replace('/\s+/u', ' ', implode(' | ', array_unique($partes))), 0, 220);
    }
}
