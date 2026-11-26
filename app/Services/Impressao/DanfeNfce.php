<?php

declare(strict_types=1);

namespace Cardapim\Services\Impressao;

use Cardapim\Modules\Fiscal\FotoFiscal;
use Cardapim\Modules\Fiscal\RateioFiscal;
use Cardapim\Repositories\Desktop\ImpressaoRepository;
use Cardapim\Repositories\Desktop\ImpressoraRepository;
use Cardapim\Repositories\Gerencia\FiscalRepository;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Esquema;
use PDO;

/**
 * DANFE NFC-e (documento auxiliar) no papel térmico, montado com os dados gravados na autorização
 * (foto fiscal, chave, protocolo e QR Code). Segue a ordem do Manual de Especificações Técnicas do DANFE NFC-e:
 * emitente, itens, totais, pagamentos, consulta pela chave, consumidor, identificação da nota e QR Code.
 * Vai para a impressora fiscal escolhida em Gerência > Nota fiscal (ou a padrão da conta do caixa).
 */
final class DanfeNfce
{
    /**
     * Coloca o DANFE na fila do Deliveryzim Agent.
     * $automatico: chamado pelo emissor logo após a autorização; sem impressora ligada, apenas não imprime.
     */
    public static function enfileirar(PDO $db, int $idRestaurante, int $idDocumento, ?int $idUser = null, bool $automatico = true): Resultado
    {
        if (!Esquema::tem($db, 'impressoes') || !Esquema::tem($db, 'impressoras')) {
            return Resultado::falha('A impressão pelo Deliveryzim Agent ainda não está disponível.');
        }
        $q = $db->prepare("SELECT d.*, r.nome AS restaurante FROM fiscal_documentos d JOIN restaurantes r ON r.id = d.id_restaurante
            WHERE d.id = ? AND d.id_restaurante = ? AND d.status = 'autorizada'");
        $q->execute([$idDocumento, $idRestaurante]);
        $doc = $q->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            return Resultado::falha('Só é possível imprimir notas autorizadas.');
        }
        $impressora = self::impressora($db, $idRestaurante);
        if ($impressora === null) {
            return Resultado::falha('Nenhuma impressora cadastrada. Instale o Deliveryzim Agent e escolha a impressora da nota em Gerência > Nota fiscal.');
        }
        $repo = new ImpressaoRepository($db);
        if (!$repo->impressoraLigada($idRestaurante, $impressora['id'])) {
            return Resultado::falha('A impressora ' . $impressora['apelido'] . ' está desligada: abra o Deliveryzim Agent no computador "' . $impressora['computador'] . '".'
                . ($automatico ? ' A nota foi emitida e pode ser impressa depois.' : ''));
        }
        $blocos = self::blocos($doc);
        $repo->criar($idRestaurante, (int) $doc['id_pedido'], 'nfce', 'NFC-e nº ' . (int) $doc['numero'], $blocos, null, $idUser, $impressora['id']);
        $db->prepare('UPDATE fiscal_documentos SET impressa_em = NOW() WHERE id = ?')->execute([$idDocumento]);
        return Resultado::sucesso('Nota enviada para a impressora ' . $impressora['apelido'] . '.');
    }

    /** Impressora da nota: a escolhida no cadastro fiscal; senão a padrão da conta do caixa; senão a primeira. */
    private static function impressora(PDO $db, int $idRestaurante): ?array
    {
        $lista = (new ImpressoraRepository($db))->listar($idRestaurante);
        if ($lista === []) {
            return null;
        }
        $fiscal = (new FiscalRepository($db))->buscar($idRestaurante);
        $escolha = $fiscal['id_impressora'] ?? null;
        $escolha ??= (new ImpressoraRepository($db))->pontos($idRestaurante)['caixa_conta']['padrao'] ?? null;
        foreach ($lista as $i) {
            if ($i['id'] === $escolha) {
                return $i;
            }
        }
        return $lista[0];
    }

