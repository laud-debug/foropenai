<?php
/**
 * RF Product Factory
 * Create disabled PrestaShop product drafts from public product pages.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class RfProductFactory extends Module
{
    const VERSION = '0.4.0';

    public function __construct()
    {
        $this->name = 'rfproductfactory';
        $this->tab = 'administration';
        $this->version = self::VERSION;
        $this->author = 'Rebel Forge';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.7.8.0', 'max' => '1.7.8.99');

        parent::__construct();

        $this->displayName = $this->l('RF Product Factory');
        $this->description = $this->l('Analyse une page web ou un copier-coller Excel et crée ou enrichit des fiches produits PrestaShop après validation.');
        $this->confirmUninstall = $this->l('Les historiques d’import seront supprimés. Continuer ?');
    }

    public function install()
    {
        if (!function_exists('curl_init')) {
            $this->_errors[] = $this->l('L’extension PHP cURL est nécessaire.');
            return false;
        }
        if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            $this->_errors[] = $this->l('L’extension PHP DOM/XML est nécessaire.');
            return false;
        }

        return parent::install()
            && $this->installDatabase()
            && $this->installTab();
    }

    public function uninstall()
    {
        Configuration::deleteByName('RFPF_EXCEL_DEFAULT_CATEGORY');
        Configuration::deleteByName('RFPF_EXCEL_AUTO_CATEGORY');

        return $this->uninstallTab()
            && $this->uninstallDatabase()
            && parent::uninstall();
    }

    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminRfProductFactory'));
    }

    private function installDatabase()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'rfproductfactory_job` (
            `id_rfproductfactory_job` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `id_employee` INT UNSIGNED NOT NULL,
            `source_url` VARCHAR(2048) NOT NULL,
            `source_hash` CHAR(64) NOT NULL,
            `status` VARCHAR(32) NOT NULL,
            `payload_json` MEDIUMTEXT NULL,
            `id_product` INT UNSIGNED NULL,
            `error_message` TEXT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_rfproductfactory_job`),
            KEY `idx_rfpf_source_hash` (`source_hash`),
            KEY `idx_rfpf_id_product` (`id_product`),
            KEY `idx_rfpf_status` (`status`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        $jobTableCreated = Db::getInstance()->execute($sql);

        $imageHashSql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'rfproductfactory_image_hash` (
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

        return $jobTableCreated && Db::getInstance()->execute($imageHashSql);
    }

    private function uninstallDatabase()
    {
        return Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'rfproductfactory_image_hash`'
        ) && Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'rfproductfactory_job`'
        );
    }

    private function installTab()
    {
        if ((int) Tab::getIdFromClassName('AdminRfProductFactory')) {
            return true;
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminRfProductFactory';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminCatalog');

        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'Product Factory';
        }

        return (bool) $tab->add();
    }

    private function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminRfProductFactory');
        if (!$idTab) {
            return true;
        }

        $tab = new Tab($idTab);
        return (bool) $tab->delete();
    }
}
