<?php
/*
miniProxy - A simple PHP web proxy. <https://github.com/joshdick/miniProxy>
Written and maintained by Joshua Dick <http://joshdick.net>.
miniProxy is licensed under the GNU GPL v3 <https://www.gnu.org/licenses/gpl-3.0.html>.
*/

/****************************** START CONFIGURATION ******************************/

//NOTE: If a given URL matches a pattern in both $whitelistPatterns and $blacklistPatterns,
//that URL will be treated as blacklisted.

//To allow proxying any URL, set $whitelistPatterns to an empty array (the default).
//To only allow proxying of specific URLs (whitelist), add corresponding regular expressions
//to the $whitelistPatterns array. To prevent possible abuse, enter the narrowest/most-specific patterns possible.
//You can optionally use the "getHostnamePattern()" helper function to build a regular expression that
//matches all URLs for a given hostname.
$whitelistPatterns = [
  //Usage example: To whitelist any URL at example.net, including sub-domains, uncomment the
  //line below (which is equivalent to [ @^https?://([a-z0-9-]+\.)*example\.net@i ]):
  //getHostnamePattern("example.net")
];

//To disallow proxying of specific URLs (blacklist), add corresponding regular expressions
//to the $blacklistPatterns array. To prevent possible abuse, enter the broadest/least-specific patterns possible.
//You can optionally use the "getHostnamePattern()" helper function to build a regular expression that
//matches all URLs for a given hostname.
$blacklistPatterns = [
  //Usage example: To blacklist any URL at example.net, including sub-domains, uncomment the
  //line below (which is equivalent to [ @^https?://([a-z0-9-]+\.)*example\.net@i ]):
  //getHostnamePattern("example.net")
];

//To enable CORS (cross-origin resource sharing) for proxied sites, set $forceCORS to true.
$forceCORS = true;

//Set to false to allow sites on the local network (where miniProxy is running) to be proxied.
$disallowLocal = true;

//Set to false to report the client machine's IP address to proxied sites via the HTTP `x-forwarded-for` header.
//Setting to false may improve compatibility with some sites, but also exposes more information about end users to proxied sites.
$anonymize = true;

/****************************** END CONFIGURATION ******************************/

/**
 * Check php requirements
 */
if (version_compare(PHP_VERSION, "5.4.7", "<")) {
  die("tinywallProxy requires PHP version 5.4.7 or later.");
}

$requiredExtensions = ["curl", "mbstring", "xml"];
foreach ($requiredExtensions as $requiredExtension) {
  if (!extension_loaded($requiredExtension)) {
    die("tinywallProxy requires PHP's \"" . $requiredExtension . "\" extension. Please install/enable it on your server and try again.");
  }
}

/**
 * Make proxy request and return the response to client browser
 */
$usingDefaultPort =  (!isset($_SERVER["HTTPS"]) && $_SERVER["SERVER_PORT"] == 80) || (isset($_SERVER["HTTPS"]) && $_SERVER["SERVER_PORT"] == 443);
$prefixPort = $usingDefaultPort ? "" : ":" . $_SERVER["SERVER_PORT"];

//Use HTTP_HOST to support client-configured DNS (instead of SERVER_NAME), but remove the port if one is present
$prefixHost = $_SERVER["HTTP_HOST"];
$prefixHost = strpos($prefixHost, ":") ? implode(":", explode(":", $_SERVER["HTTP_HOST"], -1)) : $prefixHost;

define("PROXY_PREFIX", "http" . (isset($_SERVER["HTTPS"]) ? "s" : "") . "://" . $prefixHost . $prefixPort . $_SERVER["SCRIPT_NAME"] . "?");
define("SERVER_ORIGIN", "http" . (isset($_SERVER["HTTPS"]) ? "s" : "") . "://" . $prefixHost . $prefixPort);

// Proxy request url
$url = getRequestUrl();
makeRequest($url);

/**
 * Helper functions definition
 */
//Helper function for use inside $whitelistPatterns/$blacklistPatterns.
//Returns a regex that matches all HTTP[S] URLs for a given hostname.
function getHostnamePattern($hostname)
{
  $escapedHostname = str_replace(".", "\.", $hostname);
  return "@^https?://([a-z0-9-]+\.)*" . $escapedHostname . "@i";
}

