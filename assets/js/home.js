/**
 * SalesDesk — Homepage JavaScript
 *
 * BUG FIX: this file used to also wire up the hero search form directly
 * (#hSearch, #heroQ, #heroMake, #heroProvince, #heroMinPrice/#heroMaxPrice,
 * #heroMinYear/#heroMaxYear, #heroReset). Since the hero search was
 * replaced by the self-contained HeroSearch v3 widget
 * (views/partials/hero-search-widget.php — see index.php), none of those
 * element ids exist on the page anymore. The old code's very first line,
 * `document.getElementById('hSearch').addEventListener(...)`, returned
 * null and threw a TypeError the moment this script ran — and because
 * that line came BEFORE the dynamic-VH logic below in file order, an
 * uncaught error there silently killed everything after it: the resize/
 * orientationchange listeners never got attached, meaning the mobile
 * viewport-height fix (the whole reason setVH() exists — working around
 * mobile browsers' address-bar resize behavior) was never running.
 *
 * index.php's own header comment already stated the intent — "home.js
 * retains only the activity-tab navigation and dynamic-VH helpers" — this
 * file just hadn't actually been updated to match that yet. It now only
 * contains those two things.
 */

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

/* Dynamic VH — address-bar-aware on mobile */
function setVH() {
  document.documentElement.style.setProperty('--vh', window.innerHeight * 0.01 + 'px');
}
setVH();
window.addEventListener('resize', setVH);
window.addEventListener('orientationchange', () => setTimeout(setVH, 150));
