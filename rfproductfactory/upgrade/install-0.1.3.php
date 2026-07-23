<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_1_3($module)
{
    $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'rfproductfactory_image_hash` (
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

    return (bool) Db::getInstance()->execute($sql);
}
