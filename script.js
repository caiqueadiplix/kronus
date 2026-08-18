const grid = document.getElementById('cardsGrid');
const searchInput = document.getElementById('searchInput');
const statusFilter = document.getElementById('statusFilter');
const modal = document.getElementById('modal');
let commands = Array.from({length: 18}, (_, i) => ({ id: i + 1, status: 'Livre' }));

function render(){
  const q = searchInput.value.trim();
  const status = statusFilter.value;
  const visible = commands.filter(c => (!q || String(c.id).includes(q)) && (status === 'all' || c.status === status));
  grid.innerHTML = visible.map(c => `
    <article class="command-card">
      <div class="card-main">
        <div class="command-title">Comanda<b>#${c.id}</b></div>
        <div class="card-actions">
          <button class="order-btn" data-order="${c.id}">＋ Pedido</button>
          <button class="chevron-btn" data-menu="${c.id}">⌄</button>
        </div>
      </div>
      <div class="card-footer">${c.status}</div>
      <div class="card-menu" id="menu-${c.id}">
        <button>Ver detalhes</button><button>Transferir</button><button>Fechar conta</button>
      </div>
    </article>`).join('');
}

searchInput.addEventListener('input', render);
statusFilter.addEventListener('change', render);

document.getElementById('createCommand').addEventListener('click', () => {
  commands.push({id: commands.length ? Math.max(...commands.map(c=>c.id))+1 : 1, status:'Livre'});
  render();
});

document.getElementById('newOrder').addEventListener('click', () => modal.classList.remove('hidden'));
document.getElementById('modalClose').addEventListener('click', () => modal.classList.add('hidden'));
document.getElementById('modalCancel').addEventListener('click', () => modal.classList.add('hidden'));
modal.addEventListener('click', e => { if(e.target === modal) modal.classList.add('hidden'); });

document.addEventListener('click', e => {
  const menuBtn = e.target.closest('[data-menu]');
  const orderBtn = e.target.closest('[data-order]');
  document.querySelectorAll('.card-menu.open').forEach(m => {
    if(!menuBtn || m.id !== `menu-${menuBtn.dataset.menu}`) m.classList.remove('open');
  });
  if(menuBtn){
    e.stopPropagation();
    document.getElementById(`menu-${menuBtn.dataset.menu}`).classList.toggle('open');
  }
  if(orderBtn) modal.classList.remove('hidden');
});

document.querySelectorAll('[data-tab]').forEach(btn => btn.addEventListener('click', () => {
  document.querySelectorAll('[data-tab]').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}));

render();
