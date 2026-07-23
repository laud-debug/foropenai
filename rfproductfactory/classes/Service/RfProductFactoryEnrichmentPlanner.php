<?php

class RfProductFactoryEnrichmentPlanner
{
    public function build($idProduct, array $incoming, $idLang, $idShop)
    {
        $product = new Product((int) $idProduct, false, null, (int) $idShop);
        if (!Validate::isLoadedObject($product)) {
            return array('options' => array(), 'existing' => array(), 'has_options' => false);
        }

        $existing = array(
            'name' => $this->langValue($product->name, $idLang),
            'reference' => trim((string) $product->reference),
            'ean13' => trim((string) $product->ean13),
            'price_ht' => (float) $product->price,
            'wholesale_price_ht' => (float) $product->wholesale_price,
            'description_short' => $this->langValue($product->description_short, $idLang),
            'description' => $this->langValue($product->description, $idLang),
            'meta_title' => $this->langValue($product->meta_title, $idLang),
            'meta_description' => $this->langValue($product->meta_description, $idLang),
            'id_manufacturer' => (int) $product->id_manufacturer,
            'id_supplier' => (int) $product->id_supplier,
            'available_for_order' => (int) $product->available_for_order,
            'show_price' => (int) $product->show_price,
            'visibility' => (string) $product->visibility,
            'image_count' => (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'image` WHERE `id_product` = ' . (int) $idProduct
            ),
        );

        $options = array();
        if (!$existing['available_for_order'] || !$existing['show_price'] || $existing['visibility'] !== 'both') {
            $options[] = $this->option(
                'sale_settings',
                'Préparer la fiche pour la vente',
                'Disponible : ' . ($existing['available_for_order'] ? 'oui' : 'non')
                    . ' — Prix affiché : ' . ($existing['show_price'] ? 'oui' : 'non')
                    . ' — Visibilité : ' . $this->visibilityLabel($existing['visibility']),
                'Disponible : oui — Prix affiché : oui — Visibilité : partout',
                true
            );
        }

        $incomingName = trim(isset($incoming['name']) ? (string) $incoming['name'] : '');
        if ($incomingName !== '' && $incomingName !== $existing['name']) {
            $options[] = $this->option(
                'name',
                'Remplacer le nom du produit',
                $existing['name'],
                $incomingName,
                false
            );
        }

        $incomingReference = trim(isset($incoming['reference']) ? (string) $incoming['reference'] : '');
        if ($incomingReference !== '' && $incomingReference !== $existing['reference']) {
            $options[] = $this->option(
                'reference',
                $existing['reference'] === '' ? 'Ajouter la référence absente' : 'Remplacer la référence',
                $existing['reference'] === '' ? 'Vide' : $existing['reference'],
                $incomingReference,
                $existing['reference'] === ''
            );
        }

        $incomingEan = preg_replace('/\D+/', '', isset($incoming['ean13']) ? (string) $incoming['ean13'] : '');
        if ($incomingEan !== '' && $incomingEan !== $existing['ean13']) {
            $options[] = $this->option(
                'ean13',
                $existing['ean13'] === '' ? 'Ajouter l’EAN absent' : 'Remplacer l’EAN',
                $existing['ean13'] === '' ? 'Vide' : $existing['ean13'],
                $incomingEan,
                $existing['ean13'] === ''
            );
        }

        $incomingPrice = $this->normalizePrice(isset($incoming['price_ht']) ? $incoming['price_ht'] : null);
        if ($incomingPrice !== null && abs($incomingPrice - $existing['price_ht']) > 0.005) {
            $options[] = $this->option(
                'price',
                $existing['price_ht'] <= 0 ? 'Ajouter le prix HT absent' : 'Mettre à jour le prix HT',
                number_format($existing['price_ht'], 6, ',', ' ') . ' €',
                number_format($incomingPrice, 6, ',', ' ') . ' €',
                $existing['price_ht'] <= 0
            );
        }

        $incomingWholesale = $this->normalizePrice(isset($incoming['wholesale_price_ht']) ? $incoming['wholesale_price_ht'] : null);
        if ($incomingWholesale !== null && abs($incomingWholesale - $existing['wholesale_price_ht']) > 0.005) {
            $options[] = $this->option(
                'wholesale_price',
                $existing['wholesale_price_ht'] <= 0 ? 'Ajouter le prix d’achat HT absent' : 'Mettre à jour le prix d’achat HT',
                number_format($existing['wholesale_price_ht'], 6, ',', ' ') . ' €',
                number_format($incomingWholesale, 6, ',', ' ') . ' €',
                $existing['wholesale_price_ht'] <= 0
            );
        }

        $this->appendTextOption(
            $options,
            'description_short',
            'description courte',
            $existing['description_short'],
            isset($incoming['description_short']) ? $incoming['description_short'] : ''
        );
        $this->appendTextOption(
            $options,
            'description',
            'description longue',
            $existing['description'],
            isset($incoming['description']) ? $incoming['description'] : ''
        );

        $incomingMetaTitle = trim(isset($incoming['meta_title']) ? (string) $incoming['meta_title'] : '');
        if ($incomingMetaTitle !== '' && $incomingMetaTitle !== $existing['meta_title']) {
            $options[] = $this->option(
                'meta_title',
                $existing['meta_title'] === '' ? 'Ajouter le titre SEO absent' : 'Remplacer le titre SEO',
                $existing['meta_title'] === '' ? 'Vide' : $existing['meta_title'],
                $incomingMetaTitle,
                $existing['meta_title'] === ''
            );
        }

        $incomingMetaDescription = trim(isset($incoming['meta_description']) ? (string) $incoming['meta_description'] : '');
        if ($incomingMetaDescription !== '' && $incomingMetaDescription !== $existing['meta_description']) {
            $options[] = $this->option(
                'meta_description',
                $existing['meta_description'] === '' ? 'Ajouter la méta-description absente' : 'Remplacer la méta-description',
                $existing['meta_description'] === '' ? 'Vide' : $existing['meta_description'],
                $incomingMetaDescription,
                $existing['meta_description'] === ''
            );
        }

        $incomingManufacturer = isset($incoming['id_manufacturer']) ? (int) $incoming['id_manufacturer'] : 0;
        if ($incomingManufacturer > 0 && $incomingManufacturer !== $existing['id_manufacturer']) {
            $options[] = $this->option(
                'manufacturer',
                $existing['id_manufacturer'] === 0 ? 'Ajouter le fabricant absent' : 'Remplacer le fabricant',
                $this->manufacturerName($existing['id_manufacturer']),
                $this->manufacturerName($incomingManufacturer),
                $existing['id_manufacturer'] === 0
            );
        }

        $incomingSupplier = isset($incoming['id_supplier']) ? (int) $incoming['id_supplier'] : 0;
        if ($incomingSupplier > 0 && $incomingSupplier !== $existing['id_supplier']) {
            $options[] = $this->option(
                'supplier',
                $existing['id_supplier'] === 0 ? 'Ajouter le fournisseur absent' : 'Remplacer le fournisseur',
                $this->supplierName($existing['id_supplier']),
                $this->supplierName($incomingSupplier),
                $existing['id_supplier'] === 0
            );
        }

        $incomingImages = isset($incoming['images']) && is_array($incoming['images'])
            ? array_values(array_unique($incoming['images']))
            : array();
        if ($incomingImages) {
            $options[] = $this->option(
                'images',
                'Ajouter uniquement les images réellement nouvelles',
                $existing['image_count'] . ' image(s) actuellement',
                count($incomingImages) . ' image(s) détectée(s), contrôlées avant import',
                true
            );
        }

        return array(
            'options' => $options,
            'existing' => $existing,
            'has_options' => !empty($options),
        );
    }

