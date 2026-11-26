<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Services\Gerencia\Integracoes\IfoodLojaService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Core\Response;
use Cardapim\Modules\Restaurante\HorarioFuncionamento;
use Cardapim\Repositories\Gerencia\PerfilRepository;
use Cardapim\Services\Gerencia\PerfilService;
use Cardapim\Shared\Validation\Documento;
use DateTimeImmutable;
use Cardapim\Shared\Esquema;
use Cardapim\Services\Cardapio\CardapioPublico;

/** Aba Perfil: dados do restaurante, endereco, logo e horario de funcionamento. */
final class PerfilController extends BaseGerencia
{
    public function mostrar(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->pagina([], []);
    }

    public function salvarDados(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $resultado = $this->servico()->salvarDados($_POST);
        if ($resultado->ok) {
            return $this->voltar($this->url('perfil'), 'sucesso', $resultado->mensagem);
        }
        return $this->pagina(['dados' => $resultado->erros], ['dados' => $_POST], 422);
    }

    public function logo(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $arquivo = is_array($_FILES['logo'] ?? null) ? $_FILES['logo'] : ['error' => UPLOAD_ERR_NO_FILE];
        return $this->responder($this->servico()->trocarLogo($arquivo), $this->url('perfil') . '#logo');
    }

    public function removerLogo(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->responder($this->servico()->removerLogo(), $this->url('perfil') . '#logo');
    }

    public function horarios(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $resultado = $this->servico()->salvarHorarios($_POST);
        if ($resultado->ok) {
            // Se o iFood segue o horario do Perfil, ele recebe o horario novo agora (falha vira aviso, o Perfil ja foi salvo).
            try {
                $ifood = (new IfoodLojaService($this->db, $this->usuario, $this->restaurante['id']))->acompanharPerfil();
            } catch (\Throwable) {
                $ifood = Resultado::falha('O horário foi salvo aqui, mas não deu para enviar ao iFood agora. Envie em Integrações > iFood > Loja no iFood.');
            }
            return $this->voltar($this->url('perfil') . '#horarios', $ifood !== null && !$ifood->ok ? 'aviso' : 'sucesso',
                $resultado->mensagem . ($ifood !== null ? ' ' . $ifood->mensagem : ''));
        }
        return $this->pagina(['horarios' => $resultado->erros], ['horarios' => $_POST], 422);
    }

    public function fechamento(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $resultado = $this->servico()->definirFechamento($_POST['data'] ?? null, $_POST['fechado'] ?? null);
        if (!$resultado->ok) {
            return Response::json(['ok' => false, 'mensagem' => $resultado->mensagem], 422);
        }
        $info = (new CardapioPublico($this->db))->informacoes($this->restaurante['id']);
        return Response::json(['ok' => true, 'mensagem' => $resultado->mensagem,
            'fechado' => $_POST['fechado'] === '1', 'aberto' => $info['aberto'], 'horario_informado' => $info['horario_informado']]);
    }

    /**
     * @param array<string,array<string,string>> $erros por formulario
     * @param array<string,array<string,mixed>> $enviado o que foi digitado, por formulario
     */
    private function pagina(array $erros, array $enviado, int $status = 200): Response
    {
        $repo = new PerfilRepository($this->db);
        $perfil = $repo->buscar($this->restaurante['id']) ?? [];
        $turnos = $repo->horarios($this->restaurante['id']);
        $sempre = (int) ($perfil['sempre_aberto'] ?? 0) === 1;
        $agora = new DateTimeImmutable('now');
        $fechamentos = $repo->fechamentos($this->restaurante['id']);

        $dados = $enviado['dados'] ?? [
            'nome' => $perfil['nome'] ?? '',
            'telefone' => isset($perfil['telefone']) ? Documento::formatarTelefone((string) $perfil['telefone']) : '',
            'email' => $perfil['email'] ?? '',
            'cep' => isset($perfil['cep']) ? substr((string) $perfil['cep'], 0, 5) . '-' . substr((string) $perfil['cep'], 5) : '',
        ] + array_map(static fn ($v): string => (string) ($v ?? ''), array_intersect_key($perfil, array_flip(PerfilRepository::CAMPOS_ENDERECO)));

        return $this->tela('gerencia/perfil', 'perfil', 'Perfil do restaurante', [
            'perfil' => $perfil,
            'dados' => array_map(static fn ($v): string => is_string($v) ? $v : '', $dados),
            'horario' => $enviado['horarios'] ?? null,
            'turnosPorDia' => HorarioFuncionamento::porDia($turnos),
            'sempreAberto' => $sempre,
            'abertoAgora' => HorarioFuncionamento::abertoEm($sempre, $turnos, $agora, $fechamentos),
            'semHorario' => !$sempre && $turnos === [] && !in_array($agora->format('Y-m-d'), $fechamentos, true),
            'fechamentos' => $fechamentos,
            'datasFechamento' => HorarioFuncionamento::proximasDatas($agora),
            'fechamentoDisponivel' => Esquema::tem($this->db, 'fechamento_temporario'),
            'erros' => $erros,
            'pageScripts' => ['js/forms.js', 'js/perfil.js'],
        ], $status);
    }

    private function servico(): PerfilService
    {
        return new PerfilService($this->db, $this->usuario, $this->restaurante['id']);
    }
}
