<?php
declare(strict_types=1);

namespace Cardapim\Modules\Fiscal;

use Cardapim\Modules\Entrega\TextoLocal;
use Cardapim\Shared\Http\ClienteHttp;
use Throwable;

/** Resolve município no servidor por UF e nome; nunca aceita código digitado pelo navegador. */
final class MunicipioIbge
{
    private const UFS = ['AC'=>12,'AL'=>27,'AP'=>16,'AM'=>13,'BA'=>29,'CE'=>23,'DF'=>53,'ES'=>32,'GO'=>52,'MA'=>21,'MT'=>51,'MS'=>50,'MG'=>31,'PA'=>15,'PB'=>25,'PR'=>41,'PE'=>26,'PI'=>22,'RJ'=>33,'RN'=>24,'RS'=>43,'RO'=>11,'RR'=>14,'SC'=>42,'SP'=>35,'SE'=>28,'TO'=>17];

    public function __construct(private readonly ClienteHttp $http = new ClienteHttp(5)) {}

    public static function ufValida(string $uf): bool { return isset(self::UFS[$uf]); }

    public static function identificar(array $lista, string $cidade, string $uf): ?string
    {
        if (!self::ufValida($uf) || trim($cidade) === '') { return null; }
        $alvo = TextoLocal::chave($cidade);
        $ids = [];
        foreach ($lista as $r) {
            if (!is_array($r) || !is_string($r['nome'] ?? null) || TextoLocal::chave($r['nome']) !== $alvo) { continue; }
            $id = (string) ($r['id'] ?? '');
            if (preg_match('/\A[0-9]{7}\z/', $id) && substr($id,0,2) === (string) self::UFS[$uf]) { $ids[$id] = $id; }
        }
        return count($ids) === 1 ? (string) reset($ids) : null;
    }

    public function resolver(string $cidade, string $uf, string $cep): ?string
    {
        if (!self::ufValida($uf) || trim($cidade) === '') { return null; }
        $pasta = dirname(__DIR__,3) . '/storage/cache/geo';
        $arquivo = $pasta . '/municipios-ibge-' . $uf . '.json';
        try {
            $cache = is_file($arquivo) ? json_decode((string) @file_get_contents($arquivo), true) : null;
            $lista = is_array($cache) && ($cache['expira'] ?? 0) > time() && is_array($cache['lista'] ?? null) ? $cache['lista'] : null;
            if ($lista === null) {
                $r = $this->http->enviar('GET', 'https://servicodados.ibge.gov.br/api/v1/localidades/estados/' . self::UFS[$uf] . '/municipios');
                $bruto = $r->ok() ? $r->json() : [];
                if (array_is_list($bruto) && $bruto !== []) {
                    $lista = [];
                    foreach ($bruto as $m) {
                        if (is_array($m) && is_int($m['id'] ?? null) && is_string($m['nome'] ?? null)) {
                            $lista[] = ['id'=>$m['id'],'nome'=>$m['nome']];
                        }
                    }
                    if ($lista !== []) {
                        if (!is_dir($pasta)) { @mkdir($pasta,0750,true); }
                        @file_put_contents($arquivo, json_encode(['expira'=>time()+7*86400,'lista'=>$lista],JSON_UNESCAPED_UNICODE), LOCK_EX);
                    }
                }
            }
            if (is_array($lista) && $lista !== []) {
                $id = self::identificar($lista,$cidade,$uf);
                if ($id !== null) { return $id; }
            }
        } catch (Throwable) { /* alternativa abaixo */ }
        // O ViaCEP também fornece o código, mas somente se o município e a UF coincidirem.
        if (!preg_match('/\A[0-9]{8}\z/', $cep)) { return null; }
        try {
            $r = $this->http->enviar('GET', 'https://viacep.com.br/ws/' . $cep . '/json/');
            $d = $r->ok() ? $r->json() : [];
            if (($d['erro'] ?? false) || ($d['uf'] ?? '') !== $uf || !is_string($d['localidade'] ?? null)) { return null; }
            return self::identificar([['nome'=>$d['localidade'],'id'=>$d['ibge'] ?? '']],$cidade,$uf);
        } catch (Throwable) { return null; }
    }
}
