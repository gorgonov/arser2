<?php

namespace console\controllers;

use Throwable;
use yii\console\Controller;
use console\models\ArSite;

// usage: yii.bat arser <modulName>

class ArserController extends Controller
{
    const DEBUG = false;

    /**
     * action default
     *
     * @param string $modul
     * @throws Throwable
     */
    public function actionIndex(string $modul = 'get')
    {
        if (is_integer($modul)) {
            $site = ArSite::getSiteById($modul);
        }

        if (is_string($modul)) {
            if ($modul == 'get') {
                $site = ArSite::getSiteToParse();
            } else {
                $site = ArSite::getSiteByName($modul);
            }
        }

        if (!isset($site)) {
            echo 'Site ' . $modul . ' not found!' . PHP_EOL;
            die();
        }

        if (!$site) {
            echo 'Нет сайтов для парсинга!' . PHP_EOL;
            die();
        }

        $oName = "console\helpers\Parse" . ucfirst($site['modulname']);

        if (class_exists($oName)) {
            $oParse = new $oName($site);
            if (!self::DEBUG) {
                ArSite::delModulData($site["id"]);
                ArSite::setStatus($site["id"], 'parse');
            }
            $oParse->run();
            if (!self::DEBUG) {
                ArSite::setStatus($site["id"], 'new');
            }
        }
    }
}
