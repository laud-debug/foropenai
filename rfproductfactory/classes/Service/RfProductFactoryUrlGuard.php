<?php

class RfProductFactoryUrlGuard
{
    public function assertSafe($url)
    {
        $this->resolveSafeTarget($url);
        return true;
    }

    public function resolveSafeTarget($url)
    {
        if (!is_string($url) || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new PrestaShopException('L’URL fournie est invalide.');
        }

        $parts = parse_url($url);
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if (!in_array($scheme, array('http', 'https'), true)) {
            throw new PrestaShopException('Seules les URL HTTP et HTTPS sont acceptées.');
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new PrestaShopException('Les URL contenant des identifiants sont interdites.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, array(80, 443), true)) {
            throw new PrestaShopException('Seuls les ports HTTP/HTTPS standards sont autorisés.');
        }

        $host = isset($parts['host']) ? strtolower(trim($parts['host'], '[]')) : '';
        if ($host === '' || $host === 'localhost' || substr($host, -6) === '.local') {
            throw new PrestaShopException('Ce nom d’hôte n’est pas autorisé.');
        }

        $ips = array();
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $resolved = @gethostbynamel($host);
            if (!is_array($resolved) || !$resolved) {
                throw new PrestaShopException('Le nom de domaine ne peut pas être résolu.');
            }
            $ips = array_values(array_unique($resolved));
        }

        $safeIps = array();
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $safeIps[] = $ip;
            }
        }
        if (!$safeIps) {
            throw new PrestaShopException('L’URL pointe vers une adresse réseau privée ou réservée.');
        }

        return array(
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'ip' => $safeIps[0],
        );
    }
}
