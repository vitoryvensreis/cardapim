<?php

declare(strict_types=1);

namespace Cardapim\Shared\Html;

use Cardapim\Core\Icon;

/**
 * Campos de formulario padronizados do painel (mesmo visual e comportamento do cadastro):
 * rotulo, icone, mensagem de ajuda/erro e atributos para mascaras e validacao do forms.js.
 */
final class Campo
{
    /**
     * @param array{
     *   nome:string, rotulo:string, valor?:?string, icone?:string, tipo?:string, erro?:?string, dica?:?string,
     *   opcional?:bool, sufixo?:?string, classe?:string, attrs?:array<string,string|int|bool>, id?:string, senha?:bool
     * } $o
     */
    public static function texto(array $o): string
    {
        $attrs = ['type' => $o['tipo'] ?? 'text', 'value' => (string) ($o['valor'] ?? '')] + ($o['attrs'] ?? []);
        if (empty($o['opcional']) && !array_key_exists('required', $attrs)) {
            $attrs['required'] = true;
        }
        $sufixo = array_key_exists('sufixo', $o) && $o['sufixo'] !== null ? '<span class="field-sufixo" aria-hidden="true">' . self::e((string) $o['sufixo']) . '</span>' : '';
        $acao = '';
        if (!empty($o['senha'])) {
            $acao = '<button class="field-action" type="button" data-toggle-password aria-label="Mostrar senha" aria-pressed="false">'
                . Icon::svg('eye', 'icon-show') . Icon::svg('eye-off', 'icon-hide') . '</button>';
            $o['depois'] = '<div class="strength" data-strength aria-hidden="true"><span></span><span></span><span></span><span></span></div>';
        }
        return self::envolver($o, fn (string $id, string $descr): string => '<input id="' . $id . '" name="' . self::e($o['nome']) . '" aria-describedby="' . $descr . '"'
            . self::atributos($attrs) . self::invalido($o) . '>' . $sufixo . $acao);
    }

    /**
     * @param array<string|int,string> $opcoes valor => rotulo
     * @param array{nome:string, rotulo:string, valor?:?string, icone?:string, erro?:?string, dica?:?string, opcional?:bool, classe?:string, attrs?:array<string,string|int|bool>, id?:string, vazio?:?string} $o
     */
    public static function selecao(array $o, array $opcoes): string
    {
        $valor = (string) ($o['valor'] ?? '');
        $html = isset($o['vazio']) ? '<option value="">' . self::e((string) $o['vazio']) . '</option>' : '';
        foreach ($opcoes as $v => $rotulo) {
            $html .= '<option value="' . self::e((string) $v) . '"' . ((string) $v === $valor ? ' selected' : '') . '>' . self::e($rotulo) . '</option>';
        }
        return self::envolver($o, fn (string $id, string $descr): string => '<select id="' . $id . '" name="' . self::e($o['nome']) . '" aria-describedby="' . $descr . '"'
            . self::atributos($o['attrs'] ?? []) . self::invalido($o) . '>' . $html . '</select>');
    }

    /** @param array{nome:string, rotulo:string, valor?:?string, icone?:string, erro?:?string, dica?:?string, opcional?:bool, classe?:string, attrs?:array<string,string|int|bool>, id?:string} $o */
    public static function areaTexto(array $o): string
    {
        return self::envolver($o, fn (string $id, string $descr): string => '<textarea id="' . $id . '" name="' . self::e($o['nome']) . '" aria-describedby="' . $descr . '"'
            . self::atributos($o['attrs'] ?? []) . self::invalido($o) . '>' . self::e((string) ($o['valor'] ?? '')) . '</textarea>');
    }

    /** Chave liga/desliga com titulo e explicacao. */
    public static function alternador(string $nome, string $titulo, string $explicacao, bool $marcado, array $attrs = []): string
    {
        return '<label class="alternador"><span><strong>' . self::e($titulo) . '</strong><small>' . self::e($explicacao) . '</small></span>'
            . '<input type="checkbox" name="' . self::e($nome) . '" value="1"' . ($marcado ? ' checked' : '') . self::atributos($attrs) . '>'
            . '<span class="alternador-chave" aria-hidden="true"></span></label>';
    }

    /** Lista de erros no topo do formulario (com link para cada campo). @param array<string,string> $erros */
    public static function resumoErros(array $erros, string $prefixoId = 'campo-'): string
    {
        if ($erros === []) {
            return '';
        }
        $itens = '';
        foreach ($erros as $campo => $mensagem) {
            $itens .= '<li><a href="#' . self::e($prefixoId . $campo) . '">' . self::e($mensagem) . '</a></li>';
        }
        return '<div class="notice notice-warning erros-form" role="alert" tabindex="-1" data-erros-form>'
            . Icon::svg('triangle-exclamation-point') . '<div><strong>Falta pouco: confira o que está abaixo.</strong><ul>' . $itens . '</ul></div></div>';
    }

    public static function e(string $valor): string
    {
        return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param callable(string,string):string $controle */
    private static function envolver(array $o, callable $controle): string
    {
        $id = self::e($o['id'] ?? 'campo-' . str_replace(['[', ']'], ['-', ''], $o['nome']));
        $erro = $o['erro'] ?? null;
        $dica = (string) ($o['dica'] ?? '');
        $semIcone = empty($o['icone']);
        $classe = 'field' . ($erro ? ' is-invalid' : '') . (!empty($o['classe']) ? ' ' . $o['classe'] : '');
        return '<div class="' . self::e($classe) . '" data-field>'
            . '<label for="' . $id . '">' . self::e($o['rotulo']) . (!empty($o['opcional']) ? ' <span class="field-optional">(opcional)</span>' : '') . '</label>'
            . '<div class="field-control' . ($semIcone ? ' sem-icone' : '') . '">' . ($semIcone ? '' : Icon::svg((string) $o['icone'], 'field-icon'))
            . $controle($id, $id . '-msg') . '</div>' . ($o['depois'] ?? '')
            . '<p class="field-msg" id="' . $id . '-msg" aria-live="polite" data-hint="' . self::e($dica) . '">' . self::e($erro ?: $dica) . '</p>'
            . '</div>';
    }

    private static function invalido(array $o): string
    {
        return !empty($o['erro']) ? ' aria-invalid="true"' : '';
    }

    /** @param array<string,string|int|bool> $attrs */
    private static function atributos(array $attrs): string
    {
        $html = '';
        foreach ($attrs as $nome => $valor) {
            if (is_int($nome)) { // ['data-preco'] = atributo sem valor
                [$nome, $valor] = [(string) $valor, true];
            }
            if (preg_match('/\A[a-z][a-z0-9-]*\z/', $nome) !== 1 || $valor === false) {
                continue;
            }
            $html .= $valor === true ? ' ' . $nome : ' ' . $nome . '="' . self::e((string) $valor) . '"';
        }
        return $html;
    }
}
