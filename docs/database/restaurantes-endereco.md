# Endereco dos restaurantes e Restaurante Modelo

## Colunas (migration `20261110050000_add_endereco_restaurantes.php`)

Todas opcionais (`NULL`), para nao travar restaurantes existentes ou cadastros pendentes.

| Coluna | Tipo | Regra |
| --- | --- | --- |
| `cep` | CHAR(8) | somente 8 digitos, sem traco (CHECK) |
| `logradouro` | VARCHAR(180) | rua, avenida, travessa... (nome usado pelos Correios/ViaCEP) |
| `numero` | VARCHAR(20) | texto para aceitar `S/N` e `120A` |
| `complemento` | VARCHAR(120) | loja, sala, bloco |
| `bairro` | VARCHAR(120) | |
| `cidade` | VARCHAR(120) | |
| `estado` | CHAR(2) | sigla de uma das 27 UFs, maiuscula (CHECK) |
| `ponto_referencia` | VARCHAR(180) | ajuda o entregador |
| `latitude`, `longitude` | DECIMAL(10,7) | para mapa e entregas; ambas preenchidas ou ambas vazias, dentro dos limites do globo (CHECK) |

Indice `idx_restaurantes_localidade (estado, cidade)` para futuros filtros do catalogo por cidade.

## Restaurante Modelo completo

`database/seed-restaurante-modelo.php --apply|--verify` (seeder `database/seeders/restaurante_modelo_completo.php`):

- **E-mail = e-mail do gerente vinculado ao restaurante**, lido do banco (nunca digitado no codigo).
- Telefone `85900000000` ((85) 90000-0000), CNPJ `12.345.678/0001-95` e endereco "Rua do Restaurante Modelo, 100,
  Loja 1, Centro, Fortaleza/CE, 60000-000" sao **ficticios de demonstracao**. Coordenadas aproximadas do centro de Fortaleza.
- Logo: icone oficial do Cardapim. Ultimo pagamento: 10/11/2026 (plano Demonstracao Mensal).
- Preenche somente campos vazios: rodar de novo nao sobrescreve dados ja informados.

Aplicacao em producao: `deploy/scripts/apply-endereco-modelo.sh` (backup validado, migration, verificacao e seed),
acionado uma unica vez pelo CI com `[cardapim-endereco-modelo]`.
