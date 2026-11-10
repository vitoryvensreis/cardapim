<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Modules\Restaurante\HorarioFuncionamento;
use Cardapim\Modules\Restaurante\LogoUpload;
use Cardapim\Repositories\Gerencia\PerfilRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Validation\Documento;
use PDO;
use RuntimeException;
use Throwable;

/** Perfil do restaurante: dados, endereco, logo e horario de funcionamento. */
final class PerfilService
{
    public const UFS = [
        'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas', 'BA' => 'Bahia', 'CE' => 'Ceará',
        'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo', 'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso',
        'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
        'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
        'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina', 'SP' => 'São Paulo',
        'SE' => 'Sergipe', 'TO' => 'Tocantins',
    ];

    private const ROTULOS = [
        'nome' => 'Nome', 'telefone' => 'Telefone', 'email' => 'E-mail', 'cep' => 'CEP', 'logradouro' => 'Rua',
        'numero' => 'Número', 'complemento' => 'Complemento', 'bairro' => 'Bairro', 'cidade' => 'Cidade',
        'estado' => 'Estado', 'ponto_referencia' => 'Referência',
    ];

    private readonly PerfilRepository $perfil;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor, private readonly int $idRestaurante)
    {
        $this->perfil = new PerfilRepository($db);
    }

    /** @param array<string,mixed> $e */
    public function salvarDados(array $e): Resultado
    {
        $antes = $this->perfil->buscar($this->idRestaurante);
        if ($antes === null) {
            return Resultado::falha('Restaurante não encontrado.');
        }
        $erros = [];
        $texto = static fn (string $campo, int $max): ?string => ($v = mb_substr(InsumoService::texto($e[$campo] ?? ''), 0, $max)) === '' ? null : $v;

        $nome = InsumoService::texto($e['nome'] ?? '');
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 180) {
            $erros['nome'] = 'Digite o nome do restaurante (2 a 180 letras).';
        }
        $telTexto = trim((string) ($e['telefone'] ?? ''));
        $telefone = $telTexto === '' ? null : Documento::normalizarTelefone($telTexto);
        if ($telTexto === '') {
            $erros['telefone'] = 'Digite o telefone com DDD. É por ele que os clientes falam com você.';
        } elseif ($telefone === null) {
            $erros['telefone'] = 'Digite o DDD e o número completo.';
        }
        $email = trim((string) ($e['email'] ?? ''));
        if ($email !== '' && (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $erros['email'] = 'Digite um e-mail válido, como contato@restaurante.com, ou deixe em branco.';
        }

        $cepTexto = trim((string) ($e['cep'] ?? ''));
        $cep = Documento::somenteDigitos($cepTexto);
        $endereco = [
            'cep' => $cep === '' ? null : $cep,
            'logradouro' => $texto('logradouro', 180),
            'numero' => $texto('numero', 20),
            'complemento' => $texto('complemento', 120),
            'bairro' => $texto('bairro', 120),
            'cidade' => $texto('cidade', 120),
            'estado' => strtoupper(trim((string) ($e['estado'] ?? ''))) ?: null,
            'ponto_referencia' => $texto('ponto_referencia', 180),
        ];
        $algumEndereco = array_filter($endereco, static fn ($v): bool => $v !== null) !== [];
        if ($algumEndereco) {
            if (strlen($cep) !== 8) {
                $erros['cep'] = 'Digite o CEP com 8 números.';
            }
            foreach (['logradouro' => 'a rua', 'numero' => 'o número (ou S/N)', 'bairro' => 'o bairro', 'cidade' => 'a cidade'] as $campo => $rotulo) {
                if ($endereco[$campo] === null) {
                    $erros[$campo] = 'Digite ' . $rotulo . '.';
                }
            }
            if ($endereco['estado'] === null || !isset(self::UFS[$endereco['estado']])) {
                $erros['estado'] = 'Escolha o estado.';
            }
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }

        $dados = ['nome' => $nome, 'telefone' => $telefone, 'email' => $email === '' ? null : $email] + $endereco;
        $mudouLocal = false;
        foreach (['cep', 'logradouro', 'numero', 'cidade', 'estado'] as $campo) {
            $mudouLocal = $mudouLocal || (string) ($antes[$campo] ?? '') !== (string) ($dados[$campo] ?? '');
        }
        $this->perfil->atualizar($this->idRestaurante, $dados, $mudouLocal);
        $diferenca = Auditoria::diferencas($antes, $dados, self::ROTULOS);
        if ($diferenca !== 'Nenhuma alteração.') {
            Auditoria::registrar($this->autor, 'Perfil do restaurante editado', $diferenca);
        }
        return Resultado::sucesso($diferenca === 'Nenhuma alteração.' ? 'Nada mudou: os dados já estavam assim.' : 'Dados do restaurante salvos.');
    }

    /** @param array<string,mixed> $arquivo item de $_FILES */
    public function trocarLogo(array $arquivo): Resultado
    {
        $antes = $this->perfil->buscar($this->idRestaurante);
        if ($antes === null) {
            return Resultado::falha('Restaurante não encontrado.');
        }
        try {
            $caminho = LogoUpload::salvar($arquivo);
        } catch (RuntimeException $exception) {
            return Resultado::falha($exception->getMessage());
        }
        try {
            $this->perfil->definirLogo($this->idRestaurante, $caminho);
        } catch (Throwable $exception) {
            LogoUpload::apagar($caminho);
            throw $exception;
        }
        LogoUpload::apagar($antes['logo_path'] ?? null);
        Auditoria::registrar($this->autor, 'Logo do restaurante alterada', $antes['logo_path'] ? 'Logo trocada por uma nova imagem.' : 'Primeira logo enviada.');
        return Resultado::sucesso('Logo atualizada. Ela já aparece no catálogo e na sua página.');
    }

    public function removerLogo(): Resultado
    {
        $antes = $this->perfil->buscar($this->idRestaurante);
        if ($antes === null || empty($antes['logo_path'])) {
            return Resultado::falha('O restaurante não tem logo para remover.');
        }
        $this->perfil->definirLogo($this->idRestaurante, null);
        LogoUpload::apagar((string) $antes['logo_path']);
        Auditoria::registrar($this->autor, 'Logo do restaurante removida', 'Agora aparecem as iniciais do nome no lugar da logo.');
        return Resultado::sucesso('Logo removida. No lugar dela aparecem as iniciais do restaurante.');
    }

    /** @param array<string,mixed> $e */
    public function salvarHorarios(array $e): Resultado
    {
        $antes = $this->perfil->buscar($this->idRestaurante);
        if ($antes === null) {
            return Resultado::falha('Restaurante não encontrado.');
        }
        $resumoAntes = HorarioFuncionamento::resumo((int) $antes['sempre_aberto'] === 1, $this->perfil->horarios($this->idRestaurante));
        $sempre = ($e['modo'] ?? '') === 'sempre';
        $turnos = null;
        if (!$sempre) {
            [$turnos, $erros] = HorarioFuncionamento::validar(is_array($e['dia'] ?? null) ? $e['dia'] : []);
            if ($erros !== []) {
                return Resultado::falha('Confira os horários destacados.', array_combine(
                    array_map(static fn (int $d): string => 'dia' . $d, array_keys($erros)),
                    array_values($erros),
                ));
            }
        }
        $this->db->beginTransaction();
        try {
            $this->perfil->salvarHorarios($this->idRestaurante, $sempre, $turnos);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
        $resumoDepois = HorarioFuncionamento::resumo($sempre, $turnos ?? $this->perfil->horarios($this->idRestaurante));
        if ($resumoAntes !== $resumoDepois) {
            Auditoria::registrar($this->autor, 'Horário de funcionamento alterado', 'Antes: ' . $resumoAntes . '. Agora: ' . $resumoDepois . '.');
        }
        return Resultado::sucesso($sempre ? 'Pronto: o restaurante aparece como sempre aberto.' : 'Horário de funcionamento salvo.');
    }
}
