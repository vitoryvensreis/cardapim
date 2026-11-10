<?php

declare(strict_types=1);

namespace Cardapim\Services\Cliente;

use Cardapim\Core\Session;
use Cardapim\Repositories\ClienteRepository;
use PDO;

/**
 * Fluxo de entrada do cliente (igual aos apps de delivery): numero -> codigo -> (se for novo) nome.
 * A tela so pergunta o nome DEPOIS do codigo confirmado, para nao revelar quais numeros tem cadastro.
 */
final class CadastroCliente
{
    private const CHAVE_PENDENTE = 'cliente_cadastro';
    private const VALIDADE_PENDENTE = 900;

    public function __construct(private readonly PDO $db, private readonly CodigoVerificacao $codigos)
    {
    }

    /** @return array{ok:bool, mensagem:string, etapa?:string, status?:int} etapa: 'pronto' (entrou) ou 'nome' (falta o nome) */
    public function confirmarCodigo(string $codigo, string $ip): array
    {
        $r = $this->codigos->verificar($codigo);
        if (!$r['ok']) {
            return $r;
        }
        $telefone = (string) $r['telefone'];
        $cliente = (new ClienteRepository($this->db))->buscarPorWhatsapp($telefone);
        if ($cliente !== null) {
            if ((int) $cliente['ativo'] !== 1) {
                return ['ok' => false, 'status' => 403, 'mensagem' => 'Esta conta está bloqueada. Fale com o restaurante.'];
            }
            ClienteSessao::entrar((int) $cliente['id'], $ip, false);
            return ['ok' => true, 'etapa' => 'pronto', 'mensagem' => 'Que bom te ver de novo, ' . explode(' ', trim((string) $cliente['nome']))[0] . '!'];
        }
        Session::set(self::CHAVE_PENDENTE, ['tel' => $telefone, 'expira' => time() + self::VALIDADE_PENDENTE]);
        return ['ok' => true, 'etapa' => 'nome', 'mensagem' => 'Número confirmado. Agora, como podemos te chamar?'];
    }

    /** @return array{ok:bool, mensagem:string, campo?:string, status?:int} */
    public function concluir(string $nomeDigitado, string $ip): array
    {
        $pendente = Session::get(self::CHAVE_PENDENTE);
        if (!is_array($pendente) || (int) ($pendente['expira'] ?? 0) < time()) {
            Session::remover(self::CHAVE_PENDENTE);
            return ['ok' => false, 'status' => 422, 'mensagem' => 'Confirme seu número de novo para continuar.'];
        }
        $nome = self::nomeValido($nomeDigitado);
        if ($nome === null) {
            return ['ok' => false, 'status' => 422, 'campo' => 'nome', 'mensagem' => 'Digite seu nome (pelo menos 2 letras).'];
        }
        $repo = new ClienteRepository($this->db);
        $existente = $repo->buscarPorWhatsapp((string) $pendente['tel']);
        $id = $existente !== null ? (int) $existente['id'] : $repo->criar($nome, (string) $pendente['tel'], date('Y-m-d H:i:s'));
        Session::remover(self::CHAVE_PENDENTE);
        ClienteSessao::entrar($id, $ip, $existente === null);
        return ['ok' => true, 'mensagem' => 'Prontinho, ' . explode(' ', $nome)[0] . '! Sua conta foi criada.'];
    }

    public static function nomeValido(string $nome): ?string
    {
        $nome = trim((string) preg_replace('/\s+/u', ' ', $nome));
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 80 || preg_match("/\\A[\\p{L}][\\p{L}\\p{M} .'-]*\\z/u", $nome) !== 1) {
            return null;
        }
        return $nome;
    }
}
