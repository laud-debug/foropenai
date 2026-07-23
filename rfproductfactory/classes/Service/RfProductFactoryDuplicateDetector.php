<?php

class RfProductFactoryDuplicateDetector
{
    const MAX_RESULTS = 30;

    /**
     * Recherche les doublons par EAN, référence exacte ou incluse, et nom proche.
     *
     * Exemple de référence incluse : SWQ129 / AMGSWQ129ML.
     * Exemple de nom normalisé : "Booster Flammes Obsidienne" /
     * "Booster Flammes Obsidiennes".
     */
    public function find($reference, $ean13, $name, $idLang, $idShop)
    {
        $reference = $this->normalizeReference($reference);
        $ean13 = $this->normalizeEan($ean13);
        $name = trim((string) $name);

        if ($reference === '' && $ean13 === '' && $name === '') {
            return array();
        }

        $matches = array();
        $this->appendProductIdentifierMatches($matches, $reference, $ean13, (int) $idLang, (int) $idShop);
        $this->appendCombinationIdentifierMatches($matches, $reference, $ean13, (int) $idLang, (int) $idShop);
        $this->appendNameMatches($matches, $name, (int) $idLang, (int) $idShop);

        $matches = array_values($matches);
        usort($matches, array($this, 'sortMatches'));

        return array_slice($matches, 0, self::MAX_RESULTS);
    }

    /**
     * Ajoute les correspondances visuelles produites par le comparateur d'images.
     */
    public function mergeImageMatches(array $matches, array $imageMatches)
    {
        $indexed = array();
        foreach ($matches as $match) {
            $key = $this->matchKey($match);
            $indexed[$key] = $match;
        }

        foreach ($imageMatches as $imageMatch) {
            $this->addOrMergeMatch($indexed, $imageMatch);
        }

        $matches = array_values($indexed);
        usort($matches, array($this, 'sortMatches'));

        return array_slice($matches, 0, self::MAX_RESULTS);
    }

    public function hasStrongMatch(array $matches)
    {
        foreach ($matches as $match) {
            if (!empty($match['strong_match'])) {
                return true;
            }
        }

        return false;
    }

    private function appendProductIdentifierMatches(array &$matches, $reference, $ean13, $idLang, $idShop)
    {
        $conditions = array();
        $normalizedReferenceExpression = $this->sqlNormalizedReference('p.`reference`');

        if ($reference !== '') {
            $conditions[] = $normalizedReferenceExpression . " = '" . pSQL($reference) . "'";
            if ($this->canUseContainedReference($reference)) {
                $conditions[] = '(' . $normalizedReferenceExpression . " <> ''"
                    . ' AND CHAR_LENGTH(' . $normalizedReferenceExpression . ') >= 5'
                    . ' AND (' . $normalizedReferenceExpression . " LIKE '%" . pSQL($reference) . "%'"
                    . " OR '" . pSQL($reference) . "' LIKE CONCAT('%', " . $normalizedReferenceExpression . ", '%')))";
            }
        }
        if ($ean13 !== '') {
            $conditions[] = $this->eanSqlCondition('p.`ean13`', $ean13);
        }
        if (!$conditions) {
            return;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT p.`id_product`, 0 AS `id_product_attribute`, p.`reference` AS `product_reference`,
                    p.`ean13` AS `product_ean13`, p.`active`, pl.`name`, pl.`link_rewrite`,
                    NULL AS `combination_reference`, NULL AS `combination_ean13`
             FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON (ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . (int) $idShop . ')
             INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON (pl.`id_product` = p.`id_product`
                    AND pl.`id_shop` = ' . (int) $idShop . '
                    AND pl.`id_lang` = ' . (int) $idLang . ')
             WHERE (' . implode(' OR ', $conditions) . ')
             ORDER BY p.`id_product` DESC
             LIMIT 150'
        );

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $labels = array();
            $strong = false;
            $score = 0;

            if ($reference !== '') {
                $existingReference = $this->normalizeReference($row['product_reference']);
                if ($existingReference !== '' && $reference === $existingReference) {
                    $labels[] = 'Référence produit identique : ' . $row['product_reference'];
                    $strong = true;
                    $score = max($score, 100);
                } elseif ($this->referencesContained($reference, $existingReference)) {
                    $labels[] = 'Référence enveloppée par un code fournisseur : ' . $reference . ' ↔ ' . $row['product_reference'];
                    $strong = true;
                    $score = max($score, 96);
                }
            }
            if ($ean13 !== '' && $this->eansEqual($ean13, $row['product_ean13'])) {
                $labels[] = 'EAN identique : ' . $row['product_ean13'];
                $strong = true;
                $score = max($score, 100);
            }

            if ($labels) {
                $row['match_labels'] = $labels;
                $row['strong_match'] = $strong ? 1 : 0;
                $row['match_score'] = $score;
                $this->addOrMergeMatch($matches, $row);
            }
        }
    }

