<?php

class RfProductFactoryProductCreator
{
    private $imageImporter;

    public function __construct(RfProductFactoryImageImporter $imageImporter)
    {
        $this->imageImporter = $imageImporter;
    }

    public function create(array $data, array $selectedImageUrls, $idShop, array $uploadedImages = array())
    {
        $idCategory = (int) $data['id_category_default'];
        $category = new Category($idCategory, (int) Context::getContext()->language->id, (int) $idShop);
        if (!Validate::isLoadedObject($category)) {
            throw new PrestaShopException('La catégorie sélectionnée est invalide.');
        }

        $name = trim($data['name']);
        if ($name === '' || !Validate::isCatalogName($name)) {
            throw new PrestaShopException('Le nom du produit est invalide.');
        }

        $reference = trim($data['reference']);
        if ($reference !== '' && !Validate::isReference($reference)) {
            throw new PrestaShopException('La référence produit est invalide.');
        }

        $ean13 = preg_replace('/\D+/', '', (string) $data['ean13']);
        if ($ean13 !== '' && !Validate::isEan13($ean13)) {
            throw new PrestaShopException('L’EAN-13 est invalide.');
        }

        $priceHt = (float) str_replace(array(' ', ','), array('', '.'), $data['price_ht']);
        if ($priceHt < 0) {
            throw new PrestaShopException('Le prix HT ne peut pas être négatif.');
        }

        $wholesaleRaw = isset($data['wholesale_price_ht']) ? trim((string) $data['wholesale_price_ht']) : '';
        $wholesalePrice = $wholesaleRaw === '' ? 0.0 : (float) str_replace(array(' ', ','), array('', '.'), $wholesaleRaw);
        if ($wholesalePrice < 0) {
            throw new PrestaShopException('Le prix d’achat HT ne peut pas être négatif.');
        }

        $idManufacturer = (int) $data['id_manufacturer'];
        if ($idManufacturer > 0 && !Validate::isLoadedObject(new Manufacturer($idManufacturer))) {
            throw new PrestaShopException('Le fabricant sélectionné est invalide.');
        }
        $idSupplier = (int) $data['id_supplier'];
        if ($idSupplier > 0 && !Validate::isLoadedObject(new Supplier($idSupplier))) {
            throw new PrestaShopException('Le fournisseur sélectionné est invalide.');
        }
        $idTaxRulesGroup = (int) $data['id_tax_rules_group'];
        if ($idTaxRulesGroup > 0 && !Validate::isLoadedObject(new TaxRulesGroup($idTaxRulesGroup))) {
            throw new PrestaShopException('La règle de taxe sélectionnée est invalide.');
        }

        $product = new Product();
        $product->id_shop_default = (int) $idShop;
        $product->id_category_default = $idCategory;
        $product->id_tax_rules_group = $idTaxRulesGroup;
        $product->id_manufacturer = $idManufacturer;
        $product->id_supplier = $idSupplier;
        $product->id_shop_list = array((int) $idShop);
        $product->reference = $reference;
        $product->ean13 = $ean13;
        $product->price = $priceHt;
        $product->wholesale_price = $wholesalePrice;
        $product->weight = isset($data['weight']) ? max(0, (float) $data['weight']) : 0;
        if (!empty($data['available_date']) && $data['available_date'] !== '0000-00-00') {
            $product->available_date = (string) $data['available_date'];
        }
        $publicationStatus = isset($data['publication_status']) ? (string) $data['publication_status'] : 'offline';
        if (!in_array($publicationStatus, array('online', 'offline'), true)) {
            throw new PrestaShopException('Le statut de publication sélectionné est invalide.');
        }

        // Même hors ligne, la fiche est préparée comme un produit vendable :
        // elle apparaîtra partout et sera disponible à la vente dès son activation.
        $product->active = $publicationStatus === 'online' ? 1 : 0;
        $product->visibility = 'both';
        $product->available_for_order = 1;
        $product->show_price = 1;
        $product->condition = 'new';
        $product->minimal_quantity = 1;
        $product->state = Product::STATE_SAVED;

        $languages = Language::getLanguages(false);
        foreach ($languages as $language) {
            $idLang = (int) $language['id_lang'];
            $product->name[$idLang] = $name;
            $product->link_rewrite[$idLang] = Tools::link_rewrite($data['link_rewrite'] !== '' ? $data['link_rewrite'] : $name);
            $product->description[$idLang] = $this->sanitizeHtml($data['description']);
            $product->description_short[$idLang] = $this->sanitizeHtml($data['description_short']);
            $product->meta_title[$idLang] = trim($data['meta_title']);
            $product->meta_description[$idLang] = trim($data['meta_description']);
        }

        if (!$product->add()) {
            throw new PrestaShopException('PrestaShop n’a pas pu créer le produit.');
        }

        try {
            if (!$product->addToCategories(array($idCategory))) {
                throw new PrestaShopException('Impossible d’associer le produit à la catégorie sélectionnée.');
            }
            StockAvailable::setQuantity((int) $product->id, 0, 0, (int) $idShop, false);
            $supplierReference = isset($data['supplier_reference']) && trim((string) $data['supplier_reference']) !== ''
                ? trim((string) $data['supplier_reference'])
                : $reference;
            $this->saveProductSupplier((int) $product->id, $idSupplier, $supplierReference, $wholesalePrice);
            $localImageResult = $this->imageImporter->importUploaded(
                (int) $product->id,
                $uploadedImages,
                (int) $idShop,
                $name
            );
            $remoteImageResult = $this->imageImporter->import(
                (int) $product->id,
                $selectedImageUrls,
                (int) $idShop,
                $name,
                isset($data['source_url']) ? (string) $data['source_url'] : ''
            );
            $imageResult = $this->imageImporter->mergeResults($localImageResult, $remoteImageResult);
        } catch (Exception $e) {
            $product->delete();
            throw $e;
        }

        return array(
            'id_product' => (int) $product->id,
            'images_requested' => isset($imageResult['requested']) ? (int) $imageResult['requested'] : count($selectedImageUrls),
            'images_imported' => (int) $imageResult['imported'],
            'image_errors' => $imageResult['errors'],
            'imported_image_urls' => isset($imageResult['imported_urls']) ? $imageResult['imported_urls'] : array(),
            'publication_status' => $publicationStatus,
        );
    }

