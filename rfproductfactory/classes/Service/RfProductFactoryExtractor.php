<?php

class RfProductFactoryExtractor
{
    const MAX_DISTINCT_IMAGE_CANDIDATES = 16;
    const MAX_IMAGE_FINGERPRINT_BYTES = 8388608;

    private $httpClient;

    public function __construct(RfProductFactoryHttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    public function extract($url)
    {
        $response = $this->httpClient->get($url, 5 * 1024 * 1024, 3);

        return $this->parseHtml(
            $url,
            isset($response['final_url']) ? $response['final_url'] : $url,
            isset($response['body']) ? $response['body'] : '',
            isset($response['content_type']) ? $response['content_type'] : 'text/html'
        );
    }

    /**
     * Analyse un code source HTML fourni manuellement lorsque le site distant
     * refuse les requêtes provenant du serveur PrestaShop (403/anti-bot).
     */
    public function extractFromHtml($url, $html, $finalUrl = null)
    {
        $finalUrl = is_string($finalUrl) && trim($finalUrl) !== '' ? trim($finalUrl) : trim((string) $url);

        return $this->parseHtml($url, $finalUrl, $html, 'text/html');
    }

    private function parseHtml($url, $finalUrl, $html, $contentType)
    {
        if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            throw new PrestaShopException('L’extension PHP DOM/XML est nécessaire pour analyser les pages produit.');
        }

        $contentType = strtolower((string) $contentType);
        if (strpos($contentType, 'text/html') === false && strpos($contentType, 'application/xhtml+xml') === false && $contentType !== '') {
            throw new PrestaShopException('L’URL ne semble pas pointer vers une page HTML.');
        }

        $html = (string) $html;
        if (trim($html) === '') {
            throw new PrestaShopException('La page récupérée est vide.');
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new PrestaShopException('Le code HTML de la page ne peut pas être analysé.');
        }

        $xpath = new DOMXPath($dom);
        $data = array(
            'source_url' => $url,
            'final_url' => $finalUrl,
            'name' => '',
            'description' => '',
            'description_short' => '',
            'reference' => '',
            'ean13' => '',
            'brand' => '',
            'price_ttc' => null,
            'price_ttc_kind' => '',
            'wholesale_price_ht' => null,
            'wholesale_price_ttc' => null,
            'tax_rate' => null,
            'currency' => 'EUR',
            'images' => array(),
            'meta_title' => '',
            'meta_description' => '',
            'confidence' => array(),
            'warnings' => array(),
        );

        $jsonLdProducts = $this->extractJsonLdProducts($xpath);
        if ($jsonLdProducts) {
            $this->mergeJsonLd($data, $jsonLdProducts[0]);
        }

        $meta = $this->extractMeta($xpath);
        $this->mergeMeta($data, $meta);
        $this->mergeMicrodata($data, $xpath);
        $this->mergeLabeledProductData($data, $xpath, $html, $finalUrl);
        $this->mergeProductGalleryImages($data, $xpath, $html);

        if ($data['name'] === '') {
            $data['name'] = $this->firstText($xpath, '//h1');
            $data['confidence']['name'] = $data['name'] !== '' ? 'medium' : 'low';
        }
        if ($data['name'] === '') {
            $data['name'] = $this->firstText($xpath, '//title');
        }

        if ($data['description'] === '') {
            $data['description'] = isset($meta['description']) ? $meta['description'] : '';
        }

        $data['name'] = $this->cleanSingleLine($data['name'], 128);
        $data['description'] = $this->cleanText($data['description'], 12000);
        $data['description_short'] = $this->truncate($data['description'], 350);
        $data['reference'] = $this->cleanReference($data['reference']);
        $data['ean13'] = $this->cleanEan($data['ean13']);
        $data['brand'] = $this->cleanSingleLine($data['brand'], 128);

        $images = array();
        $seenImages = array();
        foreach ($data['images'] as $imageUrl) {
            $candidate = $this->normalizeImageCandidate($imageUrl);
            if ($candidate === '') {
                continue;
            }
            $absolute = $this->httpClient->resolveUrl($finalUrl, $candidate);
            $absolute = $this->removeSafeImageResizeParameters($absolute);
            if (!filter_var($absolute, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $absolute)) {
                continue;
            }
            $dedupeKey = $this->imageDedupeKey($absolute);
            if ($dedupeKey === '' || isset($seenImages[$dedupeKey])) {
                continue;
            }
            $seenImages[$dedupeKey] = true;
            $images[] = $absolute;
        }
        $imageDeduplication = $this->deduplicateRemoteImages(
            array_slice($images, 0, self::MAX_DISTINCT_IMAGE_CANDIDATES)
        );
        $data['images'] = array_slice($imageDeduplication['urls'], 0, 8);
        $data['image_duplicates_removed'] = (int) $imageDeduplication['duplicates_removed'];
        $data['image_count'] = count($data['images']);

        $data['meta_title'] = $this->truncate($data['name'], 70);
        $data['meta_description'] = $this->truncate($data['description'], 155);

        if ($data['name'] === '') {
            $data['warnings'][] = 'Nom du produit introuvable.';
        }
        if ($data['price_ttc'] === null && $data['wholesale_price_ht'] === null && $data['wholesale_price_ttc'] === null) {
            $data['warnings'][] = 'Aucun prix de vente ou prix d’achat n’a été trouvé.';
        } elseif ($data['price_ttc'] === null && ($data['wholesale_price_ht'] !== null || $data['wholesale_price_ttc'] !== null)) {
            $data['warnings'][] = 'Un prix d’achat a été détecté, mais pas de prix de vente public. Renseignez le prix de vente avant création.';
        }
        if ($data['reference'] === '') {
            $data['warnings'][] = 'Référence fournisseur introuvable : la comparaison du catalogue sera moins précise.';
        }
        if ($data['ean13'] === '') {
            $data['warnings'][] = 'EAN-13 introuvable : la comparaison par code-barres ne pourra pas être effectuée.';
        }
        if (!$data['images']) {
            $data['warnings'][] = 'Aucune image produit détectée.';
        }

        return $data;
    }

