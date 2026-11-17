<?php

declare(strict_types=1);

// Datas da API sao instantes UTC, mas o formulario usa o relogio da loja.
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Integracoes\IfoodApi;
use Cardapim\Services\Gerencia\Integracoes\IfoodLojaService;
use Cardapim\Services\Ifood\FusoLojaIfood;

$erros = 0;
$verificar = static function (bool $sim, string $descricao) use (&$erros): void {
    if (!$sim) {
        fwrite(STDERR, "FALHOU: $descricao\n");
        $erros++;
    }
};

$acre = ['address' => ['city' => 'Bujari', 'state' => 'AC']];
$ceara = ['address' => ['city' => 'Fortaleza', 'state' => 'CE']];
$manaus = ['address' => ['city' => 'Manaus', 'state' => 'AM']];
$tabatinga = ['address' => ['city' => 'Tabatinga', 'state' => 'AM']];
$noronha = ['address' => ['city' => 'Fernando de Noronha', 'state' => 'PE']];

$verificar(FusoLojaIfood::sugerir($acre) === 'America/Rio_Branco', 'Acre UTC-05');
$verificar(FusoLojaIfood::sugerir($ceara) === 'America/Sao_Paulo', 'Ceara UTC-03');
$verificar(FusoLojaIfood::sugerir($manaus) === 'America/Manaus', 'Manaus UTC-04');
$verificar(FusoLojaIfood::sugerir($tabatinga) === 'America/Rio_Branco', 'Amazonas ocidental UTC-05');
$verificar(FusoLojaIfood::sugerir($noronha) === 'America/Noronha', 'Fernando de Noronha UTC-02');
$verificar(!FusoLojaIfood::valido('Mars/Phobos'), 'fuso arbitrario rejeitado');

$instante = IfoodApi::data('2026-11-17T17:46:01');
$verificar($instante !== null, 'data da resposta iFood reconhecida');
$verificar($instante?->getTimestamp() === strtotime('2026-11-17T17:46:01Z'), 'sem offset interpretado em UTC observado no Postman');
$verificar($instante?->setTimezone(FusoLojaIfood::timezone('America/Rio_Branco'))->format('H:i') === '12:46', 'mesmo instante exibido no Acre');
$verificar($instante?->setTimezone(FusoLojaIfood::timezone('America/Sao_Paulo'))->format('H:i') === '14:46', 'mesmo instante exibido em Fortaleza');

$padrao = IfoodLojaService::novaPausa('America/Rio_Branco');
$inicio = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $padrao['inicio'], FusoLojaIfood::timezone('America/Rio_Branco'));
$fim = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $padrao['fim'], FusoLojaIfood::timezone('America/Rio_Branco'));
$verificar($inicio !== false && $fim !== false && $fim->getTimestamp() - $inicio->getTimestamp() === 3600, 'intervalo inicial de 60 minutos no Acre');

$horaFortaleza = new DateTimeImmutable('2026-11-17T15:40:00', FusoLojaIfood::timezone('America/Sao_Paulo'));
$verificar(IfoodLojaService::utc($horaFortaleza) === '2026-11-17T18:40:00Z', 'hora Fortaleza se normaliza para UTC com +3 horas, nao eh bug');
$horaAcre = new DateTimeImmutable('2026-11-17T15:40:00', FusoLojaIfood::timezone('America/Rio_Branco'));
$verificar(IfoodLojaService::utc($horaAcre) === '2026-11-17T20:40:00Z', 'hora Acre se normaliza para UTC com +5 horas');

if ($erros !== 0) {
    exit(1);
}
echo "OK: horarios Merchant no fuso do estabelecimento, sem modo imediato adicional.\n";
