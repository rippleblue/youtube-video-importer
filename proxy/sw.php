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

const handleFetch = async (e) => {
  const {request} = e;
  const {method: reqMethod, url: reqUrl} = request;
  console.log(`[SW] handle request ${reqUrl}`);

  // Extract remote url from request
  let redirectUrl = '';
  if (reqUrl.startsWith(proxyPrefix)) {
    // Absolute url with proxy, we don't need to change it.
    redirectUrl = reqUrl;
  } else {
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
  }

  console.log(`[SW] proxying request ${reqMethod}: ${reqUrl} -> ${redirectUrl}`);
  let redirectReq = request.clone();
  redirectReq.url = redirectUrl;
  e.respondWith(fetch(redirectReq, { mode: 'cors', method: reqMethod, credentials: 'include'}));
};

self.addEventListener('install', handleInstall);
self.addEventListener('activate', handleActivate);
self.addEventListener('fetch', handleFetch);
