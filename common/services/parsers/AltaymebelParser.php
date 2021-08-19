<?php

namespace common\services\parsers;

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

class AltaymebelParser extends AbstractParser
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
        // + добавим пагинацию (формируем массив с группами товаров)
        $this->runGroupProducts();
        // 2. Обработаем 2 лист - ссылки на товары
        $this->runProducts();
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
            $this->addPagination($category, $link);
        }
        $this->aGroupProducts = ParseUtil::unique_multidim_array($this->aGroupProducts, 'link');
    }

    /**
     * add to $this->aGroupProducts[] page with group of products for pagination
     *
     * @param string $category
     * @param string $link
     * @throws InvalidSelectorException
     */
    private function addPagination(string $category, string $link)
    {
        $doc = ParseUtil::tryReadLink($link);
        // находим все ссылки на другие страницы
        $aProducts = $doc->find('.pagination a');
        $countProducts = count($aProducts);
        $oldLink = 'qqqqqqqqqqqqq';
        // на странице есть товары с ценниками
        if ($countProducts > 0) {
            foreach ($aProducts as $el) {
                $link = $this->link . $el->attr('href');
                if ($link <> $oldLink) {
                    echo "Добавляем paging-страницу: {$link}\n";
                    $this->aGroupProducts[] = [
                        'category' => $category,
                        'link' => $link
                    ];
                    $oldLink = $link;
                }
            }
        }
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
//        print_r($this->aProducts);
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

        $aProducts = $doc->find('a.jbimage-link');

        $countProducts = count($aProducts);
        echo "Найдено $countProducts продуктов на странице" . PHP_EOL;

        // на странице есть товары с ценниками
        if ($countProducts > 0) {
            $i = 0;
            foreach ($aProducts as $el) {
                $i++;

                $link = $el->attr('href');
                echo "Обрабатываем страницу: " . $link, "Категория $cat. Продукт $i/$countProducts" . PHP_EOL;
                $this->aProducts[] = [
                    'category' => $cat,
                    'link' => $link
                ];
            }
        }
    }

    private function runItems()
    {
        $product_id = $this->minid;
        foreach ($this->aProducts as $el) {
            $lnk = $el['link'];
            if (empty($lnk)) {
                continue;
            }
            $cat = $el['category'];
            $productInfo = $this->getProductInfo($lnk);

            $productInfo['site_id'] = $this->site_id;
            $productInfo['link'] = $lnk;
            $productInfo['category'] = $cat;
            $productInfo['model'] = 'Доставим через 3-7 дней';
            $productInfo['manufacturer'] = 'г.Барнаул';
            $productInfo['subtract'] = true;

//            print_r($productInfo);
            if (empty($productInfo['image_color'])) {
                $productInfo['product_id'] = $product_id++;

                ArSite::addProduct($productInfo);
                $this->cntProducts++;
            } else {
                $topic = $productInfo['topic'];
                foreach ($productInfo['image_color'] as $item) {
                    $productInfo['topic'] = $topic . ' (' . $item['color'] . ')';
                    $productInfo['aImgLink'][0] = $item['url'];
                    $productInfo['product_id'] = $product_id++;

                    ArSite::addProduct($productInfo);
                    $this->cntProducts++;
                }
            }
        }
    }

    protected function getProductInfo($link)
    {
        echo "Обрабатываем страницу: $link" . PHP_EOL;
        $doc = ParseUtil::tryReadLink($link);
        if (!$doc) {
            return false;
        }

        $ar = array();

        $ar["topic"] = $this->normalText($doc->first('h1.item-title')->text()); // Заголовок товара

        // артикул будет рассчитан при записи в таблицу

        $ar["new_price"] = parseUtil::normalSum($doc->first('.jbcurrency-value')->text()); // Цена новая
        $ar["old_price"] = "";

        $aImgLink = array();
        $aImg = $doc->find('.jbimage-link.jbimage-gallery'); // список картинок для карусели

        foreach ($aImg as $el) {
            $href = $el->attr('href');

            if ($href <> '') {
                $aImgLink [] = $href;
            }
        }
        $ar["aImgLink"] = $aImgLink;

        $temp = $this->normalText($doc->first('title')->text()); // title страницы
        $tmp = preg_split("/—/", $temp); // оставить только до знака "—"
        $ar["title"] = trim($tmp[0]);


        $item_id = $this->getItemId($doc);

        // 1. есть ли выбор цветов для товара
        $aTmp = $doc->find('.namecolor');
        $aColors = array();
        $imageList = [];
        foreach ($aTmp as $el) {
            $aColors [] = $el->text();
            $imageList [] = $this->getImageUrl($item_id, $el->text());
            $ar['image_color'][] = [
                'color' => $el->text(),
                'url' => $this->getImageUrl($item_id, $el->text()),
            ];
        }

        $ar["colors"] = $aColors;

        // формируем таблицу с характеристиками продукта c разметкой
        $product_teh = $doc->first('div.properties');
        $aTmp = [];
        if ($product_teh) {
            $ar["product_teh"] = $product_teh->html();
            $sTmp = $product_teh->first("span:contains('высота')");
            if ($sTmp) { // размеры в одной строке
//        echo "Нашел ".$sTmp->text()." \n";
                $aTmp = $this->getSize($sTmp->text());
            } else { // размеры в отдельных атрибутах
//        echo "НЕ Нашел\n";
                $sTmp = $product_teh->first("p:contains('Высота')");
                if ($sTmp) {
                    $aTmp["Высота"] = $sTmp->text();
                }
                $sTmp = $product_teh->first("p:contains('Ширина')");
                if ($sTmp) {
                    $aTmp["Ширина"] = $sTmp->text();
                }
                $sTmp = $product_teh->first("p:contains('Глубина')");
                if ($sTmp) {
                    $aTmp["Глубина"] = $sTmp->text();
                }
            }
        } else {
            $ar["product_teh"] = "";
        }
        $ar["attr"] = $aTmp;

        return $ar;
    }

    protected function normalText($s)
    {
        // удалим из названия текст "Esandwich"
        $s = trim($s);
        // TODO: убрать?
        $b[] = 'Esandwich.ru';
        $b[] = 'Esandwich';
        $b[] = 'барнаул';
        $b[] = 'есэндвич';

        $s = ParseUtil::utf8_replace($b, '', $s, true);

        return $s;
    }

    protected function getSize($sTmp)
    {
        // убираем неразрывные пробелы
        $sTmp = str_replace(array(" ", chr(0xC2) . chr(0xA0)), ' ', $sTmp);
        $aTmp = explode(" ", $sTmp);
        $aSize = array();
        foreach ($aTmp as $i => $el) {
            if ((int)$el > 0) {
                $key = mb_convert_case($aTmp[$i - 1], 2); // первый символ - заглавный
                $key = preg_replace("/:/i", "", $key);
                $aSize[$key] = $el;
            }
        }
        return $aSize;
    }

    /**
     * @param Document $doc
     * @return string
     * @throws InvalidSelectorException
     */
    private function getItemId(Document $doc): string
    {
        $jbzooItem = $doc->first('.jbzoo-item')->attr('class');

        return preg_replace("/[^0-9]/", '', $jbzooItem);
    }

    private function getImageUrl(string $item_id, string $text)
    {
        $rand = rand(100,900);
        $link = 'https://altaimebel22.ru/?option=com_zoo&task=callelement&element=ebe94140-81ee-472c-a106-9af5fbdd3474&method=ajaxChangeVariant&rand=' . $rand . '&tmpl=component&format=raw&args[template][245332131da0e8ef6191b6268319e534]=full&args[values][72155065-3547-465b-86a6-c3cd4933e5ec][value]=' . $text . '&item_id=' . $item_id;
        $client = new Client(
            [
                'base_uri' => 'https://altaimebel22.ru',
                'timeout' => 2.0,
            ]
        );

        $response = $client->request(
            'POST',
            $link,
            [
                'option' => 'com_zoo',
                'controller' => 'default',
                'task' => 'callelement',
                'element' => 'ebe94140-81ee-472c-a106-9af5fbdd3474',
                'method' => 'ajaxChangeVariant',
                'rand' => $rand,
                'tmpl' => 'component',
                'format' => 'raw',
                'args[template][245332131da0e8ef6191b6268319e534]' => 'full',
                'args[values][72155065-3547-465b-86a6-c3cd4933e5ec][value]' => $text,
                'item_id' => $item_id,
            ]
        );

        $body = $response->getBody();
        $json = json_decode($body);
        $image = $json->image;
        $img = $image->jselementfulllist2->popup;
        return $img;
    }
}
