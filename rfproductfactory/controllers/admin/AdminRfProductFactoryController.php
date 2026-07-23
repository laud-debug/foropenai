<?php

require_once dirname(__FILE__) . '/../../classes/Repository/RfProductFactoryJobRepository.php';
require_once dirname(__FILE__) . '/../../classes/Service/RfProductFactoryUrlGuard.php';
require_once dirname(__FILE__) . '/../../classes/Service/RfProductFactoryHttpClient.php';
require_once dirname(__FILE__) . '/../../classes/Service/RfProductFactoryExtractor.php';
require_once dirname(__FILE__) . '/../../classes/Service/RfProductFactoryContentBuilder.php';
require_once dirname(__FILE__) . '/../../classes/Service/RfProductFactoryDuplicateDetector.php';
require_once dirname(__FILE__) . '/../../classes/Service/RfProductFactoryImageMatcher.php';
require_once dirname(__FILE__) . '/../../classes/Service/RfProductFactoryEnrichmentPlanner.php';
require_once dirname(__FILE__) . '/../../classes/Service/RfProductFactoryImageImporter.php';
require_once dirname(__FILE__) . '/../../classes/Service/RfProductFactoryProductCreator.php';
require_once dirname(__FILE__) . '/../../classes/Service/RfProductFactoryProductEnricher.php';
require_once dirname(__FILE__) . '/../../classes/Service/RfProductFactorySpreadsheetImporter.php';

class AdminRfProductFactoryController extends ModuleAdminController
{
    const CONFIG_EXCEL_DEFAULT_CATEGORY = 'RFPF_EXCEL_DEFAULT_CATEGORY';
    const CONFIG_EXCEL_AUTO_CATEGORY = 'RFPF_EXCEL_AUTO_CATEGORY';

