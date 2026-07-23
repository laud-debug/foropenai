<?php

class RfProductFactorySpreadsheetImporter
{
    const MAX_ROWS = 200;

    private $headerAliases = array(
        'release_date' => array('releasedatelast3months', 'releasedate', 'date de sortie', 'datedesortie'),
        'module' => array('module'),
        'system' => array('system', 'système', 'systeme'),
        'race' => array('race', 'gamme', 'faction'),
        'ss_code' => array('sscode', 'ss code'),
        'product_code' => array('productcode', 'product code', 'code produit', 'codeproduit'),
        'order_qty' => array('order', 'commande', 'quantite commandee', 'quantité commandée'),
        'description' => array('description', 'designation', 'désignation', 'nom'),
        'rsl' => array('rsl'),
        'qty_in_pack' => array('qtyinpack', 'qty in pack', 'quantite par colis', 'quantité par colis'),
        'barcode' => array('barcode', 'ean', 'ean13', 'code barre', 'codebarre'),
        'commodity_code' => array('commoditycode', 'commodity code', 'code douanier', 'codedouanier'),
        'country_origin' => array('countryoforigin', 'country of origin', 'pays origine', 'pays d origine'),
        'weight_kg' => array('weightkg', 'weight (kg)', 'poidskg', 'poids kg'),
        'cube_cm' => array('cubecm', 'cube (cm)', 'cube'),
        'frr' => array('frr'),
        'eud' => array('eud'),
        'chr' => array('chr'),
        'chd' => array('chd'),
    );

