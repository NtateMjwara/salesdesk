/* Tab navigation */
document.querySelectorAll('.sd-tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const t = btn.dataset.tab;
    document.querySelectorAll('.sd-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.sd-tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + t).classList.add('active');
  });
});

/* Hero search — builds query string and navigates to /c/ */
document.getElementById('hSearch').addEventListener('click', () => {
  const params = new URLSearchParams();
  const q        = document.getElementById('heroQ').value.trim();
  const make     = document.getElementById('heroMake').value;
  const province = document.getElementById('heroProvince').value;
  const minPrice = document.getElementById('heroMinPrice').value;
  const maxPrice = document.getElementById('heroMaxPrice').value;
  const minYear  = document.getElementById('heroMinYear').value;
  const maxYear  = document.getElementById('heroMaxYear').value;

  if (q)        params.set('q',         q);
  if (make)     params.set('make',      make);
  if (province) params.set('province',  province);
  if (minPrice) params.set('price_min', minPrice);
  if (maxPrice) params.set('price_max', maxPrice);
  if (minYear)  params.set('year_min',  minYear);
  if (maxYear)  params.set('year_max',  maxYear);

  const qs = params.toString();
  window.location.href = '/c/' + (qs ? '?' + qs : '');
});

/* Enter key in any hero field triggers search */
document.querySelectorAll(
  '#heroQ,#heroMake,#heroProvince,#heroMinPrice,#heroMaxPrice,#heroMinYear,#heroMaxYear'
).forEach(el => el.addEventListener('keydown', e => {
  if (e.key === 'Enter') document.getElementById('hSearch').click();
}));

/* Hero reset */
document.getElementById('heroReset').addEventListener('click', () => {
  document.getElementById('heroQ').value = '';
  document.getElementById('heroMake').selectedIndex = 0;
  document.getElementById('heroProvince').selectedIndex = 0;
  document.getElementById('heroMinPrice').value = '';
  document.getElementById('heroMaxPrice').value = '';
  document.getElementById('heroMinYear').value = '';
  document.getElementById('heroMaxYear').value = '';
  document.querySelector('input[name="pricing"][value="price"]').checked = true;
});

/* Dynamic VH — address-bar-aware on mobile */
function setVH() {
  document.documentElement.style.setProperty('--vh', window.innerHeight * 0.01 + 'px');
}
setVH();
window.addEventListener('resize', setVH);
window.addEventListener('orientationchange', () => setTimeout(setVH, 150));
