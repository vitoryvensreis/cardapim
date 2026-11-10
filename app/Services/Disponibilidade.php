<?php

declare(strict_types=1);

namespace Cardapim\Services;

use Cardapim\Repositories\RestauranteRepository;
use Cardapim\Repositories\UsuarioRepository;
use Cardapim\Shared\Validation\Documento;

/**
 * Verifica, durante o preenchimento, se usuario, e-mail ou CNPJ ja estao cadastrados.
 * E apenas orientacao: o cadastro deve revalidar tudo e os indices UNIQUE do banco
 * continuam sendo a garantia final contra duplicidade.
 */
final class Disponibilidade
{
    public const CAMPOS = ['usuario', 'email', 'cnpj'];

    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly RestauranteRepository $restaurantes,
    ) {
    }

    /** @return array{campo:string, valido:bool, disponivel:?bool, mensagem:string} */
    public function verificar(string $campo, string $valor): array
    {
        $valor = trim($valor);
        return match ($campo) {
            'usuario' => $this->usuario(strtolower($valor)),
            'email' => $this->email($valor),
            'cnpj' => $this->cnpj($valor),
            default => throw new \InvalidArgumentException('Campo nao suportado.'),
        };
    }

    private function usuario(string $usuario): array
    {
        if (preg_match('/\A[a-z0-9._-]{3,60}\z/', $usuario) !== 1) {
            return $this->resultado('usuario', false, null, 'Use de 3 a 60 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado.');
        }
        return $this->usuarios->usuarioExiste($usuario)
            ? $this->resultado('usuario', true, false, 'Este nome de usuário já está em uso. Tente outro.')
            : $this->resultado('usuario', true, true, 'Nome de usuário disponível.');
    }

    private function email(string $email): array
    {
        if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->resultado('email', false, null, 'Digite um e-mail válido, como nome@exemplo.com.');
        }
        return $this->usuarios->emailExiste($email)
            ? $this->resultado('email', true, false, 'Este e-mail já está cadastrado. Se a conta é sua, entre pelo login.')
            : $this->resultado('email', true, true, 'E-mail disponível.');
    }

    private function cnpj(string $cnpj): array
    {
        if (!Documento::cnpjValido($cnpj)) {
            return $this->resultado('cnpj', false, null, 'CNPJ inválido. Confira os números.');
        }
        return $this->restaurantes->cnpjExiste(Documento::somenteDigitos($cnpj))
            ? $this->resultado('cnpj', true, false, 'Este CNPJ já possui um restaurante cadastrado.')
            : $this->resultado('cnpj', true, true, 'CNPJ disponível.');
    }

    private function resultado(string $campo, bool $valido, ?bool $disponivel, string $mensagem): array
    {
        return compact('campo', 'valido', 'disponivel', 'mensagem');
    }
}