    private function appendCombinationIdentifierMatches(array &$matches, $reference, $ean13, $idLang, $idShop)
    {
        $conditions = array();
        $normalizedReferenceExpression = $this->sqlNormalizedReference('pa.`reference`');

        if ($reference !== '') {
            $conditions[] = $normalizedReferenceExpression . " = '" . pSQL($reference) . "'";
            if ($this->canUseContainedReference($reference)) {
                $conditions[] = '(' . $normalizedReferenceExpression . " <> ''"
                    . ' AND CHAR_LENGTH(' . $normalizedReferenceExpression . ') >= 5'
                    . ' AND (' . $normalizedReferenceExpression . " LIKE '%" . pSQL($reference) . "%'"
                    . " OR '" . pSQL($reference) . "' LIKE CONCAT('%', " . $normalizedReferenceExpression . ", '%')))";
            }
        }
        if ($ean13 !== '') {
            $conditions[] = $this->eanSqlCondition('pa.`ean13`', $ean13);
        }
        if (!$conditions) {
            return;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT p.`id_product`, pa.`id_product_attribute`, p.`reference` AS `product_reference`,
                    p.`ean13` AS `product_ean13`, p.`active`, pl.`name`, pl.`link_rewrite`,
                    pa.`reference` AS `combination_reference`, pa.`ean13` AS `combination_ean13`
             FROM `' . _DB_PREFIX_ . 'product_attribute` pa
             INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas
                ON (pas.`id_product_attribute` = pa.`id_product_attribute`
                    AND pas.`id_shop` = ' . (int) $idShop . ')
             INNER JOIN `' . _DB_PREFIX_ . 'product` p ON (p.`id_product` = pa.`id_product`)
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON (ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . (int) $idShop . ')
             INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON (pl.`id_product` = p.`id_product`
                    AND pl.`id_shop` = ' . (int) $idShop . '
                    AND pl.`id_lang` = ' . (int) $idLang . ')
             WHERE (' . implode(' OR ', $conditions) . ')
             ORDER BY p.`id_product` DESC, pa.`id_product_attribute` DESC
             LIMIT 150'
        );

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $labels = array();
            $strong = false;
            $score = 0;

            if ($reference !== '') {
                $existingReference = $this->normalizeReference($row['combination_reference']);
                if ($existingReference !== '' && $reference === $existingReference) {
                    $labels[] = 'Référence de déclinaison identique : ' . $row['combination_reference'];
                    $strong = true;
                    $score = max($score, 100);
                } elseif ($this->referencesContained($reference, $existingReference)) {
                    $labels[] = 'Référence de déclinaison enveloppée par un code fournisseur : ' . $reference . ' ↔ ' . $row['combination_reference'];
                    $strong = true;
                    $score = max($score, 96);
                }
            }
            if ($ean13 !== '' && $this->eansEqual($ean13, $row['combination_ean13'])) {
                $labels[] = 'EAN de déclinaison identique : ' . $row['combination_ean13'];
                $strong = true;
                $score = max($score, 100);
            }

            if ($labels) {
                $row['match_labels'] = $labels;
                $row['strong_match'] = $strong ? 1 : 0;
                $row['match_score'] = $score;
                $this->addOrMergeMatch($matches, $row);
            }
        }
    }