    /**
     * Blocos do cupom (mesmo formato do CupomImpressao, desenhados pelo CupomImagem).
     * @param array<string,mixed> $doc linha de fiscal_documentos autorizada
     * @return list<array<string,mixed>>
     */
    public static function blocos(array $doc): array
    {
        $foto = json_decode((string) ($doc['dados'] ?? ''), true);
        $foto = is_array($foto) ? $foto : [];
        $e = $foto['empresa'] ?? [];
        $r = static fn (int $c): string => 'R$ ' . RateioFiscal::dinheiro($c);
        $teste = (int) $doc['ambiente'] !== 1;
        $endereco = trim(implode(', ', array_filter([(string) ($e['logradouro'] ?? ''), (string) ($e['numero'] ?? ''), (string) ($e['complemento'] ?? ''), (string) ($e['bairro'] ?? '')])));
        $b = [];
        $b[] = ['t' => 'centro', 'texto' => (string) ($e['razao'] ?? $doc['restaurante'] ?? '')];
        $b[] = ['t' => 'centro', 'texto' => 'CNPJ ' . self::cnpj((string) ($foto['cnpj'] ?? '')) . (($e['ie'] ?? '') !== '' ? ' · IE ' . $e['ie'] : '')];
        if ($endereco !== '') {
            $b[] = ['t' => 'centro', 'texto' => $endereco . ' · ' . ($e['cidade'] ?? '') . '/' . ($e['estado'] ?? '')];
        }
        $b[] = ['t' => 'linha'];
        $b[] = ['t' => 'centro', 'texto' => 'Documento Auxiliar da Nota Fiscal de Consumidor Eletrônica'];
        if ($teste) {
            $b[] = ['t' => 'destaque', 'texto' => 'EMITIDA EM AMBIENTE DE HOMOLOGAÇÃO - SEM VALOR FISCAL'];
        }
        $b[] = ['t' => 'linha'];

        $vProd = 0; $vDesc = 0; $vOutro = 0; $qtdItens = 0;
        foreach ($foto['itens'] ?? [] as $i) {
            $qtd = (int) $i['qtd'];
            $b[] = ['t' => 'item', 'qtd' => RateioFiscal::quantidade($qtd) . ' ' . ($i['ucom'] ?? 'UN'), 'nome' => (string) $i['nome'], 'valor' => RateioFiscal::dinheiro((int) $i['vprod']),
                'detalhes' => ['Cód. ' . ($i['codigo'] ?? '') . ' · unitário ' . self::unitario((int) $i['vprod'], $qtd)]];
            $vProd += (int) $i['vprod']; $vDesc += (int) $i['vdesc']; $vOutro += (int) $i['voutro'];
            $qtdItens++;
        }
        $b[] = ['t' => 'linha'];
        $b[] = ['t' => 'valor', 'rotulo' => 'Qtd. total de itens', 'valor' => (string) $qtdItens];
        $b[] = ['t' => 'valor', 'rotulo' => 'Valor total', 'valor' => $r($vProd)];
        if ($vDesc > 0) { $b[] = ['t' => 'valor', 'rotulo' => 'Descontos', 'valor' => '- ' . $r($vDesc)]; }
        if ($vOutro > 0) { $b[] = ['t' => 'valor', 'rotulo' => 'Acréscimos (taxas)', 'valor' => $r($vOutro)]; }
        $b[] = ['t' => 'valor', 'rotulo' => 'Valor a pagar', 'valor' => $r($vProd - $vDesc + $vOutro), 'forte' => true];
        $b[] = ['t' => 'valor', 'rotulo' => 'Forma de pagamento', 'valor' => 'Valor pago'];
        $troco = 0;
        foreach ($foto['pagamentos'] ?? [] as $p) {
            $b[] = ['t' => 'valor', 'rotulo' => FotoFiscal::TPAG_ROTULO[(string) $p['tpag']] ?? 'Outros', 'valor' => $r((int) $p['vpag'])];
            $troco += (int) $p['troco'];
        }
        if ($troco > 0) { $b[] = ['t' => 'valor', 'rotulo' => 'Troco', 'valor' => $r($troco)]; }
        $b[] = ['t' => 'linha'];

        $b[] = ['t' => 'centro', 'texto' => 'Consulte pela chave de acesso em'];
        $b[] = ['t' => 'centro', 'texto' => (string) ($doc['url_consulta'] ?? '') !== '' ? (string) $doc['url_consulta'] : 'site da SEFAZ do seu estado'];
        $b[] = ['t' => 'centro', 'texto' => trim(chunk_split((string) $doc['chave'], 4, ' '))];
        $b[] = ['t' => 'linha'];
        $consumidor = (string) ($doc['cpf_cnpj'] ?? '');
        $b[] = ['t' => 'centro', 'texto' => $consumidor === '' ? 'CONSUMIDOR NÃO IDENTIFICADO' : 'CONSUMIDOR ' . (strlen($consumidor) === 11 ? 'CPF ' . self::cpf($consumidor) : 'CNPJ ' . self::cnpj($consumidor))];
        $emitida = strtotime((string) ($doc['emitida_em'] ?? '')) ?: time();
        $b[] = ['t' => 'destaque', 'texto' => 'NFC-e nº ' . str_pad((string) (int) $doc['numero'], 9, '0', STR_PAD_LEFT) . ' Série ' . str_pad((string) (int) $doc['serie'], 3, '0', STR_PAD_LEFT) . ' ' . date('d/m/Y H:i:s', $emitida)];
        $autorizada = strtotime((string) ($doc['autorizada_em'] ?? '')) ?: $emitida;
        $b[] = ['t' => 'centro', 'texto' => 'Protocolo de autorização: ' . (string) ($doc['protocolo'] ?? '')];
        $b[] = ['t' => 'centro', 'texto' => 'Data de autorização: ' . date('d/m/Y H:i:s', $autorizada)];
        if ($teste) {
            $b[] = ['t' => 'centro', 'texto' => 'Documento de teste, sem valor fiscal'];
        }
        if ((string) ($doc['qrcode'] ?? '') !== '') {
            $b[] = ['t' => 'qrcode', 'dados' => (string) $doc['qrcode'], 'texto' => (string) $doc['qrcode']];
        } else {
            $b[] = ['t' => 'centro', 'texto' => 'QR Code indisponível: consulte pela chave de acesso acima.'];
        }
        $b[] = ['t' => 'centro', 'texto' => 'Pedido #' . (int) ($foto['pedido']['numero'] ?? 0)];
        return $b;
    }

    /** Valor unitário com 2 casas (até 3 quando a divisão não é exata, como no peso). */
    private static function unitario(int $centavos, int $milesimos): string
    {
        $u = RateioFiscal::unitario($centavos, $milesimos);
        [$int, $dec] = array_pad(explode(',', $u), 2, '');
        $dec = rtrim(substr(str_pad($dec, 3, '0'), 0, 3), '0');
        return $int . ',' . str_pad($dec, 2, '0');
    }

    private static function cnpj(string $c): string
    {
        return strlen($c) === 14 ? substr($c, 0, 2) . '.' . substr($c, 2, 3) . '.' . substr($c, 5, 3) . '/' . substr($c, 8, 4) . '-' . substr($c, 12) : $c;
    }

    private static function cpf(string $c): string
    {
        return strlen($c) === 11 ? substr($c, 0, 3) . '.' . substr($c, 3, 3) . '.' . substr($c, 6, 3) . '-' . substr($c, 9) : $c;
    }
}
