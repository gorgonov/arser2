<?php

namespace common\services\parsers;

use console\helpers\ParseUtil;
use console\models\ArSite;
use DiDom\Document;
use DiDom\Exceptions\InvalidSelectorException;
use PhpOffice\PhpSpreadsheet\Exception as OfficeException;
use Throwable;
use Yii;
use Exception;

class DenxParser extends AbstractParser
{
    const DEBUG = false;

    /**
     * @return bool
     * @throws Throwable
     * @throws \yii\db\Exception
     * @throws Exception
     */
    function run(): bool
    {
        if (!isset($this->spreadsheet)) {
            throw new Exception('Не удалось открыть файл ' . $this->linksFileName);
        }

        if (!self::DEBUG) {
            ArSite::delModulData($this->site_id);
            ArSite::setStatus($this->site_id, 'parse');
        }

        // 1. Обработаем 1 лист - ссылки на группы товаров
        $this->runGroupProducts();

        // 2. Обработаем 2 лист - ссылки на товары
        $this->runProducts();

        // 3. Добавим товары со страниц с группами товаров
        $this->addProducts();

        // 4. Парсим товары, пишем в БД
        $this->runItems();

        $messageLog = ["Загружено " . $this->cntProducts . " товаров"];

        Yii::info($messageLog, 'parse_info'); //запись в parse.log

        $this->endprint();

        if (!self::DEBUG) {
            ArSite::setStatus($this->site_id, 'new');
        }

        return true;
    }

