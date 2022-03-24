<?php
// Callback header function for curl to process response headers
function parseHeader($ch, $header)
{
    // end of header
    if (strlen(trim($header)) <= 0) {
        $rawResponseHeaders = ob_get_contents();
        ob_end_clean(); // Rewrite headers 
        $responseInfo = curl_getinfo($ch);
        $responseURL = $responseInfo["url"];
        //rewriteResponseHeaders($rawResponseHeaders, getRequestUrl(), $responseURL); // Rewrite response body 
        $contentType = isset($responseInfo["content_type"]) ? $responseInfo["content_type"] : null;
        if (isRewriteType($contentType)) {
            ob_start();
            echo 'raw header:\n' . $rawResponseHeaders;
        } else {
            echo 'header:\n' . $rawResponseHeaders;
        }
    }
    return strlen($header);
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

$url = 'https://video_b.redocn.com/video/201909/20190912/Redcon_2019091003074741017788_big.mp4';
ob_start();
$ch = curl_init();
//curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
//curl_setopt($ch, CURLOPT_HTTPHEADER, rewriteRequestHeaders($url));
curl_setopt($ch, CURLOPT_ENCODING, "");

//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "HEAD");
curl_setopt($ch, CURLOPT_NOBODY, true);

//Other cURL options.
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_HEADERFUNCTION, 'parseHeader');
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
    header("Content-Length: " . strlen($responseBody), true);
    ob_start("ob_gzhandler");
    echo $responseBody;
    ob_end_flush();
}
curl_close($ch);