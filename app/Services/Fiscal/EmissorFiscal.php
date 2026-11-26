<?php
declare(strict_types=1);

namespace Cardapim\Services\Fiscal;

use Cardapim\Modules\Fiscal\ChaveAcesso;
use Cardapim\Modules\Fiscal\FotoFiscal;
use Cardapim\Modules\Fiscal\MontadorNfce;
use Cardapim\Modules\Fiscal\MunicipioIbge;
use Cardapim\Modules\Integracoes\NextFiscalApi;
use Cardapim\Modules\Integracoes\NextFiscalErro;
use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Gerencia\FiscalRepository;
use Cardapim\Repositories\Plataforma\FiscalNextRepository;
use Cardapim\Shared\Http\ErroHttp;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Envia uma NFC-e da fila. Regras:
 * - número reservado em transação (fiscal_numeracao FOR UPDATE), nunca repetido;
 * - rede ou NeXT sem resposta = "incerta": na próxima rodada consulta a situação ANTES de reenviar;
 * - rejeição mantém o número, para reenviar depois de corrigir;
 * - pendência de cadastro = "bloqueada", nada é enviado nem numerado.
 * Roda só no worker (bin/fiscal-fila.php), nunca na requisição do caixa.
 */
final class EmissorFiscal
{
    /** Espera entre tentativas, em minutos (rede/NeXT indisponível). */
    private const ESPERA = [1, 3, 10, 30, 60];
    private const MAX_TENTATIVAS = 60;

    public function __construct(private readonly PDO $db, private readonly NextFiscalApi $api = new NextFiscalApi(new \Cardapim\Shared\Http\ClienteHttp(30)))
    {
    }

