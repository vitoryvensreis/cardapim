<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Modules\Catalog\ImagemCardapio;
use Cardapim\Modules\Inventory\Quantidade;
use Cardapim\Repositories\Estoque\ComplementoRepository;
use Cardapim\Repositories\Estoque\InsumoRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Numero;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Cadastro dos grupos de complementos (adicionais, bordas, molhos...) com as opcoes, fotos e ficha tecnica.
 * Um formulario so por grupo: nome, minimo/maximo de escolhas e a lista de opcoes.
 */
final class ComplementoService
{
    public const MAX_OPCOES = 50;
    public const MAX_ESCOLHAS = 30;

    private readonly ComplementoRepository $repo;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor, private readonly int $idRestaurante)
    {
        $this->repo = new ComplementoRepository($db);
    }

    /**
     * Cria ou atualiza o grupo e as opcoes.
     * $entrada: nome, minimo, maximo, opcoes[i][id|nome|preco|codigo|descricao|disponivel|remover|insumo|quantidade|tirar_foto].
     * $fotos: $_FILES['opcao_foto'] normalizado por indice (i => arquivo).
     * @param array<string,mixed> $entrada @param array<int|string,array<string,mixed>> $fotos
     */
    public function salvar(?int $id, array $entrada, array $fotos = []): Resultado
    {
        $antes = null;
        if ($id !== null) {
            $antes = $this->repo->grupo($this->idRestaurante, $id);
            if ($antes === null) {
                return Resultado::falha('Grupo não encontrado. Ele pode ter sido excluído.');
            }
        }
        $existentes = [];
        foreach ($antes['opcoes'] ?? [] as $o) {
            $existentes[$o['id']] = $o;
        }

        $erros = [];
        $nome = InsumoService::texto($entrada['nome'] ?? '');
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 80) {
            $erros['nome'] = 'Digite o nome do grupo (2 a 80 letras). Exemplo: Adicionais';
        }
        $minimo = self::inteiro($entrada['minimo'] ?? '0');
        $maximo = self::inteiro($entrada['maximo'] ?? '1');
        if ($minimo === null || $minimo < 0 || $minimo > self::MAX_ESCOLHAS) {
            $erros['minimo'] = 'Digite de 0 a ' . self::MAX_ESCOLHAS . '. Use 0 para escolha opcional.';
        }
        if ($maximo === null || $maximo < 1 || $maximo > self::MAX_ESCOLHAS) {
            $erros['maximo'] = 'Digite de 1 a ' . self::MAX_ESCOLHAS . '.';
        } elseif ($minimo !== null && $minimo > $maximo) {
            $erros['maximo'] = 'O máximo precisa ser igual ou maior que o mínimo.';
        }

        [$opcoes, $removidas, $errosOpcoes] = $this->validarOpcoes($entrada['opcoes'] ?? [], $existentes);
        $erros += $errosOpcoes;
        if ($opcoes === [] && !isset($erros['opcoes'])) {
            $erros['opcoes'] = 'Cadastre pelo menos uma opção.';
        } elseif ($minimo !== null && $minimo > 0 && $minimo > count(array_filter($opcoes, static fn (array $o): bool => $o['disponivel'] === 1))) {
            $erros['minimo'] = 'O mínimo é maior que o número de opções disponíveis.';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }

        // Fotos: recebidas antes de gravar (se uma falhar, nada e gravado e o gerente ve o erro na linha certa).
        $novasFotos = [];
        foreach ($opcoes as $i => $o) {
            $arquivo = $fotos[$i] ?? null;
            if (is_array($arquivo) && (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $novasFotos[$i] = ImagemCardapio::salvar($arquivo);
                } catch (RuntimeException $erro) {
                    $erros['opcoes.' . $i . '.foto'] = $erro->getMessage();
                }
            }
        }
        if ($erros !== []) {
            array_map([ImagemCardapio::class, 'apagar'], $novasFotos);
            return Resultado::falha('Confira os campos destacados.', $erros);
        }

        $fotosVelhas = [];
        $this->db->beginTransaction();
        try {
            if ($antes === null) {
                $id = $this->repo->criarGrupo($this->idRestaurante, $nome, (int) $minimo, (int) $maximo, $this->repo->proximaOrdemGrupo($this->idRestaurante));
            } else {
                $this->repo->atualizarGrupo($this->idRestaurante, $id, $nome, (int) $minimo, (int) $maximo);
            }
            foreach ($removidas as $idOpcao) {
                $this->repo->arquivarOpcao($this->idRestaurante, $idOpcao);
            }
            $ordem = 0;
            foreach ($opcoes as $i => $o) {
                $imagemAtual = $o['id'] !== null ? ($existentes[$o['id']]['imagem_path'] ?? null) : null;
                $imagem = $novasFotos[$i] ?? ($o['tirar_foto'] ? null : $imagemAtual);
                if ($imagemAtual !== null && $imagem !== $imagemAtual) {
                    $fotosVelhas[] = $imagemAtual;
                }
                $dados = ['nome' => $o['nome'], 'descricao' => $o['descricao'], 'preco' => $o['preco'], 'codigo' => $o['codigo'], 'imagem_path' => $imagem,
                    'disponivel' => $o['disponivel'], 'ordem' => $ordem++];
                $idOpcao = $o['id'];
                if ($idOpcao === null) {
                    $idOpcao = $this->repo->criarOpcao($this->idRestaurante, (int) $id, $dados);
                } else {
                    $this->repo->atualizarOpcao($this->idRestaurante, $idOpcao, $dados);
                }
                $this->repo->salvarFicha($this->idRestaurante, $idOpcao, $o['ficha']);
            }
            $this->db->commit();
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            array_map([ImagemCardapio::class, 'apagar'], $novasFotos);
            if ($erro instanceof PDOException && $erro->getCode() === '23000') {
                return Resultado::falha('Confira os campos destacados.', ['opcoes' => 'Algum código já está em uso por outra opção.']);
            }
            throw $erro;
        }
        array_map([ImagemCardapio::class, 'apagar'], $fotosVelhas);

        $resumo = implode(', ', array_map(static fn (array $o): string => $o['nome'] . ' ' . Numero::reais($o['preco']) . ($o['disponivel'] ? '' : ' (pausado)'), $opcoes));
        $limites = sprintf('mínimo %d, máximo %d', (int) $minimo, (int) $maximo);
        if ($antes === null) {
            Auditoria::registrar($this->autor, 'Complementos: grupo cadastrado', mb_substr($nome . ' (' . $limites . '): ' . $resumo, 0, 900));
            return Resultado::sucesso('Grupo "' . $nome . '" cadastrado. Agora escolha em quais produtos ele aparece.', (int) $id);
        }
        Auditoria::registrar($this->autor, 'Complementos: grupo editado', mb_substr($antes['nome'] . ' -> ' . $nome . ' (' . $limites . '): ' . $resumo
            . ($removidas !== [] ? '; ' . count($removidas) . ' opção(ões) removida(s)' : ''), 0, 900));
        return Resultado::sucesso('Grupo "' . $nome . '" atualizado.', (int) $id);
    }

    /** Pausar/voltar a vender uma opcao sem abrir o formulario. */
    public function alternarOpcao(int $idOpcao): Resultado
    {
        $o = $this->repo->opcao($this->idRestaurante, $idOpcao);
        if ($o === null) {
            return Resultado::falha('Opção não encontrada.');
        }
        $novo = (int) $o['disponivel'] !== 1;
        $this->repo->definirDisponivel($this->idRestaurante, $idOpcao, $novo);
        Auditoria::registrar($this->autor, $novo ? 'Complemento liberado' : 'Complemento pausado', $o['grupo'] . ': ' . $o['nome'] . '.');
        return Resultado::sucesso($novo ? '"' . $o['nome'] . '" voltou a ser vendido.' : '"' . $o['nome'] . '" foi pausado.', $idOpcao);
    }

    public function excluirGrupo(int $id): Resultado
    {
        $g = $this->repo->grupo($this->idRestaurante, $id);
        if ($g === null) {
            return Resultado::falha('Grupo não encontrado. Ele pode já ter sido excluído.');
        }
        $this->db->beginTransaction();
        try {
            $this->repo->arquivarGrupo($this->idRestaurante, $id);
            $this->db->commit();
        } catch (Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        Auditoria::registrar($this->autor, 'Complementos: grupo excluído', $g['nome'] . ' (' . count($g['opcoes']) . ' opções).');
        return Resultado::sucesso('Grupo "' . $g['nome'] . '" excluído. Ele saiu de todos os produtos. Os pedidos antigos continuam com os complementos escolhidos.');
    }

    /**
     * @param array<int,array<string,mixed>> $existentes
     * @return array{0: array<int|string, array{id:?int, nome:string, descricao:?string, preco:string, codigo:?string, disponivel:int, tirar_foto:bool, ficha:array<int,string>}>, 1: list<int>, 2: array<string,string>}
     */
    private function validarOpcoes(mixed $linhas, array $existentes): array
    {
        if (!is_array($linhas)) {
            return [[], [], []];
        }
        $mapaInsumos = (new InsumoRepository($this->db))->mapaParaFicha($this->idRestaurante);
        $opcoes = [];
        $removidas = [];
        $erros = [];
        $nomes = [];
        $codigos = [];
        foreach ($linhas as $i => $l) {
            if (!is_array($l) || (!is_int($i) && !ctype_digit((string) $i))) {
                continue;
            }
            $idOpcao = isset($l['id']) && is_string($l['id']) && ctype_digit($l['id']) ? (int) $l['id'] : null;
            if ($idOpcao !== null && !isset($existentes[$idOpcao])) {
                $idOpcao = null; // id de outro grupo ou restaurante: tratado como opcao nova
            }
            $nome = InsumoService::texto($l['nome'] ?? '');
            $precoTexto = is_string($l['preco'] ?? null) ? trim($l['preco']) : '';
            if (($l['remover'] ?? '') === '1') {
                if ($idOpcao !== null) {
                    $removidas[] = $idOpcao;
                }
                continue;
            }
            if ($nome === '' && $precoTexto === '' && $idOpcao === null) {
                continue; // linha nova vazia
            }
            if (count($opcoes) >= self::MAX_OPCOES) {
                $erros['opcoes'] = 'Cada grupo aceita no máximo ' . self::MAX_OPCOES . ' opções.';
                break;
            }
            $campo = 'opcoes.' . $i;
            if (mb_strlen($nome) < 1 || mb_strlen($nome) > 120) {
                $erros[$campo . '.nome'] = 'Digite o nome da opção (até 120 letras).';
            } elseif (isset($nomes[mb_strtolower($nome)])) {
                $erros[$campo . '.nome'] = '"' . $nome . '" aparece duas vezes neste grupo.';
            }
            $nomes[mb_strtolower($nome)] = true;
            $preco = $precoTexto === '' ? '0.00' : Numero::dinheiroParaDecimal($precoTexto);
            if ($preco === null) {
                $erros[$campo . '.preco'] = 'Preço inválido. Exemplo: 4,50 (ou deixe 0,00 se for grátis).';
            }
            $codigo = InsumoService::texto($l['codigo'] ?? '');
            if ($codigo !== '' && preg_match('/\A[A-Za-z0-9._-]{1,40}\z/', $codigo) !== 1) {
                $erros[$campo . '.codigo'] = 'Código: até 40 letras, números, ponto, hífen ou sublinhado.';
            } elseif ($codigo !== '' && (isset($codigos[$codigo]) || $this->repo->codigoEmUso($this->idRestaurante, $codigo, $idOpcao))) {
                $erros[$campo . '.codigo'] = 'Outra opção já usa este código.';
            }
            $codigos[$codigo] = true;
            $descricao = is_string($l['descricao'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $l['descricao'])) : '';
            if (mb_strlen($descricao) > 255) {
                $erros[$campo . '.descricao'] = 'Descrição com até 255 letras.';
            }
            $ficha = [];
            $idInsumo = is_string($l['insumo'] ?? null) ? trim($l['insumo']) : '';
            $qtdTexto = is_string($l['quantidade'] ?? null) ? trim($l['quantidade']) : '';
            if ($idInsumo !== '' || $qtdTexto !== '') {
                if (!ctype_digit($idInsumo) || !isset($mapaInsumos[(int) $idInsumo])) {
                    $erros[$campo . '.insumo'] = 'Escolha o insumo que esta opção gasta (ou deixe em branco).';
                } else {
                    try {
                        $m = Quantidade::paraMilesimos($qtdTexto);
                    } catch (InvalidArgumentException) {
                        $m = 0;
                    }
                    if ($m <= 0 || $m > 999_999_999_999) {
                        $erros[$campo . '.insumo'] = 'Digite quanto de "' . $mapaInsumos[(int) $idInsumo]['nome'] . '" vai em cada opção. Exemplo: 0,030';
                    } else {
                        $ficha[(int) $idInsumo] = Quantidade::paraDecimal($m);
                    }
                }
            }
            $opcoes[$i] = [
                'id' => $idOpcao, 'nome' => $nome, 'descricao' => $descricao !== '' ? $descricao : null, 'preco' => $preco ?? '0.00',
                'codigo' => $codigo !== '' ? $codigo : null, 'disponivel' => ($l['disponivel'] ?? '') === '1' ? 1 : 0,
                'tirar_foto' => ($l['tirar_foto'] ?? '') === '1', 'ficha' => $ficha,
            ];
        }
        return [$opcoes, $removidas, $erros];
    }

    private static function inteiro(mixed $v): ?int
    {
        $v = is_string($v) || is_int($v) ? trim((string) $v) : '';
        return $v !== '' && ctype_digit($v) ? (int) $v : null;
    }
}
