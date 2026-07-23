<?php

class RfProductFactoryHttpClient
{
    const BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private $guard;

    public function __construct(RfProductFactoryUrlGuard $guard)
    {
        $this->guard = $guard;
    }

    public function get($url, $maxBytes, $maxRedirects)
    {
        return $this->getWithProfile($url, $maxBytes, $maxRedirects, null, 'document');
    }

    /**
     * Télécharge une image comme le ferait un navigateur depuis la page produit.
     * Le référent est volontairement limité à une URL déjà validée par le garde-fou SSRF.
     */
    public function getImage($url, $maxBytes, $maxRedirects, $referer = null)
    {
        if (is_string($referer) && trim($referer) !== '') {
            $this->guard->assertSafe($referer);
            $referer = trim($referer);
        } else {
            $referer = null;
        }

        return $this->getWithProfile($url, $maxBytes, $maxRedirects, $referer, 'image');
    }

    private function getWithProfile($url, $maxBytes, $maxRedirects, $initialReferer, $profile)
    {
        if (!function_exists('curl_init')) {
            throw new PrestaShopException('L’extension PHP cURL est nécessaire pour analyser les pages distantes.');
        }

        $currentUrl = $url;
        $maxBytes = max(1024, (int) $maxBytes);
        $maxRedirects = max(0, min(5, (int) $maxRedirects));
        $cookieJar = array();
        $referer = $initialReferer;

        for ($redirect = 0; $redirect <= $maxRedirects; ++$redirect) {
            $target = $this->guard->resolveSafeTarget($currentUrl);
            $response = $this->requestWithBrowserFallback(
                $currentUrl,
                $maxBytes,
                $target,
                $cookieJar,
                $referer,
                $profile
            );

            $this->storeResponseCookies($cookieJar, $target['host'], $response['set_cookies']);

            if (in_array($response['status'], array(301, 302, 303, 307, 308), true)) {
                if ($redirect >= $maxRedirects || empty($response['location'])) {
                    throw new PrestaShopException('La ressource effectue trop de redirections.');
                }
                $referer = $currentUrl;
                $currentUrl = $this->resolveUrl($currentUrl, $response['location']);
                continue;
            }

            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new PrestaShopException($this->buildHttpErrorMessage($currentUrl, $response));
            }

            $response['final_url'] = $currentUrl;
            return $response;
        }

