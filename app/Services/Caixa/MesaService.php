<?php

declare(strict_types=1);

namespace Cardapim\Services\Caixa;

use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Caixa\MesaRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use PDO;

/** Cadastro das mesas: criar (uma ou varias de uma vez), editar, pausar e excluir. */
final class MesaService
{
    private const MAX_MESAS = 300;

    private readonly MesaRepository $repo;

    public function __construct(private readonly PDO $db, private readonly int $idRestaurante, private readonly UsuarioLogado $autor)
    {
        $this->repo = new MesaRepository($db);
    }

    /** @return list<array<string,mixed>> */
    public function listar(): array
    {
        return $this->repo->listar($this->idRestaurante);
    }

    public static function rotulo(array $mesa): string
    {
        $apelido = trim((string) ($mesa['apelido'] ?? ''));
        $numero = $mesa['numero'] ?? null;
        if ($numero === null) {
            return $apelido !== '' ? $apelido : 'Mesa excluída';
        }
        return 'Mesa ' . (int) $numero . ($apelido !== '' ? ' · ' . $apelido : '');
    }

    /** @param array<string,string> $d numero, apelido, lugares */
    public function criar(array $d): Resultado
    {
        $v = $this->validar($d, null);
        if ($v instanceof Resultado) {
            return $v;
        }
        if (count($this->listar()) >= self::MAX_MESAS) {
            return Resultado::falha('Limite de ' . self::MAX_MESAS . ' mesas atingido.');
        }
        $id = $this->repo->criar($this->idRestaurante, $v['numero'], $v['apelido'], $v['lugares']);
        TempoReal::publicar($this->idRestaurante, 'mesas', 'mesa.criada', ['id' => $id]);
        Auditoria::registrar($this->autor, 'Mesa criada', self::rotulo($v) . ($v['lugares'] !== null ? ' (' . $v['lugares'] . ' lugares)' : '') . '.');
        return Resultado::sucesso('Mesa ' . $v['numero'] . ' criada.', $id);
    }

    /** "+" do mapa: cria a proxima mesa (maior numero + 1), sem formulario. */
    public function criarProxima(): Resultado
    {
        $maior = 0;
        foreach ($this->listar() as $m) {
            $maior = max($maior, (int) $m['numero']);
        }
        $numero = $maior + 1;
        while ($numero <= 9999 && $this->repo->numeroEmUso($this->idRestaurante, $numero)) {
            $numero++;
        }
        return $this->criar(['numero' => (string) $numero, 'apelido' => '', 'lugares' => '4']);
    }

    /** Cria as mesas de "de" ate "ate" que ainda nao existem (ex.: 1 a 15). */
    public function criarVarias(string $deTexto, string $ateTexto): Resultado
    {
        $de = ctype_digit(trim($deTexto)) ? (int) trim($deTexto) : 0;
        $ate = ctype_digit(trim($ateTexto)) ? (int) trim($ateTexto) : 0;
        $erros = [];
        if ($de < 1 || $de > 9999) {
            $erros['de'] = 'Número de 1 a 9999.';
        }
        if ($ate < $de || $ate > 9999) {
            $erros['ate'] = 'Precisa ser igual ou maior que o primeiro número.';
        } elseif ($ate - $de >= 100) {
            $erros['ate'] = 'Crie até 100 mesas por vez.';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os números das mesas.', $erros);
        }
        $existentes = count($this->listar());
        $criadas = [];
        $this->db->beginTransaction();
        try {
            for ($n = $de; $n <= $ate; $n++) {
                if ($this->repo->numeroEmUso($this->idRestaurante, $n)) {
                    continue;
                }
                if ($existentes + count($criadas) >= self::MAX_MESAS) {
                    break;
                }
                $this->repo->criar($this->idRestaurante, $n, null, null);
                $criadas[] = $n;
            }
            $this->db->commit();
        } catch (\Throwable $erro) {
            $this->db->rollBack();
            throw $erro;
        }
        if ($criadas === []) {
            return Resultado::falha('Essas mesas já existem.');
        }
        TempoReal::publicar($this->idRestaurante, 'mesas', 'mesa.criada', ['quantidade' => count($criadas)]);
        $faixa = count($criadas) === 1 ? 'Mesa ' . $criadas[0] : 'Mesas ' . $criadas[0] . ' a ' . end($criadas);
        Auditoria::registrar($this->autor, 'Mesas criadas', $faixa . ' (' . count($criadas) . ').');
        return Resultado::sucesso(count($criadas) . ' mesa(s) criada(s): ' . mb_strtolower($faixa) . '.');
    }