    private function extractJsonLdProducts(DOMXPath $xpath)
    {
        $products = array();
        $nodes = $xpath->query('//script[contains(translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), "ld+json")]');
        foreach ($nodes as $node) {
            $raw = trim($node->textContent);
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            $this->collectProductNodes($decoded, $products);
        }

        return $products;
    }

    private function collectProductNodes($node, array &$products)
    {
        if (!is_array($node)) {
            return;
        }

        $type = isset($node['@type']) ? $node['@type'] : null;
        $types = is_array($type) ? $type : array($type);
        foreach ($types as $candidate) {
            if (is_string($candidate) && strtolower($candidate) === 'product') {
                $products[] = $node;
                break;
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectProductNodes($value, $products);
            }
        }
    }

    private function mergeJsonLd(array &$data, array $product)
    {
        if (!empty($product['name'])) {
            $data['name'] = $this->scalarValue($product['name']);
            $data['confidence']['name'] = 'high';
        }
        if (!empty($product['description'])) {
            $data['description'] = $this->scalarValue($product['description']);
            $data['confidence']['description'] = 'high';
        }
        if (!empty($product['sku'])) {
            $data['reference'] = $this->scalarValue($product['sku']);
            $data['confidence']['reference'] = 'high';
        } elseif (!empty($product['mpn'])) {
            $data['reference'] = $this->scalarValue($product['mpn']);
        }
        foreach (array('gtin13', 'gtin', 'ean', 'gtin14') as $eanKey) {
            if (!empty($product[$eanKey])) {
                $data['ean13'] = $this->scalarValue($product[$eanKey]);
                $data['confidence']['ean13'] = 'high';
                break;
            }
        }
        if (!empty($product['brand'])) {
            $data['brand'] = $this->extractBrandName($product['brand']);
        }
        if (!empty($product['image'])) {
            $data['images'] = array_merge($data['images'], $this->extractImageUrls($product['image']));
        }

        $offers = isset($product['offers']) ? $product['offers'] : null;
        if (is_array($offers) && isset($offers[0])) {
            $offers = $offers[0];
        }
        if (is_array($offers)) {
            if (isset($offers['price'])) {
                $data['price_ttc'] = $this->parsePrice($this->scalarValue($offers['price']));
                $data['confidence']['price_ttc'] = 'high';
                $data['price_ttc_kind'] = 'structured';
            } elseif (isset($offers['lowPrice'])) {
                $data['price_ttc'] = $this->parsePrice($this->scalarValue($offers['lowPrice']));
            }
            if (!empty($offers['priceCurrency'])) {
                $data['currency'] = strtoupper($this->scalarValue($offers['priceCurrency']));
            }
        }
    }

    private function extractMeta(DOMXPath $xpath)
    {
        $meta = array();
        foreach ($xpath->query('//meta[@content]') as $node) {
            $key = strtolower(trim($node->getAttribute('property')));
            if ($key === '') {
                $key = strtolower(trim($node->getAttribute('name')));
            }
            if ($key !== '') {
                $meta[$key] = trim($node->getAttribute('content'));
            }
        }
        return $meta;
    }

    private function mergeMeta(array &$data, array $meta)
    {
        if ($data['name'] === '') {
            foreach (array('og:title', 'twitter:title') as $key) {
                if (!empty($meta[$key])) {
                    $data['name'] = $meta[$key];
                    $data['confidence']['name'] = 'medium';
                    break;
                }
            }
        }

        if ($data['description'] === '') {
            foreach (array('og:description', 'twitter:description', 'description') as $key) {
                if (!empty($meta[$key])) {
                    $data['description'] = $meta[$key];
                    $data['confidence']['description'] = 'medium';
                    break;
                }
            }
        }

        foreach (array('og:image:secure_url', 'og:image', 'twitter:image') as $key) {
            if (!empty($meta[$key])) {
                $data['images'][] = $meta[$key];
            }
        }

        if ($data['price_ttc'] === null) {
            foreach (array('product:price:amount', 'og:price:amount') as $key) {
                if (isset($meta[$key])) {
                    $data['price_ttc'] = $this->parsePrice($meta[$key]);
                    $data['price_ttc_kind'] = 'structured';
                    break;
                }
            }
        }
        foreach (array('product:price:currency', 'og:price:currency') as $key) {
            if (!empty($meta[$key])) {
                $data['currency'] = strtoupper($meta[$key]);
                break;
            }
        }
    }


