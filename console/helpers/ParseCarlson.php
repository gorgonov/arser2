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
use common\traits\LogPrint;


class ParseCarlson
{

    use LogPrint;

    protected $special = array(
        3990 => 353,
        4400 => 353,
        5290 => 430,
        5990 => 430,
        9990 => 845,
        9490 => 768,
        10950 => 2500,
        11490 => 2500,
        14990 => 2500,
        6790 => 430
    );
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
        $this->start();

        $this->site_id = $site["id"];
        $this->name = $site["name"];
        $this->link = $site["link"];
        $this->minid = $site["minid"];
        $this->maxid = $site["maxid"];
        $messageLog = [
            'status' => 'Старт ParseCarlson.',
            'post' => $this->name,
        ];

        Yii::info($messageLog, 'parse_info'); //запись в лог

        $this->reprint();
        $this->print("Создался ParseCarlson");
    }

    public function run()
    {
        $cntProducts = 0;
        $product_id = $this->minid;

        $doc = ParseUtil::tryReadLink($this->link);
        $a = $doc->find("div.catalog>a");
        foreach ($a as $key => $el) {
            $lnk = $this->link . "/" . $el->attr("href");
            $productInfo = $this->getProductInfo($lnk);

            $productInfo['site_id'] = $this->site_id;
            $productInfo['link'] = $lnk;
            $productInfo['category'] = "346";
            $productInfo['product_id'] = $product_id++;
            $productInfo['model'] = "Доставим через 10-14 дней";
            $productInfo['manufacturer'] = "г. Ульяновск";
            $productInfo['subtract'] = true;

            ArSite::addProduct($productInfo);
            $cntProducts++;
        }

        $messageLog = ["ParseCarlson: загружено " . $cntProducts . " товаров"];

        Yii::info($messageLog, 'parse_info'); //запись в лог

//        Yii::info($messageLog, 'parse_success'); //отправка в почту

        $this->endprint();
    }

    protected function getProductInfo($link)
    {
        $this->print("Обрабатываем страницу: $link");
        $doc = ParseUtil::tryReadLink($link);

        $product = $doc->first("div.item-desc");
        $ar = array();
        $ar["topic"] = $this->normalText($product->first('h4')->text()); // Заголовок товара
        // артикул будет рассчитан при записи в таблицу
        $price = ParseUtil::normalSum($product->first('div.price')->text()); // Цена новая
        $ar["new_price"] = ParseUtil::normalSum($product->first('div.old-price')->text()); // Цена старая

        if (isset($this->special[$price])) {
            $ar['special'] = $this->special[$price]+$price;
        } else {
            $ar['special'] = -999;
        }

        // формируем таблицу с характеристиками продукта
        $aTmp = $product->find('div.item-desc>p');
        $sTmp = "";
        foreach ($aTmp as $p) {
            $sTmp .= $p->html();
        }

        $ar["product_teh"] = $sTmp; // таблица с характеристиками товара

        // размеры
        $aTmp_ = explode("X", $aTmp[0]);
        $ar["attr"]["Длина"] = ParseUtil::normalSum($aTmp_[0]) . "0";
        $ar["attr"]["Ширина"] = ParseUtil::normalSum($aTmp_[1]) . "0";
        $ar["attr"]["Высота"] = ParseUtil::normalSum($aTmp_[2]) . "0";

        $aTmp_ = explode("X", $aTmp[1]);
        $sTmp = ParseUtil::normalSum($aTmp_[0]) . "0" . "*" . ParseUtil::normalSum($aTmp_[1]) . "0";

        $ar["attr"]["Размер спального места"] = $sTmp;

        $aTmp_ = explode(":", $aTmp[2]->text());
        $sTmp = $aTmp_[1];

        $ar["attr"]["Материал фасадов"] = trim($sTmp); // Материалы

        $sTmp = $doc->first("script:contains('switch')")->text();
//        print_r($sTmp);

        $aTmp = $doc->find('.lightview[data-lightview-group=photo]');
        $aTmp1 = array();
        foreach ($aTmp as $item) {
            $aTmp1 [] = $this->link . $item->attr("href");
        }
/*
        preg_match_all("/<[Aa][\s]{1}[^>]*[Hh][Rr][Ee][Ff][^=]*=[\"]([^\"]+)[^>]*>/", $sTmp, $aTmp);

//        $ar["aImgLink"] = array_unique($aTmp[1]);
        print_r($aTmp);

        $a = array_unique($aTmp[1]);
        echo "\na=";
        print_r($a);
        $ar["aImgLink"] = array_map(function ($el) { return $this->link . $el; }, $a);
*/
        $ar["aImgLink"] = $aTmp1;
//        echo "\naImgLink=";
//        print_r($ar["aImgLink"]);
//        echo "\n---\n";

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

    private function runGroupProducts()
    {
        $worksheet = $this->spreadsheet->setActiveSheetIndex(0);
        $highestRow = $worksheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $category = $worksheet->getCell("A" . $row)->getValue();
//            $category = implode(",", preg_split("/[.,]/", $category));// поправка, если разделитель - "."
            $category = ParseUtil::dotToComma($category);
            $link = $worksheet->getCell("B" . $row)->getValue();
            $this->print("Добавляем страницу: {$link}");
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
                    $this->print("Добавляем paging-страницу: {$link}");
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
        $this->print("Качаем страничку $link.");
        $doc = ParseUtil::tryReadLink($link);

        $aProducts = $doc->find('a.jbimage-link');

        $countProducts = count($aProducts);
        $this->print("Найдено $countProducts продуктов на странице", "Сделано 0/$countProducts");

        // на странице есть товары с ценниками
        if ($countProducts > 0) {
            $i = 0;
            foreach ($aProducts as $el) {
                $i++;

                $link = $el->attr('href');
                $this->print("Обрабатываем страницу: " . $link, "Категория $this->category_id. Продукт $i/$countProducts");
                $this->aProducts[] = [
                    'category' => $cat,
                    'link' => $link
                ];
            }
        }
    }

}