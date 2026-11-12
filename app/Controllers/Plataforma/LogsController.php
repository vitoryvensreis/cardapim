<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Response;
use Cardapim\Repositories\Plataforma\LogAdminRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\Guard;
use Cardapim\Shared\Planilha;

/** Logs de auditoria (/painel/logs): tudo o que foi feito na plataforma, com filtros e planilha. So leitura. */
final class LogsController extends BasePlataforma
{
    private const CARGOS = [
        'adm_master' => 'Administrador', 'gerente' => 'Gerente', 'caixa' => 'Caixa', 'garcom' => 'Garçom',
        'motoqueiro' => 'Entregador', 'cliente' => 'Cliente',
    ];

    public function lista(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $repo = new LogAdminRepository($this->db);
        $filtros = self::filtros();
        $pagina = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
        $res = $repo->listar($filtros, $pagina);
        return $this->tela('plataforma/logs', 'logs', 'Logs de auditoria', [
            'logs' => $res['itens'],
            'total' => $res['total'],
            'pagina' => $pagina,
            'paginas' => max(1, (int) ceil($res['total'] / LogAdminRepository::POR_PAGINA)),
            'filtros' => $filtros,
            'restaurantes' => $repo->restaurantes(),
            'cargos' => self::CARGOS,
            'pageStyles' => ['css/plataforma-gestao.css', 'css/logs.css'],
        ]);
    }

    /** Planilha com os registros dos filtros atuais (ate 20 mil linhas). */
    public function planilha(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $filtros = self::filtros();
        $linhas = (static function (iterable $logs): \Generator {
            yield ['Data', 'Hora', 'O que aconteceu', 'Detalhes', 'Quem', 'Usuário', 'Função', 'Restaurante', 'IP'];
            foreach ($logs as $l) {
                $t = (int) strtotime((string) $l['data_hora']);
                yield [date('d/m/Y', $t), date('H:i:s', $t), (string) $l['titulo'], (string) $l['descricao'],
                    $l['id_user'] !== null ? trim($l['nome'] . ' ' . $l['sobrenome']) : 'Sistema ou cliente', (string) ($l['usuario'] ?? ''),
                    self::CARGOS[$l['cargo'] ?? ''] ?? '', (string) ($l['restaurante_nome'] ?? ($l['cargo'] === 'adm_master' ? 'Plataforma' : '')), (string) ($l['ip'] ?? '')];
            }
        })((new LogAdminRepository($this->db))->paraPlanilha($filtros));
        $csv = Planilha::csv($linhas);
        Auditoria::registrar($this->usuario, 'Logs exportados', 'Planilha dos logs de auditoria baixada.');
        return Guard::semCache(new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="logs-' . date('Y-m-d-His') . '.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    /** @return array{tipo:?string, restaurante:?int, pessoa:string, busca:string, de:?string, ate:?string} */
    private static function filtros(): array
    {
        $texto = static fn (string $c): string => mb_substr(trim(is_string($_GET[$c] ?? null) ? $_GET[$c] : ''), 0, 80);
        $data = static function (string $c): ?string {
            $v = $_GET[$c] ?? '';
            return is_string($v) && preg_match('/\A20\d{2}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])\z/', $v) === 1 && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? $v : null;
        };
        $rest = $_GET['restaurante'] ?? '';
        $restaurante = $rest === 'plataforma' ? 0 : (is_string($rest) && ctype_digit($rest) && strlen($rest) <= 18 ? (int) $rest : null);
        $de = $data('de');
        $ate = $data('ate');
        if ($de !== null && $ate !== null && $ate < $de) {
            [$de, $ate] = [$ate, $de];
        }
        return [
            'tipo' => array_key_exists($_GET['tipo'] ?? '', LogAdminRepository::TIPOS) ? (string) $_GET['tipo'] : null,
            'restaurante' => $restaurante,
            'pessoa' => $texto('pessoa'),
            'busca' => $texto('busca'),
            'de' => $de,
            'ate' => $ate,
        ];
    }
}
