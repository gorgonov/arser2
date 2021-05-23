<?php

namespace common\services\parsers;

use console\models\ArSite;

class AbstractParserFactory
{
    public static function get(string $moduleName): ?AbstractParser
    {
        if (is_string($moduleName)) {
            if ($moduleName == 'get') {
                $site = ArSite::getSiteToParse();
                if (!$site) {
                    echo 'Нет сайтов для парсинга!' . PHP_EOL;
                    return null;
                }
            } else {
                $site = ArSite::getSiteByName($moduleName);
                if (!$site) {
                    echo 'Site "' . $moduleName . '" not found!' . PHP_EOL;
                    return null;
                }
            }
        }


        $className = sprintf("common\services\parsers\%s", ucfirst($site['modulname']) . "Parser");

        echo $className . PHP_EOL;

        return (new $className($site));

    }
}