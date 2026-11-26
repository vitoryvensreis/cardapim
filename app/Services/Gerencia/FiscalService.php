<?php
declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Core\RateLimiter;
use Cardapim\Core\Session;
use Cardapim\Modules\Fiscal\ConsultaCadastroFiscal;
use Cardapim\Modules\Fiscal\MunicipioIbge;
use Cardapim\Modules\Entrega\TextoLocal;
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
        $config = $amb === 3 ? self::configSimulacao($d['config'][3] ?? []) : ($d['config'][$amb] ?? []);
        return [
            'cnpj' => $r['cnpj'] ?? (string) ($perfil['cnpj'] ?? ''),
            'habilitado' => ($r['habilitado'] ?? false) && $this->cnpjConfere($r, $perfil),
            'aprovado' => $r['aprovado'] ?? false,
            'registrado' => isset($d['token']),
            'empresa' => $dados, 'ambiente' => $amb,
            'empresa_salva' => isset($d['empresa']),
            'empresa_pronta' => self::empresaPronta($d['empresa'] ?? []),
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
        if (!FiscalAcesso::permitido($this->db,$this->id) || !Esquema::tem($this->db, 'fiscal_restaurante')) {
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
            if (!FiscalAcesso::permitido($this->db,$this->id)) { return Resultado::falha('Módulo não autorizado pela plataforma.'); }
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
            if ($ativo) { self::prepararSimulacao($r['dados']); }
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
            if (!FiscalAcesso::permitido($this->db,$this->id)) { return Resultado::falha('Libere o acesso do restaurante primeiro.'); }
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

    /**
     * Confere o token gravado com o que a NeXT associa ao CNPJ (ClientesListar) e corrige se divergir.
     * Exclusivo do adm_master. Não exibe, não registra e não compara tokens fora do servidor.
     */
    public function conferirCredencial(): Resultado
    {
        if ($this->usuario->cargo !== 'adm_master') { return Resultado::falha('A conferência é exclusiva da administração.'); }
        return $this->repo->serializar($this->id, function (): Resultado {
            $r = $this->repo->buscar($this->id);
            if (!$r || !$r['aprovado'] || !$this->cnpjConfere($r, $this->perfil())) {
                return Resultado::falha('Aprove o vínculo e confira o CNPJ do restaurante antes de conferir a credencial.');
            }
            $g = $this->global();
            if (!RateLimiter::padrao(10, 3600)->permitir('fiscal-credencial|' . $this->id)) {
                return Resultado::falha('Limite de conferências atingido. Tente mais tarde.');
            }
            $listado = $this->api->tokenDoCliente($g['cnpj'], $g['token'], $r['cnpj']);
            $gravado = is_string($r['dados']['token'] ?? null) ? $r['dados']['token'] : '';
            if ($listado !== null && $gravado !== '' && hash_equals($listado, $gravado) && ($r['dados']['software_house'] ?? '') === $g['cnpj']) {
                Auditoria::registrar($this->usuario, 'Fiscal: credencial conferida', 'Restaurante ' . $this->id . '; credencial confere com a NeXT.');
                return Resultado::sucesso('A credencial do restaurante confere com a NeXT. Nenhuma alteração foi necessária.');
            }
            if ($listado === null) {
                // CNPJ ausente da conta: cadastra de novo (idempotente na NeXT).
                $listado = $this->api->incluirCliente($g['cnpj'], $g['token'], $r['cnpj'], (string) ($r['dados']['empresa']['razao'] ?? ''));
            }
            $r['dados']['token'] = $listado;
            $r['dados']['software_house'] = $g['cnpj'];
            // Certificado e configuração podem ter ido para outra credencial: precisam ser reenviados.
            $r['dados']['certificado'] = null;
            $r['dados']['config_enviada'] = false;
            $r['dados']['status'] = [];
            $this->repo->salvar($this->id, $r);
            Auditoria::registrar($this->usuario, 'Fiscal: credencial corrigida', 'Restaurante ' . $this->id . '; credencial substituída pela registrada na NeXT. Certificado e configuração devem ser reenviados.');
            return Resultado::sucesso('Credencial corrigida com a registrada na NeXT. Peça ao restaurante para testar a conexão e, em homologação, reenviar certificado e configuração.');
        });
    }

    /** CNPJ do restaurante corrigido no cadastro: refaz o vínculo fiscal do zero, preservando os dados da empresa. */
    public function refazerVinculo(): Resultado
    {
        if ($this->usuario->cargo !== 'adm_master') { return Resultado::falha('Ação exclusiva da administração.'); }
        return $this->repo->serializar($this->id, function (): Resultado {
            $r = $this->repo->buscar($this->id);
            $novo = Documento::somenteDigitos((string) ($this->perfil()['cnpj'] ?? ''));
            if ($r === null) { return Resultado::falha('O restaurante ainda não iniciou a configuração fiscal.'); }
            if (!Documento::cnpjValido($novo)) { return Resultado::falha('O CNPJ do cadastro do restaurante é inválido. Corrija o perfil primeiro.'); }
            if ($r['cnpj'] === $novo) { return Resultado::falha('O vínculo fiscal já usa o CNPJ atual do restaurante.'); }
            $sh = (new FiscalNextRepository($this->db))->ler()['dados']['cnpj'] ?? '';
            if ($sh === $novo) { return Resultado::falha('O CNPJ da Software House não pode ser vinculado a um restaurante.'); }
            $anterior = $r['cnpj'];
            $r['cnpj'] = $novo;
            $r['aprovado'] = false;
            unset($r['dados']['token'], $r['dados']['software_house']);
            $r['dados']['certificado'] = null;
            $r['dados']['config_enviada'] = false;
            $r['dados']['status'] = [];
            $r['dados']['ambiente'] = 3;
            self::prepararSimulacao($r['dados']);
            $this->repo->substituirCnpj($this->id, $r);
            Auditoria::registrar($this->usuario, 'Fiscal: vínculo refeito', 'Restaurante ' . $this->id . '; CNPJ final ' . substr($anterior, -4) . ' trocado por final ' . substr($novo, -4) . '. Nova aprovação necessária.');
            return Resultado::sucesso('Vínculo refeito com o CNPJ atual. Confira os dados e aprove o vínculo novamente.');
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
            if (!FiscalAcesso::permitido($this->db,$this->id)) { return Resultado::falha('Módulo não autorizado pela plataforma.'); }
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

    public function consultarEmpresa(bool $atualizar): array
    {
        if (!FiscalAcesso::permitido($this->db,$this->id)) { throw new RuntimeException('Módulo indisponível.'); }
        $r = $this->repo->buscar($this->id);
        if (!$r || !$r['habilitado'] || !$this->cnpjConfere($r,$this->perfil())) {
            throw new RuntimeException('Módulo indisponível.');
        }
        // Cache filtrado, somente na sessão do usuário; nunca a resposta bruta ou os sócios.
        $chave = 'fiscal_consulta_cnpj';
        $cache = Session::get($chave);
        if (!$atualizar && is_array($cache) && ($cache['id'] ?? null) === $this->id && ($cache['cnpj'] ?? '') === $r['cnpj'] && ($cache['expira'] ?? 0) > time() && is_array($cache['dados'] ?? null)) {
            return $cache['dados'];
        }
        if (!RateLimiter::padrao(6,3600)->permitir('fiscal-cnpj|' . $this->id) || !RateLimiter::padrao(20,60)->permitir('fiscal-cnpj-global')) {
            throw new RuntimeException('Limite de consultas.');
        }
        $dados = (new ConsultaCadastroFiscal())->consultar($r['cnpj']);
        // CEP complementa campos ausentes, sem trocar o município/UF cadastral por outro.
        if (isset($dados['cep']) && (!isset($dados['logradouro']) || !isset($dados['bairro']) || !isset($dados['cidade']) || !isset($dados['estado']))) {
            $endereco = (new \Cardapim\Services\Geo\Geocodificador())->cep($dados['cep']);
            if ($endereco !== null && (!isset($dados['estado']) || $dados['estado'] === $endereco['estado'])
                && (!isset($dados['cidade']) || TextoLocal::chave($dados['cidade']) === TextoLocal::chave($endereco['cidade']))) {
                foreach (['logradouro'=>120,'bairro'=>80,'cidade'=>80,'estado'=>2] as $k=>$max) {
                    if (!isset($dados[$k]) && is_string($endereco[$k] ?? null) && $endereco[$k] !== '') {
                        $dados[$k] = mb_substr($endereco[$k],0,$max);
                    }
                }
            }
        }
        Session::set($chave,['id'=>$this->id,'cnpj'=>$r['cnpj'],'expira'=>time()+86400,'dados'=>$dados]);
        return $dados;
    }

    /** Cadastro pendente pode conectar em simulação, mas não preparar emissão em homologação. */
    public static function empresaPronta(array $e): bool
    {
        return ($e['ie_situacao'] ?? (preg_match('/\A[0-9]{2,20}\z/', (string) ($e['ie'] ?? '')) ? 'possui' : 'pendente')) === 'possui'
            && preg_match('/\A[0-9]{2,20}\z/', (string) ($e['ie'] ?? '')) === 1
            && ($e['ibge_verificado'] ?? false) === true
            && preg_match('/\A[0-9]{7}\z/', (string) ($e['ibge'] ?? '')) === 1;
    }

    /** Explica em linguagem simples o que impede a homologação. */
    public static function pendenciaEmpresa(array $e): string
    {
        if (($e['ie_situacao'] ?? '') === 'isento') {
            return 'A NFC-e exige a inscrição estadual (CGF no Ceará) do restaurante. Confirme com o contador e informe o número em Dados fiscais da empresa.';
        }
        if (!preg_match('/\A[0-9]{2,20}\z/', (string) ($e['ie'] ?? ''))) {
            return 'Informe a inscrição estadual (CGF no Ceará) em Dados fiscais da empresa antes da homologação.';
        }
        return 'Não foi possível identificar o município. Confira cidade, UF e CEP em Dados fiscais da empresa e salve de novo.';
    }

    public function empresa(array $p): Resultado
    {
        $campos = ['razao' => 160, 'nome' => 120, 'cep' => 9, 'logradouro' => 120, 'numero' => 20, 'complemento' => 100, 'bairro' => 80, 'cidade' => 80, 'estado' => 2, 'crt' => 1, 'email' => 160];
        $d = [];
        foreach ($campos as $c => $max) {
            $d[$c] = self::campo($p, $c);
            if (mb_strlen($d[$c]) > $max || (!in_array($c,['complemento','nome','email','bairro'],true) && $d[$c] === '')) {
                return Resultado::falha('Confira os campos obrigatórios dos dados fiscais.');
            }
        }
        $d['cep'] = Documento::somenteDigitos($d['cep']);
        $d['estado'] = strtoupper($d['estado']);
        $situacao = self::campo($p,'ie_situacao');
        $ie = self::campo($p,'ie');
        if (!MunicipioIbge::ufValida($d['estado']) || strlen($d['cep']) !== 8 || !in_array($d['crt'], ['1','2','3','4'], true) || ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL))) {
            return Resultado::falha('Confira CEP, UF, regime tributário e e-mail.');
        }
        if (!in_array($situacao,['possui','isento','pendente'],true) || ($situacao === 'possui' && preg_match('/\A[0-9]{2,20}\z/', $ie) !== 1)) {
            return Resultado::falha('Informe a situação da inscrição estadual e, se possuir, seus dígitos.');
        }
        $d['ie_situacao'] = $situacao;
        $d['ie'] = $situacao === 'possui' ? $ie : '';
        if ($d['nome'] === '') { $d['nome'] = mb_substr($d['razao'],0,120); }
        return $this->operacao(function (array &$r) use ($d): Resultado {
            $anterior = $r['dados']['empresa'] ?? [];
            $mesmo = ($anterior['ibge_verificado'] ?? false) === true && ($anterior['estado'] ?? '') === $d['estado']
                && TextoLocal::chave($anterior['cidade'] ?? '') === TextoLocal::chave($d['cidade']);
            $ibge = $mesmo ? ($anterior['ibge'] ?? null) : null;
            if (!$mesmo && RateLimiter::padrao(20,3600)->permitir('fiscal-ibge|' . $this->id)) {
                $ibge = (new MunicipioIbge())->resolver($d['cidade'],$d['estado'],$d['cep']);
            }
            $d['ibge'] = $ibge ?? '';
            $d['ibge_verificado'] = $ibge !== null && $ibge !== '';
            $r['dados']['empresa'] = $d;
            $r['dados']['config_enviada'] = false;
            $r['dados']['status'] = [];
            return Resultado::sucesso(self::empresaPronta($d) ? 'Dados fiscais salvos.' : 'Dados fiscais salvos. Antes da homologação: ' . lcfirst(self::pendenciaEmpresa($d)));
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

    public function consultarGtin(string $ean): array
    {
        if (!\Cardapim\Modules\Fiscal\CadastroProdutoFiscal::gtin($ean)) { throw new RuntimeException('Código de barras inválido.'); }
        return $this->repo->serializar($this->id,function () use ($ean): array {
            if (!FiscalAcesso::permitido($this->db,$this->id)) { throw new RuntimeException('Módulo indisponível.'); }
            $r=$this->repo->buscar($this->id);
            if (!$r || !$r['habilitado'] || !$this->cnpjConfere($r,$this->perfil())) { throw new RuntimeException('Módulo indisponível.'); }
            if (($r['dados']['ambiente'] ?? 3)!==2 || ($r['dados']['certificado']['expira_em'] ?? 0)<=time()) {
                throw new RuntimeException('A consulta oficial de GTIN exige certificado A1 válido e ambiente de homologação.');
            }
            [$sh,$cnpj,$token]=$this->credenciais($r);
            if (!RateLimiter::padrao(30,3600)->permitir('fiscal-gtin|'.$this->id) || !RateLimiter::padrao(60,60)->permitir('fiscal-gtin-global')) {
                throw new RuntimeException('Limite de consultas atingido.');
            }
            return $this->api->gtin($sh,$cnpj,$token,$ean);
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

    /** Convenção interna da simulação; sem credenciais SEFAZ fictícias.
     * A reserva/incremento transacional da numeração pertence ao motor de emissão (etapa 04).
     */
    public static function configSimulacao(array $anterior): array
    {
        $numero = filter_var($anterior['numero'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>999999999]]);
        return ['serie'=>999, 'numero'=>$numero === false ? 1 : $numero, 'id_csc'=>'', 'csc'=>''];
    }

    private static function prepararSimulacao(array &$dados): void
    {
        $dados['config'][3] = self::configSimulacao($dados['config'][3] ?? []);
    }

    public function ambiente(string $amb): Resultado
    {
        if (!in_array($amb, ['2','3'], true)) {
            return Resultado::falha('Produção bloqueada nesta etapa.');
        }
        return $this->operacao(function (array &$r) use ($amb): Resultado {
            $r['dados']['ambiente'] = (int) $amb;
            if ($amb === '3') { self::prepararSimulacao($r['dados']); }
            return Resultado::sucesso('Ambiente selecionado.');
        });
    }

    public function configurar(array $p): Resultado
    {
        // Séries 890 a 999 são reservadas (nota avulsa e contingência).
        $serie = filter_var(self::campo($p, 'serie'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>889]]);
        $numero = filter_var(self::campo($p, 'numero'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>999999999]]);
        $id = self::campo($p, 'id_csc');
        $csc = self::campo($p, 'csc');
        if ($serie === false) { return Resultado::falha('Série: use um número de 0 a 889 (as séries acima são reservadas).'); }
        if ($numero === false) { return Resultado::falha('Próxima numeração: use um número inteiro a partir de 1.'); }
        if (!preg_match('/\A[0-9]{1,6}\z/', $id)) { return Resultado::falha('ID do CSC: informe o número (até 6 dígitos) exibido no portal da SEFAZ junto com o código.'); }
        if (strlen($csc) > 128 || ($csc !== '' && preg_match('/\A[\x21-\x7E]+\z/', $csc) !== 1)) {
            return Resultado::falha('CSC: cole o código exatamente como aparece no portal da SEFAZ, sem espaços.');
        }
        return $this->operacao(function (array &$r) use ($serie, $numero, $id, $csc): Resultado {
            $amb = $r['dados']['ambiente'] ?? 3;
            if ($amb !== 2) {
                return Resultado::falha('Os parâmetros da simulação são automáticos. Selecione homologação para editar parâmetros fiscais.');
            }
            $old = $r['dados']['config'][$amb] ?? [];
            $cscFinal = $csc !== '' ? $csc : ($id === ($old['id_csc'] ?? '') ? ($old['csc'] ?? '') : '');
            if ($cscFinal === '') {
                // NFC-e: CSC e ID do CSC são obrigatórios em homologação e produção (QR Code).
                return Resultado::falha('CSC: informe o código de segurança do contribuinte. Ele é obrigatório na NFC-e e é gerado no portal da SEFAZ do estado.');
            }
            $r['dados']['config'][$amb] = ['serie'=>$serie, 'numero'=>$numero, 'id_csc'=>$id, 'csc'=>$cscFinal];
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
            if (!self::empresaPronta($r['dados']['empresa'] ?? [])) { return Resultado::falha(self::pendenciaEmpresa($r['dados']['empresa'] ?? [])); }
            if (($r['dados']['config'][2]['csc'] ?? '') === '' || ($r['dados']['config'][2]['id_csc'] ?? '') === '') { return Resultado::falha('Salve o CSC e o ID do CSC nos parâmetros antes de enviar o certificado.'); }
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
            return Resultado::sucesso('Certificado enviado à NeXT em homologação. Envie a configuração e teste a conexão fiscal pela NeXT.');
        });
    }

    public function enviarConfig(): Resultado
    {
        return $this->operacao(function (array &$r): Resultado {
            if (($r['dados']['ambiente'] ?? 3) !== 2 || !isset($r['dados']['empresa'], $r['dados']['config'][2]) || ($r['dados']['certificado']['expira_em'] ?? 0) <= time()) {
                return Resultado::falha('Configure homologação, parâmetros e certificado válido primeiro.');
            }
            if (!self::empresaPronta($r['dados']['empresa'] ?? [])) { return Resultado::falha(self::pendenciaEmpresa($r['dados']['empresa'] ?? [])); }
            if (($r['dados']['config'][2]['csc'] ?? '') === '' || ($r['dados']['config'][2]['id_csc'] ?? '') === '') { return Resultado::falha('Salve o CSC e o ID do CSC nos parâmetros antes de enviar a configuração.'); }
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
            if ($amb === 2 && !self::empresaPronta($r['dados']['empresa'] ?? [])) { return Resultado::falha(self::pendenciaEmpresa($r['dados']['empresa'] ?? [])); }
            if ($amb === 2 && ($r['dados']['certificado']['expira_em'] ?? 0) <= time()) { return Resultado::falha('Envie um certificado A1 válido antes do teste em homologação.'); }
            if ($amb === 2 && !($r['dados']['config_enviada'] ?? false)) { return Resultado::falha('Clique em "Enviar configuração" antes do teste em homologação.'); }
            if (!RateLimiter::padrao(20, 3600)->permitir('fiscal-teste|' . $this->id)) {
                return Resultado::falha('Limite de testes atingido.');
            }
            if ($amb === 3) { self::prepararSimulacao($r['dados']); }
            $cod = $this->api->statusCliente($sh, $cnpj, $token, $amb);
            $r['dados']['status'][$amb] = ($cod === 107 ? 'Serviço em operação' : 'Status fiscal via NeXT ' . $cod) . ($amb === 3 ? ' (simulação)' : ' (homologação)');
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
