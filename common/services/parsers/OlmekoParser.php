<?php

namespace common\services\parsers;

use Codeception\Lib\Connector\Guzzle;
use console\helpers\ParseUtil;
use console\models\ArSite;
use DiDom\Document;
use DiDom\Element;
use DiDom\Exceptions\InvalidSelectorException;
use Exception;
use Throwable;
use Yii;
use yii\db\Exception as dbException;
use GuzzleHttp\Client;
use yii\debug\panels\DumpPanel;

class OlmekoParser extends AbstractParser
{

    const DEBUG = false;

    /**
     * @throws InvalidSelectorException
     * @throws dbException
     * @throws Throwable
     */
    public function run()
    {
        if (!self::DEBUG) {
            ArSite::delModulData($this->site_id);
            ArSite::setStatus($this->site_id, 'parse');
        }

        // 1. Обработаем 1 лист - ссылки на группы товаров
        $this->runGroupProducts();
        // 2. Обработаем 2 лист - ссылки на товары
//        $this->runProducts();
        // 3. Добавим товары со страниц с группами товаров
        $this->addProducts();
        // 4. Парсим товары, пишем в БД
        $this->runItems();

        $messageLog = 'Загружено ' . $this->cntProducts . ' товаров';
        Yii::info($messageLog, 'parse_info'); //запись в parse.log
        $this->print($messageLog);
        $this->endprint();

        if (!self::DEBUG) {
            ArSite::setStatus($this->site_id, 'new');
        }
    }

