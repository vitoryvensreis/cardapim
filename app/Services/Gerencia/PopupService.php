<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Modules\Restaurante\PopupUpload;
use Cardapim\Repositories\Gerencia\PopupRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Esquema;
use Cardapim\Shared\MidiaUrl;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Clientes > Popup do cardapio: banner 1:1 ou texto que abre quando o cliente entra no link de pedidos.
 * Ligado/desligado, periodo opcional (inicio e fim, datas inclusivas). Cada salvamento sobe a versao: quem ja viu
 * ve de novo. No cardapio o popup abre uma vez por visita (cardapio-popup.js).
 */
final class PopupService
{
    public const MAX_TITULO = 60;
    public const MAX_TEXTO = 300;
    public const PADRAO = ['ativo' => false, 'tipo' => 'imagem', 'imagem_path' => null, 'titulo' => '', 'texto' => '', 'inicio' => null, 'fim' => null, 'versao' => 0];

    public function __construct(private readonly PDO $db, private readonly int $idRestaurante, private readonly UsuarioLogado $autor)
    {
    }

    public function disponivel(): bool
    {
        return Esquema::tem($this->db, 'popup_cardapio');
    }

    /** @return array{ativo:bool,tipo:string,imagem_path:?string,titulo:string,texto:string,inicio:?string,fim:?string,versao:int} */
    public function config(): array
    {
        return ($this->disponivel() ? (new PopupRepository($this->db))->buscar($this->idRestaurante) : null) ?? self::PADRAO;
    }

    /**
     * Confere o que veio do formulario. Imagem nova (ja salva) ou a que estava gravada.
     * @param array<string,mixed> $dados
     * @return array{dados:array{ativo:bool,tipo:string,titulo:string,texto:string,inicio:?string,fim:?string},erros:array<string,string>}
     */
    public static function validar(array $dados, bool $temImagem): array
    {
        $txt = static fn (string $c, int $max): string => mb_substr(trim((string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ',
            str_replace(["\r\n", "\r"], "\n", is_string($dados[$c] ?? null) ? $dados[$c] : ''))), 0, $max + 1);
        $d = [
            'ativo' => ($dados['ativo'] ?? '') === '1',
            'tipo' => ($dados['tipo'] ?? '') === 'texto' ? 'texto' : 'imagem',
            'titulo' => str_replace("\n", ' ', $txt('titulo', self::MAX_TITULO)),
            'texto' => (string) preg_replace("/\n{3,}/", "\n\n", $txt('texto', self::MAX_TEXTO)),
            'inicio' => null,
            'fim' => null,
        ];
        $erros = [];
        if (mb_strlen($d['titulo']) > self::MAX_TITULO) {
            $erros['titulo'] = 'Use até ' . self::MAX_TITULO . ' letras no título.';
        }
        if (mb_strlen($d['texto']) > self::MAX_TEXTO) {
            $erros['texto'] = 'Use até ' . self::MAX_TEXTO . ' letras na mensagem.';
        }
        foreach (['inicio' => 'início', 'fim' => 'fim'] as $campo => $rotulo) {
            $v = is_string($dados[$campo] ?? null) ? trim($dados[$campo]) : '';
            if ($v === '') {
                continue;
            }
            $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if ($data === false || $data->format('Y-m-d') !== $v) {
                $erros[$campo] = 'Confira a data de ' . $rotulo . '.';
                continue;
            }
            $d[$campo] = $v;
        }
        if ($d['inicio'] !== null && $d['fim'] !== null && $d['fim'] < $d['inicio']) {
            $erros['fim'] = 'O fim precisa ser no mesmo dia ou depois do início.';
        }
        if ($d['ativo'] && $d['tipo'] === 'imagem' && !$temImagem) {
            $erros['imagem'] = 'Envie a imagem do popup (quadrada, ideal 1080 x 1080).';
        }
        if ($d['ativo'] && $d['tipo'] === 'texto') {
            if (mb_strlen($d['titulo']) < 2) {
                $erros['titulo'] ??= 'Digite o título do popup.';
            }
            if (mb_strlen($d['texto']) < 2) {
                $erros['texto'] ??= 'Digite a mensagem do popup.';
            }
        }
        return ['dados' => $d, 'erros' => $erros];
    }

    /** O popup aparece hoje? Ligado, com conteudo e dentro do periodo. @param array<string,mixed>|null $p */
    public static function visivel(?array $p, string $hoje): bool
    {
        if ($p === null || !($p['ativo'] ?? false)) {
            return false;
        }
        if (($p['inicio'] ?? null) !== null && $hoje < $p['inicio']) {
            return false;
        }
        if (($p['fim'] ?? null) !== null && $hoje > $p['fim']) {
            return false;
        }
        return ($p['tipo'] ?? '') === 'texto'
            ? trim((string) ($p['titulo'] ?? '')) !== '' && trim((string) ($p['texto'] ?? '')) !== ''
            : ($p['imagem_path'] ?? null) !== null;
    }

