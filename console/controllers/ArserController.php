<?php

namespace console\controllers;

use common\services\parsers\AbstractParserFactory;
use yii\console\Controller;
use Exception;

// usage: php yii arser <moduleName>
// если не указан модуль, то используется модуль, у кот. status='get'
class ArserController extends Controller
{
    /**
     * @param string $module
     * @throws Exception
     */
    public function actionIndex(string $module = 'get')
    {
        try {
            $objParser = AbstractParserFactory::get($module);

            if (isset($objParser)) {
                $objParser->run();
            }
        } catch (Exception $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }
}
