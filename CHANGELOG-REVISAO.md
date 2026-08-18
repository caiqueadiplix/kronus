# Krono Menu — revisão de Pedidos e PDV

## Entregue nesta rodada

A navegação global foi estabilizada com transições mais previsíveis, camadas corrigidas e foco visível para teclado. A sidebar permanece única entre os módulos, não é coberta pelos modais e passa a esconder elementos com segurança em telas menores.

O módulo de **Pedidos** recebeu uma camada visual operacional mais sóbria, com filtros, lanes, cards e modal com limites de viewport, rolagem interna e ações identificadas. Os símbolos Unicode e emojis do modal foram removidos; os ícones principais passam a ser vetoriais. O drawer de Novo pedido preserva o carrinho, categorias e produtos no mesmo contexto e não cobre a sidebar.

O **PDV standalone** foi refinado com categorias em coluna lateral persistente e produtos filtrados na própria tela. O carrinho tem controles claros de quantidade, remoção, entrega, pagamento, documento, desconto, observação, rascunho e envio à cozinha. A seleção de categoria foi validada com dados reais.

A API existente foi preservada: pedidos confirmados no PDV entram na Cozinha, pedidos externos podem entrar na Entrada inteligente conforme Autoaceite, preços são recalculados no servidor e validações de delivery continuam ativas.

## Validação realizada

O Laravel foi executado com PHP 8.3 e SQLite. O endpoint de saúde respondeu corretamente. Um pedido de PDV foi criado e entrou em `kitchen`. Um delivery sem endereço retornou HTTP 422. O teste automatizado Feature passou após configurar a chave local do Laravel. No navegador, o Kanban carregou pedidos reais, o modal abriu dentro da viewport, a categoria de Bebidas filtrou os produtos e a inclusão de Coca-Cola atualizou o carrinho para R$ 6,00.

## Limitações

A validação visual foi feita no viewport disponível de 894×768; recomenda-se uma segunda rodada em dispositivos reais e em larguras de 360px, 768px e 1280px. Integrações externas de WhatsApp, Instagram, site por slug e multi-tenant permanecem como próximas etapas de produto; nesta rodada foi preservada a base de canais e notificações já existente.

## Próximos passos recomendados

A próxima leva deve fechar o contrato de eventos para site, WhatsApp e Instagram, criar o identificador/slug por tenant, implementar idempotência de webhooks, definir permissões por papel, revisar a conta de mesas/comandas e adicionar observabilidade. Em seguida, vale criar uma suíte E2E com Playwright/Cypress cobrindo PDV, Kanban, delivery, pagamento, rascunho, mesa e cancelamento.