//Helper function that determines whether to allow proxying of a given URL.
function isValidURL($url)
{
  //Validates a URL against the whitelist.
  function passesWhitelist($url)
  {
    if (count($GLOBALS['whitelistPatterns']) === 0) return true;
    foreach ($GLOBALS['whitelistPatterns'] as $pattern) {
      if (preg_match($pattern, $url)) {
        return true;
      }
    }
    return false;
  }

  //Validates a URL against the blacklist.
  function passesBlacklist($url)
  {
    foreach ($GLOBALS['blacklistPatterns'] as $pattern) {
      if (preg_match($pattern, $url)) {
        return false;
      }
    }
    return true;
  }

  function isLocal($url)
  {
    //First, generate a list of IP addresses that correspond to the requested URL.
    $ips = [];
    $host = parse_url($url, PHP_URL_HOST);
    if (filter_var($host, FILTER_VALIDATE_IP)) {
      //The supplied host is already a valid IP address.
      $ips = [$host];
    } else {
      //The host is not a valid IP address; attempt to resolve it to one.
      $dnsResult = dns_get_record($host, DNS_A + DNS_AAAA);
      $ips = array_map(function ($dnsRecord) {
        return $dnsRecord['type'] == 'A' ? $dnsRecord['ip'] : $dnsRecord['ipv6'];
      }, $dnsResult);
    }
    foreach ($ips as $ip) {
      //Determine whether any of the IPs are in the private or reserved range.
      if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return true;
      }
    }
    return false;
  }

  return passesWhitelist($url) && passesBlacklist($url) && ($GLOBALS['disallowLocal'] ? !isLocal($url) : true);
}

//Helper function used to removes/unset keys from an associative array using case insensitive matching
function removeKeys(&$assoc, $keys2remove)
{
  $keys = array_keys($assoc);
  $map = [];
  $removedKeys = [];
  foreach ($keys as $key) {
    $map[strtolower($key)] = $key;
  }
  foreach ($keys2remove as $key) {
    $key = strtolower($key);
    if (isset($map[$key])) {
      unset($assoc[$map[$key]]);
      $removedKeys[] = $map[$key];
    }
  }
  return $removedKeys;
}

if (!function_exists("getallheaders")) {
  //Adapted from http://www.php.net/manual/en/function.getallheaders.php#99814
  function getallheaders()
  {
    $result = [];
    foreach ($_SERVER as $key => $value) {
      if (substr($key, 0, 5) == "HTTP_") {
        $key = str_replace(" ", "-", ucwords(strtolower(str_replace("_", " ", substr($key, 5)))));
        $result[$key] = $value;
      }
    }
    return $result;
  }
}

// Rewrite request headers for curl
function rewriteRequestHeaders($url)
{
  //Get ready to proxy the browser's request headers...
  $browserRequestHeaders = getallheaders();

  //...but let cURL set some headers on its own.
  $removedHeaders = removeKeys(
    $browserRequestHeaders,
    [
      "Accept-Encoding", //Throw away the browser's Accept-Encoding header if any and let cURL make the request using gzip if possible.
      "Content-Length",
      "Host",
      "Origin",
      "Referer"
    ]
  );

  $removedHeaders = array_map("strtolower", $removedHeaders);

  //Transform the associative array from getallheaders() into an
  //indexed array of header strings to be passed to cURL.
  $curlRequestHeaders = [];
  foreach ($browserRequestHeaders as $name => $value) {
    $curlRequestHeaders[] = $name . ": " . $value;
  }

  //Tell cURL to make the request using the brower's user-agent if there is one, or a fallback user-agent otherwise.
  $user_agent = $_SERVER["HTTP_USER_AGENT"];
  if (empty($user_agent)) {
    $user_agent = "Mozilla/5.0 (compatible; tinywallProxy)";
  }
  $curlRequestHeaders[] = "User-Agent: " . $user_agent;

  // Proxy x-forwarded header
  global $anonymize;
  if (!$anonymize) {
    $curlRequestHeaders[] = "X-Forwarded-For: " . $_SERVER["REMOTE_ADDR"];
  }

  //Any `origin` header sent by the browser will refer to the proxy itself.
  //If an `origin` header is present in the request, rewrite it to point to the correct origin.
  $urlParts = parse_url($url);
  $port = isset($urlParts["port"]) ? $urlParts["port"] : '';
  $originHeader = $urlParts["scheme"] . "://" . $urlParts["host"] . (empty($port) ? "" : ":" . $port);
  if (in_array("origin", $removedHeaders)) {
    $curlRequestHeaders[] = "Origin: $originHeader";
  }

  // Set referer to origin up to strict-origin-when-cross-origin policy
  if (in_array("referer", $removedHeaders)) {
    $curlRequestHeaders[] = "Referer: $originHeader";
  }

  return $curlRequestHeaders;
}

