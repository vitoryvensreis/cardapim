<?php

declare(strict_types=1);

namespace Cardapim\Services\Suporte;

use Cardapim\Core\Config;
use Cardapim\Repositories\SuporteRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Email\Mailer;
use Cardapim\Services\Gerencia\Resultado;
use PDO;

/**
 * Regras do suporte: o gerente abre e responde chamados do PROPRIO restaurante; a administracao responde e
 * muda a situacao de qualquer chamado. Cada lado recebe um aviso por e-mail quando o outro escreve.
 */
final class SuporteService
{
    public const CATEGORIAS = [
        'duvida' => 'Dúvida de como usar',
        'problema' => 'Algo não está funcionando',
        'pedidos' => 'Pedidos, caixa ou delivery',
        'integracoes' => 'iFood, WhatsApp ou Asaas',
        'financeiro' => 'Assinatura e pagamento',
        'sugestao' => 'Sugestão de melhoria',
        'outro' => 'Outro assunto',
    ];

    public const STATUS = [
        'aberto' => 'Aguardando o suporte',
        'em_atendimento' => 'Em atendimento',
        'resolvido' => 'Resolvido',
    ];

    private const MAX_TEXTO = 4000;

    private readonly SuporteRepository $repo;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor)
    {
        $this->repo = new SuporteRepository($db);
    }

    /** Gerente abre um chamado. @param array<string,mixed> $d */
    public function abrir(int $idRestaurante, string $nomeRestaurante, string $slug, array $d): Resultado
    {
        $assunto = self::limpar((string) ($d['assunto'] ?? ''), false);
        $categoria = (string) ($d['categoria'] ?? '');
        $texto = self::limpar((string) ($d['mensagem'] ?? ''), true);
        $erros = [];
        if (mb_strlen($assunto) < 3 || mb_strlen($assunto) > 120) {
            $erros['assunto'] = 'Escreva o assunto em poucas palavras (de 3 a 120 letras).';
        }
        if (!isset(self::CATEGORIAS[$categoria])) {
            $erros['categoria'] = 'Escolha sobre o que é o chamado.';
        }
        if (mb_strlen($texto) < 10 || mb_strlen($texto) > self::MAX_TEXTO) {
            $erros['mensagem'] = 'Conte o que aconteceu (de 10 a ' . self::MAX_TEXTO . ' letras).';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        if ($this->abertosHoje($idRestaurante) >= 20) {
            return Resultado::falha('Muitos chamados abertos hoje. Responda nos chamados que já existem.');
        }
        $id = $this->repo->criar($idRestaurante, $this->autor->id, $assunto, $categoria, $texto);
        Auditoria::registrar($this->autor, 'Suporte: chamado aberto', sprintf('Chamado #%d: %s (%s).', $id, $assunto, self::CATEGORIAS[$categoria]));
        $link = self::url('/painel/suporte/' . $id);
        foreach ($this->repo->emailsAdministradores() as $email) {
            Mailer::agendar($email, '[Suporte] Chamado #' . $id . ' de ' . $nomeRestaurante . ': ' . $assunto,
                "Novo chamado de {$nomeRestaurante} (" . trim($this->autor->nome . ' ' . $this->autor->sobrenome) . ").\n\nAssunto: {$assunto}\nSobre: " . self::CATEGORIAS[$categoria] . "\n\n{$texto}\n\nResponder: {$link}\n");
        }
        return Resultado::sucesso('Chamado #' . $id . ' aberto. Você recebe a resposta aqui e por e-mail.', $id);
    }

    /** Gerente responde no chamado do proprio restaurante. */
    public function responderRestaurante(int $idRestaurante, int $id, string $texto): Resultado
    {
        $c = $this->repo->buscar($id, $idRestaurante);
        if ($c === null) {
            return Resultado::falha('Chamado não encontrado.');
        }
        $texto = self::limpar($texto, true);
        if (mb_strlen($texto) < 2 || mb_strlen($texto) > self::MAX_TEXTO) {
            return Resultado::falha('Escreva a sua mensagem.', ['mensagem' => 'Escreva a mensagem (até ' . self::MAX_TEXTO . ' letras).']);
        }
        $reaberto = $c['status'] === 'resolvido';
        $this->repo->responder($c, $this->autor->id, 'restaurante', $texto);
        Auditoria::registrar($this->autor, 'Suporte: resposta enviada', sprintf('Chamado #%d%s.', $id, $reaberto ? ' (reaberto)' : ''));
        $link = self::url('/painel/suporte/' . $id);
        foreach ($this->repo->emailsAdministradores() as $email) {
            Mailer::agendar($email, '[Suporte] Nova mensagem no chamado #' . $id . ': ' . $c['assunto'],
                "{$c['restaurante_nome']} escreveu no chamado #{$id}" . ($reaberto ? ' (reaberto)' : '') . ":\n\n{$texto}\n\nResponder: {$link}\n");
        }
        return Resultado::sucesso($reaberto ? 'Mensagem enviada e chamado reaberto.' : 'Mensagem enviada ao suporte.');
    }

    /** Gerente marca o proprio chamado como resolvido. */
    public function resolverRestaurante(int $idRestaurante, int $id): Resultado
    {
        $c = $this->repo->buscar($id, $idRestaurante);
        if ($c === null) {
            return Resultado::falha('Chamado não encontrado.');
        }
        if ($c['status'] === 'resolvido') {
            return Resultado::falha('Este chamado já está resolvido.');
        }
        $this->repo->mudarStatus($id, 'resolvido');
        Auditoria::registrar($this->autor, 'Suporte: chamado resolvido', sprintf('Chamado #%d marcado como resolvido pelo restaurante.', $id));
        return Resultado::sucesso('Que bom! Chamado #' . $id . ' resolvido. Se precisar, é só responder nele de novo.');
    }

    /** Administracao responde (e opcionalmente ja resolve). */
    public function responderSuporte(int $id, string $texto, bool $resolver): Resultado
    {
        $c = $this->repo->buscar($id, null);
        if ($c === null) {
            return Resultado::falha('Chamado não encontrado.');
        }
        $texto = self::limpar($texto, true);
        if (mb_strlen($texto) < 2 || mb_strlen($texto) > self::MAX_TEXTO) {
            return Resultado::falha('Escreva a resposta.', ['mensagem' => 'Escreva a resposta (até ' . self::MAX_TEXTO . ' letras).']);
        }
        $this->repo->responder($c, $this->autor->id, 'suporte', $texto);
        if ($resolver && $c['status'] !== 'resolvido') {
            $this->repo->mudarStatus($id, 'resolvido');
        }
        Auditoria::registrar($this->autor, 'Suporte: resposta da plataforma', sprintf('Chamado #%d de %s%s.', $id, $c['restaurante_nome'], $resolver ? ' (resolvido)' : ''));
        $this->avisarRestaurante($c, $resolver
            ? "O suporte respondeu e marcou o chamado como resolvido:\n\n{$texto}"
            : "O suporte respondeu:\n\n{$texto}");
        return Resultado::sucesso('Resposta enviada' . ($resolver ? ' e chamado resolvido.' : '. O restaurante foi avisado.'));
    }

    /** Administracao muda a situacao: em_atendimento, resolvido ou aberto (reabrir). */
    public function mudarStatus(int $id, string $status): Resultado
    {
        $c = $this->repo->buscar($id, null);
        if ($c === null) {
            return Resultado::falha('Chamado não encontrado.');
        }
        if (!isset(self::STATUS[$status])) {
            return Resultado::falha('Situação inválida.');
        }
        if ($c['status'] === $status) {
            return Resultado::falha('O chamado já está nesta situação.');
        }
        $this->repo->mudarStatus($id, $status);
        Auditoria::registrar($this->autor, 'Suporte: situação alterada', sprintf('Chamado #%d de %s: %s -> %s.', $id, $c['restaurante_nome'], self::STATUS[$c['status']], self::STATUS[$status]));
        if ($status === 'resolvido') {
            $this->avisarRestaurante($c, 'O suporte marcou o chamado como resolvido. Se ainda precisar de ajuda, é só responder nele.');
        }
        return Resultado::sucesso('Chamado #' . $id . ': ' . mb_strtolower(self::STATUS[$status]) . '.');
    }

    /** @param array<string,mixed> $c */
    private function avisarRestaurante(array $c, string $texto): void
    {
        if (!is_string($c['autor_email'] ?? null) || (int) ($c['autor_ativo'] ?? 0) !== 1 || $c['autor_excluido'] !== null) {
            return;
        }
        $link = self::url('/' . rawurlencode((string) $c['restaurante_slug']) . '/gerencia/suporte/' . (int) $c['id']);
        Mailer::agendar((string) $c['autor_email'], 'Chamado #' . (int) $c['id'] . ' respondido: ' . $c['assunto'],
            "Olá!\n\n{$texto}\n\nVer o chamado: {$link}\n\nEquipe " . marca() . "\n");
    }

    private function abertosHoje(int $idRestaurante): int
    {
        $q = $this->db->prepare('SELECT COUNT(*) FROM suporte_chamados WHERE id_restaurante = ? AND criado_em >= ?');
        $q->execute([$idRestaurante, date('Y-m-d 00:00:00')]);
        return (int) $q->fetchColumn();
    }

    private static function limpar(string $texto, bool $multilinha): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = (string) preg_replace('/[^\P{C}\n]/u', '', $texto); // tira caracteres de controle (mantem quebra de linha)
        if (!$multilinha) {
            $texto = (string) preg_replace('/\s+/u', ' ', $texto);
        } else {
            $texto = (string) preg_replace("/\n{3,}/", "\n\n", $texto);
        }
        return trim($texto);
    }

    private static function url(string $caminho): string
    {
        return rtrim(Config::get('APP_URL'), '/') . $caminho;
    }
}
