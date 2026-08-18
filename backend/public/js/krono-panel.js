(() => {
  'use strict';
  if (window.KronoPanelLoaded) return;
  window.KronoPanelLoaded = true;

  const one = (selector, root = document) => root.querySelector(selector);
  const all = (selector, root = document) => [...root.querySelectorAll(selector)];
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const money = cents => (Number(cents || 0) / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const time = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : '--:--';
  const api = async (url, options = {}) => {
    const response = await fetch(url, { headers: { 'Content-Type': 'application/json', ...(options.headers || {}) }, ...options });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.error || 'Não foi possível concluir a ação.');
    return body;
  };
  const svg = path => `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg>`;
  const boxIcon = svg('<path d="M5 7.5 12 4l7 3.5v9L12 20l-7-3.5v-9Z"/><path d="m5 7.5 7 3.5 7-3.5M12 11v9"/>');
  const toast = message => {
    let node = one('.krono-toast');
    if (!node) { node = document.createElement('div'); node.className = 'krono-toast'; document.body.appendChild(node); }
    node.textContent = message; node.classList.add('show'); clearTimeout(node._timer); node._timer = setTimeout(() => node.classList.remove('show'), 3200);
  };
  const total = order => Math.max(0, (order.items || []).reduce((sum, item) => sum + Number(item.quantity) * Number(item.unit_price_cents), 0) + Number(order.fee_cents || 0) - Number(order.discount_cents || 0));
  const typeLabel = order => order.table_number ? `Mesa ${order.table_number}` : order.command_number ? `Comanda ${order.command_number}` : ({ delivery: 'Delivery', pickup: 'Retirada', table: 'Salão' }[order.type] || 'Pedido');
  const channelLabel = value => ({ whatsapp: 'WhatsApp', counter: 'Balcão', room: 'Salão', site: 'Site', ifood: 'iFood' }[value] || value);

  async function bootstrap() { return api('/api/bootstrap'); }

  function bindGlobalSearch(localInput) {
    const global = one('#kronoGlobalSearch');
    if (!global || !localInput) return;
    global.oninput = () => { localInput.value = global.value; localInput.dispatchEvent(new Event('input', { bubbles: true })); };
  }

  function initPos(root, data, afterCreated) {
    const state = { products: data.products.filter(product => product.active), categories: data.categories, category: 'all', query: '', cart: [] };
    const productsNode = one('[data-products]', root);
    if (!productsNode) return;
    const categoryNode = one('[data-category-tabs]', root);
    const search = one('[data-product-search]', root);
    const renderCategories = () => {
      categoryNode.innerHTML = `<button class="${state.category === 'all' ? 'active' : ''}" data-category="all">Todos</button>${state.categories.map(category => `<button class="${state.category === category.id ? 'active' : ''}" data-category="${category.id}">${escapeHtml(category.name)}</button>`).join('')}`;
      all('[data-category]', categoryNode).forEach(button => button.onclick = () => { state.category = button.dataset.category === 'all' ? 'all' : Number(button.dataset.category); renderCategories(); renderProducts(); });
    };
    const renderProducts = () => {
      const query = state.query.toLowerCase();
      const products = state.products.filter(product => (state.category === 'all' || product.category_id === state.category) && `${product.name} ${product.description}`.toLowerCase().includes(query));
      productsNode.innerHTML = products.length ? products.map(product => `<button class="krono-product" data-product="${product.id}"><span class="krono-product-icon">${boxIcon}</span><strong>${escapeHtml(product.name)}</strong><small>${escapeHtml(product.description)}</small><b>${money(product.price_cents)}</b></button>`).join('') : '<div class="krono-empty">Nenhum produto encontrado.</div>';
      all('[data-product]', productsNode).forEach(button => button.onclick = () => { const product = state.products.find(item => item.id === Number(button.dataset.product)); const current = state.cart.find(item => item.product_id === product.id); current ? current.quantity++ : state.cart.push({ product_id: product.id, name: product.name, unit_price_cents: product.price_cents, quantity: 1 }); renderCart(); });
    };
    const renderCart = () => {
      const node = one('[data-cart-items]', root);
      const sum = state.cart.reduce((value, item) => value + item.quantity * item.unit_price_cents, 0);
      node.innerHTML = state.cart.length ? state.cart.map(item => `<div class="krono-cart-item"><span><strong>${escapeHtml(item.name)}</strong><small>${money(item.unit_price_cents)} cada</small></span><span class="krono-qty"><button data-minus="${item.product_id}">−</button><b>${item.quantity}</b><button data-plus="${item.product_id}">+</button></span></div>`).join('') : '<div class="krono-cart-empty">Selecione os produtos ao lado para iniciar o pedido.</div>';
      one('[data-cart-total]', root).textContent = money(sum);
      all('[data-minus]', node).forEach(button => button.onclick = () => { const item = state.cart.find(row => row.product_id === Number(button.dataset.minus)); item.quantity--; if (item.quantity < 1) state.cart = state.cart.filter(row => row !== item); renderCart(); });
      all('[data-plus]', node).forEach(button => button.onclick = () => { state.cart.find(row => row.product_id === Number(button.dataset.plus)).quantity++; renderCart(); });
    };
    const payload = () => {
      const type = one('[data-order-type]', root)?.value || 'pickup';
      return { customer: one('[data-customer]', root)?.value.trim(), phone: one('[data-phone]', root)?.value.trim(), type, channel: 'counter', address: type === 'delivery' ? one('[data-address]', root)?.value.trim() : 'Retirada no balcão', fee_cents: 0, discount_cents: 0, confirmed: true, items: state.cart };
    };
    search.oninput = () => { state.query = search.value; renderProducts(); };
    bindGlobalSearch(search);
    one('[data-order-type]', root)?.addEventListener('change', event => { const address = one('[data-address-field]', root); address.hidden = event.target.value !== 'delivery'; });
    one('[data-clear-cart]', root)?.addEventListener('click', () => { state.cart = []; renderCart(); });
    one('[data-save-draft]', root)?.addEventListener('click', async () => { try { await api('/api/drafts', { method: 'POST', body: JSON.stringify({ source: 'counter', payload: payload() }) }); toast('Rascunho salvo.'); } catch (error) { toast(error.message); } });
    one('[data-submit-order]', root)?.addEventListener('click', async event => {
      const button = event.currentTarget; const order = payload();
      if (!order.customer) return toast('Informe o nome do cliente.');
      if (!order.items.length) return toast('Adicione ao menos um produto.');
      if (order.type === 'delivery' && order.address.length < 5) return toast('Informe o endereço completo.');
      button.disabled = true;
      try { const created = await api('/api/orders', { method: 'POST', body: JSON.stringify(order) }); state.cart = []; renderCart(); one('[data-customer]', root).value = ''; one('[data-phone]', root).value = ''; toast(`Pedido #${created.id} enviado para a cozinha.`); await afterCreated?.(created); }
      catch (error) { toast(error.message); } finally { button.disabled = false; }
    });
    renderCategories(); renderProducts(); renderCart();
  }

  async function initOrders(root) {
    const state = { data: await bootstrap(), type: 'all', channel: 'all', query: '' };
    const render = () => {
      all('[data-stage]', root).forEach(lane => {
        const stage = lane.dataset.stage;
        const rows = state.data.orders.filter(order => order.status === stage && (state.type === 'all' || order.type === state.type) && (state.channel === 'all' || order.channel === state.channel) && `${order.id} ${order.customer} ${order.phone}`.toLowerCase().includes(state.query));
        one('[data-count]', lane).textContent = rows.length;
        one('[data-cards]', lane).innerHTML = rows.length ? rows.map(order => `<article class="krono-order-card" draggable="true" data-order="${order.id}"><header><strong>#${order.id}</strong><time>${time(order.updated_at || order.created_at)}</time></header><h3>${escapeHtml(order.customer)}</h3><p>${escapeHtml((order.items || []).map(item => `${item.quantity}x ${item.name}`).join(', '))}</p><footer><span>${escapeHtml(channelLabel(order.channel))} · ${escapeHtml(typeLabel(order))}</span><b>${money(total(order))}</b></footer></article>`).join('') : '<div class="krono-empty">Nenhum pedido nesta etapa.</div>';
      });
      bindOrderCards();
    };
    const refresh = async () => { state.data = await bootstrap(); one('[data-auto-accept]', root).checked = Boolean(state.data.tenant.auto_accept); render(); };
    const move = async (id, target) => { const order = state.data.orders.find(row => row.id === id); const next = { incoming: 'kitchen', kitchen: 'done' }[order.status]; if (target !== next) return toast('O pedido só pode avançar para a próxima etapa.'); try { if (order.status === 'incoming' && !order.accepted_at) await api(`/api/orders/${id}/accept`, { method: 'POST', body: '{}' }); await api(`/api/orders/${id}/status`, { method: 'PATCH', body: JSON.stringify({ status: target }) }); await refresh(); toast(`Pedido #${id} atualizado.`); } catch (error) { toast(error.message); } };
    const bindOrderCards = () => {
      all('[data-order]', root).forEach(card => { card.onclick = () => openOrder(Number(card.dataset.order)); card.ondragstart = event => { card.classList.add('dragging'); event.dataTransfer.setData('text/plain', card.dataset.order); }; card.ondragend = () => card.classList.remove('dragging'); });
      all('[data-stage]', root).forEach(lane => { lane.ondragover = event => event.preventDefault(); lane.ondrop = event => { event.preventDefault(); move(Number(event.dataTransfer.getData('text/plain')), lane.dataset.stage); }; });
    };
    const openOrder = id => {
      const order = state.data.orders.find(row => row.id === id); const modal = one('[data-order-modal]', root); modal.hidden = false; modal.dataset.id = id;
      one('[data-modal-title]', modal).textContent = `Pedido #${id}`; one('[data-modal-stage]', modal).textContent = ({ incoming: 'Entrada inteligente', kitchen: 'Cozinha', done: 'Pronto' }[order.status]);
      const whatsapp = order.phone ? `<a href="https://wa.me/55${String(order.phone).replace(/\D/g, '').replace(/^55/, '')}" target="_blank" rel="noopener">Conversar pelo WhatsApp</a>` : '<p>Telefone não informado</p>';
      one('[data-modal-customer]', modal).innerHTML = `<section class="krono-detail-card"><small>Cliente</small><h3>${escapeHtml(order.customer)}</h3><p>${escapeHtml(order.phone || 'Sem telefone')}</p>${whatsapp}</section><section class="krono-detail-card"><small>${order.type === 'delivery' ? 'Entrega' : 'Atendimento'}</small><h3>${escapeHtml(order.address || typeLabel(order))}</h3><p>${escapeHtml(channelLabel(order.channel))}</p></section><section class="krono-detail-card"><small>Pagamento</small><h3>${order.payment_status === 'paid' ? 'Pago' : 'Pendente'}</h3><div class="krono-detail-actions">${order.payment_status !== 'paid' ? `<button class="krono-button secondary" data-pay="${id}">Confirmar pagamento</button>` : ''}${order.type === 'delivery' && order.status !== 'done' ? `<button class="krono-button secondary" data-driver="${id}">${order.driver_name ? 'Trocar entregador' : 'Atribuir entregador'}</button>` : ''}</div></section>`;
      one('[data-modal-items]', modal).innerHTML = `<section class="krono-detail-card"><small>Itens do pedido</small>${order.items.map(item => `<div class="krono-detail-item"><span>${item.quantity}x ${escapeHtml(item.name)}</span><b>${money(item.quantity * item.unit_price_cents)}</b></div>`).join('')}<div class="krono-detail-total"><strong>Total</strong><strong>${money(total(order))}</strong></div></section>`;
      const nextButton = one('[data-next-order]', modal); nextButton.hidden = order.status === 'done'; nextButton.textContent = order.status === 'incoming' ? 'Enviar para cozinha' : 'Marcar como pronto';
      one('[data-pay]', modal)?.addEventListener('click', async () => { const method = prompt('Forma de pagamento: pix, card ou cash', 'pix'); if (!method) return; try { await api(`/api/orders/${id}/payment`, { method: 'PATCH', body: JSON.stringify({ status: 'paid', method, paid_amount_cents: total(order) }) }); await refresh(); openOrder(id); toast('Pagamento confirmado.'); } catch (error) { toast(error.message); } });
      one('[data-driver]', modal)?.addEventListener('click', async () => { const driver = prompt('Nome do entregador', order.driver_name || ''); if (!driver) return; try { await api(`/api/orders/${id}/driver`, { method: 'PATCH', body: JSON.stringify({ driver }) }); await refresh(); openOrder(id); toast('Entregador atribuído.'); } catch (error) { toast(error.message); } });
    };
    all('[data-close-modal]', root).forEach(button => button.onclick = () => button.closest('.krono-overlay').hidden = true);
    one('[data-next-order]', root).onclick = () => { const modal = one('[data-order-modal]', root), order = state.data.orders.find(row => row.id === Number(modal.dataset.id)); move(order.id, order.status === 'incoming' ? 'kitchen' : 'done').then(() => { modal.hidden = true; }); };
    one('[data-print-order]', root).onclick = () => window.print();
    one('[data-share-order]', root).onclick = async () => { const order = state.data.orders.find(row => row.id === Number(one('[data-order-modal]', root).dataset.id)); const text = `Pedido #${order.id} - ${order.customer} - ${money(total(order))}`; try { await navigator.clipboard.writeText(text); toast('Resumo copiado.'); } catch { toast(text); } };
    all('[data-type-filter] button', root).forEach(button => button.onclick = () => { all('[data-type-filter] button', root).forEach(row => row.classList.remove('active')); button.classList.add('active'); state.type = button.dataset.value; render(); });
    all('[data-channel-filter] button', root).forEach(button => button.onclick = () => { all('[data-channel-filter] button', root).forEach(row => row.classList.remove('active')); button.classList.add('active'); state.channel = button.dataset.value; render(); });
    const search = one('[data-order-search]', root); search.oninput = () => { state.query = search.value.toLowerCase(); render(); }; bindGlobalSearch(search);
    one('[data-auto-accept]', root).onchange = async event => { try { await api('/api/settings/auto-accept', { method: 'PATCH', body: JSON.stringify({ enabled: event.target.checked }) }); toast(event.target.checked ? 'Autoaceite ativado.' : 'Autoaceite desativado.'); } catch (error) { event.target.checked = !event.target.checked; toast(error.message); } };
    const drawer = one('[data-pos-drawer]', root); one('[data-open-pos]', root).onclick = () => { drawer.hidden = false; }; one('[data-close-pos]', root).onclick = () => { drawer.hidden = true; };
    initPos(drawer, state.data, async () => { drawer.hidden = true; await refresh(); });
    one('[data-auto-accept]', root).checked = Boolean(state.data.tenant.auto_accept); render();
    const stream = new EventSource('/api/orders/stream'); stream.addEventListener('orders.updated', () => refresh().catch(() => {}));
  }

  async function initRooms(root) {
    let data = await bootstrap(); let kind = 'table'; let query = '';
    const list = () => kind === 'table' ? data.tables : data.commands;
    const render = () => {
      const rows = list().filter(room => `${room.number} ${room.name || ''} ${room.customer || ''}`.toLowerCase().includes(query));
      one('[data-room-free]', root).textContent = list().filter(room => room.status === 'free').length; one('[data-room-busy]', root).textContent = list().filter(room => room.status === 'busy').length; one('[data-room-closing]', root).textContent = list().filter(room => room.status === 'closing').length;
      one('[data-room-grid]', root).innerHTML = rows.map(room => `<button class="krono-room-card" data-room="${room.number}"><header><h3>${kind === 'table' ? escapeHtml(room.name || `Mesa ${room.number}`) : `Comanda ${room.number}`}</h3><span class="krono-status ${room.status}">${({ free: 'Livre', busy: 'Em atendimento', closing: 'Fechando' }[room.status])}</span></header><p>${escapeHtml(room.customer || 'Nenhum cliente')}</p><footer><span>${room.orders?.length || 0} pedidos</span><strong>${money(room.balance_cents)}</strong></footer></button>`).join('');
      all('[data-room]', root).forEach(button => button.onclick = () => openRoom(Number(button.dataset.room)));
    };
    const refresh = async () => { data = await bootstrap(); render(); };
    const openRoom = async number => {
      try {
        const room = await api(`/api/rooms/${kind}/${number}`), modal = one('[data-room-modal]', root); modal.hidden = false; modal.dataset.kind = kind; modal.dataset.number = number;
        one('[data-room-title]', modal).textContent = kind === 'table' ? room.name : `Comanda ${room.number}`; one('[data-room-status]', modal).textContent = ({ free: 'Livre', busy: 'Em atendimento', closing: 'Fechando conta' }[room.status]);
        one('[data-room-content]', modal).innerHTML = `<div class="krono-room-orders">${room.orders.length ? room.orders.map(order => `<div class="krono-room-order"><span><strong>#${order.id}</strong><small>${escapeHtml(order.items.map(item => `${item.quantity}x ${item.name}`).join(', '))}</small></span><b>${money(total(order))}</b></div>`).join('') : '<div class="krono-empty">Nenhum pedido nesta conta.</div>'}</div><div class="krono-room-balance"><span>Saldo da conta</span><strong>${money(room.balance_cents)}</strong></div><div class="krono-detail-actions"><button class="krono-button primary" data-room-add>Adicionar pedido</button>${room.status !== 'free' ? '<button class="krono-button secondary" data-room-transfer>Transferir conta</button><button class="krono-button secondary" data-room-close>Fechar conta</button>' : ''}<button class="krono-button secondary" data-room-delete>Excluir</button></div><form class="krono-detail-card" data-room-form hidden><label>Cliente<input data-room-customer maxlength="80" value="${escapeHtml(room.customer)}"></label><label>Produto<select data-room-product>${data.products.filter(product => product.active).map(product => `<option value="${product.id}">${escapeHtml(product.name)} — ${money(product.price_cents)}</option>`).join('')}</select></label><label>Quantidade<input data-room-qty type="number" min="1" max="99" value="1"></label><button class="krono-button primary" type="submit">Confirmar e enviar à cozinha</button></form>`;
        one('[data-room-add]', modal).onclick = () => one('[data-room-form]', modal).hidden = false;
        one('[data-room-form]', modal).onsubmit = async event => { event.preventDefault(); const product = data.products.find(item => item.id === Number(one('[data-room-product]', modal).value)), customer = one('[data-room-customer]', modal).value.trim(); if (!customer) return toast('Informe o cliente.'); try { await api('/api/orders', { method: 'POST', body: JSON.stringify({ customer, phone: '', channel: 'room', type: 'table', confirmed: true, table_number: kind === 'table' ? number : null, command_number: kind === 'command' ? number : null, items: [{ product_id: product.id, name: product.name, unit_price_cents: product.price_cents, quantity: Number(one('[data-room-qty]', modal).value) }] }) }); await refresh(); await openRoom(number); toast('Pedido enviado para a cozinha.'); } catch (error) { toast(error.message); } };
        one('[data-room-transfer]', modal)?.addEventListener('click', async () => { const targetKind = prompt('Destino: table ou command', kind); if (!targetKind) return; const targetNumber = Number(prompt('Número do destino')); if (!targetNumber) return; try { await api(`/api/rooms/${kind}/${number}/transfer`, { method: 'POST', body: JSON.stringify({ target_kind: targetKind, target_number: targetNumber }) }); modal.hidden = true; await refresh(); toast('Conta transferida.'); } catch (error) { toast(error.message); } });
        one('[data-room-close]', modal)?.addEventListener('click', async () => { const method = prompt('Forma de pagamento: pix, card ou cash', 'pix'); if (!method) return; try { await api(`/api/rooms/${kind}/${number}/close`, { method: 'POST', body: JSON.stringify({ method, paid_amount_cents: room.balance_cents }) }); modal.hidden = true; await refresh(); toast('Conta fechada.'); } catch (error) { toast(error.message); } });
        one('[data-room-delete]', modal).onclick = async () => { if (!confirm('Excluir este registro livre?')) return; try { await api(`/api/rooms/${kind}/${number}`, { method: 'DELETE' }); modal.hidden = true; await refresh(); toast('Registro excluído.'); } catch (error) { toast(error.message); } };
      } catch (error) { toast(error.message); }
    };
    all('[data-room-tabs] button', root).forEach(button => button.onclick = () => { all('[data-room-tabs] button', root).forEach(row => row.classList.remove('active')); button.classList.add('active'); kind = button.dataset.kind; one('[data-create-room]', root).innerHTML = `${svg('<path d="M12 5v14M5 12h14"/>')}Criar ${kind === 'table' ? 'mesa' : 'comanda'}`; render(); });
    const search = one('[data-room-search]', root); search.oninput = () => { query = search.value.toLowerCase(); render(); }; bindGlobalSearch(search);
    one('[data-create-room]', root).onclick = async () => { const number = Number(prompt(`Número da ${kind === 'table' ? 'mesa' : 'comanda'}`)); if (!number) return; const body = kind === 'table' ? { number, name: prompt('Nome da mesa', `Mesa ${number}`) || `Mesa ${number}` } : { number }; try { await api(kind === 'table' ? '/api/tables' : '/api/commands', { method: 'POST', body: JSON.stringify(body) }); await refresh(); toast('Registro criado.'); } catch (error) { toast(error.message); } };
    one('[data-close-modal]', root).onclick = event => event.currentTarget.closest('.krono-overlay').hidden = true; render();
  }

  async function initKds(root) {
    const render = async () => {
      const data = await bootstrap(), rows = data.orders.filter(order => order.status === 'kitchen').sort((a, b) => new Date(a.updated_at) - new Date(b.updated_at)); one('[data-kds-count]', root).textContent = `${rows.length} ${rows.length === 1 ? 'pedido' : 'pedidos'}`;
      one('[data-kds-grid]', root).innerHTML = rows.length ? rows.map(order => `<article class="krono-kds-card"><header><strong>#${order.id}</strong><time>${time(order.updated_at)}</time></header><h3>${escapeHtml(order.customer)}</h3><div class="krono-kds-items">${order.items.map(item => `<p><strong>${item.quantity}x</strong> ${escapeHtml(item.name)}</p>`).join('')}</div><footer><span>${escapeHtml(typeLabel(order))}</span><button class="krono-button primary" data-ready="${order.id}">Marcar como pronto</button></footer></article>`).join('') : '<div class="krono-empty">Nenhum pedido em produção.</div>';
      all('[data-ready]', root).forEach(button => button.onclick = async () => { button.disabled = true; try { await api(`/api/orders/${button.dataset.ready}/status`, { method: 'PATCH', body: JSON.stringify({ status: 'done' }) }); await render(); toast('Pedido marcado como pronto.'); } catch (error) { button.disabled = false; toast(error.message); } });
    };
    one('[data-fullscreen]', root).onclick = async () => document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen(); await render(); const stream = new EventSource('/api/orders/stream'); stream.addEventListener('orders.updated', () => render().catch(() => {}));
  }

  document.addEventListener('DOMContentLoaded', async () => {
    const root = one('[data-krono-module]'); if (!root) return;
    try { const module = root.dataset.kronoModule; if (module === 'orders') await initOrders(root); if (module === 'pdv') initPos(root, await bootstrap()); if (module === 'rooms') await initRooms(root); if (module === 'kds') await initKds(root); } catch (error) { toast(error.message); }
    one('.krono-notification')?.addEventListener('click', () => toast('Você não possui novas notificações.'));
  });
})();