    /**
     * @throws OfficeException
     */
    private function runGroupProducts()
    {
        $worksheet = $this->spreadsheet->setActiveSheetIndex(0);
        $highestRow = $worksheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $category = $worksheet->getCell("A" . $row)->getValue();
            if ($category) { // борьба с пустыми строками с таблице
                $category = ParseUtil::dotToComma($category);
                $link = $worksheet->getCell("B" . $row)->getValue();
                $this->print("Добавляем страницу: $link");
                $this->aGroupProducts[] = [
                    'category' => $category,
                    'link' => $link
                ];
            }
        }
        $this->aGroupProducts = ParseUtil::unique_multidim_array($this->aGroupProducts, 'link');
    }

    /**
     * Обработаем 2 лист - ссылки на товары
     * @throws OfficeException
     */
    private function runProducts()
    {
        $worksheet = $this->spreadsheet->setActiveSheetIndex(1);
        $highestRow = $worksheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $category = $worksheet->getCell("A" . $row)->getValue();
            $category = implode(",", preg_split("/[.,]/", $category));// поправка, если разделитель - "."
            $link = $worksheet->getCell("B" . $row)->getValue();
            $this->aProducts[] = [
                'category' => $category,
                'link' => $link
            ];
        }
    }

    /**
     * Добавим товары со страниц с группами товаров
     * @throws InvalidSelectorException
     */
    private function addProducts()
    {
        foreach ($this->aGroupProducts as $item) { // на странице ссылки на продукты
            $cat = $item['category'];
            $link = $item['link'];
            $this->getProducts($link, $cat);
        }
    }

    /**
     * @throws InvalidSelectorException
     */
    private function getProducts($link, $cat)
    {
        $this->print("Качаем страничку $link.");
        $doc = ParseUtil::tryReadLink($link);

        $aProducts = $doc->find('.product-name>a');

        $countProducts = count($aProducts);
        $this->print("Найдено $countProducts продуктов на странице");

        // на странице есть товары с ценниками
        if ($countProducts > 0) {
            $i = 0;
            foreach ($aProducts as $el) {
                $i++;
                $link = $this->link . $el->attr('href');
                $this->print("Обрабатываем страницу: " . $link . "Категория $cat. Продукт $i/$countProducts");
                $this->aProducts[] = [
                    'category' => $cat,
                    'link' => $link
                ];
            }
        }
    }

    /**
     * @param $link
     * @return array
     * @throws InvalidSelectorException
     * @throws Exception
     */
    protected function getProductInfo($link): array
    {
        $this->print("Обрабатываем страницу: $link");
        $doc = ParseUtil::tryReadLink($link);
        if (!$doc) {
            throw new Exception('Не удалось прочитать страницу ' . $link);
        }

        $ar = [];

        $ar["topic"] = trim($doc->first('.site-main__inner h1')->innerHtml()); // Заголовок товара

        if ($doc->has('.product-price>.price-old')) {
            $ar["new_price"] = parseUtil::normalSum($doc->first('.price-old')->text()); // Цена старая
        } else {
            $ar["new_price"] = parseUtil::normalSum($doc->first('.product-price>.price-current')->text()); // Цена новая
        }

        $aImgLink = [];
        $aImg = $doc->find('.light_gallery2'); // список картинок для карусели
        if (!$aImg) {
            $aImg = $doc->find('.product-image>a');
        }
        foreach ($aImg as $el) {
            $href = $el->attr('href');

            if ($href <> '') {
                $aImgLink [] = $this->link . $href;
            }
        }
        $ar["aImgLink"] = $aImgLink;
        $ar["title"] = trim($doc->first('title')->text()); // title страницы

        // формируем таблицу с характеристиками продукта c разметкой
        $tbl = $doc->first('.shop2-product-options')->html();
        $description = ($s=$doc->first('#shop2-tabs-1')) ? trim($s->text()) : '';

        $aTmp = $this->getSizes($tbl . $description);

        if (!is_null($aTmp)) {
            $ar["attr"] = $aTmp;
        }

        $aTmp = $this->getAttr($tbl, ['Описание:', 'Характеристики:']);
        $aTmp[] = $description;
        $ar['product_teh'] = implode("<p>", $aTmp);

        return $ar;
    }

    /**
     * @throws InvalidSelectorException
     * @throws \yii\db\Exception
     * @throws Exception
     */
    private function runItems()
    {
        $product_id = $this->minid;
        $this->cntProducts = 0;
        foreach ($this->aProducts as $el) {
            // todo вынести в AbstractParser (если возможно)
            $lnk = $el['link'];
            $cat = $el['category'];
            $productInfo = $this->getProductInfo($lnk);
            $productInfo['site_id'] = $this->site_id;
            $productInfo['link'] = $lnk;
            $productInfo['category'] = $cat;
            $productInfo['product_id'] = $product_id++;
            $productInfo['model'] = 'Доставим через 3-7 дней';
            $productInfo['manufacturer'] = 'г.Барнаул';
            $productInfo['subtract'] = true;

            ArSite::addProduct($productInfo);
            $this->cntProducts++;
        }
    }

    /**
     * возвращает строку - описание продукта из таблицы
     *
     * @param $table - элемент table, с описанием продукта
     * <table>
     * <tbody>
     * <tr>
     * <th>имя характеристики</th>;
     * <td>значение характеристики</td>;
     * </tr>
     * ...
     * </tbody>
     * </table>
     * @param $attrList - список атрибутов, значения которых надо найти (в первой колонке th)
     * @return array - атрибуты продукта
     *
     * @throws InvalidSelectorException
     */
    protected function getAttr($table, $attrList): array
    {
        if (is_string($table)) {
            $table = new Document($table);
        } else {
            return [];
        }

        if (is_array($attrList)) {
            $aTmp = array();
            foreach ($attrList as $str) {
                $th = $table->first("tr>th:contains('" . $str . "')");
                if ($th) {
                    $td = $th->nextSibling("td");
                    $aTmp[] = $td->text();
                }
            }
            return $aTmp;
        } else {
            $th = $table->first("tr>th:contains('" . $attrList . "')");
            if ($th) {
                return [$th->nextSibling()->text()];
            } else {
                return [];
            }
        }
    }

    /**
     * @param string $str
     * @return array|null
     */
    private function getSizes(string $str): ?array
    {
        // 1. найдем размеры в формате ШхВхГ
        $re = '/(\d+)х(\d+)х(\d+)\s*мм/m';

        if (preg_match_all($re, $str, $matches, PREG_SET_ORDER)) {
            $aTmp["Ширина"] = $matches[0][1];
            $aTmp["Высота"] = $matches[0][2];
            $aTmp["Глубина"] = $matches[0][3];
        }
        // 2. Найдем размеры в формате Ширина:840 мм; Высота:736 мм; Глубина:500 мм.
        if (!isset($aTmp)) {
            $re = '/Ширина\s*[:-]\s*(\d+)[м;\s]*Высота\s*[:-]\s*(\d+)[м;\s]*Глубина\s*[:-]\s*(\d+)[м;\s]*/m';
            if (preg_match_all($re, $str, $matches, PREG_SET_ORDER)) {
                $aTmp["Ширина"] = $matches[0][1];
                $aTmp["Высота"] = $matches[0][2];
                $aTmp["Глубина"] = $matches[0][3];
            }
        }

        return $aTmp ?? null;


    }
}
