/**
 * Theme + accessibility bootstrap — apply prefs before paint (include in <head>).
 * Also sets the browser tab icon to the Oxford Suites logo.
 */
(function () {
  try {
    var saved = localStorage.getItem('oxford-rms-theme');
    var dark = saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches);
    if (dark) document.documentElement.classList.add('dark');
    else document.documentElement.classList.remove('dark');

    if (localStorage.getItem('oxford-rms-a11y-large') === '1') {
      document.documentElement.classList.add('a11y-large');
    }
    if (localStorage.getItem('oxford-rms-a11y-contrast') === '1') {
      document.documentElement.classList.add('a11y-high-contrast');
    }
    if (localStorage.getItem('oxford-rms-a11y-colorblind') === '1') {
      document.documentElement.classList.add('a11y-colorblind');
    }
    if (localStorage.getItem('oxford-rms-a11y-motion') === '1') {
      document.documentElement.classList.add('a11y-reduced-motion');
    }
  } catch (e) {}

  try {
    if (document.querySelector('link[rel="icon"], link[rel="shortcut icon"]')) return;
    var scripts = document.getElementsByTagName('script');
    var me = scripts[scripts.length - 1];
    var logo = '';
    if (me && me.src) {
      logo = me.src.replace(/\/js\/[^/?#]+([?#].*)?$/, '/img/favicon.ico?v=2');
      // theme-boot lives in /assets/js → favicon at /public/favicon.ico
      logo = me.src.replace(/\/assets\/js\/[^/?#]+([?#].*)?$/, '/favicon.ico?v=2');
    }
    if (!logo || logo.indexOf('favicon.ico') === -1) {
      var deep = /\/(staff|customer)\//.test(location.pathname);
      logo = (deep ? '../' : '') + 'favicon.ico?v=2';
    }
    function addIcon(rel) {
      var link = document.createElement('link');
      link.rel = rel;
      link.href = logo;
      link.type = 'image/x-icon';
      document.head.appendChild(link);
    }
    addIcon('icon');
    addIcon('shortcut icon');
    addIcon('apple-touch-icon');
  } catch (e) {}
})();
