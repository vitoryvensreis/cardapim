<?php
declare(strict_types=1);

namespace Cardapim\Services\Fiscal;

use Cardapim\Modules\Integracoes\NextFiscalApi;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Esquema;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Notas emitidas do restaurante: lista, detalhe, cancelamento, inutilização de números, XML, PDF,
 * envio por e-mail e exportação mensal dos XMLs para o contador. Tudo filtrado por id_restaurante.
 */
final class GestaoNotas
{
    public const POR_PAGINA = 50;
    /** Cancelamento homologado (135) ou fora do prazo mas aceito (155). Inutilização homologada (102). */
    private const CANCELADA = [135, 155];
    private const INUTILIZADA = 102;

    public function __construct(
        private readonly PDO $db,
        private readonly UsuarioLogado $autor,
        private readonly int $idRestaurante,
        private readonly NextFiscalApi $api = new NextFiscalApi(new \Cardapim\Shared\Http\ClienteHttp(30)),
    ) {}

    public static function disponivel(PDO $db): bool
    {
        return Esquema::tem($db, 'fiscal_documentos');
    }

    /**
     * @param array{status?:string,mes?:string,busca?:string,pagina?:int} $f
     * @return array{notas:list<array>,total:int,resumo:array<string,int>,pagina:int,mes:string,status:string,busca:string}
     */
    public function lista(array $f): array
    {
        $mes = preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', (string) ($f['mes'] ?? '')) ? (string) $f['mes'] : date('Y-m');
        $status = array_key_exists((string) ($f['status'] ?? ''), FiscalEmissao::ROTULOS) ? (string) $f['status'] : '';
        $busca = preg_replace('/\D/', '', (string) ($f['busca'] ?? '')) ?? '';
        $pagina = max(1, (int) ($f['pagina'] ?? 1));
        $ini = $mes . '-01 00:00:00';
        $fim = date('Y-m-d H:i:s', (int) strtotime($ini . ' +1 month'));
        $onde = 'd.id_restaurante = ? AND d.criado_em >= ? AND d.criado_em < ?';
        $par = [$this->idRestaurante, $ini, $fim];
        if ($status !== '') { $onde .= ' AND d.status = ?'; $par[] = $status; }
        if ($busca !== '') {
            $onde .= ' AND (d.numero = ? OR p.numero = ? OR d.chave = ?)';
            array_push($par, (int) substr($busca, 0, 9), (int) substr($busca, 0, 9), $busca);
        }
        $q = $this->db->prepare("SELECT COUNT(*) FROM fiscal_documentos d JOIN pedidos p ON p.id = d.id_pedido AND p.id_restaurante = d.id_restaurante WHERE $onde");
        $q->execute($par);
        $total = (int) $q->fetchColumn();
        $q = $this->db->prepare("SELECT d.id, d.id_pedido, d.status, d.ambiente, d.serie, d.numero, d.valor, d.motivo, d.cpf_cnpj, d.origem, d.criado_em, d.autorizada_em, p.numero AS pedido_numero
            FROM fiscal_documentos d JOIN pedidos p ON p.id = d.id_pedido AND p.id_restaurante = d.id_restaurante
            WHERE $onde ORDER BY d.id DESC LIMIT " . self::POR_PAGINA . ' OFFSET ' . (($pagina - 1) * self::POR_PAGINA));
        $q->execute($par);
        $notas = $q->fetchAll(PDO::FETCH_ASSOC);
        $r = $this->db->prepare('SELECT status, COUNT(*) FROM fiscal_documentos WHERE id_restaurante = ? AND criado_em >= ? AND criado_em < ? GROUP BY status');
        $r->execute([$this->idRestaurante, $ini, $fim]);
        $resumo = array_map('intval', $r->fetchAll(PDO::FETCH_KEY_PAIR));
        return ['notas' => $notas, 'total' => $total, 'resumo' => $resumo, 'pagina' => $pagina, 'mes' => $mes, 'status' => $status, 'busca' => $busca];
    }

    /** @return array{doc:array,foto:array,eventos:list<array>}|null */
    public function detalhe(int $id): ?array
    {
        $doc = $this->documento($id);
        if ($doc === null) { return null; }
        $foto = json_decode((string) ($doc['dados'] ?? ''), true);
        $q = $this->db->prepare('SELECT tipo, justificativa, cod_status, motivo, protocolo, criado_em FROM fiscal_eventos WHERE id_restaurante = ? AND id_documento = ? ORDER BY id DESC');
        $q->execute([$this->idRestaurante, $id]);
        return ['doc' => $doc, 'foto' => is_array($foto) ? $foto : [], 'eventos' => $q->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function cancelar(int $id, string $justificativa): Resultado
    {
        $just = self::justificativa($justificativa);
        if ($just === null) { return Resultado::falha('Explique o motivo do cancelamento com 15 a 255 letras.', ['justificativa' => 'Escreva de 15 a 255 letras.']); }
        $doc = $this->documento($id);
        if ($doc === null || $doc['status'] !== 'autorizada' || $doc['protocolo'] === null) { return Resultado::falha('Só é possível cancelar uma nota autorizada.'); }
        $ctx = $this->contexto();
        $r = $this->api->cancelar($ctx['sh'], $ctx['cnpj'], $ctx['token'], (int) $doc['ambiente'], $ctx['uf'], (string) $doc['chave'], (string) $doc['protocolo'], $just, $id);
        $cod = (int) ($r['dados']['CodStatus'] ?? 0);
        $motivo = trim((string) ($r['dados']['XMotivo'] ?? '')) ?: $r['mensagem'];
        $protocolo = mb_substr((string) ($r['dados']['NProt'] ?? $r['dados']['Protocolo'] ?? ''), 0, 30);
        $ok = in_array($cod, self::CANCELADA, true);
        $this->evento($id, 'cancelamento', (int) $doc['ambiente'], (int) $doc['serie'], (int) $doc['numero'], (int) $doc['numero'], $just, $cod ?: null, $motivo, $protocolo);
        if (!$ok) {
            return Resultado::falha('A SEFAZ não aceitou o cancelamento' . ($cod ? ' (' . $cod . ')' : '') . ': ' . ($motivo !== '' ? $motivo : 'sem motivo informado') . '. Na NFC-e o prazo costuma ser de 30 minutos após a autorização.');
        }
        $this->db->prepare("UPDATE fiscal_documentos SET status = 'cancelada', cancelada_em = NOW(), motivo = ? WHERE id = ? AND id_restaurante = ?")
            ->execute([mb_substr('Cancelada: ' . $just, 0, 400), $id, $this->idRestaurante]);
        try {
            \Cardapim\Realtime\TempoReal::registrar($this->db, $this->idRestaurante, 'caixa', 'fiscal.documento', ['pedido' => (int) $doc['id_pedido'], 'status' => 'cancelada']);
        } catch (Throwable) {}
        Auditoria::registrar($this->autor, 'Fiscal: nota cancelada', 'NFC-e ' . (int) $doc['numero'] . ' série ' . (int) $doc['serie'] . ' (pedido ' . (int) $doc['id_pedido'] . ').');
        return Resultado::sucesso('Nota nº ' . (int) $doc['numero'] . ' cancelada na SEFAZ.');
    }

    /**
     * Inutiliza uma faixa de números que nunca terá nota autorizada (ex.: nota rejeitada que não será reenviada).
     * Notas rejeitadas nessa faixa perdem o número e, se reenviadas, recebem um número novo.
     */
    public function inutilizar(string $serie, string $inicio, string $fim, string $justificativa): Resultado
    {
        $s = filter_var($serie, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 999]]);
        $a = filter_var($inicio, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999999999]]);
        $b = filter_var($fim !== '' ? $fim : $inicio, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999999999]]);
        $just = self::justificativa($justificativa);
        if ($s === false) { return Resultado::falha('Informe a série (de 0 a 999).', ['serie' => 'Série inválida.']); }
        if ($a === false || $b === false || $b < $a) { return Resultado::falha('Informe os números: o final não pode ser menor que o inicial.', ['inicio' => 'Confira os números.']); }
        if ($b - $a > 999) { return Resultado::falha('Inutilize no máximo 1.000 números por vez.', ['inicio' => 'Faixa grande demais.']); }
        if ($just === null) { return Resultado::falha('Explique o motivo com 15 a 255 letras.', ['justificativa' => 'Escreva de 15 a 255 letras.']); }
        $ctx = $this->contexto();
        $amb = (int) ($ctx['dados']['ambiente'] ?? 3);
        $q = $this->db->prepare("SELECT COUNT(*) FROM fiscal_documentos WHERE id_restaurante = ? AND ambiente = ? AND serie = ? AND numero BETWEEN ? AND ? AND status IN ('autorizada', 'cancelada', 'enviando', 'incerta')");
        $q->execute([$this->idRestaurante, $amb, $s, $a, $b]);
        if ((int) $q->fetchColumn() > 0) { return Resultado::falha('Há nota autorizada, cancelada ou em envio nessa faixa. Inutilize só números que não têm nota válida.'); }
        $r = $this->api->inutilizar($ctx['sh'], $ctx['cnpj'], $ctx['token'], $amb, $ctx['uf'], $s, $a, $b, $just);
        $cod = (int) ($r['dados']['CodStatus'] ?? 0);
        $motivo = trim((string) ($r['dados']['XMotivo'] ?? '')) ?: $r['mensagem'];
        $protocolo = mb_substr((string) ($r['dados']['NProt'] ?? $r['dados']['Protocolo'] ?? ''), 0, 30);
        $this->evento(null, 'inutilizacao', $amb, $s, $a, $b, $just, $cod ?: null, $motivo, $protocolo);
        if ($cod !== self::INUTILIZADA) {
            return Resultado::falha('A SEFAZ não aceitou a inutilização' . ($cod ? ' (' . $cod . ')' : '') . ': ' . ($motivo !== '' ? $motivo : 'sem motivo informado') . '.');
        }
        $this->db->prepare("UPDATE fiscal_documentos SET numero = NULL, serie = NULL, codigo_nf = NULL, chave = NULL, motivo = CONCAT('Número inutilizado. ', COALESCE(motivo, ''))
            WHERE id_restaurante = ? AND ambiente = ? AND serie = ? AND numero BETWEEN ? AND ? AND status IN ('rejeitada', 'bloqueada', 'pendente')")
            ->execute([$this->idRestaurante, $amb, $s, $a, $b]);
        Auditoria::registrar($this->autor, 'Fiscal: números inutilizados', 'Série ' . $s . ', ' . $a . ($b !== $a ? ' a ' . $b : '') . '.');
        return Resultado::sucesso($a === $b ? 'Número ' . $a . ' inutilizado na SEFAZ.' : 'Números de ' . $a . ' a ' . $b . ' inutilizados na SEFAZ.');
    }

    /** XML autorizado: do arquivo guardado; se faltar, busca no emissor e guarda. */
    public function xml(int $id): ?array
    {
        $doc = $this->documento($id);
        if ($doc === null || !in_array($doc['status'], ['autorizada', 'cancelada'], true) || $doc['chave'] === null) { return null; }
        $xml = ArquivosFiscais::lerXml($this->idRestaurante, $doc['xml_arquivo']);
        if ($xml === null) {
            $ctx = $this->contexto();
            $xml = $this->api->baixarXml($ctx['sh'], $ctx['cnpj'], $ctx['token'], (int) $doc['ambiente'], (string) $doc['chave']);
            if ($xml === '') { return null; }
            $rel = ArquivosFiscais::salvarXml($this->idRestaurante, (string) $doc['chave'], $xml);
            $this->db->prepare('UPDATE fiscal_documentos SET xml_arquivo = ? WHERE id = ? AND id_restaurante = ?')->execute([$rel, $id, $this->idRestaurante]);
        }
        return ['nome' => $doc['chave'] . '-nfce.xml', 'conteudo' => $xml];
    }

    public function pdf(int $id): ?array
    {
        $doc = $this->documento($id);
        if ($doc === null || !in_array($doc['status'], ['autorizada', 'cancelada'], true) || $doc['chave'] === null) { return null; }
        $ctx = $this->contexto();
        $pdf = $this->api->baixarPdf($ctx['sh'], $ctx['cnpj'], $ctx['token'], (int) $doc['ambiente'], (string) $doc['chave']);
        return $pdf === '' ? null : ['nome' => 'nfce-' . (int) $doc['numero'] . '.pdf', 'conteudo' => $pdf];
    }

    public function email(int $id, string $email): Resultado
    {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 160) { return Resultado::falha('Informe um e-mail válido.', ['email' => 'E-mail inválido.']); }
        $doc = $this->documento($id);
        if ($doc === null || $doc['status'] !== 'autorizada') { return Resultado::falha('Só é possível enviar notas autorizadas.'); }
        $ctx = $this->contexto();
        $nome = (string) ($ctx['empresa']['nome'] ?? '') !== '' ? (string) $ctx['empresa']['nome'] : (string) ($ctx['empresa']['razao'] ?? '');
        $this->api->enviarEmail($ctx['sh'], $ctx['cnpj'], $ctx['token'], (int) $doc['ambiente'], (string) $doc['chave'], $email,
            'Sua nota fiscal - ' . mb_substr($nome, 0, 60), 'Segue a nota fiscal (NFC-e) da sua compra. Obrigado pela preferência.');
        return Resultado::sucesso('Nota enviada para ' . $email . '.');
    }

    /** ZIP com os XMLs autorizados e cancelados do mês, para o contador. @return array{nome:string,conteudo:string,quantidade:int}|null */
    public function exportar(string $mes): ?array
    {
        if (!preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $mes) || !class_exists(ZipArchive::class)) { return null; }
        $ini = $mes . '-01 00:00:00';
        $fim = date('Y-m-d H:i:s', (int) strtotime($ini . ' +1 month'));
        $q = $this->db->prepare("SELECT chave, status, xml_arquivo FROM fiscal_documentos WHERE id_restaurante = ? AND ambiente = 1 AND status IN ('autorizada', 'cancelada') AND autorizada_em >= ? AND autorizada_em < ? ORDER BY numero");
        $q->execute([$this->idRestaurante, $ini, $fim]);
        $tmp = tempnam(sys_get_temp_dir(), 'nfce');
        if ($tmp === false) { return null; }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); return null; }
        $n = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $xml = ArquivosFiscais::lerXml($this->idRestaurante, $r['xml_arquivo']);
            if ($xml === null) { continue; }
            $zip->addFromString(($r['status'] === 'cancelada' ? 'canceladas/' : 'autorizadas/') . $r['chave'] . '-nfce.xml', $xml);
            $n++;
        }
        $e = $this->db->prepare("SELECT tipo, serie, numero_inicial, numero_final, protocolo, justificativa, criado_em FROM fiscal_eventos WHERE id_restaurante = ? AND ambiente = 1 AND cod_status IN (102, 135, 155) AND criado_em >= ? AND criado_em < ? ORDER BY id");
        $e->execute([$this->idRestaurante, $ini, $fim]);
        $linhas = "tipo;serie;numero_inicial;numero_final;protocolo;data;justificativa\n";
        foreach ($e->fetchAll(PDO::FETCH_ASSOC) as $ev) {
            $linhas .= implode(';', [$ev['tipo'], $ev['serie'], $ev['numero_inicial'], $ev['numero_final'], $ev['protocolo'], $ev['criado_em'], str_replace([';', "\n", "\r"], ' ', (string) $ev['justificativa'])]) . "\n";
        }
        $zip->addFromString('eventos.csv', $linhas);
        $zip->close();
        $conteudo = (string) file_get_contents($tmp);
        @unlink($tmp);
        return ['nome' => 'nfce-' . $mes . '.zip', 'conteudo' => $conteudo, 'quantidade' => $n];
    }

    private function documento(int $id): ?array
    {
        $q = $this->db->prepare('SELECT d.*, p.numero AS pedido_numero FROM fiscal_documentos d JOIN pedidos p ON p.id = d.id_pedido AND p.id_restaurante = d.id_restaurante WHERE d.id = ? AND d.id_restaurante = ?');
        $q->execute([$id, $this->idRestaurante]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** @return array<string,mixed> */
    private function contexto(): array
    {
        $ctx = (new EmissorFiscal($this->db, $this->api))->contexto($this->idRestaurante);
        if ($ctx === null) { throw new RuntimeException('A conexão com o emissor de notas não está ativa para este restaurante.'); }
        return $ctx;
    }

    private function evento(?int $idDoc, string $tipo, int $amb, int $serie, int $ini, int $fim, string $just, ?int $cod, string $motivo, string $protocolo): void
    {
        $this->db->prepare('INSERT INTO fiscal_eventos (id_restaurante, id_documento, tipo, ambiente, serie, numero_inicial, numero_final, justificativa, cod_status, motivo, protocolo, autor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$this->idRestaurante, $idDoc, $tipo, $amb, $serie, $ini, $fim, $just, $cod, mb_substr($motivo, 0, 400), $protocolo !== '' ? $protocolo : null, $this->autor->id]);
    }

    /** Justificativa da SEFAZ: 15 a 255 caracteres, sem quebras. */
    public static function justificativa(string $t): ?string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
        $n = mb_strlen($t);
        return $n >= 15 && $n <= 255 ? $t : null;
    }
}
