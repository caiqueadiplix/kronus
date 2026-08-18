# Krono Menu - orientação para continuidade

## Versão vigente

- Interface operacional: `legacy-static/`
- Pedidos/Kanban: `legacy-static/pedidos/`
- PDV/Balcão: `legacy-static/balcao/`
- Mesas e comandas: `legacy-static/index.html`, `legacy-static/script.js` e `legacy-static/styles.css`
- Gestor de cardápio: `legacy-static/cardapio/`
- Cardápio público: `legacy-static/loja/`
- Gestão avançada: `legacy-static/gestao-avancada/`
- Entregas e entregadores: `legacy-static/entregas/`
- App do garçom: `legacy-static/garcom/`
- Telas KDS: `legacy-static/kds/`
- Navegação global: `legacy-static/sidebar.js` e `legacy-static/sidebar.css`
- API e regras de negócio: `backend/app/Http/Controllers/Api/KronoController.php`
- Rotas da API: `backend/routes/api.php`
- Banco atual: `data/krono.sqlite`
- Migração operacional atual: `backend/database/migrations/2026_08_17_040000_complete_delivery_kds_operations.php`

As pastas `src/`, `pedidos/`, `balcao/` e `cardapio/` na raiz pertencem a experimentos/versões anteriores. Não substituir a interface vigente por elas.

## Como executar

1. Copie `backend/.env.example` para `backend/.env`.
2. Ajuste `DB_DATABASE` para o caminho absoluto de `data/krono.sqlite`.
3. Dentro de `backend/`, execute `composer install`.
4. Execute `php artisan migrate --force`.
5. Inicie com `php -S 127.0.0.1:4173 -t public server-krono.php`.
6. Abra `http://127.0.0.1:4173/pedidos/`.

## Regras que devem ser preservadas

- Navbar e sidebar únicas em todos os módulos.
- Modais e drawers começam abaixo da navbar e não cobrem a sidebar.
- Kanban com três colunas: Entrada inteligente, Cozinha e Prontos.
- Pedidos confirmados no PDV, mesa ou comanda entram diretamente em Cozinha.
- Pedidos externos respeitam o Autoaceite.
- Alterar taxa ou desconto invalida o pagamento já configurado.
- Categorias do PDV ficam na lateral esquerda.
- Entrega usa estados `awaiting_driver` → `assigned` → `picked_up` → `delivered`; somente pedidos prontos podem sair para rota.
- Taxa de serviço do salão é calculada no backend a partir da configuração do tenant, nunca pelo valor enviado pelo navegador.
- Entregadores e telas KDS são cadastrados e editados pelos seus próprios modais; exclusões respeitam registros em uso.
- O checkout público valida cliente, telefone, tipo de recebimento e endereço antes de enviar o pedido.
- Manter o visual atual, sem emojis como ícones e sem excesso de cores ou sombras.

## Verificação

Com o servidor ligado, execute na raiz:

```powershell
.\tests\smoke.ps1
```