    private function mergeMicrodata(array &$data, DOMXPath $xpath)
    {
        if ($data['reference'] === '') {
            $data['reference'] = $this->firstAttributeOrText(
                $xpath,
                '//*[@itemprop="sku" or @itemprop="mpn"]',
                array('content', 'value')
            );
            if ($data['reference'] !== '') {
                $data['confidence']['reference'] = 'medium';
            }
        }

        if ($data['ean13'] === '') {
            $data['ean13'] = $this->firstAttributeOrText(
                $xpath,
                '//*[@itemprop="gtin13" or @itemprop="gtin" or @itemprop="ean"]',
                array('content', 'value')
            );
            if ($data['ean13'] !== '') {
                $data['confidence']['ean13'] = 'medium';
            }
        }

        if ($data['price_ttc'] === null) {
            $price = $this->firstAttributeOrText($xpath, '//*[@itemprop="price"]', array('content', 'value'));
            if ($price !== '') {
                $data['price_ttc'] = $this->parsePrice($price);
                if ($data['price_ttc'] !== null) {
                    $data['confidence']['price_ttc'] = 'medium';
                    $data['price_ttc_kind'] = 'structured';
                }
            }
        }

        $currency = $this->firstAttributeOrText($xpath, '//*[@itemprop="priceCurrency"]', array('content', 'value'));
        if ($currency !== '') {
            $data['currency'] = strtoupper($currency);
        }

        foreach ($xpath->query('//*[@itemprop="image"]') as $node) {
            foreach (array('content', 'src', 'href') as $attribute) {
                $value = trim($node->getAttribute($attribute));
                if ($value !== '') {
                    $data['images'][] = $value;
                    break;
                }
            }
        }
    }

    /**
     * Récupère les données commerciales qui sont souvent absentes du JSON-LD :
     * référence affichée près du titre, EAN/UPC dans les spécifications et prix
     * d'achat des portails fournisseurs B2B.
     */
    private function mergeLabeledProductData(array &$data, DOMXPath $xpath, $html, $finalUrl)
    {
        $pairs = $this->collectLabelValuePairs($xpath);
        foreach ($pairs as $pair) {
            $this->applyLabeledValue($data, $pair['label'], $pair['value'], $finalUrl);
        }

        $bodyText = $this->firstText($xpath, '//body');
        $bodyText = html_entity_decode((string) $bodyText, ENT_QUOTES, 'UTF-8');
        $bodyText = preg_replace('/\s+/u', ' ', $bodyText);
        $bodyText = trim($bodyText);

        if ($data['reference'] === '') {
            $data['reference'] = $this->extractReferenceFromText($bodyText);
            if ($data['reference'] === '') {
                $data['reference'] = $this->extractReferenceFromUrl($finalUrl);
            }
            if ($data['reference'] !== '') {
                $data['confidence']['reference'] = 'medium';
            }
        }

        if ($data['ean13'] === '') {
            $data['ean13'] = $this->extractEanFromText($bodyText);
            if ($data['ean13'] !== '') {
                $data['confidence']['ean13'] = 'medium';
            }
        }

        if ($data['tax_rate'] === null && preg_match('/(?:TVA|VAT)[^0-9]{0,25}([0-9]{1,2}(?:[.,][0-9]+)?)\s*%/iu', $bodyText, $match)) {
            $data['tax_rate'] = (float) str_replace(',', '.', $match[1]);
        }

        $this->mergePriceAttributes($data, $xpath, $finalUrl);
        $this->mergePriceTextFallbacks($data, $bodyText, $finalUrl);

        if ($this->isKnownSupplierUrl($finalUrl)
            && $data['wholesale_price_ht'] === null
            && $data['wholesale_price_ttc'] === null
            && $data['price_ttc'] !== null
            && $data['price_ttc_kind'] !== 'public') {
            // Sur les portails B2B connus, un prix structuré sans libellé « prix public »
            // correspond au coût fournisseur, pas au prix de vente de la boutique.
            $data['wholesale_price_ht'] = $data['price_ttc'];
            $data['confidence']['wholesale_price_ht'] = isset($data['confidence']['price_ttc'])
                ? $data['confidence']['price_ttc']
                : 'medium';
            $data['price_ttc'] = null;
            $data['price_ttc_kind'] = '';
            $data['warnings'][] = 'Le prix structuré du portail fournisseur a été classé comme prix d’achat HT. Vérifiez-le avant création.';
        }
    }

    private function collectLabelValuePairs(DOMXPath $xpath)
    {
        $pairs = array();
        $seen = array();

        $rows = $xpath->query('//tr');
        if ($rows) {
            foreach ($rows as $row) {
                $cells = $xpath->query('./th|./td', $row);
                if (!$cells || $cells->length < 2) {
                    continue;
                }
                $label = trim($cells->item(0)->textContent);
                $values = array();
                for ($i = 1; $i < $cells->length; ++$i) {
                    $values[] = trim($cells->item($i)->textContent);
                }
                $this->appendLabelValuePair($pairs, $seen, $label, implode(' ', $values));
            }
        }

        $terms = $xpath->query('//dt');
        if ($terms) {
            foreach ($terms as $term) {
                $valueNode = $xpath->query('following-sibling::dd[1]', $term);
                if ($valueNode && $valueNode->length) {
                    $this->appendLabelValuePair($pairs, $seen, trim($term->textContent), trim($valueNode->item(0)->textContent));
                }
            }
        }

        $candidates = $xpath->query('//*[self::li or self::div or self::p][count(*) >= 2]');
        if ($candidates) {
            $limit = min($candidates->length, 3000);
            for ($index = 0; $index < $limit; ++$index) {
                $node = $candidates->item($index);
                $children = $xpath->query('./*', $node);
                if (!$children || $children->length < 2) {
                    continue;
                }
                $label = trim($children->item(0)->textContent);
                if (!$this->isKnownProductLabel($label)) {
                    continue;
                }
                $values = array();
                for ($i = 1; $i < $children->length; ++$i) {
                    $values[] = trim($children->item($i)->textContent);
                }
                $this->appendLabelValuePair($pairs, $seen, $label, implode(' ', $values));
            }
        }

        return $pairs;
    }