// If content-type is html or css, we need to rewrite the url in the content
function isRewriteType($contentType)
{
  if (!isset($contentType) || $contentType === null) {
    return false;
  }
  if (stripos($contentType, 'text/html') !== false || stripos($contentType, 'text/css') !== false) {
    return true;
  }
  return false;
}

// Callback header function for curl to process response headers
function parseHeader($ch, $header)
{
  // end of header
  if (strlen(trim($header)) <= 0) {
    $rawResponseHeaders = ob_get_contents();
    ob_end_clean();

    // Rewrite headers
    $responseInfo = curl_getinfo($ch);
    $responseURL = $responseInfo["url"];
    rewriteResponseHeaders($rawResponseHeaders, getRequestUrl(), $responseURL);

    // Rewrite response body
    $contentType = isset($responseInfo["content_type"]) ? $responseInfo["content_type"] : null;
    if (isRewriteType($contentType)) {
      ob_start();
    }
    return 0;
  }
  return strlen($header);
}

//Makes an HTTP request via cURL, using request data that was passed directly to this script.
function makeRequest($url)
{
  ob_start();
  $ch = curl_init();
  //curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
  curl_setopt($ch, CURLOPT_HTTPHEADER, rewriteRequestHeaders($url));
  curl_setopt($ch, CURLOPT_ENCODING, "");

  //Proxy any received GET/POST/PUT data.
  switch ($_SERVER["REQUEST_METHOD"]) {
    case "POST":
      curl_setopt($ch, CURLOPT_POST, true);
      //For some reason, $HTTP_RAW_POST_DATA isn't working as documented at
      //http://php.net/manual/en/reserved.variables.httprawpostdata.php
      //but the php://input method works. This is likely to be flaky
      //across different server environments.
      //More info here: http://stackoverflow.com/questions/8899239/http-raw-post-data-not-being-populated-after-upgrade-to-php-5-3
      $contentType = '';
      $browserRequestHeaders = getallheaders();
      foreach ($browserRequestHeaders as $key => $val) {
        if (strtolower($key) == 'content-type') {
          $contentType = strtolower($val);
          break;
        }
      }
      $postContent = file_get_contents("php://input");
      if (empty($contentType) && stripos($contentType, 'x-www-form-urlencoded') >= 0) {
        $postData = [];
        parse_str($postContent, $postData);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
      } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postContent);
      }
      break;
    case "PUT":
      curl_setopt($ch, CURLOPT_PUT, true);
      curl_setopt($ch, CURLOPT_INFILE, fopen("php://input", "r"));
      break;
    default:
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_SERVER["REQUEST_METHOD"]);
      break;
  }

  //Other cURL options.
  curl_setopt($ch, CURLOPT_HEADER, true);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);

  //Set the request URL.
  curl_setopt($ch, CURLOPT_URL, $url);

  // Output debug info to file /tmp
  curl_setopt($ch, CURLOPT_VERBOSE, true);
  curl_setopt($ch, CURLOPT_STDERR, fopen('/tmp/curl.log', 'w+'));

  // Make the request.
  curl_exec($ch);
  $responseInfo = curl_getinfo($ch);
  $contentType = isset($responseInfo["content_type"]) ? $responseInfo["content_type"] : null;

  // Rewrite body
  if (isRewriteType($contentType)) {
    $responseBody = ob_get_contents();
    ob_end_clean();

    // Rewrite 
    $rewriteResponse = $responseBody;
    if (stripos($contentType, "text/html") !== false) {
      $rewriteResponse = "<!-- Proxified page constructed by tinywallProxy -->\n" . rewriteHtml($responseBody, $url);
    } elseif (stripos($contentType, "text/css") !== false) {
      $rewriteResponse = proxifyCSS($responseBody, $url);
    }
    header("Content-Length: " . strlen($rewriteResponse), true);
    ob_start("ob_gzhandler");
    echo $rewriteResponse;
    ob_end_flush();
  }
  curl_close($ch);
}