        throw new PrestaShopException('Impossible de récupérer la ressource distante.');
    }

    /**
     * Certains sites publics refusent les User-Agent techniques et exigent une
     * première visite de leur page d’accueil afin de déposer un cookie de session.
     * On se limite à ce comportement normal de navigateur : aucun CAPTCHA ou
     * contrôle d’accès n’est contourné.
     */
    private function requestWithBrowserFallback($url, $maxBytes, array $target, array &$cookieJar, $referer, $profile)
    {
        $response = $this->requestOnce($url, $maxBytes, $target, $cookieJar, $referer, $profile);
        if ((int) $response['status'] !== 403) {
            return $response;
        }

        $originUrl = $this->buildOriginUrl($url);
        if ($originUrl === '' || $originUrl === $url) {
            return $response;
        }

        try {
            $originTarget = $this->guard->resolveSafeTarget($originUrl);
            $warmup = $this->requestOnce(
                $originUrl,
                min($maxBytes, 2 * 1024 * 1024),
                $originTarget,
                $cookieJar,
                null,
                'document'
            );
            $this->storeResponseCookies($cookieJar, $originTarget['host'], $warmup['set_cookies']);

            if ((int) $warmup['status'] >= 200 && (int) $warmup['status'] < 400) {
                $retryReferer = is_string($referer) && $referer !== '' ? $referer : $originUrl;
                return $this->requestOnce($url, $maxBytes, $target, $cookieJar, $retryReferer, $profile);
            }
        } catch (Exception $e) {
            // Le message utile reste celui de la ressource demandée, pas celui du préchargement.
        }

        return $response;
    }

    private function requestOnce($url, $maxBytes, array $target, array $cookieJar, $referer, $profile)
    {
        $body = '';
        $headers = '';
        $location = null;
        $tooLarge = false;
        $headersTooLarge = false;
        $setCookies = array();

        $resolveIp = $target['ip'];
        if (strpos($resolveIp, ':') !== false) {
            $resolveIp = '[' . $resolveIp . ']';
        }
        $resolveEntry = $target['host'] . ':' . (int) $target['port'] . ':' . $resolveIp;
        $requestHeaders = $this->buildRequestHeaders($url, $referer, $profile);

        $ch = curl_init($url);
        $options = array(
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_ENCODING => '',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => self::BROWSER_USER_AGENT,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => array($resolveEntry),
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers, &$location, &$headersTooLarge, &$setCookies) {
                $headers .= $line;
                if (strlen($headers) > 131072) {
                    $headersTooLarge = true;
                    return 0;
                }
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                } elseif (stripos($line, 'Set-Cookie:') === 0) {
                    $cookie = trim(substr($line, 11));
                    $pair = trim(strtok($cookie, ';'));
                    if ($pair !== '' && strpos($pair, '=') !== false) {
                        list($name, $value) = explode('=', $pair, 2);
                        $name = trim($name);
                        if (preg_match('/^[A-Za-z0-9_\-]+$/', $name)) {
                            $setCookies[$name] = trim($value);
                        }
                    }
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body, &$tooLarge, $maxBytes) {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        );

        $cookieHeader = $this->buildCookieHeader($cookieJar, $target['host']);
        if ($cookieHeader !== '') {
            $options[CURLOPT_COOKIE] = $cookieHeader;
        }
        if (is_string($referer) && $referer !== '') {
            $options[CURLOPT_REFERER] = $referer;
        }
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($tooLarge) {
            throw new PrestaShopException('Le contenu distant dépasse la taille maximale autorisée.');
        }
        if ($headersTooLarge) {
            throw new PrestaShopException('Les en-têtes de la réponse distante sont anormalement volumineux.');
        }
        if ($ok === false) {
            throw new PrestaShopException('Erreur réseau : ' . $curlError);
        }

        return array(
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
            'content_type' => $contentType,
            'location' => $location,
            'set_cookies' => $setCookies,
        );
    }

    private function buildRequestHeaders($url, $referer, $profile)
    {
        if ($profile === 'image') {
            $sameOrigin = $this->sameOrigin($url, $referer);
            return array(
                'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Accept-Language: fr-FR,fr;q=0.9,en;q=0.7',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Sec-Fetch-Dest: image',
                'Sec-Fetch-Mode: no-cors',
                'Sec-Fetch-Site: ' . ($sameOrigin ? 'same-origin' : 'cross-site'),
            );
        }

        return array(
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            'Accept-Language: fr-FR,fr;q=0.9,en;q=0.7',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
        );
    }

    private function sameOrigin($url, $referer)
    {
        if (!is_string($referer) || $referer === '') {
            return false;
        }
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === strtolower((string) parse_url($referer, PHP_URL_SCHEME))
            && strtolower((string) parse_url($url, PHP_URL_HOST)) === strtolower((string) parse_url($referer, PHP_URL_HOST))
            && (int) $this->effectivePort($url) === (int) $this->effectivePort($referer);
    }

    private function effectivePort($url)
    {
        $port = parse_url($url, PHP_URL_PORT);
        if ($port) {
            return (int) $port;
        }
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80;
    }

    private function buildOriginUrl($url)
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return strtolower($parts['scheme']) . '://' . $parts['host'] . $port . '/';
    }

    private function storeResponseCookies(array &$cookieJar, $host, array $cookies)
    {
        $host = strtolower((string) $host);
        if ($host === '' || !$cookies) {
            return;
        }
        if (!isset($cookieJar[$host]) || !is_array($cookieJar[$host])) {
            $cookieJar[$host] = array();
        }
        foreach ($cookies as $name => $value) {
            if ($value === '') {
                unset($cookieJar[$host][$name]);
            } else {
                $cookieJar[$host][$name] = $value;
            }
        }
    }

    private function buildCookieHeader(array $cookieJar, $host)
    {
        $host = strtolower((string) $host);
        if (!isset($cookieJar[$host]) || !is_array($cookieJar[$host])) {
            return '';
        }

        $pairs = array();
        foreach ($cookieJar[$host] as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        return implode('; ', $pairs);
    }

    private function buildHttpErrorMessage($url, array $response)
    {
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : 'le site distant';

        if ($status === 403) {
            return 'Le site ' . $host . ' refuse la ressource avec le code HTTP 403. '
                . 'Le module a utilisé un profil de navigateur, le référent de la fiche produit et une session de cookies. '
                . 'Le serveur distant bloque probablement le téléchargement automatisé depuis l’adresse IP de la boutique.';
        }
        if ($status === 429) {
            return 'Le site ' . $host . ' limite temporairement le nombre de requêtes (HTTP 429). Réessayez plus tard.';
        }

        return 'La ressource distante a répondu avec le code HTTP ' . $status . '.';
    }

    public function resolveUrl($baseUrl, $relativeUrl)
    {
        $relativeUrl = trim(html_entity_decode($relativeUrl, ENT_QUOTES, 'UTF-8'));
        if ($relativeUrl === '') {
            return $baseUrl;
        }
        if (preg_match('#^https?://#i', $relativeUrl)) {
            return $relativeUrl;
        }
        if (strpos($relativeUrl, '//') === 0) {
            $base = parse_url($baseUrl);
            return $base['scheme'] . ':' . $relativeUrl;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'];
        $host = $base['host'];
        $port = isset($base['port']) ? ':' . (int) $base['port'] : '';
        $basePath = isset($base['path']) && $base['path'] !== '' ? $base['path'] : '/';

        if (strpos($relativeUrl, '#') === 0) {
            return $scheme . '://' . $host . $port . $basePath . $relativeUrl;
        }
        if (strpos($relativeUrl, '?') === 0) {
            return $scheme . '://' . $host . $port . $basePath . $relativeUrl;
        }

        $fragment = '';
        $fragmentPos = strpos($relativeUrl, '#');
        if ($fragmentPos !== false) {
            $fragment = substr($relativeUrl, $fragmentPos);
            $relativeUrl = substr($relativeUrl, 0, $fragmentPos);
        }
        $query = '';
        $queryPos = strpos($relativeUrl, '?');
        if ($queryPos !== false) {
            $query = substr($relativeUrl, $queryPos);
            $relativeUrl = substr($relativeUrl, 0, $queryPos);
        }

        if (strpos($relativeUrl, '/') === 0) {
            $path = $relativeUrl;
        } else {
            $directory = preg_replace('#/[^/]*$#', '/', $basePath);
            $path = $directory . $relativeUrl;
        }

        $segments = array();
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $scheme . '://' . $host . $port . '/' . implode('/', $segments) . $query . $fragment;
    }
}
