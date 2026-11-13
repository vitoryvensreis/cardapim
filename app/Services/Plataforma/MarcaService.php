<?php

declare(strict_types=1);

namespace Cardapim\Services\Plataforma;

use Cardapim\Modules\Marca\MarcaUpload;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Configuracoes;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Seo\Seo;
use PDO;
use RuntimeException;

/**
 * Marca da plataforma (Configuracoes do painel do adm_master): nome, textos e logos guardados na tabela
 * configuracoes. Todo o sistema le daqui (marca(), Configuracoes::logoOuPadrao), entao trocar aqui troca em tudo.
 */
final class MarcaService
{
    /** chave => [rotulo, onde aparece, fundo da previa, aceita SVG] */
    public const LOGOS = [
        'logo_preta' => ['Logo para fundo claro', 'Cabeçalho do site, login, cadastro, cardápio e páginas legais.', 'claro', true],
        'logo_branca' => ['Logo branca', 'Rodapé do site e menu lateral dos painéis.', 'escuro', true],
        'logo_colorida' => ['Logo para fundo escuro', 'Topo da página inicial (fundo vinho).', 'escuro', true],
        'icone' => ['Ícone (favicon)', 'Aba do navegador. Quadrado, de preferência SVG.', 'claro', true],
        'icone_app' => ['Ícone do app no celular', 'Tela inicial do celular quando a equipe instala o app. PNG quadrado de 512 x 512 ou maior.', 'claro', false],
    ];

    public const TEXTOS = [
        'nome_sistema' => ['Nome da plataforma', 40, 'Nome publico da plataforma'],
        'descricao_padrao' => ['Descrição curta', 160, 'Descricao padrao de paginas'],
        'texto_rodape' => ['Frase do rodapé', 120, 'Mensagem padrao no rodape'],
    ];