    private function appendLabelValuePair(array &$pairs, array &$seen, $label, $value)
    {
        $label = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $label), ENT_QUOTES, 'UTF-8')));
        $value = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8')));
        if ($label === '' || $value === '' || !$this->isKnownProductLabel($label)) {
            return;
        }
        $key = $this->normalizeLabel($label) . '|' . $value;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $pairs[] = array('label' => $label, 'value' => $value);
    }

    private function isKnownProductLabel($label)
    {
        $label = $this->normalizeLabel($label);
        foreach (array(
            'reference', 'ref ', 'ref.', 'sku', 'article', 'code produit', 'code article',
            'ean', 'gtin', 'code barre', 'barcode',
            'prix', 'price', 'tarif', 'ppc',
            'editeur', 'éditeur', 'publisher', 'fabricant', 'manufacturer', 'marque', 'brand',
            'tva', 'vat',
        ) as $needle) {
            if (strpos($label, $this->normalizeLabel($needle)) !== false) {
                return true;
            }
        }
        return false;
    }

    private function applyLabeledValue(array &$data, $label, $value, $finalUrl)
    {
        $normalized = $this->normalizeLabel($label);

        if ($data['reference'] === '' && $this->labelMatches($normalized, array(
            'reference', 'ref', 'sku', 'n de l article', 'no de l article', 'numero de l article', 'code article', 'code produit',
        ))) {
            $candidate = $this->extractReferenceCandidate($value);
            if ($candidate !== '') {
                $data['reference'] = $candidate;
                $data['confidence']['reference'] = 'high';
            }
        }

        if ($data['ean13'] === '' && $this->labelMatches($normalized, array('ean', 'ean 13', 'gtin', 'gtin 13', 'code barre', 'barcode'))) {
            $candidate = $this->extractEanCandidate($value);
            if ($candidate !== '') {
                $data['ean13'] = $candidate;
                $data['confidence']['ean13'] = 'high';
            }
        }

        if ($data['brand'] === '' && $this->labelMatches($normalized, array('editeur', 'publisher', 'fabricant', 'manufacturer', 'marque', 'brand'))) {
            $data['brand'] = $this->cleanSingleLine($value, 128);
        }

        if ($data['tax_rate'] === null && $this->labelMatches($normalized, array('tva', 'vat'))) {
            if (preg_match('/([0-9]{1,2}(?:[.,][0-9]+)?)\s*%/u', $value, $match)) {
                $data['tax_rate'] = (float) str_replace(',', '.', $match[1]);
            }
        }

        $price = $this->parsePrice($value);
        if ($price === null || $price < 0) {
            return;
        }

        if ($this->labelMatches($normalized, array('prix public', 'prix conseille', 'prix de vente', 'ppc', 'retail price', 'recommended retail', 'msrp'))) {
            if (strpos($normalized, 'ht') !== false) {
                if ($data['tax_rate'] !== null) {
                    $data['price_ttc'] = round($price * (1 + ((float) $data['tax_rate'] / 100)), 6);
                    $data['price_ttc_kind'] = 'public';
                }
            } else {
                $data['price_ttc'] = $price;
                $data['price_ttc_kind'] = 'public';
            }
            return;
        }

        if ($this->labelMatches($normalized, array(
            'prix achat', 'prix d achat', 'prix net', 'votre prix', 'prix revendeur', 'tarif revendeur',
            'prix pro', 'tarif pro', 'prix distributeur', 'cout achat', 'cout d achat', 'purchase price', 'wholesale price', 'dealer price',
        ))) {
            if (strpos($normalized, 'ttc') !== false || strpos($normalized, 'tax incl') !== false) {
                $data['wholesale_price_ttc'] = $price;
            } else {
                $data['wholesale_price_ht'] = $price;
            }
            return;
        }

        if ($this->isKnownSupplierUrl($finalUrl) && $this->labelMatches($normalized, array('prix ht', 'price excl tax', 'hors taxe'))) {
            $data['wholesale_price_ht'] = $price;
        } elseif ($this->isKnownSupplierUrl($finalUrl) && $this->labelMatches($normalized, array('prix ttc', 'price incl tax'))) {
            $data['wholesale_price_ttc'] = $price;
        }
    }

    private function mergePriceAttributes(array &$data, DOMXPath $xpath, $finalUrl)
    {
        $nodes = $xpath->query('//*[@data-price-amount or @data-price]');
        if (!$nodes || !$nodes->length) {
            return;
        }

        $fallback = null;
        foreach ($nodes as $node) {
            $raw = trim($node->getAttribute('data-price-amount'));
            if ($raw === '') {
                $raw = trim($node->getAttribute('data-price'));
            }
            $price = $this->parsePrice($raw);
            if ($price === null || $price <= 0) {
                continue;
            }
            $type = strtolower(trim($node->getAttribute('data-price-type')));
            if ($fallback === null) {
                $fallback = $price;
            }
            if ($type === 'finalprice' || $type === 'final_price' || strpos($type, 'final') !== false) {
                $fallback = $price;
                break;
            }
        }

        if ($fallback === null) {
            return;
        }
        if ($this->isKnownSupplierUrl($finalUrl)) {
            if ($data['wholesale_price_ht'] === null && $data['wholesale_price_ttc'] === null) {
                $data['wholesale_price_ht'] = $fallback;
                $data['confidence']['wholesale_price_ht'] = 'medium';
            }
        } elseif ($data['price_ttc'] === null) {
            $data['price_ttc'] = $fallback;
            $data['confidence']['price_ttc'] = 'medium';
            $data['price_ttc_kind'] = 'structured';
        }
    }

    private function mergePriceTextFallbacks(array &$data, $bodyText, $finalUrl)
    {
        $purchasePatterns = array(
            '/(?:prix\s+d[’\']?achat|prix\s+achat|votre\s+prix|prix\s+net|prix\s+revendeur|prix\s+pro|tarif\s+pro|wholesale\s+price|purchase\s+price)[^0-9]{0,40}([0-9]+(?:[\s.,][0-9]{2,6})?)/iu',
        );
        if ($data['wholesale_price_ht'] === null && $data['wholesale_price_ttc'] === null) {
            foreach ($purchasePatterns as $pattern) {
                if (preg_match($pattern, $bodyText, $match)) {
                    $price = $this->parsePrice($match[1]);
                    if ($price !== null) {
                        $data['wholesale_price_ht'] = $price;
                        break;
                    }
                }
            }
        }

        if ($this->isKnownSupplierUrl($finalUrl) && $data['wholesale_price_ht'] === null && $data['wholesale_price_ttc'] === null) {
            if (preg_match('/(?:prix|price)[^0-9]{0,15}(?:HT|hors\s+taxe|excl\.?\s*tax)[^0-9]{0,20}([0-9]+(?:[\s.,][0-9]{2,6})?)/iu', $bodyText, $match)) {
                $data['wholesale_price_ht'] = $this->parsePrice($match[1]);
            }
        }
    }

    private function extractReferenceFromText($text)
    {
        if (preg_match('/(?:N(?:°|º|o)\s*(?:de\s+l[’\']\s*)?article|num[eé]ro\s+de\s+l[’\']article|r[eé]f(?:[eé]rence)?|SKU|code\s+(?:article|produit))\s*[:#\-]?\s*([A-Z0-9][A-Z0-9._\/\-]{3,63})/iu', (string) $text, $match)) {
            return $this->extractReferenceCandidate($match[1]);
        }
        return '';
    }

    private function extractReferenceFromUrl($url)
    {
        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        $parts = preg_split('/[-_\/]+/', trim($path, '/'));
        if (!$parts) {
            return '';
        }
        for ($i = count($parts) - 1; $i >= 0; --$i) {
            $candidate = strtoupper(trim($parts[$i]));
            if (strlen($candidate) >= 5 && strlen($candidate) <= 64 && preg_match('/[A-Z]/', $candidate) && preg_match('/\d/', $candidate)) {
                return $this->cleanReference($candidate);
            }
        }
        return '';
    }

    private function extractReferenceCandidate($value)
    {
        $value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
        if (preg_match('/\b([A-Z][A-Z0-9._\/\-]{3,63})\b/i', $value, $match)) {
            $candidate = strtoupper($this->cleanReference($match[1]));
            if (preg_match('/[A-Z]/', $candidate) && preg_match('/\d/', $candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    private function extractEanFromText($text)
    {
        if (preg_match('/(?:EAN(?:\s*[- ]?\s*13)?|GTIN(?:\s*[- ]?\s*13)?|code\s*[- ]?barres?|barcode)\s*[:#\-]?\s*((?:\d[\s.\-]?){12,14})/iu', (string) $text, $match)) {
            return $this->extractEanCandidate($match[1]);
        }
        return '';
    }

    private function extractEanCandidate($value)
    {
        if (preg_match('/(?:\d[\s.\-]?){12,14}/', (string) $value, $match)) {
            return $this->cleanEan($match[0]);
        }
        return '';
    }

    private function labelMatches($normalizedLabel, array $needles)
    {
        foreach ($needles as $needle) {
            $needle = $this->normalizeLabel($needle);
            if ($needle !== '' && ($normalizedLabel === $needle || strpos($normalizedLabel, $needle) !== false)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeLabel($value)
    {
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8');
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($ascii !== false) {
                $value = $ascii;
            }
        }
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9%]+/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function isKnownSupplierUrl($url)
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        foreach (array('shop.novalisgames.com', 'store.asmodee.com', 'shop.asmodee.fr') as $supplierHost) {
            if ($host === $supplierHost || substr($host, -strlen('.' . $supplierHost)) === '.' . $supplierHost) {
                return true;
            }
        }
        return false;
    }

    /**
     * Complète JSON-LD/OpenGraph avec les vraies images de galerie.
     * De nombreux sites Shopify n'exposent que la couverture dans le JSON-LD,
     * alors que les images secondaires sont présentes dans le DOM ou un script produit.
     */
    private function mergeProductGalleryImages(array &$data, DOMXPath $xpath, $html)
    {
        $attributeNames = array(
            'data-zoom-image', 'data-large-image', 'data-master', 'data-original',
            'data-src', 'data-image', 'data-zoom', 'src', 'href',
        );
        $queries = array(
            '//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"product") and (contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"media") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"gallery") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"image") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"thumbnail"))]//*[self::img or self::source or self::a]',
            '//*[contains(translate(@id,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"product") and (contains(translate(@id,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"media") or contains(translate(@id,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"gallery") or contains(translate(@id,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"image"))]//*[self::img or self::source or self::a]',
            '//img[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"product") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"gallery") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"thumbnail")]',
        );

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes) {
                continue;
            }
            foreach ($nodes as $node) {
                foreach ($attributeNames as $attributeName) {
                    $value = trim($node->getAttribute($attributeName));
                    if ($value !== '') {
                        $data['images'][] = $value;
                    }
                }
                foreach (array('data-srcset', 'srcset') as $srcsetAttribute) {
                    $srcset = trim($node->getAttribute($srcsetAttribute));
                    if ($srcset !== '') {
                        $srcsetUrl = $this->largestSrcsetUrl($srcset);
                        if ($srcsetUrl !== '') {
                            $data['images'][] = $srcsetUrl;
                        }
                    }
                }
            }
        }

        // Certaines pages déclarent plusieurs balises og:image ; extractMeta() n'en
        // conserve qu'une par clé, donc nous les récupérons toutes ici.
        $metaNodes = $xpath->query('//meta[@content and (translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:image" or translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:image:secure_url" or translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="twitter:image")]');
        if ($metaNodes) {
            foreach ($metaNodes as $node) {
                $value = trim($node->getAttribute('content'));
                if ($value !== '') {
                    $data['images'][] = $value;
                }
            }
        }

        // JSON produit Shopify et scripts de galerie. On limite la lecture aux scripts
        // qui ressemblent réellement au produit courant pour éviter les recommandations.
        $scriptNodes = $xpath->query('//script');
        if ($scriptNodes) {
            foreach ($scriptNodes as $node) {
                $raw = trim($node->textContent);
                if ($raw === '' || strlen($raw) > 3000000 || !$this->isLikelyProductScript($node, $raw, $data)) {
                    continue;
                }

                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $this->collectImageUrlsFromProductData($decoded, $data['images'], false);
                }

                // Secours pour les scripts JavaScript non strictement JSON.
                $unescapedRaw = str_replace(array('\\/', '\\u0026'), array('/', '&'), $raw);
                if (preg_match_all('#(?:(?:https?:)?//cdn\.shopify\.com/[^"\'\s<>]+|(?:https?:)?//[^"\'\s<>]+/cdn/shop/(?:files|products)/[^"\'\s<>]+)#i', $unescapedRaw, $matches)) {
                    foreach ($matches[0] as $match) {
                        $data['images'][] = $match;
                    }
                }
            }
        }
    }

    private function isLikelyProductScript(DOMNode $node, $raw, array $data)
    {
        $identity = strtolower(trim($node->getAttribute('id') . ' ' . $node->getAttribute('class') . ' ' . $node->getAttribute('type')));
        if (strpos($identity, 'product') !== false || strpos($identity, 'json') !== false && strpos(strtolower($raw), 'product') !== false) {
            return true;
        }
        if (!empty($data['reference']) && stripos($raw, (string) $data['reference']) !== false) {
            return true;
        }
        if (!empty($data['name'])) {
            $needle = Tools::substr((string) $data['name'], 0, 60);
            if ($needle !== '' && stripos($raw, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function collectImageUrlsFromProductData($node, array &$urls, $imageContext)
    {
        if (is_string($node)) {
            if ($imageContext && $this->looksLikeImageUrl($node)) {
                $urls[] = $node;
            }
            return;
        }
        if (!is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            $keyString = is_string($key) ? strtolower($key) : '';
            $nextImageContext = $imageContext || in_array($keyString, array(
                'image', 'images', 'media', 'featured_image', 'featured_media',
                'preview_image', 'original_src', 'src', 'contenturl',
            ), true);
            $this->collectImageUrlsFromProductData($value, $urls, $nextImageContext);
        }
    }

    private function largestSrcsetUrl($srcset)
    {
        $bestUrl = '';
        $bestWidth = -1;
        foreach (explode(',', html_entity_decode((string) $srcset, ENT_QUOTES, 'UTF-8')) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $parts = preg_split('/\\s+/', $candidate);
            $url = isset($parts[0]) ? trim($parts[0]) : '';
            $width = 0;
            if (isset($parts[1]) && preg_match('/^(\\d+)w$/', $parts[1], $match)) {
                $width = (int) $match[1];
            }
            if ($url !== '' && ($bestUrl === '' || $width >= $bestWidth)) {
                $bestUrl = $url;
                $bestWidth = $width;
            }
        }
        return $bestUrl;
    }

    private function normalizeImageCandidate($value)
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = html_entity_decode(trim((string) $value), ENT_QUOTES, 'UTF-8');
        $value = str_replace(array('\\/', '\\u0026'), array('/', '&'), $value);
        $value = trim($value, " \\t\\n\\r\\0\\x0B\\\"'");
        if ($value === '' || stripos($value, 'data:') === 0 || stripos($value, 'javascript:') === 0) {
            return '';
        }
        return $value;
    }

    private function looksLikeImageUrl($value)
    {
        $value = $this->normalizeImageCandidate($value);
        if ($value === '') {
            return false;
        }
        return (bool) preg_match('#(?:\\.(?:jpe?g|png|webp|avif|gif)(?:[?&\#]|$)|/cdn/shop/(?:files|products)/|cdn\\.shopify\\.com)#i', $value);
    }

    private function removeSafeImageResizeParameters($url)
    {
        $parts = @parse_url((string) $url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }
        $host = strtolower($parts['host']);
        $path = isset($parts['path']) ? $parts['path'] : '';
        $isShopify = strpos($host, 'shopify.com') !== false || strpos($path, '/cdn/shop/') !== false;
        if (!$isShopify || empty($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);
        foreach (array('width', 'height', 'crop', 'format', 'quality', 'q') as $key) {
            unset($query[$key]);
        }
        $parts['query'] = http_build_query($query, '', '&');
        return $this->buildUrl($parts);
    }

    private function imageDedupeKey($url)
    {
        $parts = @parse_url((string) $url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return strtolower((string) $url);
        }

        $host = strtolower((string) $parts['host']);
        $path = rawurldecode((string) $parts['path']);
        $query = array();
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach (array('width', 'height', 'w', 'h', 'crop', 'format', 'quality', 'q', 'v') as $key) {
                unset($query[$key]);
            }
            ksort($query);
        }

        // Une même image Shopify peut être exposée sous le domaine de la boutique,
        // cdn.shopify.com et plusieurs variantes de dimensions. On la ramène à son
        // nom logique pour éviter de l'afficher plusieurs fois dans l'aperçu.
        $isShopify = strpos($host, 'shopify.com') !== false || strpos($path, '/cdn/shop/') !== false;
        if ($isShopify) {
            $filename = strtolower((string) pathinfo(basename($path), PATHINFO_FILENAME));
            $previous = null;
            while ($previous !== $filename) {
                $previous = $filename;
                $filename = preg_replace('/_(?:\d{2,5}x\d{0,5}|x\d{2,5}|\d{2,5}_\d{2,5}x)$/i', '', $filename);
                $filename = preg_replace('/@(?:2x|3x)$/i', '', $filename);
            }
            if ($filename !== '') {
                return 'shopify:' . $filename;
            }
        }

        return strtolower($host . $path . ($query ? '?' . http_build_query($query, '', '&') : ''));
    }

    /**
     * Écarte aussi les doublons servis sous des URL ou des formats différents.
     * La comparaison SHA-1 détecte les fichiers strictement identiques ; le dHash
     * détecte une même image simplement redimensionnée ou recompressée.
     */
    private function deduplicateRemoteImages(array $urls)
    {
        $keptUrls = array();
        $fingerprints = array();
        $duplicatesRemoved = 0;

        foreach ($urls as $url) {
            $fingerprint = null;
            try {
                $response = $this->httpClient->get($url, self::MAX_IMAGE_FINGERPRINT_BYTES, 2);
                $fingerprint = $this->fingerprintImageBytes($response['body']);
            } catch (Exception $e) {
                // Une image inaccessible reste proposée : l'importeur fournira ensuite
                // un message détaillé si son téléchargement échoue réellement.
            }

            $duplicateIndex = null;
            if (is_array($fingerprint)) {
                foreach ($fingerprints as $index => $existing) {
                    if (!is_array($existing)) {
                        continue;
                    }
                    if ($fingerprint['sha1'] !== '' && $fingerprint['sha1'] === $existing['sha1']) {
                        $duplicateIndex = $index;
                        break;
                    }
                    if (
                        $fingerprint['dhash'] !== ''
                        && $fingerprint['dhash'] === $existing['dhash']
                        && $this->sameImageProportions($fingerprint, $existing)
                    ) {
                        $duplicateIndex = $index;
                        break;
                    }
                }
            }

            if ($duplicateIndex !== null) {
                ++$duplicatesRemoved;
                $newArea = (int) $fingerprint['width'] * (int) $fingerprint['height'];
                $oldArea = (int) $fingerprints[$duplicateIndex]['width'] * (int) $fingerprints[$duplicateIndex]['height'];
                if ($newArea > $oldArea) {
                    $keptUrls[$duplicateIndex] = $url;
                    $fingerprints[$duplicateIndex] = $fingerprint;
                }
                continue;
            }

            $keptUrls[] = $url;
            $fingerprints[] = $fingerprint;
        }

        return array(
            'urls' => array_values($keptUrls),
            'duplicates_removed' => $duplicatesRemoved,
        );
    }

    private function fingerprintImageBytes($bytes)
    {
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }

        $sha1 = sha1($bytes);
        $info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($bytes) : false;
        $width = is_array($info) && isset($info[0]) ? (int) $info[0] : 0;
        $height = is_array($info) && isset($info[1]) ? (int) $info[1] : 0;
        $dhash = '';

        if (
            function_exists('imagecreatefromstring')
            && function_exists('imagecreatetruecolor')
            && function_exists('imagecopyresampled')
        ) {
            $source = @imagecreatefromstring($bytes);
            if ($this->isGdImage($source)) {
                if ($width <= 0) {
                    $width = imagesx($source);
                }
                if ($height <= 0) {
                    $height = imagesy($source);
                }
                $small = imagecreatetruecolor(9, 8);
                if ($this->isGdImage($small)) {
                    $white = imagecolorallocate($small, 255, 255, 255);
                    imagefilledrectangle($small, 0, 0, 8, 7, $white);
                    imagecopyresampled($small, $source, 0, 0, 0, 0, 9, 8, imagesx($source), imagesy($source));
                    $bits = '';
                    for ($y = 0; $y < 8; ++$y) {
                        for ($x = 0; $x < 8; ++$x) {
                            $bits .= $this->grayAt($small, $x, $y) > $this->grayAt($small, $x + 1, $y) ? '1' : '0';
                        }
                    }
                    $dhash = $this->bitsToHex($bits);
                    imagedestroy($small);
                }
                imagedestroy($source);
            }
        }

        return array(
            'sha1' => $sha1,
            'dhash' => $dhash,
            'width' => $width,
            'height' => $height,
        );
    }

    private function sameImageProportions(array $left, array $right)
    {
        if (empty($left['width']) || empty($left['height']) || empty($right['width']) || empty($right['height'])) {
            return true;
        }
        $leftRatio = (float) $left['width'] / (float) $left['height'];
        $rightRatio = (float) $right['width'] / (float) $right['height'];
        return abs($leftRatio - $rightRatio) <= 0.025;
    }

    private function grayAt($image, $x, $y)
    {
        $rgb = imagecolorat($image, $x, $y);
        $red = ($rgb >> 16) & 0xFF;
        $green = ($rgb >> 8) & 0xFF;
        $blue = $rgb & 0xFF;
        return (int) round(($red * 0.299) + ($green * 0.587) + ($blue * 0.114));
    }

    private function bitsToHex($bits)
    {
        $hex = '';
        for ($offset = 0; $offset < strlen($bits); $offset += 4) {
            $hex .= dechex(bindec(substr($bits, $offset, 4)));
        }
        return str_pad($hex, 16, '0', STR_PAD_LEFT);
    }

    private function isGdImage($value)
    {
        if (is_resource($value)) {
            return true;
        }
        return class_exists('GdImage', false) && $value instanceof GdImage;
    }

    private function buildUrl(array $parts)
    {
        $url = '';
        if (!empty($parts['scheme'])) {
            $url .= $parts['scheme'] . '://';
        }
        if (!empty($parts['user'])) {
            $url .= $parts['user'];
            if (!empty($parts['pass'])) {
                $url .= ':' . $parts['pass'];
            }
            $url .= '@';
        }
        if (!empty($parts['host'])) {
            $url .= $parts['host'];
        }
        if (!empty($parts['port'])) {
            $url .= ':' . $parts['port'];
        }
        $url .= isset($parts['path']) ? $parts['path'] : '';
        if (!empty($parts['query'])) {
            $url .= '?' . $parts['query'];
        }
        if (!empty($parts['fragment'])) {
            $url .= '#' . $parts['fragment'];
        }
        return $url;
    }

    private function firstAttributeOrText(DOMXPath $xpath, $query, array $attributes)
    {
        $nodes = $xpath->query($query);
        if (!$nodes || !$nodes->length) {
            return '';
        }

        foreach ($nodes as $node) {
            foreach ($attributes as $attribute) {
                $value = trim($node->getAttribute($attribute));
                if ($value !== '') {
                    return $value;
                }
            }
            $value = trim($node->textContent);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function scalarValue($value)
    {
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            foreach (array('name', 'value', '@value', 'url', 'contentUrl') as $key) {
                if (isset($value[$key]) && is_scalar($value[$key])) {
                    return (string) $value[$key];
                }
            }
        }
        return '';
    }

    private function extractBrandName($brand)
    {
        if (is_scalar($brand)) {
            return (string) $brand;
        }
        if (!is_array($brand)) {
            return '';
        }
        if (isset($brand['name'])) {
            return $this->scalarValue($brand['name']);
        }
        foreach ($brand as $candidate) {
            $name = $this->extractBrandName($candidate);
            if ($name !== '') {
                return $name;
            }
        }
        return '';
    }

    private function extractImageUrls($images)
    {
        if (is_string($images)) {
            return array($images);
        }
        if (!is_array($images)) {
            return array();
        }

        foreach (array('url', 'contentUrl') as $key) {
            if (isset($images[$key]) && is_string($images[$key])) {
                return array($images[$key]);
            }
        }

        $urls = array();
        foreach ($images as $image) {
            $urls = array_merge($urls, $this->extractImageUrls($image));
        }
        return $urls;
    }

    private function firstText(DOMXPath $xpath, $query)
    {
        $nodes = $xpath->query($query);
        return $nodes && $nodes->length ? trim($nodes->item(0)->textContent) : '';
    }

    private function parsePrice($value)
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/[^0-9,\.\-]/u', '', $value);
        if ($value === '') {
            return null;
        }
        if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } else {
            $value = str_replace(',', '.', $value);
        }
        return is_numeric($value) ? round((float) $value, 6) : null;
    }

    private function cleanText($value, $maxLength)
    {
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value);
        return $this->truncate(trim($value), $maxLength);
    }

    private function cleanSingleLine($value, $maxLength)
    {
        return $this->truncate($this->cleanText($value, $maxLength), $maxLength);
    }

    private function cleanReference($value)
    {
        $value = preg_replace('/[^A-Za-z0-9_\.\-\/]/', '', (string) $value);
        return substr($value, 0, 64);
    }

    private function cleanEan($value)
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if (strlen($digits) === 12) {
            // Les fournisseurs affichent souvent un UPC-A à 12 chiffres.
            // PrestaShop attend un EAN-13 : le préfixe 0 conserve le même code.
            $digits = '0' . $digits;
        } elseif (strlen($digits) === 14 && substr($digits, 0, 1) === '0') {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) !== 13 || !$this->isValidEan13Checksum($digits)) {
            return '';
        }
        return $digits;
    }

    private function isValidEan13Checksum($digits)
    {
        if (!preg_match('/^\d{13}$/', (string) $digits) || preg_match('/^(\d)\1{12}$/', (string) $digits)) {
            return false;
        }
        $sum = 0;
        for ($index = 0; $index < 12; ++$index) {
            $sum += (int) $digits[$index] * ($index % 2 === 0 ? 1 : 3);
        }
        $expected = (10 - ($sum % 10)) % 10;
        return $expected === (int) $digits[12];
    }

    private function truncate($value, $length)
    {
        $value = (string) $value;
        if (Tools::strlen($value) <= $length) {
            return $value;
        }
        return rtrim(Tools::substr($value, 0, $length - 1)) . '…';
    }
}