    /** @param array<string,string> $d numero, apelido, lugares, ativo */
    public function atualizar(int $id, array $d): Resultado
    {
        $mesa = $this->repo->buscar($this->idRestaurante, $id);
        if ($mesa === null) {
            return Resultado::falha('Mesa não encontrada. Ela pode ter sido excluída.');
        }
        $v = $this->validar($d, $id);
        if ($v instanceof Resultado) {
            return $v;
        }
        $ativo = ($d['ativo'] ?? '1') === '1';
        if (!$ativo && $this->repo->temComandaAberta($this->idRestaurante, $id)) {
            return Resultado::falha('Feche a comanda desta mesa antes de pausá-la.');
        }
        $this->repo->atualizar($this->idRestaurante, $id, $v['numero'], $v['apelido'], $v['lugares'], $ativo);
        TempoReal::publicar($this->idRestaurante, 'mesas', 'mesa.atualizada', ['id' => $id]);
        $mudou = Auditoria::diferencas(
            ['numero' => $mesa['numero'], 'apelido' => $mesa['apelido'] ?? '', 'lugares' => $mesa['lugares'] ?? '', 'ativo' => (int) $mesa['ativo'] === 1 ? 'em uso' : 'pausada'],
            ['numero' => $v['numero'], 'apelido' => $v['apelido'] ?? '', 'lugares' => $v['lugares'] ?? '', 'ativo' => $ativo ? 'em uso' : 'pausada'],
            ['numero' => 'Número', 'apelido' => 'Nome', 'lugares' => 'Lugares', 'ativo' => 'Situação']
        );
        Auditoria::registrar($this->autor, 'Mesa editada', self::rotulo($v) . ($mudou !== '' ? ' - ' . $mudou : '') . '.');
        return Resultado::sucesso('Mesa ' . $v['numero'] . ' salva.', $id);
    }

    public function excluir(int $id): Resultado
    {
        $mesa = $this->repo->buscar($this->idRestaurante, $id);
        if ($mesa === null) {
            return Resultado::falha('Mesa não encontrada. Ela pode já ter sido excluída.');
        }
        if ($this->repo->temComandaAberta($this->idRestaurante, $id)) {
            return Resultado::falha('Esta mesa tem uma comanda aberta. Feche ou cancele a comanda antes de excluir.');
        }
        $historico = $this->repo->temHistorico($this->idRestaurante, $id);
        $historico ? $this->repo->arquivar($this->idRestaurante, $id) : $this->repo->excluir($this->idRestaurante, $id);
        TempoReal::publicar($this->idRestaurante, 'mesas', 'mesa.excluida', ['id' => $id]);
        Auditoria::registrar($this->autor, 'Mesa excluída', self::rotulo($mesa) . ($historico ? '. Os pedidos antigos dela continuam no histórico.' : '.'));
        return Resultado::sucesso(self::rotulo($mesa) . ' excluída.');
    }

    /**
     * @param array<string,string> $d
     * @return array{numero:int, apelido:?string, lugares:?int}|Resultado
     */
    private function validar(array $d, ?int $id): array|Resultado
    {
        $erros = [];
        $numeroTexto = trim($d['numero'] ?? '');
        $numero = ctype_digit($numeroTexto) ? (int) $numeroTexto : 0;
        if ($numero < 1 || $numero > 9999) {
            $erros['numero'] = 'Digite o número da mesa (1 a 9999).';
        } elseif ($this->repo->numeroEmUso($this->idRestaurante, $numero, $id)) {
            $erros['numero'] = 'Já existe a mesa ' . $numero . '.';
        }
        $apelido = trim((string) preg_replace('/\s+/u', ' ', $d['apelido'] ?? ''));
        if (mb_strlen($apelido) > 40) {
            $erros['apelido'] = 'Use até 40 caracteres.';
        }
        $lugaresTexto = trim($d['lugares'] ?? '');
        $lugares = null;
        if ($lugaresTexto !== '') {
            $lugares = ctype_digit($lugaresTexto) ? (int) $lugaresTexto : 0;
            if ($lugares < 1 || $lugares > 99) {
                $erros['lugares'] = 'De 1 a 99 lugares, ou deixe vazio.';
            }
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        return ['numero' => $numero, 'apelido' => $apelido !== '' ? $apelido : null, 'lugares' => $lugares];
    }
}
