<?php
header('Content-Type: text/javascript');
$baseUrl = isset($_GET['base']) ? base64_decode($_GET['base']) : '';
$proxyPrefix = isset($_GET['proxy']) ? base64_decode($_GET['proxy']) : $_SERVER['REQUEST_URI'] . '?';
?>
const proxyPrefix = '<?php echo $proxyPrefix; ?>'
const baseUrl = '<?php echo $baseUrl; ?>'


const handleInstall = () => {
  console.log('[SW] service worker installed');
  self.skipWaiting();
};

const handleActivate = () => {
  console.log('[SW] service worker activated');
  return self.clients.claim();
};

const proxyHost = (new URL(proxyPrefix)).origin

const handleFetch = async (request) => {
  const {method: reqMethod, url: reqUrl, headers: reqHeaders} = request;

  // Extract remote url from request
  let redirectUrl = '';

  // It's may be a bad relative url
  if (reqUrl.startsWith(proxyHost) || reqUrl.startsWith('http://localhost')) {
    redirectUrl = proxyPrefix + baseUrl + reqUrl.substr((new URL(reqUrl)).origin.length)
  } else if (reqUrl.startsWith('//')) {
    redirectUrl = proxyPrefix + 'http:' + reqUrl;
  } else if (!reqUrl.startsWith('http')) {
    redirectUrl = proxyPrefix + baseUrl + reqUrl;
  } else {
    redirectUrl = proxyPrefix + reqUrl;
  }

  console.log(`[SW] proxying request ${reqMethod}: ${reqUrl} -> ${redirectUrl}`);
  const init = { mode: 'cors', method: reqMethod, headers: reqHeaders, credentials: 'include' }
  if (reqMethod === 'POST' && !request.bodyUsed) {
    if (request.body) {
      init.body = request.body
    } else {
      const buf = await request.arrayBuffer()
      if (buf.byteLength > 0) {
        init.body = buf
      }
    }
  }

  return fetch(redirectUrl, init);
};

const handleRequest = event => {
  const reqUrl = new URL(event.request.url);
  console.log(`[SW] handle request ${reqUrl.href}`);
  if (reqUrl.href.startsWith(proxyPrefix) || !reqUrl.protocol.startsWith('http')) {
    console.log(`No need to proxy ${reqUrl.href}`)
    return;
  }
  event.respondWith(handleFetch(event.request));
};

self.addEventListener('install', handleInstall);
self.addEventListener('activate', handleActivate);
self.addEventListener('fetch', handleRequest);
