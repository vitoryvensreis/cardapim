<?php

declare(strict_types=1);

namespace Cardapim\Services\Plataforma;

use Cardapim\Repositories\PlanoRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\AssinaturaService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Numero;
use PDO;

/**
 * Planos da plataforma (painel do adm_master): criar, editar, pausar, link de convite e excluir.
 * - Publico: aparece na pagina inicial e no cadastro.
 * - Privado: so quem recebe o link de convite (/cadastro?convite=...) consegue assinar.
 * - Vitalicio: sempre gratuito, nunca cobra (parceiros).
 * Mudar o preco nao muda quem ja assinou (a assinatura guarda o valor contratado).
 */
final class PlanoService
{
    public const PERIODOS = [
        'mensal' => ['Mensal', 'Cobrado todo mês'],
        'semestral' => ['Semestral', 'Cobrado a cada 6 meses'],
        'anual' => ['Anual', 'Cobrado uma vez por ano'],
        'vitalicio' => ['Vitalício', 'Grátis para sempre, nunca cobra'],
    ];

    private readonly PlanoRepository $planos;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor)
    {
        $this->planos = new PlanoRepository($db);
    }

    /** @param array<string,mixed> $d */
    public function salvar(?int $id, array $d): Resultado
    {
        $atual = $id !== null ? $this->planos->buscar($id) : null;
        if ($id !== null && $atual === null) {
            return Resultado::falha('Plano não encontrado.');
        }
        $t = static fn (string $c): string => trim(is_string($d[$c] ?? null) ? $d[$c] : '');
        $erros = [];
        $nome = $t('nome');
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 120) {
            $erros['nome'] = 'Dê um nome de 2 a 120 letras (ex.: Profissional, Parceiro).';
        }
        $descricao = $t('descricao');
        if (mb_strlen($descricao) > 500) {
            $erros['descricao'] = 'Use no máximo 500 caracteres.';
        }
        $periodo = $t('periodo');
        if (!isset(self::PERIODOS[$periodo])) {
            $erros['periodo'] = 'Escolha de quanto em quanto tempo o plano é cobrado.';
        }
        $valor = '0.00';
        if ($periodo !== 'vitalicio') {
            $v = Numero::dinheiroParaDecimal($t('valor') === '' ? '' : $t('valor'));
            if ($v === null || (float) $v < 0 || (float) $v > 99999.99) {
                $erros['valor'] = 'Digite o preço, como 149,90. Use 0 para um plano grátis.';
            } else {
                $valor = $v;
            }
        }
        $privado = $t('visibilidade') === 'privado';
        $ativo = !empty($d['ativo']) ? 1 : 0;
        if (!isset($erros['nome']) && isset(self::PERIODOS[$periodo]) && $this->planos->nomeEmUso($nome, $periodo, $id)) {
            $erros['nome'] = 'Já existe um plano ' . self::PERIODOS[$periodo][0] . ' com esse nome.';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }

        // Privado sempre tem convite; publico tambem guarda o codigo (se ja tinha) para o link continuar valendo.
        $convite = $atual['codigo_convite'] ?? null;
        if ($privado && $convite === null) {
            $convite = self::novoConvite();
        }
        $dados = [
            'nome' => $nome,
            'descricao' => $descricao !== '' ? $descricao : null,
            'valor' => $valor,
            'periodo' => $periodo,
            'ativo' => $ativo,
            'visibilidade' => $privado ? 0 : 1,
            'codigo_convite' => $convite,
        ];
        $resumo = sprintf('%s · %s · %s · %s', $nome, AssinaturaService::preco($valor, $periodo), $privado ? 'privado (convite)' : 'público', $ativo ? 'aceitando assinaturas' : 'pausado');
        if ($atual === null) {
            $id = $this->planos->criar($dados);
            Auditoria::registrar($this->autor, 'Plano criado', $resumo);
            return Resultado::sucesso($privado ? 'Plano criado. Copie o link de convite e envie para o restaurante.' : ($ativo ? 'Plano criado e já aparece na página inicial.' : 'Plano criado (pausado).'), $id);
        }
        $this->planos->atualizar($id, $dados);
        $mudou = Auditoria::diferencas([
            'nome' => $atual['nome'],
            'preco' => AssinaturaService::preco((string) $atual['valor'], (string) $atual['periodo']),
            'quem' => (int) $atual['visibilidade'] === 1 ? 'público' : 'convite',
            'situacao' => (int) $atual['ativo'] === 1 ? 'aceitando' : 'pausado',
        ], [
            'nome' => $nome,
            'preco' => AssinaturaService::preco($valor, $periodo),
            'quem' => $privado ? 'convite' : 'público',
            'situacao' => $ativo ? 'aceitando' : 'pausado',
        ], ['nome' => 'Nome', 'preco' => 'Preço', 'quem' => 'Quem assina', 'situacao' => 'Situação']);
        Auditoria::registrar($this->autor, 'Plano alterado', $nome . ' - ' . $mudou);
        return Resultado::sucesso('Plano salvo. Quem já assinou continua com o valor contratado.', $id);
    }

    public function novoLink(int $id): Resultado
    {
        $plano = $this->planos->buscar($id);
        if ($plano === null) {
            return Resultado::falha('Plano não encontrado.');
        }
        $this->planos->trocarConvite($id, self::novoConvite());
        Auditoria::registrar($this->autor, 'Link de convite trocado', 'Plano ' . $plano['nome'] . ': o link antigo deixou de funcionar.');
        return Resultado::sucesso('Novo link criado. O link antigo não funciona mais.');
    }

    public function alternar(int $id): Resultado
    {
        $plano = $this->planos->buscar($id);
        if ($plano === null) {
            return Resultado::falha('Plano não encontrado.');
        }
        $ativo = (int) $plano['ativo'] !== 1;
        $this->planos->mudarAtivo($id, $ativo);
        Auditoria::registrar($this->autor, $ativo ? 'Plano liberado' : 'Plano pausado', (string) $plano['nome']);
        return Resultado::sucesso($ativo ? 'Plano liberado para novas assinaturas.' : 'Plano pausado. Quem já assinou continua normalmente.');
    }

    public function excluir(int $id): Resultado
    {
        $plano = $this->planos->buscar($id);
        if ($plano === null) {
            return Resultado::falha('Plano não encontrado.');
        }
        if ($this->planos->emUso($id)) {
            return Resultado::falha('Este plano já foi assinado e faz parte do histórico. Pause o plano para ninguém mais assinar.');
        }
        $this->planos->excluir($id);
        Auditoria::registrar($this->autor, 'Plano excluído', (string) $plano['nome']);
        return Resultado::sucesso('Plano excluído.');
    }

    public static function novoConvite(): string
    {
        return bin2hex(random_bytes(12));
    }
}
