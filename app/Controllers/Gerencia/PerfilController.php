<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Modules\Restaurante\HorarioFuncionamento;
use Cardapim\Repositories\Gerencia\PerfilRepository;
use Cardapim\Services\Gerencia\PerfilService;
use Cardapim\Shared\Validation\Documento;
use DateTimeImmutable;

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
            return $this->voltar($this->url('perfil') . '#horarios', 'sucesso', $resultado->mensagem);
        }
        return $this->pagina(['horarios' => $resultado->erros], ['horarios' => $_POST], 422);
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
            'abertoAgora' => HorarioFuncionamento::abertoEm($sempre, $turnos, $agora),
            'semHorario' => !$sempre && $turnos === [],
            'erros' => $erros,
            'pageScripts' => ['js/forms.js', 'js/perfil.js'],
        ], $status);
    }

    private function servico(): PerfilService
    {
        return new PerfilService($this->db, $this->usuario, $this->restaurante['id']);
    }
}
