let params = new URL(location.href).searchParams;
const proxyPrefix = atob(params.get('p'));
const baseUrl = atob(params.get('t'));
console.log(`[SW] Init PREFIX=${proxyPrefix}, BASE_URL=${baseUrl}`);

const handleInstall = (e) => {
  console.log('[SW] service worker installed');
  e.waitUntil(self.skipWaiting());
};

const handleActivate = (e) => {
  console.log('[SW] service worker activated');
  e.waitUntil(self.clients.claim());
};

const handleFetch = async (request) => {
  const {method: reqMethod, url: reqUrl, headers: reqHeaders} = request;

  // Extract origin part from request url
  const proxyOrigin = (new URL(proxyPrefix)).origin;
  const reqOrigin = (new URL(reqUrl)).origin;
  let redirectUrl = proxyPrefix + reqUrl;

  // Rewrite url to proxy server
  if (reqOrigin == proxyOrigin || reqOrigin.indexOf('localhost') > 0) {
    // Wrong url written by browser, we need to replace the origin with proxy prefix and target site's base url
    redirectUrl = proxyPrefix + baseUrl + reqUrl.substr((new URL(reqUrl)).origin.length);
  } else if (reqUrl.startsWith('//')) {
    // Add default http scheme to url
    redirectUrl = proxyPrefix + 'http:' + reqUrl;
  } else if (!reqUrl.startsWith('http')) {
    // We assume it's the path string, add base url to the path
    redirectUrl = proxyPrefix + baseUrl + reqUrl;
  }

  console.log(`[SW] proxying request ${reqMethod}: ${reqUrl} -> ${redirectUrl}`);
  const init = { mode: 'cors', method: reqMethod, headers: reqHeaders, credentials: 'include' }
  if (reqMethod === 'POST') {
    init.body = await request.clone().text();
  }

  return fetch(redirectUrl, init);
};

const handleRequest = event => {
  const scope = self.registration.scope;
  const reqUrl = new URL(event.request.url);
  console.log(`[SW] handle request ${reqUrl.href}`);
  if (reqUrl.href.startsWith(proxyPrefix) || !reqUrl.protocol.startsWith('http')
      || reqUrl.href === scope || reqUrl.href === scope + '/tinywall.js') {
    console.log(`No need to proxy ${reqUrl.href}`)
    return;
  }
  event.respondWith(handleFetch(event.request));
};

self.addEventListener('install', handleInstall);
self.addEventListener('activate', handleActivate);
self.addEventListener('fetch', handleRequest);
