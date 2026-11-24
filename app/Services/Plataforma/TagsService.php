<?php
declare(strict_types=1);

namespace Cardapim\Services\Plataforma;

use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Configuracoes;
use Cardapim\Services\Gerencia\Resultado;
use PDO;
use Throwable;

/** Configuracao publica, sem HTML ou JavaScript fornecido pelo administrador. */
final class TagsService
{
    public const CHAVE = 'marketing_tags';
    public const PROVEDORES = [
        'ga4' => ['Google Analytics 4', 'G-XXXXXXXXXX', '/\AG-[A-Z0-9]{4,20}\z/'],
        'meta' => ['Meta Pixel', '123456789012345', '/\A[0-9]{5,25}\z/'],
        'gtm' => ['Google Tag Manager', 'GTM-XXXXXXX', '/\AGTM-[A-Z0-9]{4,20}\z/'],
    ];
    // Escopo explicito, apenas paginas institucionais sem formularios de dados pessoais.
    public const ESCOPOS = ['inicio' => 'Página inicial', 'catalogo' => 'Catálogo de restaurantes'];

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor) {}

    public static function padrao(): array
    {
        return array_fill_keys(array_keys(self::PROVEDORES), ['id' => '', 'ativo' => false, 'escopos' => []]);
    }

    /** @return array{0:array,1:array} Configuracao normalizada e erros. */
    public static function validar(array $entrada): array
    {
        $dados = self::padrao();
        $erros = [];
        foreach (self::PROVEDORES as $chave => [$nome, , $regex]) {
            $v = $entrada[$chave] ?? [];
            if (!is_array($v)) {
                $erros[$chave] = 'Configuração inválida.';
                continue;
            }
            $id = $v['id'] ?? '';
            $ativo = $v['ativo'] ?? false;
            $escopos = $v['escopos'] ?? [];
            if (!is_string($id) || !is_array($escopos) || !in_array($ativo, [false, true, '0', '1'], true)) {
                $erros[$chave] = 'Configuração inválida.';
                continue;
            }
            $id = trim($id);
            $ativo = $ativo === true || $ativo === '1';
            $validos = [];
            foreach ($escopos as $escopo) {
                if (!is_string($escopo) || !isset(self::ESCOPOS[$escopo])) {
                    $erros[$chave] = 'Página não autorizada.';
                    continue;
                }
                $validos[$escopo] = true;
            }
            $dados[$chave] = ['id' => mb_substr($id, 0, 64), 'ativo' => $ativo, 'escopos' => array_keys($validos)];
            if (($id !== '' && preg_match($regex, $id) !== 1) || ($ativo && $id === '')) {
                $erros[$chave] = 'Informe um identificador válido de ' . $nome . '.';
            } elseif ($ativo && $validos === []) {
                $erros[$chave] = 'Escolha pelo menos uma página para ativar esta integração.';
            }
        }
        // Um container GTM pode conter os mesmos pixels: nao misturar no mesmo escopo.
        if ($dados['gtm']['ativo']) {
            foreach (['ga4', 'meta'] as $chave) {
                if ($dados[$chave]['ativo'] && array_intersect($dados['gtm']['escopos'], $dados[$chave]['escopos'])) {
                    $erros['gtm'] = 'Use o Tag Manager ou as tags diretas em cada página, não ambos.';
                }
            }
        }
        return [$dados, $erros];
    }

    public static function atuais(): array
    {
        try {
            $raw = Configuracoes::get(self::CHAVE);
            $dados = json_decode($raw ?? '', true);
            if (!is_array($dados)) {
                return self::padrao();
            }
            [$validos, $erros] = self::validar($dados);
            return $erros === [] ? $validos : self::padrao();
        } catch (Throwable $exception) {
            error_log('[Cardapim] Tags indisponíveis: ' . get_class($exception));
            return self::padrao();
        }
    }

    public function salvar(array $entrada): Resultado
    {
        [$dados, $erros] = self::validar($entrada);
        if ($erros !== []) {
            return Resultado::falha('Confira as configurações destacadas.', $erros);
        }
        $antes = self::atuais();
        $json = json_encode($dados, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $q = $this->db->prepare("INSERT INTO configuracoes (chave, valor, tipo, descricao) VALUES (?, ?, 'texto', ?) ON DUPLICATE KEY UPDATE valor = ?");
        $q->execute([self::CHAVE, $json, 'Tags de marketing da plataforma', $json]);
        Configuracoes::esquecer();
        $rotulos = $de = $para = [];
        foreach (self::PROVEDORES as $chave => [$nome]) {
            $rotulos[$chave] = $nome;
            $de[$chave] = self::resumo($antes[$chave]);
            $para[$chave] = self::resumo($dados[$chave]);
        }
        Auditoria::registrar($this->autor, 'Tags de marketing: configurações alteradas', Auditoria::diferencas($de, $para, $rotulos));
        return Resultado::sucesso('Configurações de rastreamento salvas.');
    }

    private static function resumo(array $v): string
    {
        return ($v['ativo'] ? 'ativo' : 'inativo') . ' / ' . $v['id'] . ' / ' . implode(', ', $v['escopos']);
    }

    /** Nao consulta o banco em paginas sem escopo explicito. */
    public static function publicas(?string $escopo): array
    {
        if ($escopo === null || !isset(self::ESCOPOS[$escopo])) {
            return [];
        }
        $tags = [];
        foreach (self::atuais() as $chave => $v) {
            if ($v['ativo'] && in_array($escopo, $v['escopos'], true)) {
                $tags[$chave] = $v['id'];
            }
        }
        return $tags;
    }
}
