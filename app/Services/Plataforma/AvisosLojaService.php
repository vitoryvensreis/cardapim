<?php

declare(strict_types=1);

namespace Cardapim\Services\Plataforma;

use Cardapim\Modules\Restaurante\PopupUpload;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Painel\MenuGerencia;
use Cardapim\Shared\Esquema;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Avisos da plataforma para as lojas: pop-up para o GERENTE em qualquer tela da gerencia (todas as lojas).
 * O administrador escolhe o periodo (inicio e fim) e quantas vezes por dia cada gerente ve. O botao leva a uma
 * tela da propria loja (o link muda para cada loja) ou a um endereco externo (https).
 */
final class AvisosLojaService
{
    /** destino => [rotulo para o administrador, chave do MenuGerencia + caminho] */
    public const DESTINOS = [
        'whatsapp' => ['WhatsApp da loja (conectar / reconectar)', 'integracoes', '/whatsapp'],
        'integracoes' => ['Integrações da loja', 'integracoes', ''],
        'visao-geral' => ['Visão geral', 'visao-geral', ''],
        'perfil' => ['Perfil do restaurante', 'perfil', ''],
        'entrega-pagamento' => ['Entrega e pagamento', 'entrega-pagamento', ''],
        'assinatura' => ['Assinatura', 'assinatura', ''],
        'suporte' => ['Suporte', 'suporte', ''],
    ];
    public const EXTERNO = 'externo';
    public const VEZES_MAX = 10;

    public function __construct(private readonly PDO $db, private readonly ?UsuarioLogado $autor = null)
    {
    }

    public static function disponivel(PDO $db): bool
    {
        return Esquema::tem($db, 'avisos_loja');
    }

