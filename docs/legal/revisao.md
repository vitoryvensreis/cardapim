# Cardapim — revisão de documentos legais

As páginas da área institucional foram criadas com base no arquivo de diretrizes de segurança, privacidade e conformidade do projeto. **São textos informativos iniciais, não uma declaração de conformidade completa.** Antes de liberar cadastro, contratação ou processamento real de dados de consumidores, realizar a revisão jurídica dos textos e dos fluxos.

## Páginas entregues

- Privacidade: \`/privacidade\`
- Termos de Uso: \`/termos-de-uso\`
- Cookies: \`/cookies\`
- Exclusão de Dados: \`/exclusao-de-dados\`
- Tratamento de Dados: \`/tratamento-de-dados\`
- Compatibilidade: \`/politica-de-privacidade\` redireciona para \`/privacidade\`.

## Pendências antes de ativar cadastro/assinaturas

1. Confirmar razão social do responsável legal, CNPJ exibido no site, endereço físico, e-mail de atendimento e canal do encarregado de dados. Não criar endereço/e-mail fictício.
2. Contratar/revisar DPA ou anexo de tratamento de dados com restaurantes, definir controladores e operadores por fluxo, prestadores e suboperadores.
3. Inventariar os dados realmente tratados, as bases legais, retenção por finalidade, procedimentos de exclusão e de resposta aos titulares.
4. Revisar condições contratuais e comerciais: planos definitivos, renovação, cancelamento, reembolsos, arrependimento quando aplicável, suporte e meios de pagamento.
5. Auditar tecnologias de cookies e terceiros. Se houver analytics/marketing não essenciais, implementar mecanismo verdadeiro de consentimento e gerenciamento (aceitar, recusar e personalizar), com versionamento e registro.
6. Implementar autenticação segura, sessões, CSRF, rate limits, controle de permissões e auditoria antes de habilitar os formulários. O HTML desta entrega **não envia dados nem cria contas**.
7. Criar canal efetivo para solicitações de titular e exclusão de dados, com identificação segura, acompanhamento e atendimento conforme LGPD.
8. Validar que todas as informações divulgadas sobre segurança, integrações, retenção e direitos refletem a operação real e as normas vigentes.
9. Aprovar textos com profissional jurídico e publicar data/versão efetiva. Notificar mudanças materiais, e recolher aceites quando juridicamente necessários.

## Testes técnicos

O CI verifica códigos HTTP, rotas, view 404, página de planos no cadastro, ausência de rotas POST de login/cadastro, recursos CSS e links do rodapé. Testes visuais e de acessibilidade em 360, 390, 768, 1024, 1280, 1920 e 2560 px, além de zoom 200%, continuam recomendados antes da liberação de autenticação real.