    private function appendTextOption(array &$options, $key, $label, $existingHtml, $incomingHtml)
    {
        $existingText = $this->normalizeText($existingHtml);
        $incomingText = $this->normalizeText($incomingHtml);
        if ($incomingText === '' || $incomingText === $existingText) {
            return;
        }

        if ($existingText === '') {
            $options[] = $this->option(
                $key . '_fill',
                'Ajouter la ' . $label . ' absente',
                'Vide',
                $this->textSummary($incomingHtml),
                true
            );
            return;
        }

        if (strpos($existingText, $incomingText) !== false) {
            return;
        }

        $options[] = $this->option(
            $key . '_merge',
            'Fusionner les informations nouvelles dans la ' . $label,
            $this->textSummary($existingHtml),
            $this->textSummary($incomingHtml),
            false
        );
    }

    private function option($key, $label, $current, $incoming, $default)
    {
        return array(
            'key' => $key,
            'label' => $label,
            'current' => $this->truncate($current, 180),
            'incoming' => $this->truncate($incoming, 180),
            'default' => $default ? 1 : 0,
        );
    }

    private function langValue($value, $idLang)
    {
        if (is_array($value)) {
            return isset($value[(int) $idLang]) ? (string) $value[(int) $idLang] : '';
        }
        return (string) $value;
    }

    private function normalizePrice($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = str_replace(array(' ', ','), array('', '.'), (string) $value);
        if (!is_numeric($value)) {
            return null;
        }
        $price = (float) $value;
        return $price >= 0 ? $price : null;
    }

    private function normalizeText($html)
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8');
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    private function textSummary($html)
    {
        $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8')));
        return Tools::strlen($plain) . ' caractères — ' . $plain;
    }

    private function truncate($value, $limit)
    {
        $value = trim((string) $value);
        if (Tools::strlen($value) <= (int) $limit) {
            return $value;
        }
        return Tools::substr($value, 0, (int) $limit - 1) . '…';
    }

    private function visibilityLabel($visibility)
    {
        $labels = array(
            'both' => 'Partout',
            'catalog' => 'Catalogue uniquement',
            'search' => 'Recherche uniquement',
            'none' => 'Nulle part',
        );
        return isset($labels[$visibility]) ? $labels[$visibility] : (string) $visibility;
    }

    private function manufacturerName($idManufacturer)
    {
        if ((int) $idManufacturer <= 0) {
            return 'Aucun';
        }
        $manufacturer = new Manufacturer((int) $idManufacturer, (int) Context::getContext()->language->id);
        return Validate::isLoadedObject($manufacturer) ? (string) $manufacturer->name : ('#' . (int) $idManufacturer);
    }

    private function supplierName($idSupplier)
    {
        if ((int) $idSupplier <= 0) {
            return 'Aucun';
        }
        $supplier = new Supplier((int) $idSupplier, (int) Context::getContext()->language->id);
        return Validate::isLoadedObject($supplier) ? (string) $supplier->name : ('#' . (int) $idSupplier);
    }
}