    /** @return list<array<string,mixed>> todos os avisos, com quantas lojas viram e clicaram */
    public function listar(): array
    {
        return $this->db->query('SELECT a.*,
                (SELECT COUNT(DISTINCT e.id_restaurante) FROM avisos_loja_exibicoes e WHERE e.id_aviso = a.id) AS lojas_viram,
                (SELECT COUNT(DISTINCT e.id_restaurante) FROM avisos_loja_exibicoes e WHERE e.id_aviso = a.id AND e.clicou_em IS NOT NULL) AS lojas_clicaram
            FROM avisos_loja a ORDER BY a.ativo DESC, a.fim DESC, a.id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $id): ?array
    {
        $q = $this->db->prepare('SELECT * FROM avisos_loja WHERE id = ?');
        $q->execute([$id]);
        $a = $q->fetch(PDO::FETCH_ASSOC);
        return $a === false ? null : $a;
    }

    /**
     * Valida o formulario. Datas no formato do campo datetime-local (2026-11-28T09:00).
     * @param array<string,mixed> $d
     * @return array{0:array<string,mixed>, 1:array<string,string>} dados normalizados e erros por campo
     */
    public static function validar(array $d): array
    {
        $t = static fn (string $c): string => trim(str_replace("\r\n", "\n", is_string($d[$c] ?? null) ? $d[$c] : ''));
        $erros = [];
        $v = [
            'titulo' => $t('titulo'),
            'texto' => $t('texto'),
            'botao_texto' => $t('botao_texto'),
            'botao_destino' => $t('botao_destino'),
            'botao_url' => $t('botao_url'),
            'inicio' => $t('inicio'),
            'fim' => $t('fim'),
            'vezes_por_dia' => $t('vezes_por_dia'),
        ];
        if (mb_strlen($v['titulo']) < 3 || mb_strlen($v['titulo']) > 80) {
            $erros['titulo'] = 'Escreva um título de 3 a 80 caracteres.';
        }
        if (mb_strlen($v['texto']) > 600) {
            $erros['texto'] = 'Use no máximo 600 caracteres.';
        }
        if ($v['botao_destino'] === '') {
            $v['botao_texto'] = '';
            $v['botao_url'] = '';
        } else {
            if (mb_strlen($v['botao_texto']) < 2 || mb_strlen($v['botao_texto']) > 40) {
                $erros['botao_texto'] = 'Escreva o texto do botão (2 a 40 caracteres). Ex.: Reconectar agora';
            }
            if ($v['botao_destino'] === self::EXTERNO) {
                if (!self::urlSegura($v['botao_url'])) {
                    $erros['botao_url'] = 'Cole um endereço completo começando com https://';
                }
            } elseif (!isset(self::DESTINOS[$v['botao_destino']])) {
                $erros['botao_destino'] = 'Escolha para onde o botão leva.';
            } else {
                $v['botao_url'] = '';
            }
        }
        $inicio = self::data($v['inicio']);
        $fim = self::data($v['fim']);
        if ($inicio === null) {
            $erros['inicio'] = 'Escolha quando o aviso começa.';
        }
        if ($fim === null) {
            $erros['fim'] = 'Escolha quando o aviso termina.';
        } elseif ($inicio !== null && $fim <= $inicio) {
            $erros['fim'] = 'O fim precisa ser depois do início.';
        }
        $vezes = ctype_digit($v['vezes_por_dia']) ? (int) $v['vezes_por_dia'] : 0;
        if ($vezes < 1 || $vezes > self::VEZES_MAX) {
            $erros['vezes_por_dia'] = 'Escolha de 1 a ' . self::VEZES_MAX . ' vezes por dia.';
        }
        $v['vezes_por_dia'] = $vezes;
        $v['inicio_sql'] = $inicio?->format('Y-m-d H:i:s');
        $v['fim_sql'] = $fim?->format('Y-m-d H:i:s');
        return [$v, $erros];
    }

    /**
     * Cria ou edita. Aviso novo nasce pausado quando $ativar = false.
     * @param array<string,mixed> $d @param array<string,mixed>|null $imagem item de $_FILES
     */
    public function salvar(?int $id, array $d, ?array $imagem, bool $removerImagem): Resultado
    {
        $atual = $id !== null ? $this->buscar($id) : null;
        if ($id !== null && $atual === null) {
            return Resultado::falha('Aviso não encontrado.');
        }
        [$v, $erros] = self::validar($d);
        $temArquivo = is_array($imagem) && (int) ($imagem['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $imagemFinal = $removerImagem ? null : ($atual['imagem_path'] ?? null);
        if ($v['texto'] === '' && !$temArquivo && $imagemFinal === null && !isset($erros['texto'])) {
            $erros['texto'] = 'Escreva um texto ou envie uma imagem.';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        $nova = null;
        if ($temArquivo) {
            try {
                $nova = PopupUpload::salvar($imagem);
            } catch (RuntimeException $e) {
                return Resultado::falha('Confira os campos destacados.', ['imagem' => $e->getMessage()]);
            }
            $imagemFinal = $nova;
        }
        $valores = [
            $v['titulo'], $v['texto'] !== '' ? $v['texto'] : null, $imagemFinal,
            $v['botao_destino'] !== '' ? $v['botao_texto'] : null,
            $v['botao_destino'] !== '' ? $v['botao_destino'] : null,
            $v['botao_destino'] === self::EXTERNO ? $v['botao_url'] : null,
            $v['inicio_sql'], $v['fim_sql'], $v['vezes_por_dia'],
        ];
        try {
            if ($atual === null) {
                $this->db->prepare('INSERT INTO avisos_loja (titulo, texto, imagem_path, botao_texto, botao_destino, botao_url, inicio, fim, vezes_por_dia, ativo, criado_por)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)')->execute([...$valores, $this->autor?->id]);
                $id = (int) $this->db->lastInsertId();
            } else {
                $this->db->prepare('UPDATE avisos_loja SET titulo = ?, texto = ?, imagem_path = ?, botao_texto = ?, botao_destino = ?, botao_url = ?,
                    inicio = ?, fim = ?, vezes_por_dia = ? WHERE id = ?')->execute([...$valores, $id]);
            }
        } catch (Throwable $e) {
            PopupUpload::apagar($nova);
            throw $e;
        }
        if ($atual !== null && ($atual['imagem_path'] ?? null) !== null && $atual['imagem_path'] !== $imagemFinal) {
            PopupUpload::apagar($atual['imagem_path']);
        }
        $this->log($atual === null ? 'Aviso para as lojas criado' : 'Aviso para as lojas editado', '"' . $v['titulo'] . '"');
        return Resultado::sucesso($atual === null
            ? 'Aviso criado. Ele está pausado: confira a prévia e toque em "Ativar" quando quiser que as lojas vejam.'
            : 'Aviso salvo.', $id);
    }

    public function alternar(int $id): Resultado
    {
        $a = $this->buscar($id);
        if ($a === null) {
            return Resultado::falha('Aviso não encontrado.');
        }
        $ativo = (int) $a['ativo'] !== 1;
        $this->db->prepare('UPDATE avisos_loja SET ativo = ? WHERE id = ?')->execute([$ativo ? 1 : 0, $id]);
        $this->log($ativo ? 'Aviso para as lojas ativado' : 'Aviso para as lojas pausado', '"' . $a['titulo'] . '"');
        if (!$ativo) {
            return Resultado::sucesso('Aviso pausado. As lojas não veem mais.');
        }
        return Resultado::sucesso(strtotime((string) $a['fim']) <= time()
            ? 'Aviso ativado, mas o período já terminou. Edite as datas para as lojas verem.'
            : 'Aviso ativado. Os gerentes veem ao abrir a gerência, dentro do período.');
    }

    public function excluir(int $id): Resultado
    {
        $a = $this->buscar($id);
        if ($a === null) {
            return Resultado::falha('Aviso não encontrado.');
        }
        $this->db->prepare('DELETE FROM avisos_loja WHERE id = ?')->execute([$id]);
        PopupUpload::apagar($a['imagem_path'] ?? null);
        $this->log('Aviso para as lojas excluído', '"' . $a['titulo'] . '"');
        return Resultado::sucesso('Aviso excluído.');
    }

    /**
     * Aviso que o gerente deve ver agora (o mais antigo ainda dentro do limite do dia) e ja conta a exibicao.
     * Nunca derruba a tela: qualquer erro = sem aviso.
     * @return array{id:int, titulo:string, texto:?string, imagem:?string, botao_texto:?string, botao_url:?string, externo:bool}|null
     */
    public function paraExibir(UsuarioLogado $usuario, int $idRestaurante, string $slug, ?DateTimeImmutable $agora = null): ?array
    {
        if ($usuario->cargo !== 'gerente' || !self::disponivel($this->db)) {
            return null;
        }
        $agora ??= new DateTimeImmutable('now');
        $momento = $agora->format('Y-m-d H:i:s');
        $dia = $agora->format('Y-m-d');
        try {
            $q = $this->db->prepare('SELECT a.* FROM avisos_loja a
                LEFT JOIN avisos_loja_exibicoes e ON e.id_aviso = a.id AND e.id_usuario = ? AND e.dia = ?
                WHERE a.ativo = 1 AND a.inicio <= ? AND a.fim > ? AND COALESCE(e.vezes, 0) < a.vezes_por_dia
                ORDER BY a.inicio, a.id LIMIT 1');
            $q->execute([$usuario->id, $dia, $momento, $momento]);
            $a = $q->fetch(PDO::FETCH_ASSOC);
            if ($a === false) {
                return null;
            }
            $this->db->prepare('INSERT INTO avisos_loja_exibicoes (id_aviso, id_usuario, dia, id_restaurante, vezes, ultima_em)
                VALUES (?, ?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE vezes = vezes + 1, ultima_em = VALUES(ultima_em)')
                ->execute([(int) $a['id'], $usuario->id, $dia, $idRestaurante, $momento]);
        } catch (Throwable $e) {
            error_log('[Cardapim] Aviso para a loja: ' . get_class($e));
            return null;
        }
        return [
            'id' => (int) $a['id'],
            'titulo' => (string) $a['titulo'],
            'texto' => $a['texto'] !== null ? (string) $a['texto'] : null,
            'imagem' => $a['imagem_path'] !== null ? (string) $a['imagem_path'] : null,
            'botao_texto' => $a['botao_texto'] !== null ? (string) $a['botao_texto'] : null,
            'botao_url' => self::link($a, $slug),
            'externo' => ($a['botao_destino'] ?? null) === self::EXTERNO,
        ];
    }

    /** Registra o clique no botao (para o administrador ver quantas lojas agiram). */
    public function clicou(int $id, UsuarioLogado $usuario, int $idRestaurante): void
    {
        if (!self::disponivel($this->db)) {
            return;
        }
        $agora = date('Y-m-d H:i:s');
        $this->db->prepare('INSERT INTO avisos_loja_exibicoes (id_aviso, id_usuario, dia, id_restaurante, vezes, ultima_em, clicou_em)
            SELECT id, ?, ?, ?, 1, ?, ? FROM avisos_loja WHERE id = ?
            ON DUPLICATE KEY UPDATE clicou_em = COALESCE(clicou_em, VALUES(clicou_em))')
            ->execute([$usuario->id, date('Y-m-d'), $idRestaurante, $agora, $agora, $id]);
    }

    /** Endereco do botao para esta loja (tela da loja) ou o link externo; null = sem botao. */
    public static function link(array $aviso, string $slug): ?string
    {
        $destino = $aviso['botao_destino'] ?? null;
        if ($destino === null || $destino === '') {
            return null;
        }
        if ($destino === self::EXTERNO) {
            $url = (string) ($aviso['botao_url'] ?? '');
            return self::urlSegura($url) ? $url : null;
        }
        $d = self::DESTINOS[$destino] ?? null;
        return $d === null ? null : MenuGerencia::url($slug, $d[1]) . $d[2];
    }

    public static function urlSegura(string $url): bool
    {
        return strlen($url) <= 500 && preg_match('~\Ahttps://[^\s<>"\']+\z~i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private static function data(string $valor): ?DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $valor) ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $valor);
        return $d === false ? null : $d;
    }

    private function log(string $titulo, string $descricao): void
    {
        if ($this->autor !== null) {
            Auditoria::registrar($this->autor, $titulo, $descricao);
        }
    }
}