//Converts relative URLs to absolute ones, given a base URL.
//Modified version of code found at http://nashruddin.com/PHP_Script_for_Converting_Relative_to_Absolute_URL
function rel2abs($rel, $base)
{
  if (empty($rel)) $rel = ".";
  if (parse_url($rel, PHP_URL_SCHEME) != "" || strpos($rel, "//") === 0) return $rel; //Return if already an absolute URL
  if ($rel[0] == "#" || $rel[0] == "?") return $base . $rel; //Queries and anchors
  extract(parse_url($base)); //Parse base URL and convert to local variables: $scheme, $host, $path
  $path = isset($path) ? preg_replace("#/[^/]*$#", "", $path) : "/"; //Remove non-directory element from path
  if ($rel[0] == "/") $path = ""; //Destroy path if relative url points to root
  $port = isset($port) && $port != 80 ? ":" . $port : "";
  $auth = "";
  if (isset($user)) {
    $auth = $user;
    if (isset($pass)) {
      $auth .= ":" . $pass;
    }
    $auth .= "@";
  }
  $abs = "$auth$host$port$path/$rel"; //Dirty absolute URL
  for ($n = 1; $n > 0; $abs = preg_replace(["#(/\.?/)#", "#/(?!\.\.)[^/]+/\.\./#"], "/", $abs, -1, $n)) {
  } //Replace '//' or '/./' or '/foo/../' with '/'
  return $scheme . "://" . $abs; //Absolute URL is ready.
}

//Proxify contents of url() references in blocks of CSS text.
function proxifyCSS($css, $baseURL)
{
  //Add a "url()" wrapper to any CSS @import rules that only specify a URL without the wrapper,
  //so that they're proxified when searching for "url()" wrappers below.
  $sourceLines = explode("\n", $css);
  $normalizedLines = [];
  foreach ($sourceLines as $line) {
    if (preg_match("/@import\s+url/i", $line)) {
      $normalizedLines[] = $line;
    } else {
      $normalizedLines[] = preg_replace_callback(
        "/(@import\s+)([^;\s]+)([\s;])/i",
        function ($matches) use ($baseURL) {
          return $matches[1] . "url(" . $matches[2] . ")" . $matches[3];
        },
        $line
      );
    }
  }
  $normalizedCSS = implode("\n", $normalizedLines);
  return preg_replace_callback(
    "/url\((.*?)\)/i",
    function ($matches) use ($baseURL) {
      $url = $matches[1];
      //Remove any surrounding single or double quotes from the URL so it can be passed to rel2abs - the quotes are optional in CSS
      //Assume that if there is a leading quote then there should be a trailing quote, so just use trim() to remove them
      if (strpos($url, "'") === 0) {
        $url = trim($url, "'");
      }
      if (strpos($url, "\"") === 0) {
        $url = trim($url, "\"");
      }
      if (stripos($url, "data:") === 0) return "url(" . $url . ")"; //The URL isn't an HTTP URL but is actual binary data. Don't proxify it.
      return "url(" . PROXY_PREFIX . rel2abs($url, $baseURL) . ")";
    },
    $normalizedCSS
  );
}

//Proxify "srcset" attributes (normally associated with <img> tags.)
function proxifySrcset($srcset, $baseURL)
{
  $sources = array_map("trim", explode(",", $srcset)); //Split all contents by comma and trim each value
  $proxifiedSources = array_map(function ($source) use ($baseURL) {
    $components = array_map("trim", str_split($source, strrpos($source, " "))); //Split by last space and trim
    $components[0] = PROXY_PREFIX . rel2abs(ltrim($components[0], "/"), $baseURL); //First component of the split source string should be an image URL; proxify it
    return implode(" ", $components); //Recombine the components into a single source
  }, $sources);
  $proxifiedSrcset = implode(", ", $proxifiedSources); //Recombine the sources into a single "srcset"
  return $proxifiedSrcset;
}

