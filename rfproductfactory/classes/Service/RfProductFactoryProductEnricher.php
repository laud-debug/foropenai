<?php

class RfProductFactoryProductEnricher
{
    private $imageImporter;
    private $imageMatcher;

    public function __construct(
        RfProductFactoryImageImporter $imageImporter,
        RfProductFactoryImageMatcher $imageMatcher
    ) {
        $this->imageImporter = $imageImporter;
        $this->imageMatcher = $imageMatcher;
    }

    public function enrich($idProduct, array $incoming, array $selectedFields, array $selectedImageUrls, $idShop, $idLang, array $uploadedImages = array())
    {
        $idProduct = (int) $idProduct;
        $idShop = (int) $idShop;
        $idLang = (int) $idLang;

        $product = new Product($idProduct, false, null, $idShop);
        if (!Validate::isLoadedObject($product)) {
            throw new PrestaShopException('La fiche produit à enrichir est introuvable.');
        }
        $existsInShop = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_shop`'
            . ' WHERE `id_product` = ' . $idProduct . ' AND `id_shop` = ' . $idShop
        );
        if (!$existsInShop) {
            throw new PrestaShopException('La fiche produit à enrichir n’appartient pas à la boutique courante.');
        }

        $allowed = array(
            'name', 'reference', 'ean13', 'price', 'wholesale_price', 'description_short_fill',
            'description_short_merge', 'description_fill', 'description_merge',
            'meta_title', 'meta_description', 'manufacturer', 'supplier', 'sale_settings', 'images',
        );
        $selectedFields = array_values(array_intersect($allowed, array_unique($selectedFields)));
        if (!$selectedFields) {
            throw new PrestaShopException('Aucun élément d’enrichissement n’a été sélectionné.');
        }

        $product->id_shop_list = array($idShop);
        $changes = array();
        $needsUpdate = false;

        if (in_array('sale_settings', $selectedFields, true)) {
            $product->available_for_order = 1;
            $product->show_price = 1;
            $product->visibility = 'both';
            $changes[] = 'Produit préparé pour la vente : disponible, prix affiché et visibilité partout';
            $needsUpdate = true;
        }

        if (in_array('name', $selectedFields, true)) {
            $name = trim(isset($incoming['name']) ? (string) $incoming['name'] : '');
            if ($name === '' || !Validate::isCatalogName($name)) {
                throw new PrestaShopException('Le nouveau nom du produit est invalide.');
            }
            $this->setLangValue($product->name, $idLang, $name);
            $changes[] = 'Nom mis à jour';
            $needsUpdate = true;
        }

        if (in_array('reference', $selectedFields, true)) {
            $reference = trim(isset($incoming['reference']) ? (string) $incoming['reference'] : '');
            if ($reference !== '' && !Validate::isReference($reference)) {
                throw new PrestaShopException('La nouvelle référence produit est invalide.');
            }
            if ($reference !== '') {
                $product->reference = $reference;
                $changes[] = 'Référence mise à jour';
                $needsUpdate = true;
            }
        }

        if (in_array('ean13', $selectedFields, true)) {
            $ean13 = preg_replace('/\D+/', '', isset($incoming['ean13']) ? (string) $incoming['ean13'] : '');
            if ($ean13 !== '' && !Validate::isEan13($ean13)) {
                throw new PrestaShopException('Le nouvel EAN-13 est invalide.');
            }
            if ($ean13 !== '') {
                $product->ean13 = $ean13;
                $changes[] = 'EAN mis à jour';
                $needsUpdate = true;
            }
        }

        if (in_array('price', $selectedFields, true)) {
            $priceRaw = str_replace(array(' ', ','), array('', '.'), isset($incoming['price_ht']) ? (string) $incoming['price_ht'] : '');
            if ($priceRaw === '' || !is_numeric($priceRaw) || (float) $priceRaw < 0) {
                throw new PrestaShopException('Le nouveau prix HT est invalide.');
            }
            $price = (float) $priceRaw;
            $product->price = $price;
            $changes[] = 'Prix HT mis à jour';
            $needsUpdate = true;
        }

        if (in_array('wholesale_price', $selectedFields, true)) {
            $priceRaw = str_replace(array(' ', ','), array('', '.'), isset($incoming['wholesale_price_ht']) ? (string) $incoming['wholesale_price_ht'] : '');
            if ($priceRaw === '' || !is_numeric($priceRaw) || (float) $priceRaw < 0) {
                throw new PrestaShopException('Le nouveau prix d’achat HT est invalide.');
            }
            $product->wholesale_price = (float) $priceRaw;
            $changes[] = 'Prix d’achat HT mis à jour';
            $needsUpdate = true;
        }

        if (in_array('manufacturer', $selectedFields, true)) {
            $idManufacturer = isset($incoming['id_manufacturer']) ? (int) $incoming['id_manufacturer'] : 0;
            if ($idManufacturer > 0 && !Validate::isLoadedObject(new Manufacturer($idManufacturer))) {
                throw new PrestaShopException('Le fabricant proposé est invalide.');
            }
            if ($idManufacturer > 0) {
                $product->id_manufacturer = $idManufacturer;
                $changes[] = 'Fabricant ajouté ou mis à jour';
                $needsUpdate = true;
            }
        }

        if (in_array('supplier', $selectedFields, true)) {
            $idSupplier = isset($incoming['id_supplier']) ? (int) $incoming['id_supplier'] : 0;
            if ($idSupplier > 0 && !Validate::isLoadedObject(new Supplier($idSupplier))) {
                throw new PrestaShopException('Le fournisseur proposé est invalide.');
            }
            if ($idSupplier > 0) {
                $product->id_supplier = $idSupplier;
                $changes[] = 'Fournisseur ajouté ou mis à jour';
                $needsUpdate = true;
            }
        }

        $this->applyTextEnrichment($product, $incoming, $selectedFields, $idLang, $changes, $needsUpdate);

        if ($needsUpdate && !$product->update()) {
            throw new PrestaShopException('PrestaShop n’a pas pu enregistrer l’enrichissement de la fiche.');
        }

        if ($needsUpdate && (int) $product->id_supplier > 0
            && (in_array('wholesale_price', $selectedFields, true)
                || in_array('supplier', $selectedFields, true)
                || in_array('reference', $selectedFields, true))) {
            $this->saveProductSupplier($product);
        }

        $imageResult = array(
            'imported' => 0,
            'errors' => array(),
            'skipped' => array(),
            'warnings' => array(),
        );
        if (in_array('images', $selectedFields, true) && ($selectedImageUrls || $uploadedImages)) {
            $filter = array(
                'new_urls' => array(),
                'skipped' => array(),
                'warnings' => array(),
            );
            if ($selectedImageUrls) {
                $filter = $this->imageMatcher->filterNewImagesForProduct(
                    $selectedImageUrls,
                    $idProduct,
                    $idShop,
                    isset($incoming['source_url']) ? (string) $incoming['source_url'] : ''
                );
            }

            // Local uploads are imported first so the first selected file can become
            // the cover when the product does not have an image yet.
            $localImport = $this->imageImporter->importUploaded(
                $idProduct,
                $uploadedImages,
                $idShop,
                $this->langValue($product->name, $idLang)
            );
            $remoteImport = $this->imageImporter->import(
                $idProduct,
                $filter['new_urls'],
                $idShop,
                $this->langValue($product->name, $idLang),
                isset($incoming['source_url']) ? (string) $incoming['source_url'] : ''
            );
            $import = $this->imageImporter->mergeResults($localImport, $remoteImport);
            $imageResult = array(
                'imported' => (int) $import['imported'],
                'errors' => isset($import['errors']) ? $import['errors'] : array(),
                'skipped' => isset($filter['skipped']) ? $filter['skipped'] : array(),
                'warnings' => isset($filter['warnings']) ? $filter['warnings'] : array(),
            );
            if ($imageResult['imported'] > 0) {
                $changes[] = $imageResult['imported'] . ' nouvelle(s) image(s) ajoutée(s)';
            }
        }

        if (!$changes && $imageResult['imported'] === 0) {
            $changes[] = 'Aucune donnée nouvelle à appliquer';
        }

        return array(
            'action' => 'enriched',
            'id_product' => $idProduct,
            'changes' => array_values(array_unique($changes)),
            'images_imported' => $imageResult['imported'],
            'images_skipped' => $imageResult['skipped'],
            'image_errors' => $imageResult['errors'],
            'warnings' => $imageResult['warnings'],
        );
    }

    private function saveProductSupplier(Product $product)
    {
        $idSupplier = (int) $product->id_supplier;
        if ($idSupplier <= 0) {
            return;
        }
        $idProductSupplier = (int) ProductSupplier::getIdByProductAndSupplier((int) $product->id, 0, $idSupplier);
        $productSupplier = $idProductSupplier > 0
            ? new ProductSupplier($idProductSupplier)
            : new ProductSupplier();
        $productSupplier->id_product = (int) $product->id;
        $productSupplier->id_product_attribute = 0;
        $productSupplier->id_supplier = $idSupplier;
        $productSupplier->product_supplier_reference = trim((string) $product->reference);
        $productSupplier->product_supplier_price_te = (float) $product->wholesale_price;
        $productSupplier->id_currency = (int) Context::getContext()->currency->id;
        $saved = Validate::isLoadedObject($productSupplier)
            ? $productSupplier->update()
            : $productSupplier->add();
        if (!$saved) {
            throw new PrestaShopException('Impossible d’enregistrer le prix d’achat chez le fournisseur.');
        }
    }

    private function applyTextEnrichment(Product $product, array $incoming, array $selectedFields, $idLang, array &$changes, &$needsUpdate)
    {
        $shortIncoming = $this->limitShortHtml(
            $this->sanitizeHtml(isset($incoming['description_short']) ? $incoming['description_short'] : ''),
            780
        );
        $shortCurrent = $this->langValue($product->description_short, $idLang);
        if (in_array('description_short_fill', $selectedFields, true) && trim(strip_tags($shortCurrent)) === '' && $shortIncoming !== '') {
            $this->setLangValue($product->description_short, $idLang, $shortIncoming);
            $changes[] = 'Description courte ajoutée';
            $needsUpdate = true;
        } elseif (in_array('description_short_merge', $selectedFields, true) && $shortIncoming !== '') {
            $merged = $this->mergeHtml($shortCurrent, $shortIncoming, 780);
            if ($merged !== $shortCurrent) {
                $this->setLangValue($product->description_short, $idLang, $merged);
                $changes[] = 'Description courte enrichie';
                $needsUpdate = true;
            }
        }

        $descriptionIncoming = $this->sanitizeHtml(isset($incoming['description']) ? $incoming['description'] : '');
        $descriptionCurrent = $this->langValue($product->description, $idLang);
        if (in_array('description_fill', $selectedFields, true) && trim(strip_tags($descriptionCurrent)) === '' && $descriptionIncoming !== '') {
            $this->setLangValue($product->description, $idLang, $descriptionIncoming);
            $changes[] = 'Description longue ajoutée';
            $needsUpdate = true;
        } elseif (in_array('description_merge', $selectedFields, true) && $descriptionIncoming !== '') {
            $merged = $this->mergeHtml($descriptionCurrent, $descriptionIncoming);
            if ($merged !== $descriptionCurrent) {
                $this->setLangValue($product->description, $idLang, $merged);
                $changes[] = 'Description longue enrichie';
                $needsUpdate = true;
            }
        }

        if (in_array('meta_title', $selectedFields, true)) {
            $metaTitle = trim(isset($incoming['meta_title']) ? (string) $incoming['meta_title'] : '');
            if ($metaTitle !== '') {
                $this->setLangValue($product->meta_title, $idLang, Tools::substr($metaTitle, 0, 70));
                $changes[] = 'Titre SEO mis à jour';
                $needsUpdate = true;
            }
        }

        if (in_array('meta_description', $selectedFields, true)) {
            $metaDescription = trim(isset($incoming['meta_description']) ? (string) $incoming['meta_description'] : '');
            if ($metaDescription !== '') {
                $this->setLangValue($product->meta_description, $idLang, Tools::substr($metaDescription, 0, 255));
                $changes[] = 'Méta-description mise à jour';
                $needsUpdate = true;
            }
        }
    }

    private function mergeHtml($existingHtml, $incomingHtml, $maxPlainLength = 0)
    {
        $existing = (string) $existingHtml;
        $incoming = $this->sanitizeHtml($incomingHtml);
        $existingNormalized = $this->normalizeText($existing);
        $incomingNormalized = $this->normalizeText($incoming);

        if ($incomingNormalized === '' || strpos($existingNormalized, $incomingNormalized) !== false) {
            return $existing;
        }
        if ($existingNormalized === '') {
            return $incoming;
        }

        $blocks = preg_split('/\s*(?=<(?:p|h2|h3|ul|ol)\b)/i', $incoming, -1, PREG_SPLIT_NO_EMPTY);
        if (!$blocks) {
            $blocks = array($incoming);
        }

        $merged = $existing;
        $mergedNormalized = $existingNormalized;
        foreach ($blocks as $block) {
            $block = trim($block);
            $blockNormalized = $this->normalizeText($block);
            if ($blockNormalized === '' || strpos($mergedNormalized, $blockNormalized) !== false) {
                continue;
            }
            if ((int) $maxPlainLength > 0
                && Tools::strlen(trim($mergedNormalized . ' ' . $blockNormalized)) > (int) $maxPlainLength) {
                continue;
            }
            $merged .= "\n" . $block;
            $mergedNormalized .= ' ' . $blockNormalized;
        }

        return trim($merged);
    }

    private function limitShortHtml($html, $maxPlainLength)
    {
        $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8')));
        if (Tools::strlen($plain) <= (int) $maxPlainLength) {
            return $html;
        }

        return '<p>' . htmlspecialchars(Tools::substr($plain, 0, (int) $maxPlainLength - 1) . '…', ENT_QUOTES, 'UTF-8') . '</p>';
    }

    private function setLangValue(&$field, $idLang, $value)
    {
        if (!is_array($field)) {
            $field = array();
        }
        $field[(int) $idLang] = $value;
    }

    private function langValue($field, $idLang)
    {
        if (is_array($field)) {
            return isset($field[(int) $idLang]) ? (string) $field[(int) $idLang] : '';
        }
        return (string) $field;
    }

    private function sanitizeHtml($html)
    {
        $allowedTags = '<p><br><h2><h3><strong><b><em><i><ul><ol><li>';
        $clean = strip_tags((string) $html, $allowedTags);
        $clean = preg_replace('/<([a-z0-9]+)\b[^>]*>/i', '<$1>', $clean);
        return trim($clean);
    }

    private function normalizeText($html)
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8');
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}
