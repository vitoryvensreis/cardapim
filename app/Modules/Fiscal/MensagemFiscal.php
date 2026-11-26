<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

use Cardapim\Modules\Integracoes\NextFiscalErro;
use Cardapim\Shared\Http\ErroHttp;
use RuntimeException;
use Throwable;

/** Traduz falhas fiscais em mensagens seguras e úteis; nunca expõe SQL, stack ou credenciais. */
final class MensagemFiscal
{
    /** Mensagens produzidas pelo nosso próprio código, seguras para exibir. */
    private const CONHECIDAS = [
        'A NeXT recusou a credencial ou a permissão administrativa.' => 'A NeXT recusou a credencial. Peça à administração para conferir a credencial do restaurante.',
        'Limite de consultas da NeXT atingido.' => 'Limite de consultas da NeXT atingido. Tente novamente em alguns minutos.',
        'A NeXT não confirmou a operação. Confira as credenciais e tente novamente.' => 'A NeXT não confirmou a operação. Tente novamente; se continuar, avise a administração.',
        'Credencial individual ausente.' => 'A NeXT não devolveu a credencial do restaurante. Tente conectar novamente.',
        'Configuração fiscal ocupada.' => 'Outra alteração fiscal está em andamento. Aguarde alguns segundos e tente de novo.',
        'Vínculo fiscal indisponível.' => 'O restaurante ainda não está conectado à NeXT. Conclua a etapa de conexão.',
        'Integração administrativa indisponível.' => 'A integração fiscal da plataforma está desativada no momento. Avise a administração.',
    ];

    public static function para(Throwable $e, string $padrao): string
    {
        if ($e instanceof NextFiscalErro) {
            return $e->paraUsuario();
        }
        if ($e instanceof ErroHttp) {
            return 'Não foi possível falar com a NeXT agora. Confira a internet do servidor e tente em alguns minutos.';
        }
        if (get_class($e) === RuntimeException::class) {
            $m = $e->getMessage();
            if (isset(self::CONHECIDAS[$m])) { return self::CONHECIDAS[$m]; }
            if (preg_match('/\AA NeXT retornou erro HTTP [0-9]{3}\.\z/', $m) === 1) { return $m . ' Tente novamente em alguns minutos.'; }
        }
        return $padrao;
    }
}