// Rewrite the html response, replace the relative url to proxied absolute url
function rewriteHtml($responseBody, $url)
{
  //Attempt to normalize character encoding.
  $detectedEncoding = mb_detect_encoding($responseBody, "UTF-8, ISO-8859-1, GBK, GB2312");
  if ($detectedEncoding) {
    $responseBody = mb_convert_encoding($responseBody, "HTML-ENTITIES", $detectedEncoding);
  }

  //Parse the DOM.
  $doc = new DomDocument();
  @$doc->loadHTML($responseBody);
  $xpath = new DOMXPath($doc);

  //Rewrite forms so that their actions point back to the proxy.
  foreach ($xpath->query("//form") as $form) {
    $method = $form->getAttribute("method");
    $action = $form->getAttribute("action");
    //If the form doesn't have an action, the action is the page itself.
    //Otherwise, change an existing action to an absolute version.
    $action = empty($action) ? $url : rel2abs($action, $url);
    //Rewrite the form action to point back at the proxy.
    $form->setAttribute("action", rtrim(PROXY_PREFIX, "?"));
    //Add a hidden form field that the proxy can later use to retreive the original form action.
    $actionInput = $doc->createDocumentFragment();
    $actionInput->appendXML('<input type="hidden" name="miniProxyFormAction" value="' . htmlspecialchars($action) . '" />');
    $form->appendChild($actionInput);
  }
  //Proxify <meta> tags with an 'http-equiv="refresh"' attribute.
  foreach ($xpath->query("//meta[@http-equiv]") as $element) {
    if (strcasecmp($element->getAttribute("http-equiv"), "refresh") === 0) {
      $content = $element->getAttribute("content");
      if (!empty($content)) {
        $splitContent = preg_split("/=/", $content);
        if (isset($splitContent[1])) {
          $element->setAttribute("content", $splitContent[0] . "=" . PROXY_PREFIX . rel2abs($splitContent[1], $url));
        }
      }
    }
  }
  //Profixy <style> tags.
  foreach ($xpath->query("//style") as $style) {
    $style->nodeValue = proxifyCSS($style->nodeValue, $url);
  }
  //Proxify tags with a "style" attribute.
  foreach ($xpath->query("//*[@style]") as $element) {
    $element->setAttribute("style", proxifyCSS($element->getAttribute("style"), $url));
  }
  //Proxify "srcset" attributes in <img> tags.
  foreach ($xpath->query("//img[@srcset]") as $element) {
    $element->setAttribute("srcset", proxifySrcset($element->getAttribute("srcset"), $url));
  }
  //Proxify any of these attributes appearing in any tag.
  $proxifyAttributes = ["href", "src"];
  foreach ($proxifyAttributes as $attrName) {
    foreach ($xpath->query("//*[@" . $attrName . "]") as $element) { //For every element with the given attribute...
      $attrContent = $element->getAttribute($attrName);
      if ($attrName == "href" && preg_match("/^(about|javascript|magnet|mailto):|#/i", $attrContent)) continue;
      if ($attrName == "src" && preg_match("/^(data):/i", $attrContent)) continue;
      $attrContent = rel2abs($attrContent, $url);
      $attrContent = PROXY_PREFIX . $attrContent;
      $element->setAttribute($attrName, $attrContent);
    }
  }

  // Inject javascript code to add service worker or hook js api to proxify those
  // elements generated by js
  $head = $xpath->query("//head")->item(0);
  $body = $xpath->query("//body")->item(0);
  $prependElem = $head != null ? $head : $body;

  //Only bother trying to apply this hack if the DOM has a <head> or <body> element;
  //insert some JavaScript at the top of whichever is available first.
  //Protects against cases where the server sends a Content-Type of "text/html" when
  //what's coming back is most likely not actually HTML.
  //TODO: Do this check before attempting to do any sort of DOM parsing?
  if ($prependElem != null) {
    $scriptElem = $doc->createElement("script");
    $scriptElem->setAttribute("src", "tinywall.js");
    $scriptElem->setAttribute("type", "text/javascript");
    $prependElem->insertBefore($scriptElem, $prependElem->firstChild);
  }

  // Convert html-entities back to its original encoing to avoid any conversion mistakes
  return mb_convert_encoding($doc->saveHTML(), $detectedEncoding, "HTML-ENTITIES");
}

