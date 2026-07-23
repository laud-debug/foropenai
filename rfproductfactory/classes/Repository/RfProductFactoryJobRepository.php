<?php

class RfProductFactoryJobRepository
{
    public function create($sourceUrl, array $payload, $status, $idShop, $idEmployee)
    {
        $now = date('Y-m-d H:i:s');
        $ok = Db::getInstance()->insert('rfproductfactory_job', array(
            'id_shop' => (int) $idShop,
            'id_employee' => (int) $idEmployee,
            'source_url' => pSQL($sourceUrl, true),
            'source_hash' => hash('sha256', $sourceUrl),
            'status' => pSQL($status),
            'payload_json' => pSQL(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true),
            'id_product' => null,
            'error_message' => null,
            'date_add' => $now,
            'date_upd' => $now,
        ), false, true, Db::INSERT);

        if (!$ok) {
            throw new PrestaShopException('Impossible d’enregistrer l’analyse.');
        }

        return (int) Db::getInstance()->Insert_ID();
    }

    public function get($idJob)
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'rfproductfactory_job`
             WHERE `id_rfproductfactory_job` = ' . (int) $idJob
        );

        if (!$row) {
            return null;
        }

        $row['payload'] = array();
        if (!empty($row['payload_json'])) {
            $decoded = json_decode($row['payload_json'], true);
            if (is_array($decoded)) {
                $row['payload'] = $decoded;
            }
        }

        return $row;
    }

    public function markCreated($idJob, $idProduct, array $payload)
    {
        return Db::getInstance()->update('rfproductfactory_job', array(
            'status' => 'created',
            'id_product' => (int) $idProduct,
            'payload_json' => pSQL(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true),
            'error_message' => null,
            'date_upd' => date('Y-m-d H:i:s'),
        ), '`id_rfproductfactory_job` = ' . (int) $idJob, 1, true);
    }

    public function markEnriched($idJob, $idProduct, array $payload)
    {
        return Db::getInstance()->update('rfproductfactory_job', array(
            'status' => 'enriched',
            'id_product' => (int) $idProduct,
            'payload_json' => pSQL(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true),
            'error_message' => null,
            'date_upd' => date('Y-m-d H:i:s'),
        ), '`id_rfproductfactory_job` = ' . (int) $idJob, 1, true);
    }

    public function markError($idJob, $message)
    {
        return Db::getInstance()->update('rfproductfactory_job', array(
            'status' => 'error',
            'error_message' => pSQL($message, true),
            'date_upd' => date('Y-m-d H:i:s'),
        ), '`id_rfproductfactory_job` = ' . (int) $idJob, 1, true);
    }

    public function getActivityStats($idShop)
    {
        $idShop = (int) $idShop;
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        $thirtyDaysStart = date('Y-m-d 00:00:00', strtotime('-30 days'));

        $sqlAnalysesToday = 'SELECT COUNT(*)
            FROM `' . _DB_PREFIX_ . 'rfproductfactory_job`
            WHERE `id_shop` = ' . $idShop . '
              AND `date_add` >= \\' . pSQL($todayStart) . '\\'
              AND `date_add` <= \\' . pSQL($todayEnd) . '\\'';
        $sqlCreatedToday = 'SELECT COUNT(*)
            FROM `' . _DB_PREFIX_ . 'rfproductfactory_job`
            WHERE `id_shop` = ' . $idShop . '
              AND `status` = \'created\'
              AND `date_upd` >= \\' . pSQL($todayStart) . '\\'
              AND `date_upd` <= \\' . pSQL($todayEnd) . '\\'';
        $sqlEnrichedToday = 'SELECT COUNT(*)
            FROM `' . _DB_PREFIX_ . 'rfproductfactory_job`
            WHERE `id_shop` = ' . $idShop . '
              AND `status` = \'enriched\'
              AND `date_upd` >= \\' . pSQL($todayStart) . '\\'
              AND `date_upd` <= \\' . pSQL($todayEnd) . '\\'';
        $sqlErrorsToday = 'SELECT COUNT(*)
            FROM `' . _DB_PREFIX_ . 'rfproductfactory_job`
            WHERE `id_shop` = ' . $idShop . '
              AND `status` = \'error\'
              AND `date_upd` >= \\' . pSQL($todayStart) . '\\'
              AND `date_upd` <= \\' . pSQL($todayEnd) . '\\'';
        $sqlAnalysesLast30Days = 'SELECT COUNT(*)
            FROM `' . _DB_PREFIX_ . 'rfproductfactory_job`
            WHERE `id_shop` = ' . $idShop . '
              AND `date_add` >= \\' . pSQL($thirtyDaysStart) . '\\'';

        return array(
            'analyses_today' => (int) Db::getInstance()->getValue($sqlAnalysesToday),
            'products_created_today' => (int) Db::getInstance()->getValue($sqlCreatedToday),
            'products_enriched_today' => (int) Db::getInstance()->getValue($sqlEnrichedToday),
            'errors_today' => (int) Db::getInstance()->getValue($sqlErrorsToday),
            'analyses_last_30_days' => (int) Db::getInstance()->getValue($sqlAnalysesLast30Days),
        );
    }

    public function getRecentJobs($idShop, $limit)
    {
        $limit = max(1, min(50, (int) $limit));

        return Db::getInstance()->executeS(
            'SELECT j.*, pl.`name` AS product_name
             FROM `' . _DB_PREFIX_ . 'rfproductfactory_job` j
             LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON (pl.`id_product` = j.`id_product`
                    AND pl.`id_lang` = ' . (int) Context::getContext()->language->id . '
                    AND pl.`id_shop` = ' . (int) $idShop . ')
             WHERE j.`id_shop` = ' . (int) $idShop . '
             ORDER BY j.`id_rfproductfactory_job` DESC
             LIMIT ' . $limit
        );
    }

    public function getLatest($idShop, $limit)
    {
        return $this->getRecentJobs($idShop, $limit);
    }
}
