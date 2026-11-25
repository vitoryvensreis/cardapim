<?php
declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Core\RateLimiter;
use Cardapim\Modules\Fiscal\CertificadoA1;
use Cardapim\Modules\Integracoes\NextFiscalApi;
use Cardapim\Repositories\Desktop\ImpressoraRepository;
use Cardapim\Repositories\Gerencia\FiscalRepository;
use Cardapim\Repositories\Gerencia\PerfilRepository;
use Cardapim\Repositories\Plataforma\FiscalNextRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Esquema;
use Cardapim\Shared\Validation\Documento;
use PDO;
use RuntimeException;

final class FiscalService
{
    private readonly FiscalRepository $repo;
    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $usuario, private readonly int $id, private readonly NextFiscalApi $api = new NextFiscalApi())
    {
        $this->repo = new FiscalRepository($db);
    }

    private function perfil(): array
    {
        return (new PerfilRepository($this->db))->buscar($this->id) ?? [];
    }

    public function estado(): array
    {
        $perfil = $this->perfil();
        $r = $this->repo->buscar($this->id);
        $d = $r['dados'] ?? [];
        $dados = $d['empresa'] ?? [];
        foreach (['nome', 'email', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'estado'] as $c) {
            $dados[$c] ??= (string) ($perfil[$c] ?? '');
        }
        $amb = (int) ($d['ambiente'] ?? 3);
        $config = $d['config'][$amb] ?? [];
        return [
            'cnpj' => $r['cnpj'] ?? (string) ($perfil['cnpj'] ?? ''),
            'habilitado' => ($r['habilitado'] ?? false) && $this->cnpjConfere($r, $perfil),
            'aprovado' => $r['aprovado'] ?? false,
            'registrado' => isset($d['token']),
            'empresa' => $dados, 'ambiente' => $amb,
            'config' => array_intersect_key($config, array_flip(['serie', 'numero', 'id_csc'])),
            'tem_csc' => isset($config['csc']) && $config['csc'] !== '',
            'certificado' => $d['certificado'] ?? null,
            'alerta' => CertificadoA1::alerta($d['certificado']['expira_em'] ?? null),
            'status' => $d['status'][$amb] ?? 'Não testado',
            'config_enviada' => $d['config_enviada'] ?? false,
            'impressora' => $this->impressora($r),
        ];
    }

    private function cnpjConfere(?array $r, array $p): bool
    {
        return $r !== null && $r['cnpj'] === Documento::somenteDigitos((string) ($p['cnpj'] ?? ''));
    }

    /** Apenas projeção de impressão, sem dependência do provedor. */
    public function impressora(?array $r = null): array
    {
        if (!Esquema::tem($this->db, 'fiscal_restaurante')) {
            return ['habilitado' => false];
        }
        $r ??= $this->repo->buscar($this->id);
        if (!$r || !$r['habilitado'] || !$this->cnpjConfere($r, $this->perfil())) {
            return ['habilitado' => false];
        }
        $repo = new ImpressoraRepository($this->db);
        $lista = Esquema::tem($this->db, 'impressoras') ? $repo->listar($this->id) : [];
        $cfg = $lista ? ($repo->pontos($this->id)['caixa_conta'] ?? []) : [];
        $id = $r['id_impressora'] ?? ($cfg['padrao'] ?? null);
        $destino = null;
        foreach ($lista as $i) {
            if ($i['id'] === $id) {
                $destino = $i['apelido'];
            }
        }
        return ['habilitado' => true, 'escolha' => $r['id_impressora'], 'destino' => $destino, 'id_destino' => $id];
    }

    public function habilitar(bool $ativo): Resultado
    {
        return $this->repo->serializar($this->id, function () use ($ativo): Resultado {
            $p = $this->perfil();
            $cnpj = Documento::somenteDigitos((string) ($p['cnpj'] ?? ''));
            $r = $this->repo->buscar($this->id);
            if (!$ativo && $r === null) {
                return Resultado::sucesso('Módulo fiscal desativado.');
            }
            if (!Documento::cnpjValido($cnpj) || ($r !== null && !$this->cnpjConfere($r, $p))) {
                return Resultado::falha('Confira o CNPJ do cadastro com a administração da plataforma.');
            }
            $r ??= ['cnpj' => $cnpj, 'habilitado' => false, 'aprovado' => false, 'id_impressora' => null, 'dados' => ['ambiente' => 3]];
            $r['habilitado'] = $ativo;
            $this->repo->salvar($this->id, $r);
            Auditoria::registrar($this->usuario, 'Módulo fiscal', $ativo ? 'Habilitado para configuração.' : 'Desativado; configuração preservada.');
            return Resultado::sucesso($ativo ? 'Módulo habilitado para configuração. Emissão real permanece bloqueada.' : 'Módulo fiscal desativado.');
        });
    }

    /** Aprovação explícita pelo adm_master, no controlador da plataforma. */
    public function aprovar(string $cnpj): Resultado
    {
        if ($this->usuario->cargo !== 'adm_master') { return Resultado::falha('A aprovação é exclusiva da administração.'); }
        return $this->repo->serializar($this->id, function () use ($cnpj): Resultado {
            $r = $this->repo->buscar($this->id);
            if (!$r || !$r['habilitado'] || !$this->cnpjConfere($r, $this->perfil()) || $r['cnpj'] !== $cnpj || !isset($r['dados']['empresa'])) {
                return Resultado::falha('Confira os dados fiscais e o vínculo do restaurante antes de aprovar.');
            }
            $global = $this->global();
            if ($global['cnpj'] === $cnpj) {
                return Resultado::falha('O CNPJ da Software House não pode ser vinculado a um restaurante.');
            }
            $r['aprovado'] = true;
            $this->repo->salvar($this->id, $r);
            Auditoria::registrar($this->usuario, 'Fiscal: vínculo aprovado', 'Restaurante ' . $this->id . '; CNPJ conferido pela administração.');
            return Resultado::sucesso('Vínculo autorizado. O gerente já pode conectar o emitente automaticamente.');
        });
    }

    private function global(): array
    {
        $d = (new FiscalNextRepository($this->db))->ler()['dados'];
        if (($d['ativo'] ?? false) !== true || !isset($d['cnpj'], $d['token'])) {
            throw new RuntimeException('Integração administrativa indisponível.');
        }
        return $d;
    }

    private function operacao(callable $acao): Resultado
    {
        return $this->repo->serializar($this->id, function () use ($acao): Resultado {
            $r = $this->repo->buscar($this->id);
            if (!$r || !$r['habilitado'] || !$this->cnpjConfere($r, $this->perfil())) {
                return Resultado::falha('Habilite o módulo fiscal e confira o CNPJ cadastrado.');
            }
            $res = $acao($r);
            if ($res->ok) {
                $this->repo->salvar($this->id, $r);
                Auditoria::registrar($this->usuario, 'Fiscal: configuração atualizada', 'Operação fiscal de configuração, sem emissão.');
            }
            return $res;
        });
    }

    private static function campo(array $p, string $c): string
    {
        return is_string($p[$c] ?? null) ? trim($p[$c]) : '';
    }

    public function empresa(array $p): Resultado
    {
        $campos = ['razao' => 160, 'nome' => 120, 'ie' => 20, 'cep' => 9, 'logradouro' => 120, 'numero' => 20, 'complemento' => 100, 'bairro' => 80, 'cidade' => 80, 'estado' => 2, 'ibge' => 7, 'crt' => 1, 'email' => 160];
        $d = [];
        foreach ($campos as $c => $max) {
            $d[$c] = self::campo($p, $c);
            if (mb_strlen($d[$c]) > $max || ($c !== 'complemento' && $d[$c] === '')) {
                return Resultado::falha('Confira os campos obrigatórios dos dados fiscais.');
            }
        }
        $d['cep'] = Documento::somenteDigitos($d['cep']);
        $d['estado'] = strtoupper($d['estado']);
        $ufs = ['AC'=>12,'AL'=>27,'AP'=>16,'AM'=>13,'BA'=>29,'CE'=>23,'DF'=>53,'ES'=>32,'GO'=>52,'MA'=>21,'MT'=>51,'MS'=>50,'MG'=>31,'PA'=>15,'PB'=>25,'PR'=>41,'PE'=>26,'PI'=>22,'RJ'=>33,'RN'=>24,'RS'=>43,'RO'=>11,'RR'=>14,'SC'=>42,'SP'=>35,'SE'=>28,'TO'=>17];
        if (!isset($ufs[$d['estado']]) || preg_match('/\A[0-9]{7}\z/', $d['ibge']) !== 1 || substr($d['ibge'], 0, 2) !== (string) $ufs[$d['estado']] || strlen($d['cep']) !== 8 || preg_match('/\A[0-9]{2,20}\z/', $d['ie']) !== 1 || !in_array($d['crt'], ['1','2','3','4'], true) || !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            return Resultado::falha('Confira CEP, IE, UF, código IBGE, regime tributário e e-mail.');
        }
        return $this->operacao(function (array &$r) use ($d): Resultado {
            $r['dados']['empresa'] = $d;
            $r['dados']['config_enviada'] = false;
            $r['dados']['status'] = [];
            return Resultado::sucesso('Dados fiscais salvos.');
        });
    }

    public function conectar(): Resultado
    {
        return $this->operacao(function (array &$r): Resultado {
            if (!$r['aprovado'] || !isset($r['dados']['empresa'])) {
                return Resultado::falha('Complete os dados e aguarde a confirmação do vínculo pela administração.');
            }
            $g = $this->global();
            if ($g['cnpj'] === $r['cnpj']) {
                return Resultado::falha('O CNPJ da Software House não pode ser usado como emitente.');
            }
            if (isset($r['dados']['token']) && ($r['dados']['software_house'] ?? '') === $g['cnpj']) {
                return Resultado::sucesso('Emitente já conectado.');
            }
            if (!RateLimiter::padrao(10, 3600)->permitir('fiscal-conectar|' . $this->id)) {
                return Resultado::falha('Limite de tentativas atingido. Tente mais tarde.');
            }
            $token = $this->api->incluirCliente($g['cnpj'], $g['token'], $r['cnpj'], $r['dados']['empresa']['razao']);
            $r['dados']['token'] = $token;
            $r['dados']['software_house'] = $g['cnpj'];
            $r['dados']['certificado'] = null;
            $r['dados']['config_enviada'] = false;
            $r['dados']['status'] = [];
            return Resultado::sucesso('Emitente conectado à NeXT com credencial protegida.');
        });
    }

    private function credenciais(array $r): array
    {
        $g = $this->global();
        if (!$r['aprovado'] || !isset($r['dados']['token']) || ($r['dados']['software_house'] ?? '') !== $g['cnpj'] || $r['cnpj'] === $g['cnpj']) {
            throw new RuntimeException('Vínculo fiscal indisponível.');
        }
        return [$g['cnpj'], $r['cnpj'], $r['dados']['token']];
    }

    public function ambiente(string $amb): Resultado
    {
        if (!in_array($amb, ['2','3'], true)) {
            return Resultado::falha('Produção bloqueada nesta etapa.');
        }
        return $this->operacao(function (array &$r) use ($amb): Resultado {
            $r['dados']['ambiente'] = (int) $amb;
            return Resultado::sucesso('Ambiente selecionado.');
        });
    }

    public function configurar(array $p): Resultado
    {
        $serie = filter_var(self::campo($p, 'serie'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>999]]);
        $numero = filter_var(self::campo($p, 'numero'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>999999999]]);
        $id = self::campo($p, 'id_csc');
        $csc = self::campo($p, 'csc');
        if ($serie === false || $numero === false || strlen($id) > 6 || ($id !== '' && !ctype_digit($id)) || strlen($csc) > 128 || ($csc !== '' && preg_match('/\A[\x21-\x7E]+\z/', $csc) !== 1)) {
            return Resultado::falha('Confira série, próxima numeração e CSC.');
        }
        return $this->operacao(function (array &$r) use ($serie, $numero, $id, $csc): Resultado {
            $amb = $r['dados']['ambiente'] ?? 3;
            $old = $r['dados']['config'][$amb] ?? [];
            $r['dados']['config'][$amb] = ['serie'=>$serie, 'numero'=>$numero, 'id_csc'=>$id, 'csc'=>$csc !== '' ? $csc : ($id === ($old['id_csc'] ?? '') ? ($old['csc'] ?? '') : '')];
            if ($amb === 2) {
                $r['dados']['config_enviada'] = false;
            }
            unset($r['dados']['status'][$amb]);
            return Resultado::sucesso('Parâmetros fiscais salvos para este ambiente.');
        });
    }

    public function certificado(array $arquivo, #[\SensitiveParameter] string $senha): Resultado
    {
        $tmp = $arquivo['tmp_name'] ?? null;
        if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($tmp) || !is_uploaded_file($tmp) || ($arquivo['size'] ?? 0) > 1048576) {
            return Resultado::falha('Selecione um certificado A1 de até 1 MB.');
        }
        $bytes = file_get_contents($tmp, false, null, 0, 1048577);
        if (!is_string($bytes)) {
            return Resultado::falha('Não foi possível ler o certificado.');
        }
        return $this->operacao(function (array &$r) use ($bytes, $senha): Resultado {
            if (($r['dados']['ambiente'] ?? 3) !== 2 || !isset($r['dados']['empresa'], $r['dados']['config'][2])) {
                return Resultado::falha('Selecione homologação e salve os parâmetros antes do certificado.');
            }
            [$sh, $cnpj, $token] = $this->credenciais($r);
            try {
                $meta = CertificadoA1::validar($bytes, $senha, $cnpj);
            } catch (RuntimeException $e) {
                return Resultado::falha($e->getMessage());
            }
            $c = $r['dados']['config'][2];
            $e = $r['dados']['empresa'];
            if (!RateLimiter::padrao(10, 3600)->permitir('fiscal-certificado|' . $this->id)) { return Resultado::falha('Limite de envios atingido.'); }
            $this->api->certificadoUpload($sh, $cnpj, $token, $bytes, $senha, $e['razao'], $e['estado'], $c['csc'], $c['id_csc']);
            $r['dados']['certificado'] = $meta;
            $r['dados']['config_enviada'] = false;
            unset($r['dados']['status'][2]);
            return Resultado::sucesso('Certificado enviado à NeXT em homologação. Envie a configuração e teste a SEFAZ.');
        });
    }

    public function enviarConfig(): Resultado
    {
        return $this->operacao(function (array &$r): Resultado {
            if (($r['dados']['ambiente'] ?? 3) !== 2 || !isset($r['dados']['empresa'], $r['dados']['config'][2]) || ($r['dados']['certificado']['expira_em'] ?? 0) <= time()) {
                return Resultado::falha('Configure homologação, parâmetros e certificado válido primeiro.');
            }
            [$sh, $cnpj, $token] = $this->credenciais($r);
            $c = $r['dados']['config'][2]; $e = $r['dados']['empresa'];
            if (!RateLimiter::padrao(20, 3600)->permitir('fiscal-config|' . $this->id)) { return Resultado::falha('Limite de envios atingido.'); }
            $this->api->configHomologacao($sh, $cnpj, $token, $e['razao'], $e['estado'], $c['csc'], $c['id_csc']);
            $r['dados']['config_enviada'] = true;
            unset($r['dados']['status'][2]);
            return Resultado::sucesso('Configuração enviada à NeXT em homologação.');
        });
    }

    public function testar(): Resultado
    {
        return $this->operacao(function (array &$r): Resultado {
            [$sh, $cnpj, $token] = $this->credenciais($r);
            $amb = $r['dados']['ambiente'] ?? 3;
            if ($amb === 2 && (!($r['dados']['config_enviada'] ?? false) || ($r['dados']['certificado']['expira_em'] ?? 0) <= time())) {
                return Resultado::falha('Envie a configuração de homologação e confira o certificado antes do teste.');
            }
            if (!RateLimiter::padrao(20, 3600)->permitir('fiscal-teste|' . $this->id)) {
                return Resultado::falha('Limite de testes atingido.');
            }
            $cod = $this->api->statusCliente($sh, $cnpj, $token, $amb);
            $r['dados']['status'][$amb] = ($cod === 107 ? 'Serviço em operação' : 'Status SEFAZ ' . $cod) . ($amb === 3 ? ' (simulação)' : ' (homologação)');
            return Resultado::sucesso('Consulta concluída: ' . $r['dados']['status'][$amb] . '. Nenhuma nota emitida.');
        });
    }

    public function salvarImpressora(string $id): Resultado
    {
        return $this->operacao(function (array &$r) use ($id): Resultado {
            if ($id === 'padrao') {
                $r['id_impressora'] = null;
            } else {
                if (!ctype_digit($id) || strlen($id) > 18 || !Esquema::tem($this->db, 'impressoras')) {
                    return Resultado::falha('Selecione uma impressora cadastrada.');
                }
                $ids = array_column((new ImpressoraRepository($this->db))->listar($this->id), 'id');
                if (!in_array((int) $id, $ids, true)) {
                    return Resultado::falha('Impressora não pertence ao restaurante ou não está cadastrada.');
                }
                $r['id_impressora'] = (int) $id;
            }
            return Resultado::sucesso('Impressora fiscal salva. Os demais pontos de impressão não foram alterados.');
        });
    }
}