    /**
     * Popup para o cardapio publico (null = nao mostrar). Nunca derruba o cardapio.
     * @return array{tipo:string,imagem:?string,titulo:string,texto:string,versao:int}|null
     */
    public static function paraCardapio(PDO $db, int $idRestaurante): ?array
    {
        try {
            if (!Esquema::tem($db, 'popup_cardapio')) {
                return null;
            }
            $p = (new PopupRepository($db))->buscar($idRestaurante);
            if (!self::visivel($p, date('Y-m-d'))) {
                return null;
            }
            return [
                'tipo' => $p['tipo'],
                'imagem' => $p['tipo'] === 'imagem' && $p['imagem_path'] !== null ? MidiaUrl::publica($p['imagem_path']) : null,
                'titulo' => $p['titulo'],
                'texto' => $p['texto'],
                'versao' => $p['versao'],
            ];
        } catch (Throwable $e) {
            error_log('[Cardapim] Popup do cardapio indisponivel (' . get_class($e) . ').');
            return null;
        }
    }

    /**
     * Salva o popup. $arquivo: item de $_FILES (imagem nova, opcional).
     * @param array<string,mixed> $dados
     * @param array<string,mixed> $arquivo
     */
    public function salvar(array $dados, array $arquivo): Resultado
    {
        if (!$this->disponivel()) {
            return Resultado::falha('O popup ainda está sendo ativado. Tente de novo em alguns minutos.');
        }
        $atual = $this->config();
        $nova = null;
        if ((int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $nova = PopupUpload::salvar($arquivo);
            } catch (RuntimeException $e) {
                return Resultado::falha($e->getMessage(), ['imagem' => $e->getMessage()]);
            }
        }
        $imagem = $nova ?? $atual['imagem_path'];
        $v = self::validar($dados, $imagem !== null);
        if ($v['erros'] !== []) {
            PopupUpload::apagar($nova);
            return Resultado::falha('Confira os campos destacados.', $v['erros']);
        }
        $d = $v['dados'];
        try {
            (new PopupRepository($this->db))->salvar($this->idRestaurante, $d + ['imagem_path' => $imagem]);
        } catch (Throwable $e) {
            PopupUpload::apagar($nova);
            throw $e;
        }
        if ($nova !== null && $atual['imagem_path'] !== null && $atual['imagem_path'] !== $nova) {
            PopupUpload::apagar($atual['imagem_path']);
        }
        Auditoria::registrar($this->autor, 'Popup do cardápio', ($d['ativo'] ? 'Ligado' : 'Desligado') . ', ' . ($d['tipo'] === 'imagem' ? 'imagem' : 'texto')
            . ($d['inicio'] !== null || $d['fim'] !== null ? ', de ' . ($d['inicio'] ?? 'agora') . ' até ' . ($d['fim'] ?? 'sem fim') : '') . '.');
        $mensagem = !$d['ativo'] ? 'Popup salvo e desligado: ninguém vê.'
            : (self::visivel($d + ['imagem_path' => $imagem], date('Y-m-d')) ? 'Popup salvo. Ele já aparece para quem abrir o cardápio.'
                : 'Popup salvo. Ele aparece a partir de ' . date('d/m/Y', (int) strtotime((string) $d['inicio'])) . '.');
        if ($d['ativo'] && $d['fim'] !== null && $d['fim'] < date('Y-m-d')) {
            $mensagem = 'Popup salvo, mas o período já terminou: ninguém vê. Mude a data de fim para mostrar de novo.';
        }
        return Resultado::sucesso($mensagem);
    }

    /** Tira a imagem (o popup de imagem fica desligado). */
    public function removerImagem(): Resultado
    {
        if (!$this->disponivel()) {
            return Resultado::falha('O popup ainda está sendo ativado.');
        }
        $atual = $this->config();
        if ($atual['imagem_path'] === null) {
            return Resultado::falha('O popup não tem imagem.');
        }
        $ativo = $atual['ativo'] && $atual['tipo'] === 'texto';
        (new PopupRepository($this->db))->salvar($this->idRestaurante, ['imagem_path' => null, 'ativo' => $ativo] + $atual);
        PopupUpload::apagar($atual['imagem_path']);
        Auditoria::registrar($this->autor, 'Popup do cardápio', 'Imagem removida.');
        return Resultado::sucesso($atual['ativo'] && !$ativo ? 'Imagem removida. O popup foi desligado.' : 'Imagem removida.');
    }
}
