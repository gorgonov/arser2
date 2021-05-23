<?php
/**
 * Created by PhpStorm.
 * User: papaha
 * Date: 15.10.2019
 * Time: 20:08
 */

namespace console\helpers;

require_once('vendor/autoload.php');

use console\models\ArSite;
use Yii;
use DiDom\Document;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ParseAltaymebel
{
    protected $oProductsSheet;
    protected $aProducts = [];
    protected $aGroupProducts = [];
    private $id;
    private $name;
    // объекты
    private $link;
    private $minid;
    private $maxid;    // массив ссылок на продукты
    private $spreadsheet;    // массив ссылок на страницы с продуктами

    /**
     * ParseDenx constructor.
     */
    public function __construct($site)
    {
        $this->site_id = $site["id"];
        $this->name = $site["name"];
        $this->link = $site["link"];
        $this->minid = $site["minid"];
        $this->maxid = $site["maxid"];
        $linksFileName = __DIR__ . '\..\..\..\XLSX\AltaymebelLinks.xlsx';
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $this->spreadsheet = $reader->load($linksFileName);

        echo "Создался ParseAltaymebel\n";
        $messageLog = [
            'status' => 'Старт ParseAltaymebel.',
            'post' => $this->name,
        ];

        Yii::info($messageLog, 'parse_info'); //запись в лог
    }

    public function run()
    {
        $cntProducts = 0;

        // 1. Обработаем 1 лист - ссылки на группы товаров
        // + добавим пагинацию (формируем массив с группами товаров)
        $this->runGroupProducts();
        // 2. Обработаем 2 лист - ссылки на товары
        $this->runProducts();
        // 3. Добавим товары со страниц с группами товаров
        $this->addProducts();
        print_r($this->aProducts);
        // 4. Парсим товары, пишем в БД
        $product_id = $this->minid;
        foreach ($this->aProducts as $el) {
            $lnk = $el['link'];
            $cat = $el['category'];
            $productInfo = $this->getProductInfo($lnk);
            if (count($productInfo["colors"])>0) {
                $productInfo['ProductOptions'] = 'Цвет';
            }
            $productInfo['site_id'] = $this->site_id;
            $productInfo['link'] = $lnk;
            $productInfo['category'] = $cat;
            $productInfo['product_id'] = $product_id++;
            $productInfo['model'] = 'Доставим через 3-7 дней';
            $productInfo['manufacturer'] = 'г.Барнаул';
            $productInfo['subtract'] = true;
//            $productInfo['subtract'] = Если есть в наличии то true если нет то false

//            print_r($productInfo);
            ArSite::addProduct($productInfo);
            $cntProducts++;
        }

        $messageLog = ["Загружено " . $cntProducts . " штук товаров"];

        Yii::info($messageLog, 'parse_info'); //запись в лог

    }

    private function runGroupProducts()
    {
        $worksheet = $this->spreadsheet->setActiveSheetIndex(0);
        $highestRow = $worksheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $category = $worksheet->getCell("A" . $row)->getValue();
//            $category = implode(",", preg_split("/[.,]/", $category));// поправка, если разделитель - "."
            $category = ParseUtil::dotToComma($category);
            $link = $worksheet->getCell("B" . $row)->getValue();
            echo "Добавляем страницу: {$link}\n";
            $this->aGroupProducts[] = [
                'category' => $category,
                'link' => $link
            ];
            $this->addPagination($category, $link);
        }
        $this->aGroupProducts = ParseUtil::unique_multidim_array($this->aGroupProducts, 'link');
        print_r($this->aGroupProducts);
//        die();
    }

    /**
     * add to $this->aGroupProducts[] page with group of products for pagination
     * @param string $category
     * @param string $link
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
//            echo "A" . $row ."=". $cA . "; B" . $row . "=" . $cB . "\n";
        }
        print_r($this->aProducts);
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
        echo "Найдено $countProducts продуктов на странице", "Сделано 0/$countProducts\n";

        // на странице есть товары с ценниками
        if ($countProducts > 0) {
            $i = 0;
            foreach ($aProducts as $el) {
                $i++;

                $link = $el->attr('href');
                echo "Обрабатываем страницу: " . $link, "Категория $this->category_id. Продукт $i/$countProducts\n";
                $this->aProducts[] = [
                    'category' => $cat,
                    'link' => $link
                ];
            }
        }
    }

    protected function getProductInfo($link)
    {
        echo "Обрабатываем страницу: $link\n";
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

        $aTmp = $doc->find('.namecolor');
        $aColors = array();
        foreach ($aTmp as $el) {
            $aColors [] = $el->text();
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
        print_r("sTmp=" . $sTmp . "\n");
        $aTmp = explode(" ", $sTmp);
//    echo '<pre>';
//    var_dump($aTmp);
//    echo '</pre>';
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

}