    /** Processa um documento; devolve o novo status. */
    public function processar(int $idDocumento): string
    {
        $doc = $this->documento($idDocumento);
        if ($doc === null || in_array($doc['status'], ['autorizada', 'cancelada'], true)) { return $doc['status'] ?? 'ausente'; }
        // Reivindica o documento: evita duas rodadas trabalhando nele ao mesmo tempo.
        $u = $this->db->prepare("UPDATE fiscal_documentos SET status = 'enviando', tentativas = tentativas + 1 WHERE id = ? AND status = ?");
        $u->execute([$idDocumento, $doc['status']]);
        if ($u->rowCount() !== 1) { return 'ocupado'; }
        $estavaIncerta = $doc['status'] === 'incerta' || ($doc['status'] === 'enviando');
        try {
            $ctx = $this->contexto((int) $doc['id_restaurante']);
            if ($ctx === null) { return $this->bloquear($doc, ['O módulo fiscal foi desativado ou a conexão com o emissor foi desfeita.']); }
            if ($estavaIncerta && $doc['chave'] !== null) {
                $consulta = $this->api->consultarSituacao($ctx['sh'], $ctx['cnpj'], $ctx['token'], (int) $doc['ambiente'], $ctx['uf'], (string) $doc['chave']);
                $cod = (int) ($consulta['dados']['CodStatus'] ?? 0);
                if (in_array($cod, [100, 150], true)) {
                    return $this->autorizar($doc, $ctx, (string) ($consulta['dados']['Protocolo'] ?? ''), $cod, (string) ($consulta['dados']['XMLRet'] ?? ''));
                }
                if ($cod !== 217 && $cod !== 0) {
                    return $this->adiar($doc, 'incerta', 'Aguardando confirmação da SEFAZ (' . $cod . ').');
                }
                // 217: a nota não consta na SEFAZ; pode reenviar com o mesmo número.
            }
            $montagem = (new FotoFiscal($this->db, (int) $doc['id_restaurante']))->montar((int) $doc['id_pedido'], $ctx['cnpj'], $ctx['empresa'], (int) $doc['ambiente'], $doc['cpf_cnpj']);
            if ($montagem['pendencias'] !== []) { return $this->bloquear($doc, $montagem['pendencias']); }
            $doc = $this->numerar($doc, $ctx);
            $emissao = new DateTimeImmutable('now');
            $chave = ChaveAcesso::gerar(MunicipioIbge::codigoUf($ctx['uf']), $emissao, $ctx['cnpj'], 65, (int) $doc['serie'], (int) $doc['numero'], 1, (string) $doc['codigo_nf']);
            $ini = MontadorNfce::ini($montagem['foto'], ['numero'=>(int) $doc['numero'], 'serie'=>(int) $doc['serie'], 'cnf'=>(string) $doc['codigo_nf'], 'emissao'=>$emissao,
                'cnpj_sh'=>$ctx['sh'], 'contato_sh'=>$ctx['razao_sh'], 'email_sh'=>$ctx['email_sh'], 'fone_sh'=>$ctx['fone_sh']]);
            $this->db->prepare("UPDATE fiscal_documentos SET chave = ?, dados = ?, emitida_em = ?, motivo = NULL WHERE id = ?")
                ->execute([$chave, json_encode($montagem['foto'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $emissao->format('Y-m-d H:i:s'), $doc['id']]);
            $doc['chave'] = $chave;
            try {
                $r = $this->api->enviarNfce($ctx['sh'], $ctx['cnpj'], $ctx['token'], (int) $doc['ambiente'], $ctx['uf'], (int) $doc['id'], $ini, $chave);
            } catch (ErroHttp $e) {
                return $this->adiar($doc, 'incerta', 'Sem resposta do emissor. A situação será confirmada antes de reenviar.');
            } catch (NextFiscalErro $e) {
                // Credencial recusada não é erro da nota: tenta de novo mais tarde.
                return stripos($e->getMessage(), 'credencial') !== false
                    ? $this->adiar($doc, 'pendente', $e->paraUsuario())
                    : $this->rejeitar($doc, null, $e->getMessage());
            } catch (RuntimeException $e) {
                return str_contains($e->getMessage(), 'HTTP 5')
                    ? $this->adiar($doc, 'incerta', 'O emissor está instável. A situação será confirmada antes de reenviar.')
                    : $this->adiar($doc, 'pendente', \Cardapim\Modules\Fiscal\MensagemFiscal::para($e, 'Falha ao falar com o emissor.'));
            }
            $cod = (int) ($r['dados']['CodStatus'] ?? 0);
            $motivo = trim((string) ($r['dados']['XMotivo'] ?? '')) ?: $r['mensagem'];
            if (in_array($cod, [100, 150], true)) {
                return $this->autorizar($doc, $ctx, (string) ($r['dados']['NProt'] ?? ''), $cod, (string) ($r['dados']['XMLAutorizado'] ?? ''));
            }
            if (in_array($cod, [103, 104, 105, 204], true) || ($cod === 0 && $r['sucesso'])) {
                // Lote em processamento ou duplicidade: confirmar pela consulta de situação.
                return $this->adiar($doc, 'incerta', 'Aguardando a SEFAZ processar a nota.');
            }
            return $this->rejeitar($doc, $cod ?: null, $motivo !== '' ? $motivo : 'A nota não foi autorizada.');
        } catch (Throwable $e) {
            error_log('[Cardapim] Fiscal: falha ao processar documento ' . $idDocumento . ' (' . get_class($e) . ').');
            return $this->adiar($doc, $doc['chave'] !== null ? 'incerta' : 'pendente', \Cardapim\Modules\Fiscal\MensagemFiscal::para($e, 'Falha temporária. Nova tentativa em instantes.'));
        }
    }

    /** Credenciais e dados do emitente, lidos no servidor; nunca expostos. */
    private function contexto(int $idRestaurante): ?array
    {
        $r = (new FiscalRepository($this->db))->buscar($idRestaurante);
        $g = (new FiscalNextRepository($this->db))->ler()['dados'];
        if ($r === null || !$r['habilitado'] || !$r['aprovado'] || !isset($r['dados']['token']) || ($g['ativo'] ?? false) !== true
            || ($r['dados']['software_house'] ?? '') !== ($g['cnpj'] ?? null)) {
            return null;
        }
        $empresa = $r['dados']['empresa'] ?? [];
        return [
            'sh' => (string) $g['cnpj'], 'razao_sh' => (string) ($g['razao'] ?? ''), 'email_sh' => (string) ($g['email'] ?? ''), 'fone_sh' => (string) ($g['fone'] ?? ''),
            'cnpj' => $r['cnpj'], 'token' => (string) $r['dados']['token'], 'uf' => (string) ($empresa['estado'] ?? ''),
            'empresa' => $empresa, 'config' => $r['dados']['config'] ?? [], 'dados' => $r['dados'],
        ];
    }

    /** Reserva série, número e cNF uma única vez por documento. */
    private function numerar(array $doc, array $ctx): array
    {
        if ($doc['numero'] !== null) { return $doc; }
        $amb = (int) $doc['ambiente'];
        $cfg = $amb === 3 ? \Cardapim\Services\Gerencia\FiscalService::configSimulacao($ctx['config'][3] ?? []) : ($ctx['config'][$amb] ?? []);
        $serie = (int) ($cfg['serie'] ?? 1);
        $inicial = max(1, (int) ($cfg['numero'] ?? 1));
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT IGNORE INTO fiscal_numeracao (id_restaurante, ambiente, serie, proximo) VALUES (?, ?, ?, ?)')
                ->execute([$doc['id_restaurante'], $amb, $serie, $inicial]);
            $q = $this->db->prepare('SELECT proximo FROM fiscal_numeracao WHERE id_restaurante = ? AND ambiente = ? AND serie = ? FOR UPDATE');
            $q->execute([$doc['id_restaurante'], $amb, $serie]);
            // Se o gerente informou uma numeração maior nos parâmetros, ela vale; nunca volta para trás.
            $numero = max((int) $q->fetchColumn(), $inicial);
            $this->db->prepare('UPDATE fiscal_numeracao SET proximo = ? WHERE id_restaurante = ? AND ambiente = ? AND serie = ?')
                ->execute([$numero + 1, $doc['id_restaurante'], $amb, $serie]);
            $cnf = ChaveAcesso::codigoAleatorio($numero);
            $this->db->prepare('UPDATE fiscal_documentos SET serie = ?, numero = ?, codigo_nf = ? WHERE id = ? AND numero IS NULL')
                ->execute([$serie, $numero, $cnf, $doc['id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $this->documento((int) $doc['id']) ?? throw new RuntimeException('Documento fiscal sumiu.');
    }

    private function autorizar(array $doc, array $ctx, string $protocolo, int $cod, string $xml): string
    {
        if ($xml === '') {
            try { $xml = $this->api->baixarXml($ctx['sh'], $ctx['cnpj'], $ctx['token'], (int) $doc['ambiente'], (string) $doc['chave']); } catch (Throwable) { $xml = ''; }
        }
        [$qrcode, $url] = self::qrcodeDoXml($xml);
        $arquivo = $xml !== '' ? ArquivosFiscais::salvarXml((int) $doc['id_restaurante'], (string) $doc['chave'], $xml) : null;
        $this->db->prepare("UPDATE fiscal_documentos SET status = 'autorizada', cod_status = ?, protocolo = ?, motivo = NULL, qrcode = ?, url_consulta = ?, xml_arquivo = COALESCE(?, xml_arquivo), autorizada_em = NOW(), proxima_tentativa_em = NULL WHERE id = ?")
            ->execute([$cod, mb_substr($protocolo, 0, 30), $qrcode, $url, $arquivo, $doc['id']]);
        $this->avisar($doc, 'autorizada');
        if (\Cardapim\Services\Fiscal\FiscalEmissao::configuracao($ctx['dados'])['imprimir'] && class_exists(\Cardapim\Services\Impressao\DanfeNfce::class)) {
            try { \Cardapim\Services\Impressao\DanfeNfce::enfileirar($this->db, (int) $doc['id_restaurante'], (int) $doc['id']); }
            catch (Throwable $e) { error_log('[Cardapim] Fiscal: impressão da nota não enfileirada (' . get_class($e) . ').'); }
        }
        return 'autorizada';
    }

    private function rejeitar(array $doc, ?int $cod, string $motivo): string
    {
        $this->db->prepare("UPDATE fiscal_documentos SET status = 'rejeitada', cod_status = ?, motivo = ?, proxima_tentativa_em = NULL WHERE id = ?")
            ->execute([$cod, mb_substr(($cod ? $cod . ' - ' : '') . $motivo, 0, 400), $doc['id']]);
        $this->avisar($doc, 'rejeitada');
        return 'rejeitada';
    }

    /** @param list<string> $pendencias */
    private function bloquear(array $doc, array $pendencias): string
    {
        // Revalida sozinha a cada 30 minutos por até um dia: corrigiu o cadastro, a nota sai.
        $prox = (int) $doc['tentativas'] < 48 ? date('Y-m-d H:i:s', time() + 1800) : null;
        $this->db->prepare("UPDATE fiscal_documentos SET status = 'bloqueada', motivo = ?, proxima_tentativa_em = ? WHERE id = ?")
            ->execute([mb_substr(implode(' ', array_slice($pendencias, 0, 4)), 0, 400), $prox, $doc['id']]);
        $this->avisar($doc, 'bloqueada');
        return 'bloqueada';
    }

    private function adiar(array $doc, string $status, string $motivo): string
    {
        $t = (int) ($this->documento((int) $doc['id'])['tentativas'] ?? 1);
        $espera = self::ESPERA[min(max(0, $t - 1), count(self::ESPERA) - 1)];
        $prox = $t >= self::MAX_TENTATIVAS ? null : date('Y-m-d H:i:s', time() + $espera * 60);
        $this->db->prepare('UPDATE fiscal_documentos SET status = ?, motivo = ?, proxima_tentativa_em = ? WHERE id = ?')
            ->execute([$status, mb_substr($motivo, 0, 400), $prox, $doc['id']]);
        $this->avisar($doc, $status);
        return $status;
    }

    private function avisar(array $doc, string $status): void
    {
        try {
            TempoReal::registrar($this->db, (int) $doc['id_restaurante'], 'caixa', 'fiscal.documento', ['pedido' => (int) $doc['id_pedido'], 'status' => $status]);
        } catch (Throwable) { /* tempo real é conveniência; o status já está no banco */ }
    }

    private function documento(int $id): ?array
    {
        $q = $this->db->prepare('SELECT * FROM fiscal_documentos WHERE id = ?');
        $q->execute([$id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** QR Code e URL de consulta vêm do XML autorizado gerado pela NeXT (infNFeSupl). @return array{0:?string,1:?string} */
    public static function qrcodeDoXml(string $xml): array
    {
        if ($xml === '' || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $xml)) { return [null, null]; }
        $qr = preg_match('~<qrCode>\s*(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?\s*</qrCode>~s', $xml, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1) : null;
        $url = preg_match('~<urlChave>\s*(.*?)\s*</urlChave>~s', $xml, $m2) ? html_entity_decode(trim($m2[1]), ENT_QUOTES | ENT_XML1) : null;
        $ok = static fn (?string $v): ?string => $v !== null && preg_match('~\Ahttps?://[^\s<>"]{5,990}\z~', $v) ? $v : null;
        return [$ok($qr), $ok($url)];
    }
}
