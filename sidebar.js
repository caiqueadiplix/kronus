(() => {
  const sidebar = document.querySelector('.app-sidebar');
  if (!sidebar) return;
  const route = document.body.dataset.route || '';
  sidebar.querySelectorAll('[data-nav]').forEach(link => {
    link.classList.toggle('active', link.dataset.nav === route);
  });
})();
