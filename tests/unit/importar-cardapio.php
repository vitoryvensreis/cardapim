<?php

declare(strict_types=1);

// Teste sem banco da importacao do cardapio por planilha: php tests/unit/importar-cardapio.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Catalog\LeitorPlanilha;
use Cardapim\Modules\Catalog\PlanilhaCardapio;
use Cardapim\Shared\Planilha;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    if (!$cond) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $nome\n");
    }
};
$erroCom = static fn (array $plano, int $linha, string $trecho): bool => array_filter($plano['erros'],
    static fn (array $e): bool => $e['linha'] === $linha && str_contains($e['mensagem'], $trecho)) !== [];

// ---------- CSV ----------
$csv = "\u{FEFF}Categoria;Produto;Preço\nLanches;X-Burger;24,90\n;X-Salada;\"22,90\"\n";
$l = LeitorPlanilha::csv($csv);
$ok($l[1] === ['Categoria', 'Produto', 'Preço'] && $l[2] === ['Lanches', 'X-Burger', '24,90'] && $l[3] === ['', 'X-Salada', '22,90'], 'csv com ; e BOM');
$l = LeitorPlanilha::csv("Produto,Preço\n\"Pão, ovo\",5.50\n");
$ok($l[2] === ['Pão, ovo', '5.50'], 'csv com virgula e aspas');
$l = LeitorPlanilha::csv("Produto\tPreço\nSuco\t7\n");
$ok($l[2] === ['Suco', '7'], 'csv com tabulacao');
$l = LeitorPlanilha::csv(mb_convert_encoding("Produto;Preço\nCajá;7,99\n", 'Windows-1252', 'UTF-8'));
$ok($l[1][1] === 'Preço' && $l[2][0] === 'Cajá', 'csv do Excel em ANSI vira UTF-8');
$l = LeitorPlanilha::csv("Produto;Preço\n  Batata   frita ;18\n");
$ok($l[2][0] === 'Batata frita', 'espacos sobrando removidos');
try {
    LeitorPlanilha::csv("a\0b");
    $ok(false, 'binario recusado');
} catch (RuntimeException) {
    $ok(true, 'binario recusado');
}

// ---------- Numero do Excel ----------
$ok(LeitorPlanilha::numero('12.990000000000002') === '12.99' && LeitorPlanilha::numero('101') === '101' && LeitorPlanilha::numero('9.5') === '9.5', 'numero do excel sem ruido');

