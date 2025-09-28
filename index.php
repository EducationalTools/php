<?php
// index.php - simple reverse proxy to edutools.ingo.au

// Target host (no trailing slash)
define('TARGET_HOST', 'edutools.ingo.au');
define('TARGET_SCHEME', 'https');

// Build target URL: same path + query as incoming request
// $_SERVER['REQUEST_URI'] already contains path + query string
$targetUrl = TARGET_SCHEME . '://' . TARGET_HOST . $_SERVER['REQUEST_URI'];

// Get request method and body
$method = $_SERVER['REQUEST_METHOD'];
$body = file_get_contents('php://input');

// Portable getallheaders() fallback
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$header] = $value;
            }
        }
        // CONTENT_TYPE and CONTENT_LENGTH are not prefixed with HTTP_
        if (isset($_SERVER['CONTENT_TYPE'])) $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        if (isset($_SERVER['CONTENT_LENGTH'])) $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        return $headers;
    }
}

// Prepare headers to forward (exclude hop-by-hop headers)
$incomingHeaders = getallheaders();
$hopByHop = array_map('strtolower', [
    'connection','keep-alive','proxy-authenticate','proxy-authorization','te',
    'trailers','transfer-encoding','upgrade','host'
]);

$forwardHeaders = [];
foreach ($incomingHeaders as $name => $value) {
    if (in_array(strtolower($name), $hopByHop)) continue;
    // Rebuild header lines for cURL
    if (is_array($value)) {
        foreach ($value as $v) {
            $forwardHeaders[] = $name . ': ' . $v;
        }
    } else {
        $forwardHeaders[] = $name . ': ' . $value;
    }
}

// Add/override forwarding headers
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
$forwardHeaders[] = 'X-Forwarded-For: ' . $remoteAddr;
$forwardHeaders[] = 'X-Forwarded-Proto: ' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
$forwardHeaders[] = 'X-Forwarded-Host: ' . ($_SERVER['HTTP_HOST'] ?? '');
$forwardHeaders[] = 'X-Forwarded-Port: ' . ($_SERVER['SERVER_PORT'] ?? '');


// Initialize cURL
$ch = curl_init($targetUrl);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // we'll process headers/body together
curl_setopt($ch, CURLOPT_HEADER, true);         // include response headers in output
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

// Forward headers if present
if (!empty($forwardHeaders)) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, $forwardHeaders);
}

// Forward the request body when applicable
if ($method !== 'GET' && $method !== 'HEAD' && $body !== false && $body !== '') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    // Ensure Content-Length is accurate - remove any incoming content-length header (we excluded it above),
    // and allow cURL to set it automatically, or explicitly add it:
    // $forwardHeaders[] = 'Content-Length: ' . strlen($body);
}

// If you want to verify the TLS certificate of the target, leave the defaults (recommended).
// If your environment requires, you can disable it (NOT recommended in production):
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

// Execute request
$response = curl_exec($ch);

if ($response === false) {
    http_response_code(502);
    header('Content-Type: text/plain');
    echo 'Bad Gateway: ' . curl_error($ch);
    curl_close($ch);
    exit;
}

// Separate headers and body
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$responseHeadersRaw = substr($response, 0, $headerSize);
$responseBody = substr($response, $headerSize);

// Forward response status code
http_response_code($statusCode);

// Parse response headers and send them to client (exclude hop-by-hop)
$lines = preg_split("/\r\n|\n|\r/", trim($responseHeadersRaw));
foreach ($lines as $line) {
    // Skip HTTP status lines like "HTTP/1.1 200 OK"
    if (stripos($line, 'http/') === 0) {
        continue;
    }
    if (strpos($line, ':') === false) {
        continue;
    }
    list($name, $value) = explode(':', $line, 2);
    $name = trim($name);
    $value = trim($value);
    if (in_array(strtolower($name), $hopByHop)) continue;
    // Prevent PHP from replacing headers like "Set-Cookie" — allow multiple by passing false
    header($name . ': ' . $value, false);
}

// Output body
echo $responseBody;

curl_close($ch);