    private function appendNameMatches(array &$matches, $name, $idLang, $idShop)
    {
        $incomingTokens = $this->normalizeNameTokens($name);
        if (!$incomingTokens) {
            return;
        }

        $searchTokens = array_slice(array_values(array_unique($incomingTokens)), 0, 8);
        $conditions = array("pl.`name` = '" . pSQL($name) . "'");
        $relevanceParts = array("(pl.`name` = '" . pSQL($name) . "') * 20");
        foreach ($searchTokens as $token) {
            if ($this->stringLength($token) >= 4) {
                $condition = "pl.`name` LIKE '%" . pSQL($token) . "%'";
                $conditions[] = $condition;
                $relevanceParts[] = '(' . $condition . ')';
            }
        }
        if (!$conditions) {
            return;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT p.`id_product`, 0 AS `id_product_attribute`, p.`reference` AS `product_reference`,
                    p.`ean13` AS `product_ean13`, p.`active`, pl.`name`, pl.`link_rewrite`,
                    NULL AS `combination_reference`, NULL AS `combination_ean13`
             FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON (ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . (int) $idShop . ')
             INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON (pl.`id_product` = p.`id_product`
                    AND pl.`id_shop` = ' . (int) $idShop . '
                    AND pl.`id_lang` = ' . (int) $idLang . ')
             WHERE (' . implode(' OR ', $conditions) . ')
             ORDER BY (' . implode(' + ', $relevanceParts) . ') DESC, p.`id_product` DESC
             LIMIT 300'
        );

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $comparison = $this->compareNames($name, $row['name']);
            if ($comparison['level'] === 'none') {
                continue;
            }

            $row['match_labels'] = array($comparison['label']);
            $row['strong_match'] = $comparison['level'] === 'strong' ? 1 : 0;
            $row['match_score'] = $comparison['score'];
            $this->addOrMergeMatch($matches, $row);
        }
    }

    private function compareNames($incomingName, $existingName)
    {
        if (strcasecmp(trim((string) $incomingName), trim((string) $existingName)) === 0) {
            return array(
                'level' => 'strong',
                'score' => 100,
                'label' => 'Nom strictement identique',
            );
        }

        $leftTokens = $this->normalizeNameTokens($incomingName);
        $rightTokens = $this->normalizeNameTokens($existingName);
        if (!$leftTokens || !$rightTokens) {
            return array('level' => 'none', 'score' => 0, 'label' => '');
        }

        $leftSequence = implode(' ', $leftTokens);
        $rightSequence = implode(' ', $rightTokens);
        $leftSet = array_values(array_unique($leftTokens));
        $rightSet = array_values(array_unique($rightTokens));
        sort($leftSet);
        sort($rightSet);

        if ($leftSequence === $rightSequence || $leftSet === $rightSet) {
            $singleTokenStrong = count($leftSet) === 1 && strlen($leftSet[0]) >= 6;
            return array(
                'level' => (count($leftSet) >= 2 || $singleTokenStrong) ? 'strong' : 'warning',
                'score' => 99,
                'label' => 'Nom équivalent après normalisation des pluriels et accents (99 %)',
            );
        }

        $intersection = array_values(array_intersect($leftSet, $rightSet));
        $union = array_values(array_unique(array_merge($leftSet, $rightSet)));
        $jaccard = count($union) ? count($intersection) / count($union) : 0;
        $containment = min(count($leftSet), count($rightSet))
            ? count($intersection) / min(count($leftSet), count($rightSet))
            : 0;
        $levenshteinRatio = $this->levenshteinRatio($leftSequence, $rightSequence);

        $score = (int) round(max(
            $jaccard * 100,
            ($containment * 0.65 + $levenshteinRatio * 0.35) * 100,
            $levenshteinRatio * 100
        ));

        $commonCount = count($intersection);
        $minimumTokens = min(count($leftSet), count($rightSet));
        if ($score >= 92 && $commonCount >= 2 && $minimumTokens >= 2) {
            return array(
                'level' => 'strong',
                'score' => $score,
                'label' => 'Nom très proche après normalisation (' . $score . ' %)',
            );
        }
        if ($score >= 78 && $commonCount >= 2) {
            return array(
                'level' => 'warning',
                'score' => $score,
                'label' => 'Nom similaire à vérifier (' . $score . ' %)',
            );
        }

        return array('level' => 'none', 'score' => $score, 'label' => '');
    }

