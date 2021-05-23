<?php

namespace console\controllers;

use common\services\parsers\AbstractParserFactory;
use Throwable;
use yii\console\Controller;

// usage: php yii arser <moduleName>
// если не указан модуль, то используется модуль, у кот. status='get'

class ArserController extends Controller
{
    const DEBUG = false;

    /**
     * action default
     *
     * @param string $module
     * @throws Throwable
     */
    public function actionIndex(string $module = 'get')
    {
        $objParser = AbstractParserFactory::get($module);

        if (isset($objParser)) {
            $objParser->run();
        }
    }
}
