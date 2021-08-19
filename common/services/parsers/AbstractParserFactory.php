<?php

namespace common\services\parsers;

use console\models\ArSite;
use Exception;

class AbstractParserFactory
{
    /**
     * @param string $moduleName
     * @return AbstractParser
     * @throws Exception
     */
    public static function get(string $moduleName): AbstractParser
    {
        $site = ($moduleName == 'get') ? ArSite::getSiteToParse() : ArSite::getSiteByName($moduleName);

        if (!$site) {
            throw new Exception(self::getTextException($moduleName));
        }

        $className = sprintf("common\services\parsers\%s", ucfirst($site['modulname']) . "Parser");

        return (new $className($site));
    }

    /**
     * @param string $moduleName
     * @return string
     */
    private static function getTextException(string $moduleName): string
    {
        if ($moduleName == 'get') {
            return 'Нет сайтов для парсинга!';
        }

        return 'Site "' . $moduleName . '" not found!';
    }
}
