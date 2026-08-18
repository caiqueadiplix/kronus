# Krono Menu

Aplicação SaaS/multiempresa para operação de lanchonetes. A interface operacional preserva o HTML original, com uma navbar/sidebar única e compartilhada. A API, as regras e os dados usam Laravel 12 com SQLite. O Filament permanece instalado para os futuros módulos administrativos.

## Executar localmente

```powershell
cd backend
C:\xampp\php\php.exe artisan migrate --force
C:\xampp\php\php.exe -S 127.0.0.1:4173 -t public server-krono.php
```

Acesse [http://127.0.0.1:4173/pedidos/](http://127.0.0.1:4173/pedidos/). O banco fica em `data/krono.sqlite`.

## Rotas operacionais

- Pedidos: `/pedidos/`
- Balcão (PDV): `/balcao/`
- Mesas e comandas: `/`
- Cozinha (KDS): `/kds/cozinha.html`

O Filament está reservado em `/filament` e não envolve mais os módulos operacionais em iframes.

## Fluxo de pedidos

- Pedido confirmado no PDV, drawer, mesa ou comanda entra diretamente em `Cozinha`.
- Pedido externo não confirmado entra em `Entrada inteligente`, exceto quando o autoaceite está ativo.
- O Kanban possui três etapas: `Entrada inteligente → Cozinha → Prontos`.
- O backend impede saltos ou retrocessos de etapa.
- Uma mesa/comanda fica ocupada quando recebe o primeiro pedido da sessão.
- A conta só pode ser fechada depois que todos os pedidos estiverem prontos.
- Transferências movem todos os pedidos da sessão e podem juntar contas no destino.
- Cancelar o único pedido ativo libera a mesa/comanda.
- Preços dos produtos são recalculados no servidor; o valor enviado pelo navegador não é confiado.

## Validações principais

- Pedido exige cliente e pelo menos um item ativo.
- Delivery exige endereço; taxa e desconto não podem ser negativos.
- Desconto não pode superar subtotal mais taxa.
- Telefone, quando informado, deve ter de 10 a 13 dígitos.
- CPF/CNPJ, quando informado, deve ter 11 ou 14 dígitos.
- Pagamento exige Pix, cartão ou dinheiro e valor suficiente; troco é calculado.
- Pedido só pode ser editado enquanto estiver na Entrada inteligente.

## Google Maps

“Abrir rota” já gera uma URL oficial de direções do Google Maps e funciona sem chave. Para a futura integração avançada (distância, geocodificação e aplicativo de entregador), defina `GOOGLE_MAPS_API_KEY` em `backend/.env`.

## Teste rápido

Com o servidor ligado:

```powershell
.\tests\smoke.ps1
```

O teste cobre saúde do Laravel, PDV → cozinha, mesa → cozinha, liberação de mesa e rejeição de delivery sem endereço.