    private function normalizeNameTokens($value)
    {
        $value = $this->asciiLower((string) $value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        $rawTokens = preg_split('/\s+/', trim($value));
        if (!is_array($rawTokens)) {
            return array();
        }

        $stopWords = array(
            'le', 'la', 'les', 'de', 'des', 'du', 'un', 'une', 'et', 'the', 'of', 'a', 'an',
            'pour', 'avec', 'sur', 'en', 'edition', 'francais', 'francaise', 'vf', 'fr', 'ml',
        );
        $tokens = array();
        foreach ($rawTokens as $token) {
            if ($token === '' || in_array($token, $stopWords, true)) {
                continue;
            }
            $token = $this->singularizeToken($token);
            if ($this->stringLength($token) < 2) {
                continue;
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    private function singularizeToken($token)
    {
        $length = $this->stringLength($token);
        if ($length > 4 && substr($token, -1) === 's' && substr($token, -2) !== 'ss') {
            return substr($token, 0, -1);
        }
        if ($length > 5 && substr($token, -1) === 'x') {
            return substr($token, 0, -1);
        }

        return $token;
    }

    private function levenshteinRatio($left, $right)
    {
        if ($left === $right) {
            return 1;
        }
        $maxLength = max(strlen($left), strlen($right));
        if ($maxLength === 0) {
            return 1;
        }

        // Les noms sont limités à 128 caractères par PrestaShop, donc levenshtein reste sûr ici.
        return max(0, 1 - (levenshtein($left, $right) / $maxLength));
    }

    private function addOrMergeMatch(array &$matches, array $row)
    {
        if (!isset($row['id_product'])) {
            return;
        }
        if (!isset($row['id_product_attribute'])) {
            $row['id_product_attribute'] = 0;
        }
        if (!isset($row['match_labels']) || !is_array($row['match_labels'])) {
            $row['match_labels'] = array();
        }
        if (!isset($row['strong_match'])) {
            $row['strong_match'] = 0;
        }
        if (!isset($row['match_score'])) {
            $row['match_score'] = 0;
        }

        $key = $this->matchKey($row);
        if (!isset($matches[$key])) {
            $matches[$key] = $row;
            return;
        }

        $existing = $matches[$key];
        $existing['match_labels'] = array_values(array_unique(array_merge(
            isset($existing['match_labels']) ? $existing['match_labels'] : array(),
            $row['match_labels']
        )));
        $existing['strong_match'] = (!empty($existing['strong_match']) || !empty($row['strong_match'])) ? 1 : 0;
        $existing['match_score'] = max(
            isset($existing['match_score']) ? (int) $existing['match_score'] : 0,
            (int) $row['match_score']
        );

        foreach ($row as $field => $value) {
            if (!isset($existing[$field]) || $existing[$field] === '' || $existing[$field] === null) {
                $existing[$field] = $value;
            }
        }

        $matches[$key] = $existing;
    }

    private function matchKey(array $match)
    {
        return (int) $match['id_product'] . ':' . (int) (isset($match['id_product_attribute']) ? $match['id_product_attribute'] : 0);
    }

    public function sortMatches($left, $right)
    {
        $leftStrong = !empty($left['strong_match']) ? 1 : 0;
        $rightStrong = !empty($right['strong_match']) ? 1 : 0;
        if ($leftStrong !== $rightStrong) {
            return $rightStrong - $leftStrong;
        }

        $leftScore = isset($left['match_score']) ? (int) $left['match_score'] : 0;
        $rightScore = isset($right['match_score']) ? (int) $right['match_score'] : 0;
        if ($leftScore !== $rightScore) {
            return $rightScore - $leftScore;
        }

        if ((int) $left['id_product'] !== (int) $right['id_product']) {
            return (int) $right['id_product'] - (int) $left['id_product'];
        }

        return (int) $right['id_product_attribute'] - (int) $left['id_product_attribute'];
    }

    private function normalizeReference($reference)
    {
        return strtoupper(preg_replace('/[^A-Z0-9]+/i', '', trim((string) $reference)));
    }

    private function normalizeEan($ean13)
    {
        $digits = preg_replace('/\D+/', '', (string) $ean13);
        if (strlen($digits) === 12) {
            $digits = '0' . $digits;
        } elseif (strlen($digits) === 14 && substr($digits, 0, 1) === '0') {
            $digits = substr($digits, 1);
        }
        return $digits;
    }

    private function eanSqlCondition($field, $ean13)
    {
        $normalized = $this->normalizeEan($ean13);
        $values = array($normalized);
        if (strlen($normalized) === 13 && substr($normalized, 0, 1) === '0') {
            $values[] = substr($normalized, 1);
        }
        $values = array_values(array_unique(array_filter($values, 'strlen')));
        $escaped = array();
        foreach ($values as $value) {
            $escaped[] = "'" . pSQL($value) . "'";
        }
        return $field . ' IN (' . implode(', ', $escaped) . ')';
    }

    private function canUseContainedReference($reference)
    {
        return strlen($reference) >= 5;
    }

    private function referencesContained($left, $right)
    {
        $left = $this->normalizeReference($left);
        $right = $this->normalizeReference($right);
        if (!$this->canUseContainedReference($left) || !$this->canUseContainedReference($right)) {
            return false;
        }
        if ($left === $right) {
            return false;
        }

        $shortReference = strlen($left) < strlen($right) ? $left : $right;
        $longReference = strlen($left) < strlen($right) ? $right : $left;
        if ((strlen($shortReference) / strlen($longReference)) < 0.45) {
            return false;
        }

        $position = strpos($longReference, $shortReference);
        if ($position === false) {
            return false;
        }

        $prefix = substr($longReference, 0, $position);
        $suffix = substr($longReference, $position + strlen($shortReference));
        if ($prefix === '' && $suffix === '') {
            return false;
        }

        /*
         * Une référence enveloppée par un préfixe/suffixe fournisseur est acceptée :
         * SWQ129 ↔ AMGSWQ129ML.
         * En revanche, une simple prolongation numérique désigne généralement une
         * autre référence : SWQ15 ne doit jamais correspondre à SWQ154.
         */
        if (($prefix !== '' && preg_match('/\d$/', $prefix))
            || ($suffix !== '' && preg_match('/^\d/', $suffix))) {
            return false;
        }

        // Les caractères ajoutés doivent être uniquement alphabétiques.
        return preg_match('/^[A-Z]*$/', $prefix . $suffix) === 1;
    }

    private function eansEqual($left, $right)
    {
        $left = $this->normalizeEan($left);
        $right = $this->normalizeEan($right);

        return $left !== '' && $left === $right;
    }

    private function sqlNormalizedReference($field)
    {
        return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(" . $field
            . ", '-', ''), ' ', ''), '/', ''), '_', ''), '.', ''))";
    }

    private function asciiLower($value)
    {
        $value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }

        return strtolower($value);
    }

    private function stringLength($value)
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