    public function parse($raw, array $options, array $categories, array $taxGroups, $idLang, $idShop)
    {
        $raw = $this->normalizeInput($raw);
        if ($raw === '') {
            throw new PrestaShopException('Collez d’abord les lignes copiées depuis Excel.');
        }
        if (strlen($raw) > 2 * 1024 * 1024) {
            throw new PrestaShopException('Le copier-coller dépasse la limite de 2 Mo.');
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $lines = array_values(array_filter($lines, array($this, 'lineIsNotEmpty')));
        if (count($lines) < 2) {
            throw new PrestaShopException('Le tableau doit contenir une ligne d’en-têtes et au moins une ligne produit.');
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $headers = str_getcsv($lines[0], $delimiter);
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        }
        $headerMap = $this->buildHeaderMap($headers);

        foreach (array('description', 'barcode') as $required) {
            if (!isset($headerMap[$required])) {
                throw new PrestaShopException(sprintf('La colonne obligatoire « %s » est introuvable.', $required === 'description' ? 'Description' : 'Barcode'));
            }
        }
        if (!isset($headerMap['product_code'])) {
            throw new PrestaShopException('La colonne « Product Code » est obligatoire : la référence PrestaShop suit la règle GW- + Product Code.');
        }

        $rows = array();
        $warnings = array();
        $limitReached = false;
        for ($lineIndex = 1; $lineIndex < count($lines); $lineIndex++) {
            if (count($rows) >= self::MAX_ROWS) {
                $limitReached = true;
                break;
            }
            $cells = str_getcsv($lines[$lineIndex], $delimiter);
            if (!$this->hasUsefulCells($cells)) {
                continue;
            }
            $rawRow = $this->mapRow($cells, $headerMap);
            $rows[] = $this->prepareRow($rawRow, count($rows), $options, $categories, $taxGroups);
        }

        if (!$rows) {
            throw new PrestaShopException('Aucune ligne produit exploitable n’a été trouvée.');
        }
        if ($limitReached) {
            $warnings[] = sprintf('Seules les %d premières lignes ont été analysées.', self::MAX_ROWS);
        }

        $this->attachExistingProducts($rows, (int) $idLang, (int) $idShop);
        $this->markInternalDuplicates($rows);

        $stats = array(
            'total' => count($rows),
            'valid' => 0,
            'new' => 0,
            'existing' => 0,
            'invalid' => 0,
            'selected' => 0,
        );
        foreach ($rows as &$row) {
            if (!empty($row['errors'])) {
                $stats['invalid']++;
                $row['selected'] = 0;
            } elseif (!empty($row['existing_product'])) {
                $stats['existing']++;
                $row['selected'] = 0;
            } else {
                $stats['valid']++;
                $stats['new']++;
                $row['selected'] = 1;
                $stats['selected']++;
            }
        }
        unset($row);

        return array(
            'rows' => $rows,
            'stats' => $stats,
            'warnings' => $warnings,
            'headers' => $headers,
            'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter,
        );
    }

    public function refreshExistingProducts(array &$rows, $idLang, $idShop)
    {
        foreach ($rows as &$row) {
            $row['existing_product'] = null;
            $row['existing_reason'] = '';
        }
        unset($row);
        $this->attachExistingProducts($rows, (int) $idLang, (int) $idShop);
        $this->markInternalDuplicates($rows);
    }

    public function validateSubmittedRow(array $row, array $categories, array $taxGroups)
    {
        $errors = array();
        $name = trim(isset($row['name']) ? (string) $row['name'] : '');
        $productCode = trim(isset($row['product_code']) ? (string) $row['product_code'] : '');
        $reference = $this->buildReference($productCode, 'GW-');
        $ean13 = preg_replace('/\D+/', '', isset($row['ean13']) ? (string) $row['ean13'] : '');
        $priceTtc = $this->parseMoney(isset($row['price_ttc']) ? $row['price_ttc'] : '');
        $wholesale = $this->parseMoney(isset($row['wholesale_price_ht']) ? $row['wholesale_price_ht'] : '');
        $weight = $this->parseDecimal(isset($row['weight']) ? $row['weight'] : '');
        $idCategory = (int) (isset($row['id_category_default']) ? $row['id_category_default'] : 0);
        $idTax = (int) (isset($row['id_tax_rules_group']) ? $row['id_tax_rules_group'] : 0);

        if ($name === '' || !Validate::isCatalogName($name)) {
            $errors[] = 'Nom invalide.';
        }
        if ($productCode === '') {
            $errors[] = 'Product Code manquant : la référence doit être GW- + Product Code.';
        } elseif ($reference === '' || !Validate::isReference($reference)) {
            $errors[] = 'Référence GW-Product Code invalide.';
        }
        if ($ean13 === '' || !Validate::isEan13($ean13)) {
            $errors[] = 'EAN-13 invalide.';
        }
        if ($priceTtc === null || $priceTtc < 0) {
            $errors[] = 'Prix de vente TTC invalide.';
        }
        if ($wholesale === null || $wholesale < 0) {
            $errors[] = 'Prix d’achat HT invalide.';
        }
        if (!$this->idExistsInRows($idCategory, $categories, 'id_category')) {
            $errors[] = 'Catégorie invalide.';
        }
        if (!$this->idExistsInRows($idTax, $taxGroups, 'id_tax_rules_group')) {
            $errors[] = 'Règle de taxe invalide.';
        }

        $rate = $this->getTaxRate($idTax, $taxGroups);
        $row['name'] = $name;
        $row['reference'] = $reference;
        $row['ean13'] = $ean13;
        $row['price_ttc'] = $priceTtc === null ? 0 : $priceTtc;
        $row['price_ht'] = $priceTtc === null ? 0 : round($priceTtc / (1 + ($rate / 100)), 6);
        $row['wholesale_price_ht'] = $wholesale === null ? 0 : $wholesale;
        $row['weight'] = $weight === null ? 0 : max(0, $weight);
        $row['id_category_default'] = $idCategory;
        $row['id_tax_rules_group'] = $idTax;
        $row['available_date'] = $this->normalizeDate(isset($row['available_date']) ? $row['available_date'] : '');
        $row['product_code'] = $productCode;
        $row['supplier_reference'] = $productCode;
        $row['errors'] = $errors;

        return $row;
    }

    private function prepareRow(array $raw, $index, array $options, array $categories, array $taxGroups)
    {
        $ssCode = trim(isset($raw['ss_code']) ? (string) $raw['ss_code'] : '');
        $productCode = trim(isset($raw['product_code']) ? (string) $raw['product_code'] : '');

        // Règle catalogue Rebel Forge : la référence produit est toujours GW- + Product Code.
        // Le SS Code reste une information fournisseur séparée et ne sert jamais de référence PrestaShop.
        $reference = $this->buildReference($productCode, 'GW-');
        $supplierReference = $productCode;

        $name = trim(isset($raw['description']) ? (string) $raw['description'] : '');
        if (!empty($options['normalize_names'])) {
            $name = $this->normalizeProductName($name);
        }

        $ean13 = preg_replace('/\D+/', '', isset($raw['barcode']) ? (string) $raw['barcode'] : '');
        if (strlen($ean13) === 12) {
            $ean13 = '0' . $ean13;
        }

        $saleColumn = isset($options['sale_price_source']) ? strtolower((string) $options['sale_price_source']) : 'frr';
        $wholesaleColumn = isset($options['wholesale_price_source']) ? strtolower((string) $options['wholesale_price_source']) : 'eud';
        $priceTtc = $this->parseMoney(isset($raw[$saleColumn]) ? $raw[$saleColumn] : '');
        $wholesale = $this->parseMoney(isset($raw[$wholesaleColumn]) ? $raw[$wholesaleColumn] : '');

        $isBook = $this->isBook($raw, $ean13);
        $idTax = $isBook
            ? (int) (isset($options['id_tax_rules_group_book']) ? $options['id_tax_rules_group_book'] : 0)
            : (int) (isset($options['id_tax_rules_group_standard']) ? $options['id_tax_rules_group_standard'] : 0);
        if (!$this->idExistsInRows($idTax, $taxGroups, 'id_tax_rules_group')) {
            $idTax = (int) (isset($options['id_tax_rules_group_standard']) ? $options['id_tax_rules_group_standard'] : 0);
        }

        $idCategory = $this->guessCategoryId(
            isset($raw['race']) ? $raw['race'] : '',
            isset($raw['system']) ? $raw['system'] : '',
            (int) (isset($options['id_category_default']) ? $options['id_category_default'] : 0),
            $categories,
            !empty($options['auto_category'])
        );
        $rate = $this->getTaxRate($idTax, $taxGroups);

        $errors = array();
        if ($name === '' || !Validate::isCatalogName($name)) {
            $errors[] = 'Nom manquant ou invalide.';
        }
        if ($productCode === '') {
            $errors[] = 'Product Code manquant : impossible de construire la référence GW-Product Code.';
        } elseif ($reference === '' || !Validate::isReference($reference)) {
            $errors[] = 'Référence GW-Product Code invalide.';
        }
        if ($ean13 === '' || !Validate::isEan13($ean13)) {
            $errors[] = 'Code-barres invalide.';
        }
        if ($priceTtc === null || $priceTtc < 0) {
            $errors[] = 'Prix de vente introuvable dans ' . strtoupper($saleColumn) . '.';
        }
        if ($wholesale === null || $wholesale < 0) {
            $errors[] = 'Prix d’achat introuvable dans ' . strtoupper($wholesaleColumn) . '.';
        }
        if (!$this->idExistsInRows($idCategory, $categories, 'id_category')) {
            $errors[] = 'Catégorie invalide.';
        }
        if (!$this->idExistsInRows($idTax, $taxGroups, 'id_tax_rules_group')) {
            $errors[] = 'Règle de taxe invalide.';
        }

        return array(
            'index' => (int) $index,
            'selected' => empty($errors) ? 1 : 0,
            'name' => $name,
            'reference' => $reference,
            'ean13' => $ean13,
            'supplier_reference' => $supplierReference,
            'price_ttc' => $priceTtc === null ? '' : $priceTtc,
            'price_ht' => $priceTtc === null ? 0 : round($priceTtc / (1 + ($rate / 100)), 6),
            'wholesale_price_ht' => $wholesale === null ? '' : $wholesale,
            'id_category_default' => $idCategory,
            'id_tax_rules_group' => $idTax,
            'available_date' => $this->normalizeDate(isset($raw['release_date']) ? $raw['release_date'] : ''),
            'weight' => max(0, (float) ($this->parseDecimal(isset($raw['weight_kg']) ? $raw['weight_kg'] : '') ?: 0)),
            'is_book' => $isBook ? 1 : 0,
            'system' => trim(isset($raw['system']) ? (string) $raw['system'] : ''),
            'race' => trim(isset($raw['race']) ? (string) $raw['race'] : ''),
            'ss_code' => $ssCode,
            'product_code' => $productCode,
            'order_qty' => $this->parseInteger(isset($raw['order_qty']) ? $raw['order_qty'] : ''),
            'qty_in_pack' => $this->parseInteger(isset($raw['qty_in_pack']) ? $raw['qty_in_pack'] : ''),
            'commodity_code' => trim(isset($raw['commodity_code']) ? (string) $raw['commodity_code'] : ''),
            'country_origin' => trim(isset($raw['country_origin']) ? (string) $raw['country_origin'] : ''),
            'cube_cm' => $this->parseDecimal(isset($raw['cube_cm']) ? $raw['cube_cm'] : ''),
            'module' => trim(isset($raw['module']) ? (string) $raw['module'] : ''),
            'rsl' => trim(isset($raw['rsl']) ? (string) $raw['rsl'] : ''),
            'errors' => $errors,
            'existing_product' => null,
            'existing_reason' => '',
        );
    }

    private function disambiguateBatchReferences(array &$rows)
    {
        $groups = array();
        foreach ($rows as $index => $row) {
            $key = strtoupper(trim((string) $row['reference']));
            if ($key !== '') {
                if (!isset($groups[$key])) {
                    $groups[$key] = array();
                }
                $groups[$key][] = (int) $index;
            }
        }

        $changed = 0;
        foreach ($groups as $reference => $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            $used = array();
            foreach ($indexes as $rowIndex) {
                $suffix = $this->detectLanguageSuffix(isset($rows[$rowIndex]['name']) ? $rows[$rowIndex]['name'] : '');
                if ($suffix === '' || isset($used[$suffix])) {
                    $productCode = preg_replace('/[^A-Za-z0-9]+/', '', isset($rows[$rowIndex]['product_code']) ? $rows[$rowIndex]['product_code'] : '');
                    $suffix = $productCode !== '' ? $productCode : (string) ($rowIndex + 1);
                }
                $candidate = Tools::substr($rows[$rowIndex]['reference'] . '-' . $suffix, 0, 64);
                $candidateKey = strtoupper($candidate);
                $serial = 2;
                while (isset($used[$candidateKey])) {
                    $candidate = Tools::substr($rows[$rowIndex]['reference'] . '-' . $suffix . '-' . $serial, 0, 64);
                    $candidateKey = strtoupper($candidate);
                    $serial++;
                }
                $used[$suffix] = true;
                $used[$candidateKey] = true;
                $rows[$rowIndex]['reference'] = $candidate;
                $changed++;
            }
        }
        return $changed;
    }

    private function detectLanguageSuffix($name)
    {
        $normalized = $this->normalizeText($name);
        $languages = array(
            'francais' => 'FR', 'french' => 'FR',
            'english' => 'EN', 'anglais' => 'EN',
            'german' => 'DE', 'allemand' => 'DE',
            'italian' => 'IT', 'italien' => 'IT',
            'spanish' => 'ES', 'espagnol' => 'ES',
        );
        foreach ($languages as $needle => $suffix) {
            if (preg_match('/(^| )' . preg_quote($needle, '/') . '( |$)/', $normalized)) {
                return $suffix;
            }
        }
        return '';
    }

    private function attachExistingProducts(array &$rows, $idLang, $idShop)
    {
        $references = array();
        $eans = array();
        $supplierReferences = array();
        $names = array();
        foreach ($rows as $row) {
            if (!empty($row['reference'])) {
                $references[strtoupper((string) $row['reference'])] = true;
            }
            if (!empty($row['ean13'])) {
                $eans[(string) $row['ean13']] = true;
            }
            if (!empty($row['supplier_reference'])) {
                $supplierReferences[strtoupper((string) $row['supplier_reference'])] = true;
            }
            if (!empty($row['name'])) {
                $names[$this->normalizeText($row['name'])] = true;
            }
        }

        $matches = array();
        $conditions = array();
        if ($references) {
            $conditions[] = 'UPPER(p.`reference`) IN (' . $this->quoteList(array_keys($references)) . ')';
        }
        if ($eans) {
            $conditions[] = 'p.`ean13` IN (' . $this->quoteList(array_keys($eans)) . ')';
        }
        if ($conditions) {
            $sql = 'SELECT p.`id_product`, p.`reference`, p.`ean13`, pl.`name`'
                . ' FROM `' . _DB_PREFIX_ . 'product` p'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON (ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . (int) $idShop . ')'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (pl.`id_product` = p.`id_product` AND pl.`id_shop` = ' . (int) $idShop . ' AND pl.`id_lang` = ' . (int) $idLang . ')'
                . ' WHERE ' . implode(' OR ', $conditions);
            foreach ((array) Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql) as $match) {
                $matches[(int) $match['id_product']] = $match;
            }
        }

        if ($supplierReferences) {
            $sql = 'SELECT psu.`id_product`, p.`reference`, p.`ean13`, pl.`name`, psu.`product_supplier_reference`'
                . ' FROM `' . _DB_PREFIX_ . 'product_supplier` psu'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'product` p ON (p.`id_product` = psu.`id_product`)'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'product_shop` pshop ON (pshop.`id_product` = p.`id_product` AND pshop.`id_shop` = ' . (int) $idShop . ')'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (pl.`id_product` = p.`id_product` AND pl.`id_shop` = ' . (int) $idShop . ' AND pl.`id_lang` = ' . (int) $idLang . ')'
                . ' WHERE UPPER(psu.`product_supplier_reference`) IN (' . $this->quoteList(array_keys($supplierReferences)) . ')';
            foreach ((array) Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql) as $match) {
                $matches[(int) $match['id_product']] = $match;
            }
        }

        foreach ($rows as &$row) {
            foreach ($matches as $match) {
                $reason = '';
                if (!empty($row['reference']) && strtoupper((string) $match['reference']) === strtoupper((string) $row['reference'])) {
                    $reason = 'Référence GW-Product Code identique';
                } elseif (!empty($row['ean13']) && (string) $match['ean13'] === (string) $row['ean13']) {
                    $reason = 'EAN identique';
                } elseif (isset($match['product_supplier_reference']) && !empty($row['supplier_reference'])
                    && strtoupper((string) $match['product_supplier_reference']) === strtoupper((string) $row['supplier_reference'])) {
                    $reason = 'Référence fournisseur identique';
                }
                if ($reason !== '') {
                    $row['existing_product'] = array(
                        'id_product' => (int) $match['id_product'],
                        'name' => (string) $match['name'],
                        'reference' => (string) $match['reference'],
                        'ean13' => (string) $match['ean13'],
                    );
                    $row['existing_reason'] = $reason;
                    break;
                }
            }
        }
        unset($row);
    }