    private function runGroupProducts()
    {
        $worksheet = $this->spreadsheet->setActiveSheetIndex(0);
        $highestRow = $worksheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $category = $worksheet->getCell("A" . $row)->getValue();
            if (empty($category)) {
                continue;
            }
//            $category = implode(",", preg_split("/[.,]/", $category));// поправка, если разделитель - "."
            $category = ParseUtil::dotToComma($category);
            $link = $worksheet->getCell("B" . $row)->getValue();
            echo "Добавляем группу продуктов: {$link}\n";
            $this->aGroupProducts[] = [
                'category' => $category,
                'link' => $link
            ];
        }
        $this->aGroupProducts = ParseUtil::unique_multidim_array($this->aGroupProducts, 'link');
    }

    /**
     * @throws InvalidSelectorException
     */
    protected function runSection(): void
    {
        $doc = ParseUtil::tryReadLink($this->link);

        $aProducts = $doc->find('nav.main-menu a');
        foreach ($aProducts as $el) {
            $link = $el->attr('href');
            if (str_contains($link, 'katalog')) {
                $this->aGroupProducts[] = $link;
                $this->print("Добавили ссылку на группу товаров: " . $link);
            }
        }
    }

    /**
     * @param string $link
     * @param Document $doc
     * @throws InvalidSelectorException
     */
    protected function runGroup(string $link, Document $doc): void
    {
        $this->print("Обрабатываем группу: " . $link);

        list(, $groupClass) = explode("#", $link);
        if (substr($groupClass, -1) == "s") {
            $groupClass = substr($groupClass, 0, -1);
        }
        $groupClass = '.' . $groupClass . '_cat a';

        if (str_contains($groupClass, 'hall_ctgr')) {
            $groupClass = '.hall_cat a';
        }
        $aProducts = $doc->find($groupClass);

        $oldLink = 'qqqqqqqqqqqq';
        foreach ($aProducts as $el) {
            $link = $this->link . $el->attr('href') . 'index.php';
            if ($oldLink != $link) {
                $oldLink = $link;
                $this->aProducts[] = $link;
                $this->print("Добавили ссылку на товары: " . $link);
            }
        }
    }

    private function runProducts()
    {
        $worksheet = $this->spreadsheet->setActiveSheetIndex(1);
        $highestRow = $worksheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $category = $worksheet->getCell("A" . $row)->getValue();
            $category = implode(",", preg_split("/[.,]/", $category));// поправка, если разделитель - "."
            $link = $worksheet->getCell("B" . $row)->getValue();
            echo "Добавляем ссылку на товар: {$link}\n";
            $this->aProducts[] = [
                'category' => $category,
                'link' => $link
            ];
        }
    }

    /**
     * @param Element $el
     * @return array|null
     * @throws InvalidSelectorException
     */
    private function getSizes(Element $el): ?array
    {
        $element = $el->first('.module_element p')
            ?? $el->first('.module_description p');

        if (!$element) {
            return null;
        }

        $str = $element->innerHtml();

        $re = '/(\d+)[\sx]+(\d+)[^\d]+(\d+)/m';
        if (preg_match_all($re, $str, $matches, PREG_SET_ORDER) == 0) {
            return null;
        }

        return $matches[0];
    }

    private function addProducts()
    {
        foreach ($this->aGroupProducts as $item) { // на странице ссылки на продукты
            $cat = $item['category'];
            $link = $item['link'];
            $this->getProducts($link, $cat);
        }
    }

    private function getProducts($link, $cat)
    {
        echo "Качаем страничку $link.\n";
        $doc = ParseUtil::tryReadLink($link);

        $aProducts = $doc->find('a.bx_catalog_item_images.with-img');

        $countProducts = count($aProducts);
        echo "Найдено $countProducts продуктов на странице" . PHP_EOL;

        // на странице есть товары с ценниками
        if ($countProducts > 0) {
            $i = 0;
            $oldlink = "qqqqqqqqq";
            foreach ($aProducts as $el) {
                $i++;

                $link = $this->link . $el->attr('href');
                if ($oldlink != $link) {
                    $oldlink = $link;
                    $this->print("Добавляем продукт: " . $link, "Категория $cat. Продукт $i/$countProducts");
                    $this->aProducts[] = [
                        'category' => $cat,
                        'link' => $link
                    ];
                }
            }
        }
    }

    /**
     * @throws InvalidSelectorException
     * @throws dbException
     */
    private function runItems()
    {
        $offers = [];
        $product_id = $this->minid;
        foreach ($this->aProducts as $el) {
            $lnk = $el['link'];
            if (empty($lnk)) {
                continue;
            }
            $cat = $el['category'];
            $productInfo = $this->getProductInfo($lnk);

            foreach ($productInfo as $element) {
                if (isset($offers[$element['offer_id']])) {
                    continue;
                }
                if ($element['topic']) { // защита от пустых/кривых страниц с товарами (или если товара нет в наличии)
                    $offers[$element['offer_id']] = 1; // запоминаем этот оффер
                    $element['site_id'] = $this->site_id;
                    $element['link'] = $lnk;
                    $element['category'] = $cat;

                    $element['model'] = 'Доставим через 3-7 дней';
                    $element['manufacturer'] = 'Олмеко, г.Балахна';

                    $element['subtract'] = true;
                    $element['product_id'] = $product_id++;

//            print_r($productInfo);
//            die();

                    ArSite::addProduct($element);
                    $this->cntProducts++;
                }
            }
        }
    }

    /**
     * @throws InvalidSelectorException
     */
    protected function getProductInfo($link)
    {
        echo str_repeat('-', 10) . PHP_EOL;
        echo "Обрабатываем страницу: $link" . PHP_EOL;

        $doc = ParseUtil::tryReadLink($link);
        if (!$doc) {
            return false;
        }

        $sTmp = $doc->first('h1.bx-title');
        while (!$sTmp) {
            $this->print("НЕУДАЧА. Ждем 5 секунд.");
            sleep(5);
            $doc = ParseUtil::tryReadLink($link);
            $sTmp = $doc->first('h1.bx-title');
        }

        $ar = [];
        if ($sTmp) {
            $topic = $sTmp->text(); // Заголовок товара
        } else {
            $sTmp = $doc->find('.bx-breadcrumb-item>span');
            $topic = $sTmp[count($sTmp) - 1];
        }
        $description = $this->getDescription($doc);
        $pictureList = $this->getPictureList($doc->text());
        $aTmp = $doc->find('.detailed_color_label');
        foreach ($aTmp as $item) {
            $element = $item->first('input');
            $offer_id = $element->attr('data-id_offer');
            $offer_color = $element->attr('data-tsvet');
            $attr = $this->getAttributes($pictureList[$offer_id]['properties']);
            $ar[] = [
                'offer_id' => $offer_id,
                'topic' => $topic . ' ' . $offer_color,
                'new_price' => 0,
                'product_teh' => $description . $pictureList[$offer_id]['properties'],
                'aImgLink' => $pictureList[$offer_id]['picture'],
                'attr' => $attr,
            ];
        }

        return $ar;
    }

    /**
     * @param Document $doc
     * @return string
     * @throws InvalidSelectorException
     */
    private function getDescription(Document $doc)
    {
        if (!$doc->first('.detailed_tabs li:contains("Описание")')) {
            return '';
        }

        return ParseUtil::convertEntities($doc->first('.detailed_tabs div.tabs__content:not(.modul)')->html());
    }

    /**
     * @param string $str
     * @param string $propName
     * @return ?array
     */
    private function getPictureList(string $str): ?array
    {
        $re = '/new JCCatalogElement\((.+\})\);/m';
        preg_match_all($re, $str, $matches, PREG_SET_ORDER);
        $json = $matches[0][1];
        $json = str_replace("'", '"', $json);
        $json = json_decode($json, true);

        $offers = $json['OFFERS'];

        $productList = [];
        foreach ($offers as $offer) {
            $id = $offer['ID'];
            $productList[$id]['properties'] = ParseUtil::convertEntities($offer['DISPLAY_PROPERTIES']);
            foreach ($offer['SLIDER'] as $picture) {
                $productList[$id]['picture'][] = $this->link . $picture['SRC'];
            }
        }

        return $productList;
    }

    /**
     * @param string $attrName
     * @param string $str
     * @return array|false
     */
    private function getAttribute(string $attrName, string $str)
    {
        $re = '/<dt>' . $attrName . '[^<]+<\/dt><dd>(\d+)<\/dd>/m';

        if (!preg_match_all($re, $str, $matches, PREG_SET_ORDER, 0)) {
            return false;
        }

        return [$attrName => $matches[0][1]];
    }

    /**
     * @param string $description
     * @return array
     */
    private function getAttributes(string $description): array
    {
        $result = [];
        if ($aTmp = $this->getAttribute('Высота', $description)) {
            $result = array_merge($result, $aTmp);
        }
        if ($aTmp = $this->getAttribute('Ширина', $description)) {
            $result = array_merge($result, $aTmp);
        }
        if ($aTmp = $this->getAttribute('Глубина', $description)) {
            $result = array_merge($result, $aTmp);
        }

        return $result;
    }
}
