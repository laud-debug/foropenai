<?php

class RfProductFactoryImageMatcher
{
    const AUTO_INDEX_BATCH = 120;
    const MAX_REMOTE_IMAGES = 2;
    const MAX_REMOTE_BYTES = 12582912;

    private $httpClient;

    public function __construct(RfProductFactoryHttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    public static function getCreateTableSql()
    {
        return 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'rfproductfactory_image_hash` (
            `id_rfproductfactory_image_hash` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `id_product` INT UNSIGNED NOT NULL,
            `id_image` INT UNSIGNED NOT NULL,
            `sha1` CHAR(40) NOT NULL DEFAULT \'\',
            `dhash` CHAR(16) NOT NULL DEFAULT \'\',
            `width` INT UNSIGNED NOT NULL DEFAULT 0,
            `height` INT UNSIGNED NOT NULL DEFAULT 0,
            `variance` DECIMAL(10,4) NOT NULL DEFAULT 0,
            `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
            `file_mtime` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_rfproductfactory_image_hash`),
            UNIQUE KEY `uniq_rfpf_shop_image` (`id_shop`, `id_image`),
            KEY `idx_rfpf_image_product` (`id_shop`, `id_product`),
            KEY `idx_rfpf_image_sha1` (`sha1`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
    }

    public function ensureTable()
    {
        return (bool) Db::getInstance()->execute(self::getCreateTableSql());
    }

    /**
     * Compare les premières images distantes avec les couvertures déjà indexées.
     * Les couvertures des candidats texte/référence sont indexées immédiatement.
     */
    public function findMatches(array $imageUrls, $idLang, $idShop, array $candidateProductIds = array())
    {
        $result = array(
            'matches' => array(),
            'warnings' => array(),
            'stats' => array(),
        );

        if (!$this->gdAvailable()) {
            $result['warnings'][] = 'La comparaison visuelle est désactivée car PHP/GD ne peut pas décoder les images.';
            return $result;
        }
        if (!$this->ensureTable()) {
            $result['warnings'][] = 'Le cache de comparaison des images n’a pas pu être initialisé.';
            return $result;
        }

        $candidateProductIds = array_values(array_unique(array_filter(array_map('intval', $candidateProductIds))));
        if ($candidateProductIds) {
            $this->indexProductIds($idShop, $candidateProductIds, true);
        }

        // Le cache progresse automatiquement à chaque analyse, même sans action manuelle.
        $this->indexBatch($idShop, self::AUTO_INDEX_BATCH);

        $sourceFingerprints = array();
        foreach (array_slice(array_values(array_unique($imageUrls)), 0, self::MAX_REMOTE_IMAGES) as $imageUrl) {
            try {
                $response = $this->httpClient->getImage($imageUrl, self::MAX_REMOTE_BYTES, 3, null);
                $fingerprint = $this->fingerprintBytes($response['body']);
                if ($fingerprint === null) {
                    $result['warnings'][] = 'Une image distante n’a pas pu être utilisée pour la comparaison visuelle.';
                    continue;
                }
                if ($fingerprint['variance'] < 5) {
                    // Évite que des images presque blanches ou transparentes créent de faux doublons.
                    continue;
                }
                $fingerprint['source_url'] = $imageUrl;
                $sourceFingerprints[] = $fingerprint;
            } catch (Exception $e) {
                $result['warnings'][] = 'Comparaison d’image ignorée pour une source : ' . $e->getMessage();
            }
        }

        if (!$sourceFingerprints) {
            $result['stats'] = $this->getStats($idShop);
            return $result;
        }

        $cached = Db::getInstance()->executeS(
            'SELECT h.`id_product`, h.`id_image`, h.`sha1`, h.`dhash`, h.`width`, h.`height`, h.`variance`,
                    p.`reference` AS `product_reference`, p.`ean13` AS `product_ean13`, p.`active`,
                    pl.`name`, pl.`link_rewrite`, 0 AS `id_product_attribute`,
                    NULL AS `combination_reference`, NULL AS `combination_ean13`
             FROM `' . _DB_PREFIX_ . 'rfproductfactory_image_hash` h
             INNER JOIN `' . _DB_PREFIX_ . 'product` p ON (p.`id_product` = h.`id_product`)
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON (ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . (int) $idShop . ')
             INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON (pl.`id_product` = p.`id_product`
                    AND pl.`id_shop` = ' . (int) $idShop . '
                    AND pl.`id_lang` = ' . (int) $idLang . ')
             WHERE h.`id_shop` = ' . (int) $idShop . " AND h.`dhash` <> ''"
        );

        if (!is_array($cached)) {
            $cached = array();
        }

        $matches = array();
        foreach ($cached as $row) {
            $best = null;
            foreach ($sourceFingerprints as $source) {
                $comparison = $this->compareFingerprints($source, $row);
                if ($comparison === null) {
                    continue;
                }
                if ($best === null || $comparison['score'] > $best['score']) {
                    $best = $comparison;
                }
            }
            if ($best === null) {
                continue;
            }

            $key = (int) $row['id_product'];
            $row['match_labels'] = array($best['label']);
            $row['strong_match'] = $best['strong'] ? 1 : 0;
            $row['match_score'] = $best['score'];
            $row['matched_image_id'] = (int) $row['id_image'];

            if (!isset($matches[$key]) || (int) $row['match_score'] > (int) $matches[$key]['match_score']) {
                $matches[$key] = $row;
            }
        }

        $result['matches'] = array_values($matches);
        $result['stats'] = $this->getStats($idShop);
        if (!empty($result['stats']['remaining'])) {
            $result['warnings'][] = sprintf(
                'La comparaison visuelle couvre actuellement %d image(s) sur %d. Utilisez le bouton d’indexation pour analyser tout le catalogue.',
                (int) $result['stats']['indexed'],
                (int) $result['stats']['total']
            );
        }

        return $result;
    }

    /**
     * Retourne uniquement les images distantes qui ne sont pas déjà présentes
     * sur le produit cible. Les doublons binaires sont toujours écartés ; les
     * doublons visuels sont écartés lorsque PHP/GD est disponible.
     */
    public function filterNewImagesForProduct(array $imageUrls, $idProduct, $idShop, $sourceUrl = '')
    {
        $result = array(
            'new_urls' => array(),
            'skipped' => array(),
            'warnings' => array(),
        );

        $idProduct = (int) $idProduct;
        $idShop = (int) $idShop;
        if ($idProduct <= 0) {
            throw new PrestaShopException('Le produit à enrichir est invalide.');
        }

        $existsInShop = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_shop`'
            . ' WHERE `id_product` = ' . $idProduct . ' AND `id_shop` = ' . $idShop
        );
        if (!$existsInShop) {
            throw new PrestaShopException('Le produit à enrichir n’appartient pas à la boutique courante.');
        }

        $rows = Db::getInstance()->executeS(
            'SELECT i.`id_image`'
            . ' FROM `' . _DB_PREFIX_ . 'image` i'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'image_shop` ish'
            . ' ON (ish.`id_image` = i.`id_image` AND ish.`id_shop` = ' . $idShop . ')'
            . ' WHERE i.`id_product` = ' . $idProduct
            . ' ORDER BY i.`position` ASC, i.`id_image` ASC'
        );
        if (!is_array($rows)) {
            $rows = array();
        }

        $canCompareVisually = $this->gdAvailable();
        $existing = array();
        foreach ($rows as $row) {
            $path = $this->getImagePath((int) $row['id_image']);
            if (!$path || !is_file($path) || !is_readable($path)) {
                continue;
            }

            $fingerprint = array(
                'sha1' => (string) @sha1_file($path),
                'dhash' => '',
                'width' => 0,
                'height' => 0,
            );
            if ($canCompareVisually) {
                $bytes = @file_get_contents($path);
                if ($bytes !== false) {
                    $visual = $this->fingerprintBytes($bytes);
                    if (is_array($visual)) {
                        $fingerprint = $visual;
                    }
                }
            }
            if ($fingerprint['sha1'] !== '') {
                $existing[] = $fingerprint;
            }
        }

        foreach (array_slice(array_values(array_unique($imageUrls)), 0, 8) as $imageUrl) {
            try {
                $response = $this->httpClient->getImage($imageUrl, self::MAX_REMOTE_BYTES, 3, $sourceUrl);
                $bytes = isset($response['body']) ? $response['body'] : '';
                if (!is_string($bytes) || $bytes === '') {
                    throw new PrestaShopException('L’image distante est vide.');
                }

                $remote = array(
                    'sha1' => sha1($bytes),
                    'dhash' => '',
                    'width' => 0,
                    'height' => 0,
                );
                if ($canCompareVisually) {
                    $visual = $this->fingerprintBytes($bytes);
                    if (is_array($visual)) {
                        $remote = $visual;
                    }
                }

                $duplicateReason = '';
                foreach ($existing as $existingFingerprint) {
                    if ($existingFingerprint['sha1'] !== '' && hash_equals($existingFingerprint['sha1'], $remote['sha1'])) {
                        $duplicateReason = 'Image strictement identique déjà présente';
                        break;
                    }
                    if ($canCompareVisually && $remote['dhash'] !== '' && $existingFingerprint['dhash'] !== '') {
                        $comparison = $this->compareFingerprints($remote, $existingFingerprint);
                        if (is_array($comparison) && !empty($comparison['strong'])) {
                            $duplicateReason = $comparison['label'];
                            break;
                        }
                    }
                }

                if ($duplicateReason !== '') {
                    $result['skipped'][] = array(
                        'url' => $imageUrl,
                        'reason' => $duplicateReason,
                    );
                    continue;
                }

                $result['new_urls'][] = $imageUrl;
                $existing[] = $remote;
            } catch (Exception $e) {
                // Une comparaison visuelle est seulement une optimisation. Elle ne doit
                // jamais empêcher l'import réel, qui possède ses propres tentatives,
                // son profil HTTP image et ses variantes de taille.
                $result['new_urls'][] = $imageUrl;
                $result['warnings'][] = $imageUrl
                    . ' : comparaison préalable impossible (' . $e->getMessage()
                    . '), import direct tenté.';
            }
        }

        if (!$canCompareVisually) {
            $result['warnings'][] = 'PHP/GD n’est pas disponible : seules les images strictement identiques ont pu être écartées.';
        }

        return $result;
    }

    /**
     * Indexe un lot de couvertures. Cette méthode est aussi appelée en AJAX.
     */
    public function indexBatch($idShop, $limit)
    {
        if (!$this->gdAvailable()) {
            throw new PrestaShopException('PHP/GD est nécessaire pour indexer les images de couverture.');
        }
        $this->ensureTable();
        $limit = max(1, min(500, (int) $limit));

        $rows = Db::getInstance()->executeS(
            'SELECT i.`id_image`, i.`id_product`
             FROM `' . _DB_PREFIX_ . 'image` i
             INNER JOIN `' . _DB_PREFIX_ . 'image_shop` ish
                ON (ish.`id_image` = i.`id_image`
                    AND ish.`id_shop` = ' . (int) $idShop . '
                    AND ish.`cover` = 1)
             LEFT JOIN `' . _DB_PREFIX_ . 'rfproductfactory_image_hash` h
                ON (h.`id_shop` = ' . (int) $idShop . ' AND h.`id_image` = i.`id_image`)
             WHERE h.`id_rfproductfactory_image_hash` IS NULL
             ORDER BY i.`id_image` ASC
             LIMIT ' . $limit
        );

        $processed = 0;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $this->indexImage((int) $idShop, (int) $row['id_product'], (int) $row['id_image'], false);
                ++$processed;
            }
        }