    private $jobRepository;
    private $urlGuard;
    private $httpClient;
    private $extractor;
    private $contentBuilder;
    private $duplicateDetector;
    private $imageMatcher;
    private $enrichmentPlanner;
    private $productCreator;
    private $productEnricher;
    private $imageImporter;
    private $preview = null;
    private $duplicates = array();
    private $successData = null;
    private $manualSourceUrl = '';
    private $manualFallbackSuggested = false;
    private $spreadsheetImporter;
    private $excelPreview = null;
    private $excelOptions = array();
    private $excelResult = null;
    private $excelRaw = '';
    private $currentSection = 'web';

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        $this->urlGuard = new RfProductFactoryUrlGuard();
        $this->httpClient = new RfProductFactoryHttpClient($this->urlGuard);
        $this->jobRepository = new RfProductFactoryJobRepository();
        $this->extractor = new RfProductFactoryExtractor($this->httpClient);
        $this->contentBuilder = new RfProductFactoryContentBuilder();
        $this->duplicateDetector = new RfProductFactoryDuplicateDetector();
        $this->imageMatcher = new RfProductFactoryImageMatcher($this->httpClient);
        $this->enrichmentPlanner = new RfProductFactoryEnrichmentPlanner();
        $this->imageImporter = new RfProductFactoryImageImporter($this->httpClient);
        $this->productCreator = new RfProductFactoryProductCreator($this->imageImporter);
        $this->productEnricher = new RfProductFactoryProductEnricher($this->imageImporter, $this->imageMatcher);
        $this->spreadsheetImporter = new RfProductFactorySpreadsheetImporter();
    }

    public function initPageHeaderToolbar()
    {
        $this->page_header_toolbar_title = $this->l('RF Product Factory');
        parent::initPageHeaderToolbar();
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $assetVersion = rawurlencode((string) $this->module->version);
        $this->addCSS($this->module->getPathUri() . 'views/css/admin.css?v=' . $assetVersion);
        $this->addJS($this->module->getPathUri() . 'views/js/admin.js?v=' . $assetVersion);
    }

    public function postProcess()
    {
        $requestedSection = (string) Tools::getValue('rfpf_section', 'web');
        if ($requestedSection === 'excel') {
            $this->currentSection = 'excel';
        } elseif ($requestedSection === 'dashboard') {
            $this->currentSection = 'dashboard';
        } else {
            $this->currentSection = 'web';
        }

        if (Tools::isSubmit('submitRfpfExcelPreview')) {
            $this->currentSection = 'excel';
            $this->processExcelPreview();
        } elseif (Tools::isSubmit('submitRfpfExcelCreate')) {
            $this->currentSection = 'excel';
            $this->processExcelCreate();
        } elseif (Tools::isSubmit('submitRfpfAnalyzeHtml')) {
            $this->processAnalyzeHtml();
        } elseif (Tools::isSubmit('submitRfpfAnalyze')) {
            $this->processAnalyze();
        } elseif (Tools::isSubmit('submitRfpfUploadLocalImages')) {
            $this->processUploadLocalImages();
        } elseif (Tools::isSubmit('submitRfpfEnrich')) {
            $this->processEnrich();
        } elseif (Tools::isSubmit('submitRfpfCreate')) {
            $this->processCreate();
        }

        parent::postProcess();
    }

    private function processUploadLocalImages()
    {
        $idProduct = (int) Tools::getValue('upload_product_id');
        if ($idProduct <= 0) {
            $this->errors[] = $this->l('Indiquez une fiche produit valide.');
            return;
        }

        $isAssociated = (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'product_shop`'
            . ' WHERE `id_product` = ' . (int) $idProduct
            . ' AND `id_shop` = ' . (int) $this->context->shop->id
        );
        if (!$isAssociated) {
            $this->errors[] = $this->l('Cette fiche produit n’existe pas dans la boutique courante.');
            return;
        }

        $uploadedImages = $this->getUploadedImages('direct_local_images');
        if (!$uploadedImages) {
            $this->errors[] = $this->l('Sélectionnez au moins une image sur votre ordinateur.');
            return;
        }

        $product = new Product(
            $idProduct,
            false,
            (int) $this->context->language->id,
            (int) $this->context->shop->id
        );
        if (!Validate::isLoadedObject($product)) {
            $this->errors[] = $this->l('La fiche produit est introuvable.');
            return;
        }

        try {
            $result = $this->imageImporter->importUploaded(
                $idProduct,
                $uploadedImages,
                (int) $this->context->shop->id,
                is_array($product->name)
                    ? (string) reset($product->name)
                    : (string) $product->name
            );

            $this->successData = array(
                'action' => 'local_images_uploaded',
                'id_product' => $idProduct,
                'images_imported' => isset($result['imported']) ? (int) $result['imported'] : 0,
                'image_errors' => isset($result['errors']) ? $result['errors'] : array(),
                'edit_url' => $this->getProductEditUrl($idProduct),
            );

            if (!empty($result['imported'])) {
                $this->confirmations[] = sprintf(
                    $this->l('%d image(s) locale(s) ajoutée(s) à la fiche produit #%d.'),
                    (int) $result['imported'],
                    $idProduct
                );
            }
            foreach (isset($result['errors']) ? $result['errors'] : array() as $imageError) {
                $this->warnings[] = $imageError;
            }
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
        }
    }

    private function processAnalyze()
    {
        $url = trim((string) Tools::getValue('source_url'));
        $this->manualSourceUrl = $url;

        try {
            $data = $this->extractor->extract($url);
            $this->finalizeAnalysis($url, $data);
        } catch (Exception $e) {
            $message = $e->getMessage();
            $this->errors[] = $message;
            if (stripos($message, 'HTTP 403') !== false || stripos($message, 'refuse la requête') !== false) {
                $this->manualFallbackSuggested = true;
            }
        }
    }

    private function processAnalyzeHtml()
    {
        $url = trim((string) Tools::getValue('manual_source_url'));
        $this->manualSourceUrl = $url;
        $this->manualFallbackSuggested = true;

        try {
            $this->urlGuard->assertSafe($url);
            $html = $this->getManualHtmlInput();
            if (strlen($html) > 5 * 1024 * 1024) {
                throw new PrestaShopException($this->l('Le fichier ou code HTML dépasse la limite de 5 Mo.'));
            }
            if (strlen(trim($html)) < 200) {
                throw new PrestaShopException($this->l('Le code HTML fourni est vide ou trop court. Utilisez le code source complet de la page.'));
            }

            $data = $this->extractor->extractFromHtml($url, $html, $url);
            $data['warnings'][] = $this->l('Page analysée en mode manuel : vérifiez les informations et les images avant de créer le produit.');
            $this->finalizeAnalysis($url, $data);
            $this->confirmations[] = $this->l('Le code source fourni a été analysé sans demander la page au serveur distant.');
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
        }
    }

    private function getManualHtmlInput()
    {
        $html = (string) Tools::getValue('source_html', '');
        if (trim($html) !== '') {
            return $html;
        }

        if (!isset($_FILES['source_html_file']) || !is_array($_FILES['source_html_file'])) {
            return '';
        }

        $file = $_FILES['source_html_file'];
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return '';
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new PrestaShopException($this->l('Le fichier HTML n’a pas pu être envoyé.'));
        }

        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            throw new PrestaShopException($this->l('Le fichier HTML doit peser entre 1 octet et 5 Mo.'));
        }

        $name = isset($file['name']) ? (string) $file['name'] : '';
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, array('html', 'htm', 'txt'), true)) {
            throw new PrestaShopException($this->l('Seuls les fichiers .html, .htm ou .txt sont acceptés.'));
        }

        $tmpName = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new PrestaShopException($this->l('Le fichier HTML envoyé n’est pas valide.'));
        }

        $content = file_get_contents($tmpName);
        if ($content === false) {
            throw new PrestaShopException($this->l('Impossible de lire le fichier HTML envoyé.'));
        }

        return (string) $content;
    }

    private function finalizeAnalysis($url, array $data)
    {
        $content = $this->contentBuilder->build($data);
        $data = array_merge($data, $content);
        $data['id_category_default'] = (int) Configuration::get('PS_HOME_CATEGORY');
        $data['id_manufacturer'] = $this->guessManufacturerId($data['brand']);
        $data['id_supplier'] = $this->guessSupplierId($data);

        $shopCurrency = strtoupper((string) $this->context->currency->iso_code);
        if (!empty($data['currency']) && strtoupper((string) $data['currency']) !== $shopCurrency) {
            $data['warnings'][] = sprintf(
                $this->l('La page indique un prix en %s alors que la boutique utilise %s. Le prix HT doit être vérifié manuellement.'),
                strtoupper((string) $data['currency']),
                $shopCurrency
            );
        }

        $detection = $this->detectDuplicates($data);
        if (!empty($detection['warnings'])) {
            $data['warnings'] = array_values(array_unique(array_merge($data['warnings'], $detection['warnings'])));
        }
        $data['image_index_stats'] = $detection['stats'];
        $data['remote_images_blocked'] = $this->isKnownBlockedImageHost(
            !empty($data['final_url']) ? (string) $data['final_url'] : (string) $url
        );

        $idJob = $this->jobRepository->create(
            $url,
            $data,
            'analyzed',
            (int) $this->context->shop->id,
            (int) $this->context->employee->id
        );
        $data['id_job'] = $idJob;
        $this->preview = $data;
        $this->duplicates = $this->decorateDuplicates($detection['matches'], $data);
    }

    private function processCreate()
    {
        $idJob = (int) Tools::getValue('id_job');
        $job = $this->jobRepository->get($idJob);
        if (!$job || (int) $job['id_shop'] !== (int) $this->context->shop->id) {
            $this->errors[] = $this->l('Analyse introuvable ou appartenant à une autre boutique.');
            return;
        }
        if (!empty($job['id_product'])) {
            $this->errors[] = $this->l('Cette analyse a déjà créé un produit.');
            return;
        }

        $payload = $job['payload'];
        $allowedImages = isset($payload['images']) && is_array($payload['images']) ? $payload['images'] : array();
        $requestedImages = Tools::getValue('image_urls', array());
        if (!is_array($requestedImages)) {
            $requestedImages = array();
        }
        $selectedImages = $this->selectRequestedImages($requestedImages, $allowedImages);
        $uploadedImages = $this->getUploadedImages('local_images');

        $data = $this->getPostedProductData();
        $data['source_url'] = !empty($payload['final_url'])
            ? (string) $payload['final_url']
            : (isset($job['source_url']) ? (string) $job['source_url'] : '');
        $blockedRemoteWarning = '';
        if ($uploadedImages && (bool) Tools::getValue('local_images_only', 0)) {
            $selectedImages = array();
        } elseif (!$uploadedImages && $selectedImages && $this->isKnownBlockedImageHost($data['source_url'])) {
            $selectedImages = array();
            $blockedRemoteWarning = $this->l('Novalis bloque les images demandées depuis le serveur de la boutique. Aucune nouvelle tentative distante n’a été faite : ajoutez les fichiers avec la zone d’import local.');
        }

        $detectionData = array_merge($data, array('images' => $allowedImages));
        $detection = $this->detectDuplicates($detectionData);
        $duplicates = $this->decorateDuplicates($detection['matches'], $detectionData);
        $hasStrongDuplicate = $this->duplicateDetector->hasStrongMatch($duplicates);
        if ($hasStrongDuplicate && !Tools::getValue('confirm_duplicate')) {
            $this->errors[] = $this->l('Un produit existant ou très similaire a été détecté par référence, EAN, nom ou image. Cochez la confirmation pour forcer la création.');
            $this->preview = array_merge($payload, $data, array(
                'id_job' => $idJob,
                'selected_images' => $selectedImages,
                'local_images_selected' => count($uploadedImages),
                'confirm_duplicate' => (bool) Tools::getValue('confirm_duplicate'),
                'image_index_stats' => $detection['stats'],
                'warnings' => array_values(array_unique(array_merge(
                    isset($payload['warnings']) && is_array($payload['warnings']) ? $payload['warnings'] : array(),
                    $detection['warnings']
                ))),
            ));
            $this->duplicates = $duplicates;
            return;
        }

        try {
            $result = $this->productCreator->create(
                $data,
                $selectedImages,
                (int) $this->context->shop->id,
                $uploadedImages
            );
            $savedPayload = array_merge($payload, $data, array(
                'selected_images' => $selectedImages,
                'creation_result' => $result,
            ));
            $this->jobRepository->markCreated($idJob, $result['id_product'], $savedPayload);
            $result['edit_url'] = $this->getProductEditUrl((int) $result['id_product']);
            $this->successData = $result;
            if ($blockedRemoteWarning !== '') {
                $this->warnings[] = $blockedRemoteWarning;
            }
            $statusLabel = isset($result['publication_status']) && $result['publication_status'] === 'online'
                ? $this->l('en ligne')
                : $this->l('hors ligne');
            $this->confirmations[] = sprintf(
                $this->l('Produit #%d créé %s, disponible à la vente, avec %d image(s).'),
                $result['id_product'],
                $statusLabel,
                $result['images_imported']
            );
            foreach ($result['image_errors'] as $imageError) {
                $this->warnings[] = $imageError;
            }
        } catch (Exception $e) {
            $this->jobRepository->markError($idJob, $e->getMessage());
            $this->errors[] = $e->getMessage();
            $this->preview = array_merge($payload, $data, array(
                'id_job' => $idJob,
                'selected_images' => $selectedImages,
                'local_images_selected' => count($uploadedImages),
                'confirm_duplicate' => (bool) Tools::getValue('confirm_duplicate'),
            ));
            $this->duplicates = $duplicates;
        }
    }

    private function processEnrich()
    {
        $idJob = (int) Tools::getValue('id_job');
        $job = $this->jobRepository->get($idJob);
        if (!$job || (int) $job['id_shop'] !== (int) $this->context->shop->id) {
            $this->errors[] = $this->l('Analyse introuvable ou appartenant à une autre boutique.');
            return;
        }
        if (!empty($job['id_product'])) {
            $this->errors[] = $this->l('Cette analyse a déjà été utilisée pour créer ou enrichir un produit.');
            return;
        }

        $idProduct = (int) Tools::getValue('enrich_product_id');
        if ($idProduct <= 0) {
            $this->errors[] = $this->l('Sélectionnez la fiche existante à enrichir.');
            return;
        }

        $payload = $job['payload'];
        $allowedImages = isset($payload['images']) && is_array($payload['images']) ? $payload['images'] : array();
        $requestedImages = Tools::getValue('image_urls', array());
        if (!is_array($requestedImages)) {
            $requestedImages = array();
        }
        $selectedImages = $this->selectRequestedImages($requestedImages, $allowedImages);
        $uploadedImages = $this->getUploadedImages('local_images');

        $data = $this->getPostedProductData();
        $data['source_url'] = !empty($payload['final_url'])
            ? (string) $payload['final_url']
            : (isset($job['source_url']) ? (string) $job['source_url'] : '');
        $blockedRemoteWarning = '';
        if ($uploadedImages && (bool) Tools::getValue('local_images_only', 0)) {
            $selectedImages = array();
        } elseif (!$uploadedImages && $selectedImages && $this->isKnownBlockedImageHost($data['source_url'])) {
            $selectedImages = array();
            $blockedRemoteWarning = $this->l('Novalis bloque les images demandées depuis le serveur de la boutique. Aucune nouvelle tentative distante n’a été faite : ajoutez les fichiers avec la zone d’import local.');
        }
        $detectionData = array(
            'reference' => isset($payload['reference']) ? $payload['reference'] : '',
            'ean13' => isset($payload['ean13']) ? $payload['ean13'] : '',
            'name' => isset($payload['name']) ? $payload['name'] : '',
            'images' => $allowedImages,
        );
        $detection = $this->detectDuplicates($detectionData);
        $duplicates = $this->decorateDuplicates($detection['matches'], array_merge($payload, $data, array('images' => $allowedImages)));

        $allowedTarget = false;
        foreach ($duplicates as $duplicate) {
            if ((int) $duplicate['id_product'] === $idProduct) {
                $allowedTarget = true;
                break;
            }
        }
        if (!$allowedTarget) {
            $this->errors[] = $this->l('La fiche choisie ne correspond plus aux doublons détectés. Relancez l’analyse.');
            $this->preview = array_merge($payload, $data, array(
                'id_job' => $idJob,
                'selected_images' => $selectedImages,
                'local_images_selected' => count($uploadedImages),
                'selected_enrich_product_id' => $idProduct,
            ));
            $this->duplicates = $duplicates;
            return;
        }

        $allFields = Tools::getValue('enrich_fields', array());
        $selectedFields = array();
        if (is_array($allFields) && isset($allFields[$idProduct]) && is_array($allFields[$idProduct])) {
            $selectedFields = array_values($allFields[$idProduct]);
        }
        if ($uploadedImages && !in_array('images', $selectedFields, true)) {
            $selectedFields[] = 'images';
        }

        try {
            $result = $this->productEnricher->enrich(
                $idProduct,
                $data,
                $selectedFields,
                $selectedImages,
                (int) $this->context->shop->id,
                (int) $this->context->language->id,
                $uploadedImages
            );
            $savedPayload = array_merge($payload, $data, array(
                'selected_images' => $selectedImages,
                'selected_enrichment_fields' => $selectedFields,
                'enrichment_result' => $result,
            ));
            $this->jobRepository->markEnriched($idJob, $idProduct, $savedPayload);
            $result['edit_url'] = $this->getProductEditUrl($idProduct);
            $this->successData = $result;
            if ($blockedRemoteWarning !== '') {
                $this->warnings[] = $blockedRemoteWarning;
            }
            $this->confirmations[] = sprintf(
                $this->l('La fiche produit #%d a été enrichie.'),
                $idProduct
            );
            foreach ($result['images_skipped'] as $skipped) {
                $this->warnings[] = 'Image ignorée : ' . $skipped['reason'];
            }
            foreach ($result['warnings'] as $warning) {
                $this->warnings[] = $warning;
            }
            foreach ($result['image_errors'] as $imageError) {
                $this->warnings[] = $imageError;
            }
        } catch (Exception $e) {
            $this->jobRepository->markError($idJob, $e->getMessage());
            $this->errors[] = $e->getMessage();
            $this->preview = array_merge($payload, $data, array(
                'id_job' => $idJob,
                'selected_images' => $selectedImages,
                'local_images_selected' => count($uploadedImages),
                'selected_enrich_product_id' => $idProduct,
                'selected_enrichment_fields' => $selectedFields,
            ));
            $this->duplicates = $duplicates;
        }
    }

    private function processExcelPreview()
    {
        $this->excelRaw = $this->getSpreadsheetInput();
        $categories = $this->getCategoryOptions();
        $taxGroups = $this->normalizeTaxGroups(TaxRulesGroup::getTaxRulesGroupsForOptions());
        $this->excelOptions = $this->getExcelOptions($categories, $taxGroups);
        $this->saveExcelPreferences($this->excelOptions, $categories);

        try {
            $this->excelPreview = $this->spreadsheetImporter->parse(
                $this->excelRaw,
                $this->excelOptions,
                $categories,
                $taxGroups,
                (int) $this->context->language->id,
                (int) $this->context->shop->id
            );
            $this->decorateExcelRows($this->excelPreview['rows'], $categories, $taxGroups);
            foreach ($this->excelPreview['warnings'] as $warning) {
                $this->warnings[] = $warning;
            }
            $this->confirmations[] = sprintf(
                $this->l('%d ligne(s) analysée(s) : %d nouveau(x) produit(s), %d doublon(s), %d ligne(s) invalide(s).'),
                (int) $this->excelPreview['stats']['total'],
                (int) $this->excelPreview['stats']['new'],
                (int) $this->excelPreview['stats']['existing'],
                (int) $this->excelPreview['stats']['invalid']
            );
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
        }
    }

    private function processExcelCreate()
    {
        $categories = $this->getCategoryOptions();
        $taxGroups = $this->normalizeTaxGroups(TaxRulesGroup::getTaxRulesGroupsForOptions());
        $this->excelOptions = $this->getExcelOptions($categories, $taxGroups);
        $this->saveExcelPreferences($this->excelOptions, $categories);
        $json = (string) Tools::getValue('batch_rows_json', '');
        $rows = json_decode($json, true);
        if (!is_array($rows) || !$rows) {
            $this->errors[] = $this->l('Le lot à créer est vide ou invalide. Relancez la prévisualisation.');
            return;
        }
        if (count($rows) > RfProductFactorySpreadsheetImporter::MAX_ROWS) {
            $this->errors[] = $this->l('Le lot dépasse la limite autorisée.');
            return;
        }

        foreach ($rows as &$rowToValidate) {
            if (!empty($rowToValidate['selected'])) {
                $rowToValidate = $this->spreadsheetImporter->validateSubmittedRow($rowToValidate, $categories, $taxGroups);
            }
        }
        unset($rowToValidate);

        $this->spreadsheetImporter->refreshExistingProducts(
            $rows,
            (int) $this->context->language->id,
            (int) $this->context->shop->id
        );

        $idManufacturer = (int) $this->excelOptions['id_manufacturer'];
        $idSupplier = (int) $this->excelOptions['id_supplier'];
        $publicationStatus = $this->excelOptions['publication_status'] === 'online' ? 'online' : 'offline';
        $created = array();
        $skipped = array();
        $failed = array();

        foreach ($rows as &$row) {
            if (empty($row['selected'])) {
                continue;
            }
            if (!empty($row['existing_product'])) {
                $skipped[] = array(
                    'name' => isset($row['name']) ? $row['name'] : '',
                    'reason' => isset($row['existing_reason']) ? $row['existing_reason'] : $this->l('Produit existant'),
                    'id_product' => (int) $row['existing_product']['id_product'],
                );
                $row['selected'] = 0;
                continue;
            }
            if (!empty($row['errors'])) {
                $failed[] = array(
                    'name' => isset($row['name']) ? $row['name'] : '',
                    'message' => implode(' ', $row['errors']),
                );
                continue;
            }

            $name = trim((string) $row['name']);
            $data = array(
                'name' => $name,
                'reference' => trim((string) $row['reference']),
                'ean13' => trim((string) $row['ean13']),
                'price_ht' => (string) $row['price_ht'],
                'wholesale_price_ht' => (string) $row['wholesale_price_ht'],
                'id_category_default' => (int) $row['id_category_default'],
                'id_tax_rules_group' => (int) $row['id_tax_rules_group'],
                'id_manufacturer' => $idManufacturer,
                'id_supplier' => $idSupplier,
                'description' => '',
                'description_short' => '',
                'meta_title' => Tools::substr($name, 0, 255),
                'meta_description' => Tools::substr($name, 0, 512),
                'link_rewrite' => Tools::link_rewrite($name),
                'publication_status' => $publicationStatus,
                'available_date' => isset($row['available_date']) ? (string) $row['available_date'] : '0000-00-00',
                'weight' => isset($row['weight']) ? (float) $row['weight'] : 0,
                'supplier_reference' => isset($row['supplier_reference']) ? trim((string) $row['supplier_reference']) : '',
                'source_url' => '',
            );

            try {
                $result = $this->productCreator->create(
                    $data,
                    array(),
                    (int) $this->context->shop->id,
                    array()
                );
                $row['selected'] = 0;
                $row['created_product'] = array(
                    'id_product' => (int) $result['id_product'],
                    'edit_url' => $this->getProductEditUrl((int) $result['id_product']),
                );
                $created[] = array(
                    'id_product' => (int) $result['id_product'],
                    'name' => $name,
                    'reference' => (string) $row['reference'],
                    'edit_url' => $this->getProductEditUrl((int) $result['id_product']),
                );
            } catch (Exception $e) {
                $row['errors'][] = $e->getMessage();
                $failed[] = array('name' => $name, 'message' => $e->getMessage());
            }
        }
        unset($row);

        $this->decorateExcelRows($rows, $categories, $taxGroups);
        $this->excelPreview = array(
            'rows' => $rows,
            'stats' => $this->buildExcelStats($rows),
            'warnings' => array(),
            'headers' => array(),
            'delimiter' => 'tab',
        );
        $this->excelResult = array(
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'created_count' => count($created),
            'skipped_count' => count($skipped),
            'failed_count' => count($failed),
        );

        if ($created) {
            $this->confirmations[] = sprintf(
                $this->l('%d produit(s) créé(s) avec succès. Les doublons ont été ignorés.'),
                count($created)
            );
        }
        if ($skipped) {
            $this->warnings[] = sprintf($this->l('%d produit(s) déjà existant(s) ont été ignorés.'), count($skipped));
        }
        if ($failed) {
            $this->warnings[] = sprintf($this->l('%d ligne(s) n’ont pas pu être créées. Consultez le détail ci-dessous.'), count($failed));
        }
    }

    private function getSpreadsheetInput()
    {
        $raw = (string) Tools::getValue('spreadsheet_data', '');
        if (trim($raw) !== '') {
            return $raw;
        }
        if (!isset($_FILES['spreadsheet_file']) || !is_array($_FILES['spreadsheet_file'])) {
            return '';
        }
        $file = $_FILES['spreadsheet_file'];
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return '';
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new PrestaShopException($this->l('Le fichier Excel exporté n’a pas pu être envoyé.'));
        }
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            throw new PrestaShopException($this->l('Le fichier doit peser entre 1 octet et 2 Mo.'));
        }
        $extension = strtolower(pathinfo(isset($file['name']) ? (string) $file['name'] : '', PATHINFO_EXTENSION));
        if (!in_array($extension, array('csv', 'tsv', 'txt'), true)) {
            throw new PrestaShopException($this->l('Utilisez un fichier .csv, .tsv ou .txt. Pour un .xlsx, copiez directement les cellules depuis Excel.'));
        }
        $tmpName = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new PrestaShopException($this->l('Le fichier envoyé n’est pas valide.'));
        }
        $content = file_get_contents($tmpName);
        if ($content === false) {
            throw new PrestaShopException($this->l('Impossible de lire le fichier envoyé.'));
        }
        return (string) $content;
    }

    private function getExcelOptions(array $categories, array $taxGroups)
    {
        $defaults = $this->getExcelDefaultOptions($categories, $taxGroups);
        $options = array(
            // Règle fixe Rebel Forge : référence PrestaShop = GW- + Product Code.
            'reference_source' => 'product_code',
            'reference_prefix' => 'GW-',
            'supplier_reference_source' => 'product_code',
            'sale_price_source' => strtolower((string) Tools::getValue('sale_price_source', $defaults['sale_price_source'])),
            'wholesale_price_source' => strtolower((string) Tools::getValue('wholesale_price_source', $defaults['wholesale_price_source'])),
            'id_category_default' => (int) Tools::getValue('excel_id_category_default', $defaults['id_category_default']),
            'auto_category' => (int) Tools::getValue('auto_category', $defaults['auto_category']) ? 1 : 0,
            'id_tax_rules_group_standard' => (int) Tools::getValue('id_tax_rules_group_standard', $defaults['id_tax_rules_group_standard']),
            'id_tax_rules_group_book' => (int) Tools::getValue('id_tax_rules_group_book', $defaults['id_tax_rules_group_book']),
            'id_manufacturer' => (int) Tools::getValue('excel_id_manufacturer', $defaults['id_manufacturer']),
            'id_supplier' => (int) Tools::getValue('excel_id_supplier', $defaults['id_supplier']),
            'publication_status' => (string) Tools::getValue('excel_publication_status', $defaults['publication_status']),
            'normalize_names' => (int) Tools::getValue('normalize_names', $defaults['normalize_names']) ? 1 : 0,
        );
        if (!in_array($options['sale_price_source'], array('frr', 'chr'), true)) {
            $options['sale_price_source'] = 'frr';
        }
        if (!in_array($options['wholesale_price_source'], array('eud', 'chd'), true)) {
            $options['wholesale_price_source'] = 'eud';
        }
        if (!in_array($options['publication_status'], array('online', 'offline'), true)) {
            $options['publication_status'] = 'offline';
        }
        return $options;
    }

    private function getExcelDefaultOptions(array $categories, array $taxGroups)
    {
        $manufacturers = Manufacturer::getManufacturers(false, (int) $this->context->language->id);
        $suppliers = Supplier::getSuppliers(false, (int) $this->context->language->id);

        $configuredCategory = (int) Configuration::get(self::CONFIG_EXCEL_DEFAULT_CATEGORY);
        if (!$this->categoryIdExists($configuredCategory, $categories)) {
            $configuredCategory = $this->guessCategoryIdByNames(
                $categories,
                array('Réservation GW', 'Reservation GW')
            );
        }
        if (!$this->categoryIdExists($configuredCategory, $categories)) {
            $configuredCategory = (int) Configuration::get('PS_HOME_CATEGORY');
        }

        $configuredAutoCategory = (int) Configuration::get(self::CONFIG_EXCEL_AUTO_CATEGORY);

        return array(
            'reference_source' => 'product_code',
            'reference_prefix' => 'GW-',
            'supplier_reference_source' => 'product_code',
            'sale_price_source' => 'frr',
            'wholesale_price_source' => 'eud',
            'id_category_default' => $configuredCategory,
            'auto_category' => $configuredAutoCategory ? 1 : 0,
            'id_tax_rules_group_standard' => $this->guessTaxGroupByRate($taxGroups, 20),
            'id_tax_rules_group_book' => $this->guessTaxGroupByRate($taxGroups, 5.5),
            'id_manufacturer' => $this->guessEntityIdByNames($manufacturers, 'id_manufacturer', array('games workshop')),
            'id_supplier' => $this->guessEntityIdByNames($suppliers, 'id_supplier', array('games workshop', 'gw')),
            'publication_status' => 'offline',
            'normalize_names' => 0,
        );
    }

    private function saveExcelPreferences(array $options, array $categories)
    {
        $idCategory = isset($options['id_category_default']) ? (int) $options['id_category_default'] : 0;
        if ($this->categoryIdExists($idCategory, $categories)) {
            Configuration::updateValue(self::CONFIG_EXCEL_DEFAULT_CATEGORY, $idCategory);
        }
        Configuration::updateValue(
            self::CONFIG_EXCEL_AUTO_CATEGORY,
            !empty($options['auto_category']) ? 1 : 0
        );
    }

    private function categoryIdExists($idCategory, array $categories)
    {
        if ((int) $idCategory <= 0) {
            return false;
        }
        foreach ($categories as $category) {
            if ((int) $category['id_category'] === (int) $idCategory) {
                return true;
            }
        }
        return false;
    }

    private function guessCategoryIdByNames(array $categories, array $names)
    {
        $normalizedNames = array();
        foreach ($names as $name) {
            $normalized = $this->normalizeSearchText($name);
            if ($normalized !== '') {
                $normalizedNames[] = $normalized;
            }
        }

        foreach ($categories as $category) {
            $categoryName = isset($category['name']) ? $this->normalizeSearchText($category['name']) : '';
            if ($categoryName !== '' && in_array($categoryName, $normalizedNames, true)) {
                return isset($category['id_category']) ? (int) $category['id_category'] : 0;
            }
        }

        foreach ($categories as $category) {
            $categoryName = isset($category['name']) ? $this->normalizeSearchText($category['name']) : '';
            foreach ($normalizedNames as $normalizedName) {
                if ($categoryName !== '' && strpos($categoryName, $normalizedName) !== false) {
                    return isset($category['id_category']) ? (int) $category['id_category'] : 0;
                }
            }
        }

        return 0;
    }

    private function guessTaxGroupByRate(array $taxGroups, $targetRate)
    {
        $bestId = 0;
        $bestDelta = 999;
        foreach ($taxGroups as $group) {
            $delta = abs((float) $group['rate'] - (float) $targetRate);
            if ($delta < $bestDelta) {
                $bestDelta = $delta;
                $bestId = (int) $group['id_tax_rules_group'];
            }
        }
        return $bestId;
    }

    private function guessEntityIdByNames($rows, $idKey, array $needles)
    {
        foreach (is_array($rows) ? $rows : array() as $row) {
            $name = isset($row['name']) ? $this->normalizeSearchText($row['name']) : '';
            foreach ($needles as $needle) {
                $needle = $this->normalizeSearchText($needle);
                if ($name !== '' && $needle !== '' && strpos($name, $needle) !== false) {
                    return isset($row[$idKey]) ? (int) $row[$idKey] : 0;
                }
            }
        }
        return 0;
    }

    private function decorateExcelRows(array &$rows, array $categories, array $taxGroups)
    {
        $categoryNames = array();
        foreach ($categories as $category) {
            $categoryNames[(int) $category['id_category']] = (string) $category['name'];
        }
        $taxNames = array();
        foreach ($taxGroups as $taxGroup) {
            $taxNames[(int) $taxGroup['id_tax_rules_group']] = (string) $taxGroup['name'];
        }
        foreach ($rows as &$row) {
            $row['category_name'] = isset($categoryNames[(int) $row['id_category_default']])
                ? $categoryNames[(int) $row['id_category_default']] : '';
            $row['tax_name'] = isset($taxNames[(int) $row['id_tax_rules_group']])
                ? $taxNames[(int) $row['id_tax_rules_group']] : '';
            if (!empty($row['existing_product']['id_product'])) {
                $row['existing_product']['edit_url'] = $this->getProductEditUrl((int) $row['existing_product']['id_product']);
            }
        }
        unset($row);
    }

    private function buildExcelStats(array $rows)
    {
        $stats = array('total' => count($rows), 'valid' => 0, 'new' => 0, 'existing' => 0, 'invalid' => 0, 'selected' => 0);
        foreach ($rows as $row) {
            if (!empty($row['created_product'])) {
                continue;
            }
            if (!empty($row['errors'])) {
                $stats['invalid']++;
            } elseif (!empty($row['existing_product'])) {
                $stats['existing']++;
            } else {
                $stats['valid']++;
                $stats['new']++;
                if (!empty($row['selected'])) {
                    $stats['selected']++;
                }
            }
        }
        return $stats;
    }

    private function getUploadedImages($fieldName)
    {
        if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
            return array();
        }

        $raw = $_FILES[$fieldName];
        $names = isset($raw['name']) ? $raw['name'] : array();
        if (!is_array($names)) {
            $names = array($names);
        }

        $files = array();
        foreach ($names as $index => $name) {
            $error = isset($raw['error'][$index]) ? (int) $raw['error'][$index] : UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE && trim((string) $name) === '') {
                continue;
            }
            $files[] = array(
                'name' => (string) $name,
                'type' => isset($raw['type'][$index]) ? (string) $raw['type'][$index] : '',
                'tmp_name' => isset($raw['tmp_name'][$index]) ? (string) $raw['tmp_name'][$index] : '',
                'error' => $error,
                'size' => isset($raw['size'][$index]) ? (int) $raw['size'][$index] : 0,
            );
        }

        if (count($files) > 8) {
            $files = array_slice($files, 0, 8);
            $this->warnings[] = $this->l('Seules les 8 premières images locales ont été prises en compte.');
        }

        return $files;
    }

    private function isKnownBlockedImageHost($url)
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        return $host === 'shop.novalisgames.com' || $host === 'www.shop.novalisgames.com';
    }

    private function selectRequestedImages(array $requestedImages, array $allowedImages)
    {
        $allowedByKey = array();
        foreach ($allowedImages as $allowedImage) {
            $key = $this->normalizePostedImageUrl($allowedImage);
            if ($key !== '') {
                $allowedByKey[$key] = $allowedImage;
            }
        }

        $selected = array();
        foreach ($requestedImages as $requestedImage) {
            $key = $this->normalizePostedImageUrl($requestedImage);
            if ($key !== '' && isset($allowedByKey[$key])) {
                $selected[] = $allowedByKey[$key];
            }
        }

        return array_values(array_unique($selected));
    }

    private function normalizePostedImageUrl($url)
    {
        $url = html_entity_decode(trim((string) $url), ENT_QUOTES, 'UTF-8');
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? (string) $parts['path'] : '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return $scheme . '://' . $host . $port . $path . $query;
    }

    private function getPostedProductData()
    {
        return array(
            'name' => trim((string) Tools::getValue('name')),
            'reference' => trim((string) Tools::getValue('reference')),
            'ean13' => trim((string) Tools::getValue('ean13')),
            'price_ht' => trim((string) Tools::getValue('price_ht')),
            'wholesale_price_ht' => trim((string) Tools::getValue('wholesale_price_ht')),
            'id_category_default' => (int) Tools::getValue('id_category_default'),
            'id_tax_rules_group' => (int) Tools::getValue('id_tax_rules_group'),
            'id_manufacturer' => (int) Tools::getValue('id_manufacturer'),
            'id_supplier' => (int) Tools::getValue('id_supplier'),
            'description' => (string) Tools::getValue('description'),
            'description_short' => (string) Tools::getValue('description_short'),
            'meta_title' => trim((string) Tools::getValue('meta_title')),
            'meta_description' => trim((string) Tools::getValue('meta_description')),
            'link_rewrite' => trim((string) Tools::getValue('link_rewrite')),
            'publication_status' => (string) Tools::getValue('publication_status', 'offline'),
        );
    }

    public function initContent()
    {
        parent::initContent();

        $baseAction = $this->context->link->getAdminLink('AdminRfProductFactory');
        $webUrl = $baseAction . '&rfpf_section=web';
        $excelUrl = $baseAction . '&rfpf_section=excel';
        $dashboardUrl = $baseAction . '&rfpf_section=dashboard';
        $categories = $this->getCategoryOptions();
        $taxGroups = $this->normalizeTaxGroups(TaxRulesGroup::getTaxRulesGroupsForOptions());
        $manufacturers = Manufacturer::getManufacturers(false, (int) $this->context->language->id);
        $suppliers = Supplier::getSuppliers(false, (int) $this->context->language->id);

        if ($this->currentSection === 'excel') {
            if (!$this->excelOptions) {
                $this->excelOptions = $this->getExcelDefaultOptions($categories, $taxGroups);
            }
            $rowsJson = $this->excelPreview && !empty($this->excelPreview['rows'])
                ? json_encode($this->excelPreview['rows'])
                : '[]';
            if ($rowsJson === false) {
                $rowsJson = '[]';
            }

            $this->context->smarty->assign(array(
                'rfpf_action' => $excelUrl,
                'rfpf_section' => 'excel',
                'rfpf_web_url' => $webUrl,
                'rfpf_excel_url' => $excelUrl,
                'rfpf_dashboard_url' => $dashboardUrl,
                'rfpf_excel_preview' => $this->excelPreview,
                'rfpf_excel_rows_json' => $rowsJson,
                'rfpf_excel_options' => $this->excelOptions,
                'rfpf_excel_result' => $this->excelResult,
                'rfpf_excel_raw' => $this->excelRaw,
                'rfpf_categories' => $categories,
                'rfpf_manufacturers' => is_array($manufacturers) ? $manufacturers : array(),
                'rfpf_suppliers' => is_array($suppliers) ? $suppliers : array(),
                'rfpf_tax_groups' => $taxGroups,
                'rfpf_currency_sign' => $this->context->currency->sign,
            ));
            $this->setTemplate('excel_import.tpl');
            return;
        }

        if ($this->currentSection === 'dashboard') {
            $activityStats = $this->jobRepository->getActivityStats((int) $this->context->shop->id);
            $recentJobs = $this->jobRepository->getRecentJobs((int) $this->context->shop->id, 20);
            foreach ($recentJobs as &$job) {
                $job['product_edit_url'] = '';
                if (!empty($job['id_product'])) {
                    $job['product_edit_url'] = $this->getProductEditUrl((int) $job['id_product']);
                }
            }
            unset($job);

            $this->context->smarty->assign(array(
                'rfpf_action' => $dashboardUrl,
                'rfpf_section' => 'dashboard',
                'rfpf_web_url' => $webUrl,
                'rfpf_excel_url' => $excelUrl,
                'rfpf_dashboard_url' => $dashboardUrl,
                'rfpf_activity_stats' => $activityStats,
                'rfpf_recent_jobs' => $recentJobs,
            ));
            $this->setTemplate('activity_dashboard.tpl');
            return;
        }

        $defaultTaxGroup = $this->guessDefaultTaxGroup($taxGroups);
        $selectedTaxGroup = $this->preview && isset($this->preview['id_tax_rules_group'])
            ? (int) $this->preview['id_tax_rules_group']
            : $defaultTaxGroup;
        $shopCurrency = strtoupper((string) $this->context->currency->iso_code);
        $currencyMatches = !$this->preview
            || empty($this->preview['currency'])
            || strtoupper((string) $this->preview['currency']) === $shopCurrency;
        if ($this->preview && isset($this->preview['price_ttc']) && $this->preview['price_ttc'] !== null && !isset($this->preview['price_ht']) && $currencyMatches) {
            $rate = $this->getRateForTaxGroup($taxGroups, $selectedTaxGroup);
            $this->preview['price_ht'] = round((float) $this->preview['price_ttc'] / (1 + ($rate / 100)), 6);
        }
        if ($this->preview
            && (!isset($this->preview['wholesale_price_ht']) || $this->preview['wholesale_price_ht'] === null || $this->preview['wholesale_price_ht'] === '')
            && isset($this->preview['wholesale_price_ttc'])
            && $this->preview['wholesale_price_ttc'] !== null
            && $currencyMatches) {
            $rate = isset($this->preview['tax_rate']) && $this->preview['tax_rate'] !== null
                ? (float) $this->preview['tax_rate']
                : $this->getRateForTaxGroup($taxGroups, $selectedTaxGroup);
            $this->preview['wholesale_price_ht'] = round((float) $this->preview['wholesale_price_ttc'] / (1 + ($rate / 100)), 6);
        }
        if ($this->preview && !isset($this->preview['id_tax_rules_group'])) {
            $this->preview['id_tax_rules_group'] = $defaultTaxGroup;
        }
        if ($this->preview && !isset($this->preview['remote_images_blocked'])) {
            $previewSourceUrl = !empty($this->preview['final_url'])
                ? (string) $this->preview['final_url']
                : (!empty($this->preview['source_url']) ? (string) $this->preview['source_url'] : '');
            $this->preview['remote_images_blocked'] = $this->isKnownBlockedImageHost($previewSourceUrl);
        }

        $defaultEnrichProductId = 0;
        $this->context->smarty->assign(array(
            'rfpf_action' => $webUrl,
            'rfpf_section' => 'web',
            'rfpf_web_url' => $webUrl,
            'rfpf_excel_url' => $excelUrl,
            'rfpf_dashboard_url' => $dashboardUrl,
            'rfpf_preview' => $this->preview,
            'rfpf_duplicates' => $this->duplicates,
            'rfpf_has_strong_duplicate' => $this->duplicateDetector->hasStrongMatch($this->duplicates),
            'rfpf_default_enrich_product_id' => $defaultEnrichProductId,
            'rfpf_success' => $this->successData,
            'rfpf_categories' => $categories,
            'rfpf_manufacturers' => is_array($manufacturers) ? $manufacturers : array(),
            'rfpf_suppliers' => is_array($suppliers) ? $suppliers : array(),
            'rfpf_tax_groups' => $taxGroups,
            'rfpf_default_tax_group' => $defaultTaxGroup,
            'rfpf_latest_jobs' => $this->jobRepository->getLatest((int) $this->context->shop->id, 15),
            'rfpf_currency_sign' => $this->context->currency->sign,
            'rfpf_products_link' => $this->context->link->getAdminLink('AdminProducts'),
            'rfpf_image_index_stats' => $this->imageMatcher->getStats((int) $this->context->shop->id),
            'rfpf_image_index_url' => $baseAction . '&ajax=1&action=IndexImages',
            'rfpf_product_search_url' => $baseAction . '&ajax=1&action=SearchProducts',
            'rfpf_manual_source_url' => $this->manualSourceUrl,
            'rfpf_manual_fallback_suggested' => $this->manualFallbackSuggested,
        ));

        $this->setTemplate('dashboard.tpl');
    }


    public function ajaxProcessIndexImages()
    {
        try {
            $stats = $this->imageMatcher->indexBatch((int) $this->context->shop->id, 150);
            $this->ajaxDie(json_encode(array(
                'success' => true,
                'stats' => $stats,
            )));
        } catch (Exception $e) {
            $this->ajaxDie(json_encode(array(
                'success' => false,
                'message' => $e->getMessage(),
            )));
        }
    }

    public function ajaxProcessSearchProducts()
    {
        try {
            $term = trim((string) Tools::getValue('q', ''));
            $termLength = function_exists('mb_strlen') ? mb_strlen($term, 'UTF-8') : strlen($term);
            if ($term === '' || ($termLength < 2 && !ctype_digit($term))) {
                $this->ajaxDie(json_encode(array(
                    'success' => true,
                    'products' => array(),
                )));
            }

            $idShop = (int) $this->context->shop->id;
            $idLang = (int) $this->context->language->id;
            $escaped = pSQL($term);
            $numericId = ctype_digit($term) ? (int) $term : 0;

            $conditions = array(
                "p.`reference` LIKE '%" . $escaped . "%'",
                "p.`ean13` LIKE '%" . $escaped . "%'",
                "pl.`name` LIKE '%" . $escaped . "%'",
            );
            if ($numericId > 0) {
                array_unshift($conditions, 'p.`id_product` = ' . (int) $numericId);
            }

            $order = $numericId > 0
                ? 'CASE WHEN p.`id_product` = ' . (int) $numericId . ' THEN 0 ELSE 1 END, pl.`name` ASC'
                : "CASE WHEN p.`reference` = '" . $escaped . "' OR p.`ean13` = '" . $escaped . "' THEN 0 ELSE 1 END, pl.`name` ASC";

            $sql = 'SELECT DISTINCT p.`id_product`, p.`reference`, p.`ean13`, ps.`active`, pl.`name`, pl.`link_rewrite`'
                . ' FROM `' . _DB_PREFIX_ . 'product` p'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON (ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . ')'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (pl.`id_product` = p.`id_product` AND pl.`id_shop` = ' . $idShop . ' AND pl.`id_lang` = ' . $idLang . ')'
                . ' WHERE (' . implode(' OR ', $conditions) . ')'
                . ' ORDER BY ' . $order
                . ' LIMIT 20';

            $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
            $products = array();
            foreach (is_array($rows) ? $rows : array() as $row) {
                $idProduct = (int) $row['id_product'];
                $coverUrl = '';
                $cover = Image::getCover($idProduct);
                if (is_array($cover) && !empty($cover['id_image'])) {
                    $coverUrl = $this->context->link->getImageLink(
                        (string) $row['link_rewrite'],
                        $idProduct . '-' . (int) $cover['id_image'],
                        'small_default'
                    );
                }

                $products[] = array(
                    'id_product' => $idProduct,
                    'name' => (string) $row['name'],
                    'reference' => (string) $row['reference'],
                    'ean13' => (string) $row['ean13'],
                    'active' => (int) $row['active'],
                    'cover_url' => $coverUrl,
                    'edit_url' => $this->getProductEditUrl($idProduct),
                );
            }

            $this->ajaxDie(json_encode(array(
                'success' => true,
                'products' => $products,
            )));
        } catch (Exception $e) {
            $this->ajaxDie(json_encode(array(
                'success' => false,
                'message' => $e->getMessage(),
            )));
        }
    }

    private function detectDuplicates(array $data)
    {
        $matches = $this->duplicateDetector->find(
            isset($data['reference']) ? $data['reference'] : '',
            isset($data['ean13']) ? $data['ean13'] : '',
            isset($data['name']) ? $data['name'] : '',
            (int) $this->context->language->id,
            (int) $this->context->shop->id
        );

        $candidateIds = array();
        foreach ($matches as $match) {
            if (!empty($match['id_product'])) {
                $candidateIds[] = (int) $match['id_product'];
            }
        }

        $imageResult = $this->imageMatcher->findMatches(
            isset($data['images']) && is_array($data['images']) ? $data['images'] : array(),
            (int) $this->context->language->id,
            (int) $this->context->shop->id,
            $candidateIds
        );

        return array(
            'matches' => $this->duplicateDetector->mergeImageMatches($matches, $imageResult['matches']),
            'warnings' => isset($imageResult['warnings']) ? $imageResult['warnings'] : array(),
            'stats' => isset($imageResult['stats']) ? $imageResult['stats'] : array(),
        );
    }

    private function decorateDuplicates(array $duplicates, array $incoming = array())
    {
        foreach ($duplicates as &$duplicate) {
            $duplicate['edit_url'] = $this->context->link->getAdminLink(
                'AdminProducts',
                true,
                array(),
                array(
                    'id_product' => (int) $duplicate['id_product'],
                    'updateproduct' => 1,
                )
            );
            $duplicate['cover_url'] = '';
            $cover = Image::getCover((int) $duplicate['id_product']);
            if (is_array($cover) && !empty($cover['id_image'])) {
                $rewrite = isset($duplicate['link_rewrite']) ? (string) $duplicate['link_rewrite'] : '';
                $duplicate['cover_url'] = $this->context->link->getImageLink(
                    $rewrite,
                    (int) $duplicate['id_product'] . '-' . (int) $cover['id_image'],
                    'small_default'
                );
            }
            $duplicate['enrichment'] = $this->enrichmentPlanner->build(
                (int) $duplicate['id_product'],
                $incoming,
                (int) $this->context->language->id,
                (int) $this->context->shop->id
            );
        }
        unset($duplicate);

        return $duplicates;
    }

    private function getDefaultEnrichProductId(array $duplicates)
    {
        foreach ($duplicates as $duplicate) {
            if (!empty($duplicate['id_product'])
                && !empty($duplicate['enrichment'])
                && !empty($duplicate['enrichment']['has_options'])) {
                return (int) $duplicate['id_product'];
            }
        }

        return 0;
    }

    private function getProductEditUrl($idProduct)
    {
        return $this->context->link->getAdminLink(
            'AdminProducts',
            true,
            array(),
            array(
                'id_product' => (int) $idProduct,
                'updateproduct' => 1,
            )
        );
    }

    private function getCategoryOptions()
    {
        $categories = Db::getInstance()->executeS(
            'SELECT c.`id_category`, cl.`name`, c.`level_depth`
             FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                ON (cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . (int) $this->context->shop->id . ')
             INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON (cl.`id_category` = c.`id_category`
                    AND cl.`id_shop` = ' . (int) $this->context->shop->id . '
                    AND cl.`id_lang` = ' . (int) $this->context->language->id . ')
             WHERE c.`id_category` NOT IN (' . (int) Configuration::get('PS_ROOT_CATEGORY') . ')
             ORDER BY c.`nleft` ASC'
        );

        foreach ($categories as &$category) {
            $category['indent'] = str_repeat('— ', max(0, (int) $category['level_depth'] - 1));
        }
        unset($category);

        return $categories;
    }


    private function normalizeTaxGroups($taxGroups)
    {
        if (!is_array($taxGroups)) {
            return array();
        }

        $normalized = array();
        foreach ($taxGroups as $group) {
            if (!is_array($group) || empty($group['id_tax_rules_group'])) {
                continue;
            }
            $id = (int) $group['id_tax_rules_group'];
            if (!isset($normalized[$id])) {
                $normalized[$id] = array(
                    'id_tax_rules_group' => $id,
                    'name' => isset($group['name']) ? $group['name'] : ('Taxe #' . $id),
                    'rate' => isset($group['rate']) ? (float) $group['rate'] : 0,
                );
            }
        }

        return array_values($normalized);
    }

    private function guessSupplierId(array $data)
    {
        $url = isset($data['final_url']) ? (string) $data['final_url'] : (isset($data['source_url']) ? (string) $data['source_url'] : '');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $needles = array();
        if (strpos($host, 'novalisgames.com') !== false) {
            $needles[] = 'novalis';
        }
        if (strpos($host, 'asmodee') !== false) {
            $needles[] = 'asmodee';
        }
        if (!$needles) {
            return 0;
        }

        $suppliers = Supplier::getSuppliers(false, (int) $this->context->language->id);
        if (!is_array($suppliers)) {
            return 0;
        }
        foreach ($suppliers as $supplier) {
            $name = isset($supplier['name']) ? $this->normalizeSearchText($supplier['name']) : '';
            foreach ($needles as $needle) {
                if ($name !== '' && strpos($name, $needle) !== false) {
                    return (int) $supplier['id_supplier'];
                }
            }
        }
        return 0;
    }

    private function normalizeSearchText($value)
    {
        $value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($ascii !== false) {
                $value = $ascii;
            }
        }
        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $value)));
    }

    private function guessManufacturerId($brand)
    {
        $brand = trim((string) $brand);
        if ($brand === '') {
            return 0;
        }

        $manufacturers = Manufacturer::getManufacturers(false, (int) $this->context->language->id);
        if (!is_array($manufacturers)) {
            return 0;
        }

        foreach ($manufacturers as $manufacturer) {
            if (isset($manufacturer['name']) && strcasecmp(trim($manufacturer['name']), $brand) === 0) {
                return (int) $manufacturer['id_manufacturer'];
            }
        }

        return 0;
    }

    private function guessDefaultTaxGroup(array $taxGroups)
    {
        $bestId = 0;
        $bestDelta = 999;
        foreach ($taxGroups as $group) {
            $rate = isset($group['rate']) ? (float) $group['rate'] : 0;
            $delta = abs(20 - $rate);
            if ((int) $group['id_tax_rules_group'] > 0 && $delta < $bestDelta) {
                $bestDelta = $delta;
                $bestId = (int) $group['id_tax_rules_group'];
            }
        }
        return $bestId;
    }

    private function getRateForTaxGroup(array $taxGroups, $idTaxGroup)
    {
        foreach ($taxGroups as $group) {
            if ((int) $group['id_tax_rules_group'] === (int) $idTaxGroup) {
                return isset($group['rate']) ? (float) $group['rate'] : 0;
            }
        }
        return 0;
    }
}
