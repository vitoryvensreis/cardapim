# Fiscal NeXT — etapa 01

Central administrativa em /painel/fiscal (adm_master). Todos os planos terão direito à ativação opcional do módulo em uma etapa posterior.

- CNPJ da Software House, razão social, token administrativo, ambiente 3 (simulação) ou 2 (homologação), limite mensal informado manualmente.
- Configuração cifrada com Cripto na chave privada fiscal_next_global da tabela configuracoes existente, não exposta por Configuracoes::get(). Não exige alteração de esquema nem migration.
- POSTs protegidos por RBAC e CSRF. URL HTTPS fixa. Sem registro de payload bruto, credenciais ou tokens de emitentes. Rotas somente para salvar, testar/sincronizar, ativar e desativar.
- Disponibilidade e ClientesListar são verificações independentes. O segundo método confirma autorização administrativa e devolve a lista de emitentes. Somente CNPJ, razão social e indicação Software House são preservados, até 100 CNPJs; total completo e data de sincronização são apresentados. CPF não é armazenado. Não vincula automaticamente emitentes a restaurantes.
- Rotação de credencial ou mudança de ambiente invalida testes anteriores e desativa a integração. Atualizações otimistas evitam sobrescrever configuração alterada por outra sessão.
- Ativação administrativa não habilita emissão fiscal no PDV. Produção não é selecionável nem aceita no backend nesta etapa.
- Indicadores de documentos, consumo, certificados e pendências são identificados como aguardando as etapas correspondentes. Não são estimados.
- Validação sem rede no teste unitário de integrações; teste real pelo painel, com token fornecido exclusivamente no painel seguro.

Documentação: https://www.apinext.com.br/documentacao.aspx (Clientes / ClientesListar e Disponibilidade), https://www.apinext.com.br/api-rest.aspx.

Pendências: cadastro/ativação por restaurante, tributação, motor de emissão, pagamentos, impressão, gestão dos documentos e homologação. Confirmar XML/DANFE, contingência offline e requisitos fiscais antes da produção.