        $stats = $this->getStats($idShop);
        $stats['processed'] = $processed;

        return $stats;
    }

    public function getStats($idShop)
    {
        $this->ensureTable();
        $total = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*)
             FROM `' . _DB_PREFIX_ . 'image_shop`
             WHERE `id_shop` = ' . (int) $idShop . ' AND `cover` = 1'
        );
        $indexed = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*)
             FROM `' . _DB_PREFIX_ . 'rfproductfactory_image_hash` h
             INNER JOIN `' . _DB_PREFIX_ . 'image_shop` ish
                ON (ish.`id_image` = h.`id_image`
                    AND ish.`id_shop` = ' . (int) $idShop . '
                    AND ish.`cover` = 1)
             WHERE h.`id_shop` = ' . (int) $idShop
        );

        return array(
            'total' => $total,
            'indexed' => min($indexed, $total),
            'remaining' => max(0, $total - $indexed),
            'percent' => $total > 0 ? min(100, (int) floor(($indexed / $total) * 100)) : 100,
        );
    }

    private function indexProductIds($idShop, array $productIds, $force)
    {
        if (!$productIds) {
            return;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT i.`id_image`, i.`id_product`
             FROM `' . _DB_PREFIX_ . 'image` i
             INNER JOIN `' . _DB_PREFIX_ . 'image_shop` ish
                ON (ish.`id_image` = i.`id_image`
                    AND ish.`id_shop` = ' . (int) $idShop . '
                    AND ish.`cover` = 1)
             WHERE i.`id_product` IN (' . implode(',', array_map('intval', $productIds)) . ')'
        );

        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            $this->indexImage((int) $idShop, (int) $row['id_product'], (int) $row['id_image'], (bool) $force);
        }
    }

    private function indexImage($idShop, $idProduct, $idImage, $force)
    {
        if (!$force) {
            $exists = (int) Db::getInstance()->getValue(
                'SELECT `id_rfproductfactory_image_hash`
                 FROM `' . _DB_PREFIX_ . 'rfproductfactory_image_hash`
                 WHERE `id_shop` = ' . (int) $idShop . ' AND `id_image` = ' . (int) $idImage
            );
            if ($exists) {
                return true;
            }
        }

        $path = $this->getImagePath($idImage);
        $fingerprint = null;
        $fileSize = 0;
        $fileMtime = 0;
        if ($path && is_file($path) && is_readable($path)) {
            $fileSize = (int) @filesize($path);
            $fileMtime = (int) @filemtime($path);
            if ($fileSize > 0 && $fileSize <= self::MAX_REMOTE_BYTES) {
                $bytes = @file_get_contents($path);
                if ($bytes !== false) {
                    $fingerprint = $this->fingerprintBytes($bytes);
                }
            }
        }

        $data = array(
            'id_shop' => (int) $idShop,
            'id_product' => (int) $idProduct,
            'id_image' => (int) $idImage,
            'sha1' => $fingerprint ? pSQL($fingerprint['sha1']) : '',
            'dhash' => $fingerprint ? pSQL($fingerprint['dhash']) : '',
            'width' => $fingerprint ? (int) $fingerprint['width'] : 0,
            'height' => $fingerprint ? (int) $fingerprint['height'] : 0,
            'variance' => $fingerprint ? (float) $fingerprint['variance'] : 0,
            'file_size' => $fileSize,
            'file_mtime' => $fileMtime,
            'date_upd' => date('Y-m-d H:i:s'),
        );

        return (bool) Db::getInstance()->insert(
            'rfproductfactory_image_hash',
            $data,
            false,
            true,
            Db::REPLACE
        );
    }

    private function getImagePath($idImage)
    {
        try {
            $image = new Image((int) $idImage);
            if (!Validate::isLoadedObject($image)) {
                return null;
            }
            $base = _PS_PROD_IMG_DIR_ . $image->getExistingImgPath();
            foreach (array('.jpg', '.jpeg', '.png', '.webp') as $extension) {
                if (is_file($base . $extension)) {
                    return $base . $extension;
                }
            }
        } catch (Exception $e) {
            return null;
        }

        return null;
    }

    private function compareFingerprints(array $source, array $cached)
    {
        $cachedSha1 = isset($cached['sha1']) ? (string) $cached['sha1'] : '';
        if ($cachedSha1 !== '' && hash_equals($cachedSha1, $source['sha1'])) {
            return array(
                'strong' => true,
                'score' => 100,
                'label' => 'Image de couverture strictement identique',
            );
        }

        $cachedDhash = isset($cached['dhash']) ? (string) $cached['dhash'] : '';
        if (strlen($cachedDhash) !== 16 || strlen($source['dhash']) !== 16) {
            return null;
        }

        $sourceRatio = $source['height'] > 0 ? $source['width'] / $source['height'] : 0;
        $cachedHeight = isset($cached['height']) ? (int) $cached['height'] : 0;
        $cachedWidth = isset($cached['width']) ? (int) $cached['width'] : 0;
        $cachedRatio = $cachedHeight > 0 ? $cachedWidth / $cachedHeight : 0;
        if ($sourceRatio <= 0 || $cachedRatio <= 0) {
            return null;
        }

        $ratioDifference = abs($sourceRatio - $cachedRatio) / max($sourceRatio, $cachedRatio);
        if ($ratioDifference > 0.10) {
            return null;
        }

        $distance = $this->hammingDistance($source['dhash'], $cachedDhash);
        if ($distance <= 2 && $ratioDifference <= 0.05) {
            return array(
                'strong' => true,
                'score' => 97 - ($distance * 2),
                'label' => 'Image de couverture visuellement identique (' . (int) $distance . ' différence(s))',
            );
        }
        if ($distance <= 5 && $ratioDifference <= 0.08) {
            return array(
                'strong' => false,
                'score' => 88 - ($distance * 2),
                'label' => 'Image de couverture très proche, à vérifier',
            );
        }

        return null;
    }

    private function fingerprintBytes($bytes)
    {
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }

        $source = @imagecreatefromstring($bytes);
        if (!$this->isGdImage($source)) {
            return null;
        }

        $width = (int) imagesx($source);
        $height = (int) imagesy($source);
        if ($width < 16 || $height < 16 || $width > 10000 || $height > 10000) {
            @imagedestroy($source);
            return null;
        }

        $hashCanvas = imagecreatetruecolor(9, 8);
        $varianceCanvas = imagecreatetruecolor(16, 16);
        if (!$this->isGdImage($hashCanvas) || !$this->isGdImage($varianceCanvas)) {
            @imagedestroy($source);
            if ($this->isGdImage($hashCanvas)) {
                @imagedestroy($hashCanvas);
            }
            if ($this->isGdImage($varianceCanvas)) {
                @imagedestroy($varianceCanvas);
            }
            return null;
        }

        $whiteHash = imagecolorallocate($hashCanvas, 255, 255, 255);
        $whiteVariance = imagecolorallocate($varianceCanvas, 255, 255, 255);
        imagefilledrectangle($hashCanvas, 0, 0, 8, 7, $whiteHash);
        imagefilledrectangle($varianceCanvas, 0, 0, 15, 15, $whiteVariance);
        imagecopyresampled($hashCanvas, $source, 0, 0, 0, 0, 9, 8, $width, $height);
        imagecopyresampled($varianceCanvas, $source, 0, 0, 0, 0, 16, 16, $width, $height);

        $bits = '';
        for ($y = 0; $y < 8; ++$y) {
            for ($x = 0; $x < 8; ++$x) {
                $left = $this->grayAt($hashCanvas, $x, $y);
                $right = $this->grayAt($hashCanvas, $x + 1, $y);
                $bits .= $left > $right ? '1' : '0';
            }
        }

        $values = array();
        for ($y = 0; $y < 16; ++$y) {
            for ($x = 0; $x < 16; ++$x) {
                $values[] = $this->grayAt($varianceCanvas, $x, $y);
            }
        }
        $mean = array_sum($values) / count($values);
        $varianceSum = 0;
        foreach ($values as $value) {
            $varianceSum += ($value - $mean) * ($value - $mean);
        }
        $standardDeviation = sqrt($varianceSum / count($values));

        @imagedestroy($hashCanvas);
        @imagedestroy($varianceCanvas);
        @imagedestroy($source);

        return array(
            'sha1' => sha1($bytes),
            'dhash' => $this->bitsToHex($bits),
            'width' => $width,
            'height' => $height,
            'variance' => round($standardDeviation, 4),
        );
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

    private function hammingDistance($leftHex, $rightHex)
    {
        static $bitCounts = array(0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4);
        $distance = 0;
        for ($i = 0; $i < 16; ++$i) {
            $left = hexdec($leftHex[$i]);
            $right = hexdec($rightHex[$i]);
            $distance += $bitCounts[$left ^ $right];
        }

        return $distance;
    }

    private function gdAvailable()
    {
        return function_exists('imagecreatefromstring')
            && function_exists('imagecreatetruecolor')
            && function_exists('imagecopyresampled');
    }

    private function isGdImage($value)
    {
        if (is_resource($value)) {
            return true;
        }

        return class_exists('GdImage', false) && $value instanceof GdImage;
    }
}