    /** Google e redes sociais: chave => [rotulo, maximo, descricao no banco]. Vazio = texto padrao (Services\Seo\Seo). */
    public const SEO = [
        'seo_titulo' => ['Título da página inicial', Seo::TITULO_MAX, 'Titulo da pagina inicial no Google e nas redes'],
        'seo_descricao' => ['Descrição da página inicial', Seo::DESCRICAO_MAX, 'Descricao da pagina inicial no Google e nas redes'],
        'frase_compartilhamento' => ['Frase da imagem do link', Seo::FRASE_MAX, 'Frase do cartao automatico do link'],
        'google_verificacao' => ['Código de verificação do Google', 100, 'Google Search Console'],
    ];

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor)
    {
    }

    /** Valores atuais direto do banco (sem o cache da requisicao). @return array<string,string> */
    public function atuais(): array
    {
        $chaves = [...array_keys(self::TEXTOS), ...array_keys(self::LOGOS), ...array_keys(self::SEO), 'imagem_compartilhamento'];
        $q = $this->db->prepare('SELECT chave, valor FROM configuracoes WHERE chave IN (' . implode(',', array_fill(0, count($chaves), '?')) . ')');
        $q->execute($chaves);
        $valores = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        $r = [];
        foreach (self::TEXTOS as $chave => $_) {
            $r[$chave] = (string) ($valores[$chave] ?? ($chave === 'nome_sistema' ? Configuracoes::NOME_PADRAO : ''));
        }
        foreach (self::LOGOS as $chave => $_) {
            $v = (string) ($valores[$chave] ?? '');
            $r[$chave] = $v !== '' ? $v : Configuracoes::LOGO_FALLBACK[$chave];
        }
        foreach ([...array_keys(self::SEO), 'imagem_compartilhamento'] as $chave) {
            $r[$chave] = (string) ($valores[$chave] ?? '');
        }
        return $r;
    }

    /**
     * Titulo, descricao e frase do link e o codigo do Google. Campo vazio volta ao texto padrao.
     * @param array<string,mixed> $d
     */
    public function salvarSeo(array $d): Resultado
    {
        $erros = [];
        $novos = [];
        foreach (self::SEO as $chave => [$rotulo, $max]) {
            $v = trim((string) preg_replace('/\s+/u', ' ', is_string($d[$chave] ?? null) ? $d[$chave] : ''));
            if ($chave === 'google_verificacao' && $v !== '') {
                // Aceita a etiqueta inteira copiada do Google: fica so o codigo.
                if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $v, $m) === 1) {
                    $v = trim($m[1]);
                }
                if (preg_match('/\A[A-Za-z0-9_-]{10,100}\z/', $v) !== 1) {
                    $erros[$chave] = 'Cole o código que o Google mostra (letras, números, - e _), sem espaços.';
                }
            } elseif (mb_strlen($v) > $max) {
                $erros[$chave] = 'Use no máximo ' . $max . ' caracteres (agora tem ' . mb_strlen($v) . ').';
            } elseif ($v !== '' && mb_strlen($v) < 10) {
                $erros[$chave] = 'Escreva um pouco mais (pelo menos 10 letras) ou deixe vazio para usar o texto padrão.';
            }
            $novos[$chave] = $v;
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        $antes = $this->atuais();
        foreach ($novos as $chave => $valor) {
            $this->gravar($chave, $valor, 'texto', self::SEO[$chave][2]);
        }
        Configuracoes::esquecer();
        $rotulos = array_map(static fn (array $t): string => $t[0], self::SEO);
        Auditoria::registrar($this->autor, 'SEO: textos alterados', Auditoria::diferencas(array_intersect_key($antes, $novos), $novos, $rotulos));
        return Resultado::sucesso('Pronto! Os novos textos já valem para o Google e para os links compartilhados.');
    }

    /** @param array<string,mixed> $arquivo item de $_FILES */
    public function enviarImagemCompartilhamento(array $arquivo): Resultado
    {
        try {
            $caminho = MarcaUpload::salvarCompartilhamento($arquivo);
        } catch (RuntimeException $erro) {
            return Resultado::falha($erro->getMessage());
        }
        $antes = $this->atuais()['imagem_compartilhamento'];
        $this->gravar('imagem_compartilhamento', $caminho, 'imagem', 'Imagem do link compartilhado');
        Configuracoes::esquecer();
        Auditoria::registrar($this->autor, 'SEO: imagem do link trocada', sprintf('%s -> %s', $antes !== '' ? $antes : 'automática', $caminho));
        return Resultado::sucesso('Imagem do link trocada. Os próximos links compartilhados já mostram a nova imagem.');
    }

    public function voltarImagemAutomatica(): Resultado
    {
        $this->gravar('imagem_compartilhamento', '', 'imagem', 'Imagem do link compartilhado');
        Configuracoes::esquecer();
        Auditoria::registrar($this->autor, 'SEO: imagem do link automática', 'Voltou à imagem gerada com o ícone, o nome e a frase.');
        return Resultado::sucesso('Pronto! O link volta a usar a imagem automática, com o ícone, o nome e a frase.');
    }

    /** @param array<string,mixed> $d */
    public function salvarTextos(array $d): Resultado
    {
        $erros = [];
        $novos = [];
        foreach (self::TEXTOS as $chave => [$rotulo, $max]) {
            $v = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', is_string($d[$chave] ?? null) ? $d[$chave] : ''));
            if ($chave === 'nome_sistema' && mb_strlen($v) < 2) {
                $erros[$chave] = 'Digite o nome da plataforma (2 a 40 letras).';
            } elseif (mb_strlen($v) > $max) {
                $erros[$chave] = 'Use no máximo ' . $max . ' caracteres.';
            }
            $novos[$chave] = $v;
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        $antes = $this->atuais();
        foreach ($novos as $chave => $valor) {
            $this->gravar($chave, $valor, 'texto', self::TEXTOS[$chave][2]);
        }
        Configuracoes::esquecer();
        $rotulos = array_map(static fn (array $t): string => $t[0], self::TEXTOS);
        Auditoria::registrar($this->autor, 'Marca: textos alterados', Auditoria::diferencas($antes, $novos, $rotulos));
        return Resultado::sucesso('Pronto! O novo nome e os textos já aparecem em todo o sistema.');
    }

    /** @param array<string,mixed> $arquivo item de $_FILES */
    public function enviarLogo(string $chave, array $arquivo): Resultado
    {
        if (!isset(self::LOGOS[$chave])) {
            return Resultado::falha('Escolha qual logo trocar.');
        }
        try {
            $caminho = MarcaUpload::salvar($arquivo, $chave === 'icone_app');
        } catch (RuntimeException $erro) {
            return Resultado::falha($erro->getMessage());
        }
        $antes = $this->atuais()[$chave];
        $this->gravar($chave, $caminho, 'imagem', self::LOGOS[$chave][0]);
        Configuracoes::esquecer();
        Auditoria::registrar($this->autor, 'Marca: logo trocada', sprintf('%s: %s -> %s', self::LOGOS[$chave][0], $antes, $caminho));
        return Resultado::sucesso(self::LOGOS[$chave][0] . ' trocada. Já aparece em todo o sistema.');
    }

    public function voltarPadrao(string $chave): Resultado
    {
        if (!isset(self::LOGOS[$chave])) {
            return Resultado::falha('Escolha qual logo voltar ao padrão.');
        }
        $this->gravar($chave, Configuracoes::LOGO_FALLBACK[$chave], 'imagem', self::LOGOS[$chave][0]);
        Configuracoes::esquecer();
        Auditoria::registrar($this->autor, 'Marca: logo padrão', self::LOGOS[$chave][0] . ' voltou ao arquivo original.');
        return Resultado::sucesso(self::LOGOS[$chave][0] . ' voltou ao padrão.');
    }

    /** UPDATE ou INSERT (sem depender de ON DUPLICATE KEY, igual no MySQL e nos testes). */
    private function gravar(string $chave, string $valor, string $tipo, string $descricao): void
    {
        $q = $this->db->prepare('SELECT 1 FROM configuracoes WHERE chave = ?');
        $q->execute([$chave]);
        if ($q->fetchColumn() !== false) {
            $this->db->prepare('UPDATE configuracoes SET valor = ? WHERE chave = ?')->execute([$valor, $chave]);
            return;
        }
        $this->db->prepare('INSERT INTO configuracoes (chave, valor, tipo, descricao) VALUES (?, ?, ?, ?)')->execute([$chave, $valor, $tipo, $descricao]);
    }
}