    private function saveProductSupplier($idProduct, $idSupplier, $reference, $wholesalePrice)
    {
        if ((int) $idSupplier <= 0) {
            return;
        }

        $idProductSupplier = (int) ProductSupplier::getIdByProductAndSupplier(
            (int) $idProduct,
            0,
            (int) $idSupplier
        );
        $productSupplier = $idProductSupplier > 0
            ? new ProductSupplier($idProductSupplier)
            : new ProductSupplier();

        $productSupplier->id_product = (int) $idProduct;
        $productSupplier->id_product_attribute = 0;
        $productSupplier->id_supplier = (int) $idSupplier;
        $productSupplier->product_supplier_reference = trim((string) $reference);
        $productSupplier->product_supplier_price_te = (float) $wholesalePrice;
        $productSupplier->id_currency = (int) Context::getContext()->currency->id;

        $saved = Validate::isLoadedObject($productSupplier)
            ? $productSupplier->update()
            : $productSupplier->add();
        if (!$saved) {
            throw new PrestaShopException('Impossible d’enregistrer la référence et le prix d’achat du fournisseur.');
        }
    }

    private function sanitizeHtml($html)
    {
        $allowedTags = '<p><br><h2><h3><strong><b><em><i><ul><ol><li>';
        $clean = strip_tags((string) $html, $allowedTags);
        $clean = preg_replace('/<([a-z0-9]+)\b[^>]*>/i', '<$1>', $clean);
        return trim($clean);
    }
}