// Extract user's request url from server parameters
function getRequestUrl()
{
  //Extract and sanitize the requested URL, handling cases where forms have been rewritten to point to the proxy.
  if (isset($_POST["miniProxyFormAction"])) {
    $url = $_POST["miniProxyFormAction"];
    unset($_POST["miniProxyFormAction"]);
  } else {
    $queryParams = [];
    parse_str($_SERVER["QUERY_STRING"], $queryParams);
    //If the miniProxyFormAction field appears in the query string, make $url start with its value, and rebuild the the query string without it.
    if (isset($queryParams["miniProxyFormAction"])) {
      $formAction = $queryParams["miniProxyFormAction"];
      unset($queryParams["miniProxyFormAction"]);
      $url = $formAction . "?" . http_build_query($queryParams);
    } else {
      $url = substr($_SERVER["REQUEST_URI"], strlen($_SERVER["SCRIPT_NAME"]) + 1);
    }
  }

  if (strpos($url, ":/") !== strpos($url, "://")) {
    //Work around the fact that some web servers (e.g. IIS 8.5) change double slashes appearing in the URL to a single slash.
    //See https://github.com/joshdick/miniProxy/pull/14
    $pos = strpos($url, ":/");
    $url = substr_replace($url, "://", $pos, strlen(":/"));
  }
  $scheme = parse_url($url, PHP_URL_SCHEME);
  if (empty($scheme)) {
    if (strpos($url, "//") === 0) {
      //Assume that any supplied URLs starting with // are HTTP URLs.
      $url = "http:" . $url;
    } else {
      //Assume that any supplied URLs without a scheme (just a host) are HTTP URLs.
      $url = "http://" . $url;
    }
  } else if (!preg_match("/^https?$/i", $scheme)) {
    die('Error: Detected a "' . $scheme . '" URL. tinywallProxy exclusively supports http[s] URLs.');
  }

  if (!isValidURL($url)) {
    die("Error: The requested URL was disallowed by the server administrator.");
  }

  return $url;
}

/**
 * Rewrite the reponse headers from server before sending back to client browser
 */
function rewriteResponseHeaders($rawResponseHeaders, $requestUrl, $responseURL)
{
  //If CURLOPT_FOLLOWLOCATION landed the proxy at a diferent URL than
  //what was requested, explicitly redirect the proxy there.
  if ($responseURL !== $requestUrl) {
    header("Location: " . PROXY_PREFIX . $responseURL, true);
    exit(0);
  }

  //A regex that indicates which server response headers should be stripped out of the proxified response.
  $header_blacklist_pattern = "/^Content-Length|^Transfer-Encoding|^Content-Encoding.*gzip/i";

  //cURL can make multiple requests internally (for example, if CURLOPT_FOLLOWLOCATION is enabled), and reports
  //headers for every request it makes. Only proxy the last set of received response headers,
  //corresponding to the final request made by cURL for any given call to makeRequest().
  $responseHeaderBlocks = array_filter(explode("\r\n\r\n", $rawResponseHeaders));
  $lastHeaderBlock = end($responseHeaderBlocks);
  $headerLines = explode("\r\n", $lastHeaderBlock);
  foreach ($headerLines as $header) {
    $header = trim($header);
    if (!preg_match($header_blacklist_pattern, $header)) {
      header($header, false);
    } elseif(stripos($header, "content-length") !== false) {
      if (isRewriteType($rawResponseHeaders)) {
        header($header,false);    // Output the origin content-length if we don't need to rewrite body
      }
    }
  }

  //Prevent robots from indexing proxified pages
  header("X-Robots-Tag: noindex, nofollow", true);

  global $forceCORS;
  if ($forceCORS) {
    //This logic is based on code found at: http://stackoverflow.com/a/9866124/278810
    //CORS headers sent below may conflict with CORS headers from the original response,
    //so these headers are sent after the original response headers to ensure their values
    //are the ones that actually end up getting sent to the browser.
    //Explicit [ $replace = true ] is used for these headers even though this is PHP's default behavior.

    //Allow access from any origin.
    $serverOrigin = SERVER_ORIGIN;
    if (isset($_SERVER["HTTP_ORIGIN"])) {
      $serverOrigin = $_SERVER["HTTP_ORIGIN"];
    } else if (isset($_SERVER['HTTP_REFERER'])) {
      $serverOrigin = $_SERVER['HTTP_REFERER'];
    }
    header("Access-Control-Allow-Origin: $serverOrigin", true);
    header("Access-Control-Allow-Credentials: true", true);

    //Handle CORS headers received during OPTIONS requests.
    if ($_SERVER["REQUEST_METHOD"] == "OPTIONS") {
      // OPTIONS methods always return 200 for cors
      http_response_code(200);
      if (isset($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_METHOD"])) {
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS", true);
      }
      if (isset($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_HEADERS"])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}", true);
      }
      //No further action is needed for OPTIONS requests.
      exit(0);
    }
  }
}