// ---------- XLSX (gerado aqui) ----------
if (class_exists(ZipArchive::class)) {
    $xlsx = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($xlsx, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Cardapio" sheetId="1" r:id="rId9"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId9" Type="x" Target="worksheets/aba.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Produto</t></si><si><t>Preço</t></si><si><r><t>Suco de </t></r><r><t>Cajá</t></r></si></sst>');
    $zip->addFromString('xl/worksheets/aba.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
        . '<row r="3"><c r="A3" t="s"><v>2</v></c><c r="B3"><v>12.990000000000002</v></c></row>'
        . '<row r="4"><c r="B4"><v>7</v></c><c r="A4" t="inlineStr"><is><t>Água</t></is></c></row>'
        . '</sheetData></worksheet>');
    $zip->close();
    $l = LeitorPlanilha::ler($xlsx, 'cardapio.xlsx');
    $ok(($l[1] ?? null) === ['Produto', 'Preço'], 'xlsx: titulos dos textos compartilhados');
    $ok(!isset($l[2]) && ($l[3] ?? null) === ['Suco de Cajá', '12.99'], 'xlsx: numero da linha real e texto com partes');
    $ok(($l[4] ?? null) === ['Água', '7'], 'xlsx: texto direto na celula e colunas pela referencia');
    // Mesmo conteudo com extensao trocada: recusado (nao confia so no nome).
    try {
        LeitorPlanilha::ler($xlsx, 'cardapio.csv');
        $ok(false, 'xlsx com nome .csv recusado');
    } catch (RuntimeException) {
        $ok(true, 'xlsx com nome .csv recusado');
    }
    unlink($xlsx);
}
$tmp = tempnam(sys_get_temp_dir(), 'pl');
file_put_contents($tmp, "Produto;Preço\nSuco;7\n");
try {
    LeitorPlanilha::ler($tmp, 'cardapio.xls');
    $ok(false, 'xls antigo recusado');
} catch (RuntimeException $e) {
    $ok(str_contains($e->getMessage(), '.xlsx'), 'xls antigo recusado com orientacao');
}
try {
    LeitorPlanilha::ler($tmp, 'foto.png');
    $ok(false, 'outra extensao recusada');
} catch (RuntimeException) {
    $ok(true, 'outra extensao recusada');
}
$ok(LeitorPlanilha::ler($tmp, 'cardapio.CSV')[2] === ['Suco', '7'], 'csv lido pelo caminho');
unlink($tmp);

// ---------- Regras da planilha ----------
$ok(PlanilhaCardapio::preco('24,90') === '24.90' && PlanilhaCardapio::preco('R$ 1.234,56') === '1234.56' && PlanilhaCardapio::preco('24.9') === '24.90', 'precos aceitos');
$ok(PlanilhaCardapio::preco('12.9900001') === '12.99' && PlanilhaCardapio::preco('abc') === null && PlanilhaCardapio::preco('-3') === null, 'precos arredondados e invalidos');
$ok(PlanilhaCardapio::chave('  Cajá   Grande ') === 'caja grande', 'chave sem acento');

$linhas = LeitorPlanilha::csv(Planilha::csv(PlanilhaCardapio::modelo()));
$plano = PlanilhaCardapio::interpretar($linhas);
$ok($plano['erros'] === [], 'modelo baixado nao tem erros: ' . json_encode($plano['erros'], JSON_UNESCAPED_UNICODE));
$ok(count($plano['produtos']) === 6, 'modelo tem 6 produtos');
$xb = $plano['produtos'][0];
$ok($xb['nome'] === 'X-Burger' && $xb['categoria'] === 'Lanches' && $xb['preco'] === '24.90' && $xb['codigo'] === '101', 'produto lido');
$ok(count($xb['grupos']) === 1 && $xb['grupos'][0]['nome'] === 'Adicionais' && $xb['grupos'][0]['minimo'] === 0 && $xb['grupos'][0]['maximo'] === 3
    && array_column($xb['grupos'][0]['opcoes'], 'nome') === ['Bacon', 'Ovo', 'Cheddar'] && $xb['grupos'][0]['opcoes'][0]['preco'] === '4.00', 'grupo com opcoes nas linhas de baixo');
$ok($plano['produtos'][1]['categoria'] === 'Lanches' && $plano['produtos'][1]['grupos'] === [], 'categoria vazia herda a de cima; sem complementos');
$suco = $plano['produtos'][4];
$ok(count($suco['grupos']) === 2 && $suco['grupos'][0]['nome'] === 'Sabor' && $suco['grupos'][0]['minimo'] === 1 && $suco['grupos'][0]['opcoes'][1] === ['nome' => 'Acerola', 'preco' => '0.00']
    && $suco['grupos'][1]['nome'] === 'Tamanho' && $suco['grupos'][1]['opcoes'][1]['preco'] === '5.00', 'dois grupos no mesmo produto, opcao gratis');

// Titulos em outra ordem, com nomes alternativos e colunas extras
$p = PlanilhaCardapio::interpretar([1 => ['Valor', 'Observação', 'Nome do produto'], 2 => ['8', 'x', 'Água']]);
$ok($p['erros'] === [] && $p['produtos'][0]['nome'] === 'Água' && $p['produtos'][0]['preco'] === '8.00' && $p['produtos'][0]['categoria'] === null, 'colunas por nome, em qualquer ordem');

// Erros
$p = PlanilhaCardapio::interpretar([1 => ['Categoria', 'Item']]);
$ok($erroCom($p, 1, 'Produto') && $p['produtos'] === [], 'sem coluna de preco');
$ok(PlanilhaCardapio::interpretar([])['erros'][0]['mensagem'] === 'A planilha está vazia.', 'planilha vazia');
$ok($erroCom(PlanilhaCardapio::interpretar([1 => ['Produto', 'Preço']]), 1, 'nenhum produto'), 'so titulos');

$t = PlanilhaCardapio::TITULOS;
$p = PlanilhaCardapio::interpretar([
    1 => $t,
    2 => ['Bebidas', 'Suco', '', '', '', '', '', '', '', ''],
    3 => ['', 'Suco', '', '7', '', '', '', '', '', ''],
    4 => ['', 'Refri', '', 'sete', 'A B', '', '', '', '', ''],
    5 => ['', 'Chá', '', '5', '9', '', '', '', '', ''],
    6 => ['', 'Café', '', '4', '9', '', '', '', '', ''],
    7 => ['', '', '', '', '', '', '', '', 'Grande', '2'],
    8 => ['', 'Lanche', '', '10', '', 'Molho', 'talvez', '0', 'Alho', 'x'],
    9 => ['', '', '', '', '', 'Molho', '', '', 'Alho', ''],
    10 => ['', '', '', '', '', 'Ponto', '', '', '', ''],
    11 => ['', '', '', '9,00', '', '', '', '', '', ''],
    12 => ['X', '', '', '', '', '', '', '', '', ''],
]);
$ok($erroCom($p, 2, 'falta o preço') || $erroCom($p, 2, 'Falta o preço'), 'preco obrigatorio');
$ok($erroCom($p, 3, 'aparece de novo na linha 2'), 'nome repetido na planilha');
$ok($erroCom($p, 4, 'não é um valor válido') && $erroCom($p, 4, 'código'), 'preco e codigo invalidos');
$ok($erroCom($p, 6, 'já foi usado na linha 5'), 'codigo repetido');
$ok($erroCom($p, 7, 'nome do complemento'), 'opcao sem complemento');
$ok($erroCom($p, 8, 'Sim ou Não') && $erroCom($p, 8, 'Máximo') && $erroCom($p, 8, 'preço da opção'), 'obrigatorio, maximo e preco da opcao invalidos');
$ok($erroCom($p, 10, 'Falta o nome da opção'), 'complemento sem opcao');
$ok($erroCom($p, 11, 'Falta o nome do produto'), 'linha com preco e sem produto');
$ok($erroCom($p, 12, '2 a 80 letras'), 'categoria curta demais');
$ok(array_column($p['erros'], 'linha') === array_values(array_filter(array_column($p['erros'], 'linha'), static fn (): bool => true)) && $p['erros'][0]['linha'] <= end($p['erros'])['linha'], 'erros em ordem de linha');
$p = PlanilhaCardapio::interpretar([1 => $t, 2 => ['', '', '', '', '', 'Tamanho', 'Sim', '', 'P', '']]);
$ok($erroCom($p, 2, 'antes de qualquer produto'), 'opcao antes do primeiro produto');
$p = PlanilhaCardapio::interpretar([1 => $t, 2 => ['', 'Pizza', '', '40', '', 'Borda', 'Sim', '', 'Catupiry', '8'], 3 => ['', '', '', '', '', 'borda', '', '', 'catupiry', '8']]);
$ok($erroCom($p, 3, 'aparece duas vezes'), 'opcao repetida no grupo (sem diferenciar maiusculas)');
$ok(count($p['produtos'][0]['grupos']) === 1, 'mesmo grupo com letras diferentes');

// Assinatura: grupos iguais sao reaproveitados, diferentes nao.
$g = ['nome' => 'Tamanho', 'minimo' => 1, 'maximo' => 1, 'opcoes' => [['nome' => 'P', 'preco' => '0.00'], ['nome' => 'G', 'preco' => '5.00']]];
$ok(PlanilhaCardapio::assinaturaGrupo($g) === PlanilhaCardapio::assinaturaGrupo(['nome' => 'tamanho'] + $g), 'assinatura ignora maiusculas');
$ok(PlanilhaCardapio::assinaturaGrupo($g) !== PlanilhaCardapio::assinaturaGrupo(['minimo' => 0] + $g), 'assinatura muda com o minimo');
$g2 = $g;
$g2['opcoes'][1]['preco'] = '6.00';
$ok(PlanilhaCardapio::assinaturaGrupo($g) !== PlanilhaCardapio::assinaturaGrupo($g2), 'assinatura muda com o preco');
$g3 = $g;
$g3['opcoes'][1]['preco'] = '5';
$ok(PlanilhaCardapio::assinaturaGrupo($g) === PlanilhaCardapio::assinaturaGrupo($g3), 'assinatura compara o valor do preco');

// ---------- Servico em SQLite na memoria: conferir e importar ----------
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE pedido_itens (id INTEGER PRIMARY KEY, complementos_texto TEXT)'); // marca "complementos liberados" (Esquema)
$db->exec('CREATE TABLE categorias (id INTEGER PRIMARY KEY, id_restaurante INT, nome TEXT, ordem INT DEFAULT 0, UNIQUE (id_restaurante, nome))');
$db->exec("CREATE TABLE produtos (id INTEGER PRIMARY KEY, id_restaurante INT, id_categoria INT, nome TEXT, descricao TEXT, preco TEXT, unidade_venda TEXT DEFAULT 'un',
    venda_minima TEXT, codigo TEXT, imagem_path TEXT, controla_estoque INT, disponivel INT, ativo INT DEFAULT 1, ordem INT DEFAULT 0,
    UNIQUE (id_restaurante, nome), UNIQUE (id_restaurante, codigo))");
$db->exec('CREATE TABLE complemento_grupos (id INTEGER PRIMARY KEY, id_restaurante INT, nome TEXT, minimo INT, maximo INT, ordem INT DEFAULT 0, ativo INT DEFAULT 1)');
$db->exec('CREATE TABLE complementos (id INTEGER PRIMARY KEY, id_restaurante INT, id_grupo INT, nome TEXT, descricao TEXT, preco TEXT, codigo TEXT, imagem_path TEXT,
    disponivel INT DEFAULT 1, ativo INT DEFAULT 1, ordem INT DEFAULT 0)');
$db->exec('CREATE TABLE complemento_insumos (id INTEGER PRIMARY KEY, id_restaurante INT, id_complemento INT, id_insumo INT, quantidade TEXT)');
$db->exec('CREATE TABLE produto_complemento_grupos (id_produto INT, id_grupo INT, id_restaurante INT, ordem INT)');
$db->exec("INSERT INTO categorias (id_restaurante, nome, ordem) VALUES (1, 'Bebidas', 1), (2, 'Lanches', 1)");
$db->exec("INSERT INTO produtos (id_restaurante, nome, preco, codigo, controla_estoque, disponivel) VALUES (1, 'Água mineral', '4.00', 'AGUA', 0, 1), (2, 'X-Burger', '20.00', '101', 0, 1)");
$db->exec("INSERT INTO complemento_grupos (id, id_restaurante, nome, minimo, maximo) VALUES (50, 1, 'Sabor', 1, 1), (60, 2, 'Tamanho', 1, 1)");
$db->exec("INSERT INTO complementos (id_restaurante, id_grupo, nome, preco, ordem) VALUES (1, 50, 'Goiaba', '0.00', 0), (1, 50, 'Acerola', '0.00', 1), (2, 60, 'P', '0.00', 0), (2, 60, 'G', '5.00', 1)");
\Cardapim\Shared\Esquema::esquecer();
$autor = new \Cardapim\Services\Auth\UsuarioLogado(0, 'gerente', 'CI', 'Importar', true, 1, 'ci', 'CI', 'ativo');
$svc = new \Cardapim\Services\Gerencia\ImportarCardapioService($db, $autor, 1);

$plano = PlanilhaCardapio::interpretar([
    1 => $t,
    2 => ['Bebidas', 'Agua Mineral', '', '4', '', '', '', '', '', ''],          // ja existe (sem acento e minusculo): ignorado
    3 => ['', 'Suco natural', '', '7', '', 'Sabor', 'Sim', '1', 'Goiaba', ''],   // grupo igual ao do banco: reaproveitado
    4 => ['', '', '', '', '', '', '', '', 'Acerola', ''],
    5 => ['Lanches', 'X-Burger', '', '24,90', '101', 'Tamanho', 'Sim', '', 'P', ''], // X-Burger e Tamanho sao de OUTRO restaurante
    6 => ['', '', '', '', '', '', '', '', 'G', '5'],
    7 => ['', 'X-Salada', '', '22,90', '', 'Tamanho', 'Sim', '', 'P', ''],
    8 => ['', '', '', '', '', '', '', '', 'G', '5'],
]);
$ok($plano['erros'] === [], 'plano do servico sem erros');
$conf = $svc->conferir($plano);
$ok(array_column($conf['existentes'], 'nome') === ['Agua Mineral'], 'produto existente reconhecido sem acento');
$ok(array_column($conf['novos'], 'nome') === ['Suco natural', 'X-Burger', 'X-Salada'], 'produto de outro restaurante nao conta como existente');
$ok($conf['categorias_novas'] === ['Lanches'], 'categoria de outro restaurante nao e reaproveitada');
$ok($conf['grupos_reaproveitados'] === 1 && $conf['grupos_novos'] === 1 && $conf['opcoes'] === 2, 'grupo do banco reaproveitado, Tamanho novo contado uma vez');
$ok($conf['erros'] === [], 'conferencia sem erros');

$r = $svc->importar($plano, 'teste.csv');
$ok($r->ok && $r->id === 3 && str_starts_with($r->mensagem, '3 produtos importados. 1 já estavam'), 'importa 3 produtos: ' . $r->mensagem);
$prod = $db->query("SELECT p.nome, p.preco, p.codigo, p.controla_estoque, p.disponivel, p.unidade_venda, c.nome AS cat, p.ordem
    FROM produtos p LEFT JOIN categorias c ON c.id = p.id_categoria WHERE p.id_restaurante = 1 ORDER BY p.id")->fetchAll();
$ok(count($prod) === 4 && $prod[1]['nome'] === 'Suco natural' && $prod[1]['cat'] === 'Bebidas' && $prod[2]['cat'] === 'Lanches' && $prod[2]['codigo'] === '101' && $prod[2]['preco'] === '24.90', 'produtos gravados com categoria, preco e codigo');
$ok((int) $prod[1]['controla_estoque'] === 0 && (int) $prod[1]['disponivel'] === 1 && $prod[1]['unidade_venda'] === 'un', 'entram a venda, sem controle de estoque, por unidade');
$ok((int) $prod[1]['ordem'] < (int) $prod[2]['ordem'] && (int) $prod[2]['ordem'] < (int) $prod[3]['ordem'], 'ordem da planilha mantida');
$ok((int) $db->query('SELECT COUNT(*) FROM categorias WHERE id_restaurante = 1')->fetchColumn() === 2, 'uma categoria nova');
$gruposR1 = $db->query('SELECT id, nome FROM complemento_grupos WHERE id_restaurante = 1 ORDER BY id')->fetchAll();
$ok(count($gruposR1) === 2 && $gruposR1[1]['nome'] === 'Tamanho', 'Tamanho criado no restaurante 1 (nao usa o do restaurante 2)');
$lig = $db->query('SELECT id_produto, id_grupo FROM produto_complemento_grupos WHERE id_restaurante = 1 ORDER BY id_produto')->fetchAll();
$ok(count($lig) === 3 && (int) $lig[0]['id_grupo'] === 50 && (int) $lig[1]['id_grupo'] === (int) $lig[2]['id_grupo'] && (int) $lig[1]['id_grupo'] === (int) $gruposR1[1]['id'], 'suco no grupo existente; os dois lanches no mesmo Tamanho');
$ok($db->query('SELECT nome, preco FROM complementos WHERE id_grupo = ' . (int) $gruposR1[1]['id'] . ' ORDER BY ordem')->fetchAll() === [['nome' => 'P', 'preco' => '0.00'], ['nome' => 'G', 'preco' => '5.00']], 'opcoes com preco e ordem');
$ok((int) $db->query('SELECT COUNT(*) FROM produtos WHERE id_restaurante = 2')->fetchColumn() === 1, 'outro restaurante intacto');

// Reimportar: nada novo; codigo ja usado por outro produto e recusado; nada gravado com erro.
$conf = $svc->conferir($plano);
$ok($conf['novos'] === [] && count($conf['existentes']) === 4 && str_contains($conf['erros'][0]['mensagem'] ?? '', 'Não há nada novo'), 'reenvio sem nada novo');
$plano2 = PlanilhaCardapio::interpretar([1 => $t, 2 => ['', 'Misto', '', '9', 'agua', '', '', '', '', ''], 3 => ['', 'Torrada', '', '6', '', '', '', '', '', '']]);
$conf = $svc->conferir($plano2);
$ok(str_contains($conf['erros'][0]['mensagem'] ?? '', 'já é usado por outro produto'), 'codigo em uso (sem diferenciar maiusculas) recusado');
$r = $svc->importar($plano2, 'x.csv');
$ok(!$r->ok && (int) $db->query("SELECT COUNT(*) FROM produtos WHERE nome = 'Torrada'")->fetchColumn() === 0, 'com erro nada e gravado (nem a linha certa)');

if ($falhas > 0) {
    fwrite(STDERR, "$falhas teste(s) falharam.\n");
    exit(1);
}
echo "OK importacao do cardapio (unit)\n";