    private function markInternalDuplicates(array &$rows)
    {
        $seenReferences = array();
        $seenEans = array();
        foreach ($rows as &$row) {
            $refKey = strtoupper(trim((string) $row['reference']));
            $eanKey = trim((string) $row['ean13']);
            $duplicateLine = null;
            if ($refKey !== '' && isset($seenReferences[$refKey])) {
                $duplicateLine = $seenReferences[$refKey] + 1;
            } elseif ($eanKey !== '' && isset($seenEans[$eanKey])) {
                $duplicateLine = $seenEans[$eanKey] + 1;
            }
            if ($duplicateLine !== null) {
                $row['errors'][] = 'Doublon dans le copier-coller avec la ligne produit ' . $duplicateLine . '.';
                $row['selected'] = 0;
            } else {
                if ($refKey !== '') {
                    $seenReferences[$refKey] = (int) $row['index'];
                }
                if ($eanKey !== '') {
                    $seenEans[$eanKey] = (int) $row['index'];
                }
            }
        }
        unset($row);
    }

    private function buildHeaderMap(array $headers)
    {
        $normalizedAliases = array();
        foreach ($this->headerAliases as $field => $aliases) {
            foreach ($aliases as $alias) {
                $normalizedAliases[$this->normalizeHeader($alias)] = $field;
            }
        }

        $map = array();
        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader($header);
            if (isset($normalizedAliases[$normalized])) {
                $map[$normalizedAliases[$normalized]] = (int) $index;
            }
        }
        return $map;
    }

    private function mapRow(array $cells, array $headerMap)
    {
        $row = array();
        foreach ($headerMap as $field => $index) {
            $row[$field] = isset($cells[$index]) ? trim((string) $cells[$index]) : '';
        }
        return $row;
    }

    private function detectDelimiter($headerLine)
    {
        $candidates = array("\t", ';', ',');
        $best = "\t";
        $bestCount = 0;
        foreach ($candidates as $candidate) {
            $count = count(str_getcsv((string) $headerLine, $candidate));
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $candidate;
            }
        }
        return $best;
    }

    private function normalizeInput($raw)
    {
        $raw = (string) $raw;
        $raw = str_replace("\0", '', $raw);
        return trim($raw);
    }

    private function normalizeHeader($value)
    {
        return str_replace(' ', '', $this->normalizeText($value));
    }

    private function normalizeText($value)
    {
        $value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($ascii !== false) {
                $value = $ascii;
            }
        }
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function normalizeProductName($name)
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));
        if ($name === '') {
            return '';
        }
        if (function_exists('mb_convert_case')) {
            $name = mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        } else {
            $name = ucwords(strtolower($name));
        }
        $replacements = array(
            'Warhammer' => 'Warhammer', 'Aos' => 'AoS', 'Hh' => 'HH', 'Kt' => 'KT',
            'T\'au' => 'T\'au', 'I/Agents' => 'I/Agents', 'L/A' => 'L/A',
        );
        return strtr($name, $replacements);
    }

    private function buildReference($base, $prefix)
    {
        $base = trim((string) $base);
        if ($base === '') {
            return '';
        }
        $base = preg_replace('/\s+/', '', $base);
        if ($prefix !== '' && stripos($base, $prefix) !== 0) {
            $base = $prefix . $base;
        }
        return Tools::substr($base, 0, 64);
    }

    private function parseMoney($value)
    {
        $value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
        $value = str_replace(array("\xc2\xa0", '€', 'EUR', 'eur', ' '), '', $value);
        $value = preg_replace('/[^0-9,\.\-]/', '', $value);
        if ($value === '' || $value === '-' || $value === ',' || $value === '.') {
            return null;
        }
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($lastComma !== false) {
            $value = str_replace(',', '.', $value);
        }
        return is_numeric($value) ? (float) $value : null;
    }

    private function parseDecimal($value)
    {
        return $this->parseMoney($value);
    }

    private function parseInteger($value)
    {
        $value = preg_replace('/[^0-9\-]/', '', (string) $value);
        return $value === '' ? 0 : (int) $value;
    }

    private function normalizeDate($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '0000-00-00';
        }
        foreach (array('d/m/Y', 'd-m-Y', 'Y-m-d') as $format) {
            $date = DateTime::createFromFormat($format, $value);
            if ($date instanceof DateTime && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }
        return '0000-00-00';
    }

    private function isBook(array $row, $ean13)
    {
        $commodity = preg_replace('/\D+/', '', isset($row['commodity_code']) ? (string) $row['commodity_code'] : '');
        $race = $this->normalizeText(isset($row['race']) ? $row['race'] : '');
        $name = $this->normalizeText(isset($row['description']) ? $row['description'] : '');
        return strpos($commodity, '4901') === 0
            || strpos((string) $ean13, '978') === 0
            || strpos((string) $ean13, '979') === 0
            || strpos($race, 'black library') !== false
            || preg_match('/(^| )pb( |$)/', $name);
    }

    private function guessCategoryId($race, $system, $defaultId, array $categories, $auto)
    {
        if (!$auto) {
            return (int) $defaultId;
        }
        $exact = array();
        foreach ($categories as $category) {
            $key = $this->normalizeText(isset($category['name']) ? $category['name'] : '');
            if ($key !== '' && !isset($exact[$key])) {
                $exact[$key] = (int) $category['id_category'];
            }
        }
        $candidates = array();
        if (strpos($this->normalizeText($race), 'black library') !== false) {
            $candidates[] = 'Black Library';
        }
        foreach (array($race, $system) as $source) {
            $source = trim((string) $source);
            if ($source === '') {
                continue;
            }
            $candidates[] = $source;
            $parts = preg_split('/\s+-\s+/', $source);
            if (is_array($parts)) {
                for ($i = count($parts) - 1; $i >= 0; $i--) {
                    $part = trim($parts[$i]);
                    if ($part !== '' && !in_array(strtolower($part), array('generic', 'imperium', 'chaos', 'xenos'), true)) {
                        $candidates[] = $part;
                    }
                }
            }
        }
        foreach ($candidates as $candidate) {
            $key = $this->normalizeText($candidate);
            if ($key !== '' && isset($exact[$key])) {
                return (int) $exact[$key];
            }
        }
        return (int) $defaultId;
    }

    private function getTaxRate($idTax, array $taxGroups)
    {
        foreach ($taxGroups as $group) {
            if ((int) $group['id_tax_rules_group'] === (int) $idTax) {
                return isset($group['rate']) ? (float) $group['rate'] : 0;
            }
        }
        return 0;
    }

    private function idExistsInRows($id, array $rows, $key)
    {
        foreach ($rows as $row) {
            if (isset($row[$key]) && (int) $row[$key] === (int) $id) {
                return true;
            }
        }
        return false;
    }

    private function quoteList(array $values)
    {
        $quoted = array();
        foreach ($values as $value) {
            $quoted[] = "'" . pSQL((string) $value) . "'";
        }
        return implode(',', $quoted);
    }

    public function lineIsNotEmpty($line)
    {
        return trim((string) $line) !== '';
    }

    private function hasUsefulCells(array $cells)
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return true;
            }
        }
        return false;
    }
}
