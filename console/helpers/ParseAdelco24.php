<?php

/**
 * Created by PhpStorm.
 * User: papaha
 * Date: 12.04.2021
 * Time: 16:01
 */

namespace console\helpers;

require_once('vendor/autoload.php');

use console\models\ArSite;
use Yii;
use DiDom\Document;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use common\traits\LogPrint;


class ParseAdelco24
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

        $this->reprint();
        $this->print("Создался " . self::class);

        $messageLog = [
            'status' => 'Старт ' . self::class,
            'post' => $this->name,
        ];

        Yii::info($messageLog, 'parse_info'); //запись в лог
    }

    public function run()
    {
        // 1. Соберем разделы мебели
        $this->runSection();

        $cntProducts = 0;
        $product_id = $this->minid;

        // 1.1 Возможно, есть подгруппы
        foreach ($this->aGroupProducts as $group) {
            $this->runSubSection($group);
        }

        // 2. Пробежимся по группам товаров $aGroupProducts, заполним товары
        foreach ($this->aGroupProducts as $group) {
            $this->runGroup($group);
        }

        print_r($this->aProducts);

        // 3. Записываем в базу продукты
        $this->runItems();

        $messageLog = ["Загружено " . $this->cntProducts . " штук товаров"];
        Yii::info($messageLog, 'parse_info'); //запись в лог

        $this->endprint();
    }

    private function runSection()
    {
        $doc = ParseUtil::tryReadLink($this->link);

        $aProducts = $doc->find(".category a");
        foreach ($aProducts as $el) {
            $link = $this->link  . $el->attr('href');
            $this->aGroupProducts[] = $link;
            $this->print("Добавили ссылку на категорию товаров: " . $link);
        }

        return;
    }

    private function runSubSection(string $link)
    {
        $this->print("Ищем подгруппы в: " . $link);
        $doc = ParseUtil::tryReadLink($link);

        $aProducts = $doc->find("button.all");
        foreach ($aProducts as $el) {
            $link = $this->link . $el->parent()->attr('href');
            $this->aGroupProducts[] = $link;
            $this->print("Добавили ссылку на подкатегорию товаров: " . $link);
        }

        return;
    }

    private function runGroup(string $link)
    {
        $this->print("Обрабатываем группу: " . $link);
        $doc = ParseUtil::tryReadLink($link);

        $aProducts = $doc->find('.items .item a'); // найдем ссылку на товар

        foreach ($aProducts as $el) {
            $link = $this->link . $el->attr('href');
            $this->aProducts[] = $link;
            $this->print("Добавили ссылку на товар: " . $link);
        }

        return;
    }

    private function runItems()
    {
        $product_id = $this->minid;
        foreach ($this->aProducts as $link) {
            $productInfo = $this->getProductInfo($link);
            // $productInfo = $this->getProductInfo($this->aProducts[7]);

//             print_r($productInfo);
//             die;

            $productInfo['link'] = $link;
            $productInfo['site_id'] = $this->site_id;
            $productInfo['category'] = 0;
            $productInfo['product_id'] = $product_id++;
            $productInfo['model'] = '2-3 недели';
            $productInfo['manufacturer'] = 'г.Казань';
            $productInfo['subtract'] = true;
            echo PHP_EOL . 'productInfo=';
            print_r($productInfo);
            if (count($productInfo['aImgLink']) > 0) {
                ArSite::addProduct($productInfo);
                $this->cntProducts++;
            }
        }
    }

    protected function getProductInfo($link)
    {
        $this->print("Обрабатываем страницу: $link");
        $doc = ParseUtil::tryReadLink($link);

        $card = $doc->first(".card .itemProps");

        $ar = array();
        $ar["topic"] = $card->first('h1')->text(); // Заголовок товара
        $ar["new_price"] = $card->first('.price')->text();
        $ar["old_price"] = $card->first('.old_price')->text();

        $ar["aImgLink"] = [];
        $aTmp = $doc->find('.itemImage a');
        foreach ($aTmp as $key => $item) {
            $ar["aImgLink"][] = $this->link . $item->attr("href");
        }

        // описание
        $tmp = '';
        if ($s= $doc->first('div.description')){
            $tmp .= $s->html() ?? '';
        }
        if ($s = $doc->first('div.long_description')) {
            $tmp .= $s->html() ?? '';
        }

        // размеры (см), перевожу в мм
        if ($tmp!='') {
//            $attr = explode("X", $tmp);
            $attr = preg_split("/[X(]+/", $tmp);
            $ar["attr"]["Длина"] = ParseUtil::normalSum($attr[1]) . '0';
            $ar["attr"]["Ширина"] = ParseUtil::normalSum($attr[2]) . '0';
            $ar["attr"]["Высота"] = ParseUtil::normalSum($attr[3]) . '0';
        }

        // ищем комплектацию
        $complects = $doc->find('.item.complect');
        if ($complects) {
            $tmp .= "<h2>Комплектация</h2>";
            foreach ($complects as $key => $value) {
                $tmp .= $value->first('h4')->text() . "<br>";
                $ar["aImgLink"][] = $value->first('span')->attr('data-image');
            }
        }
        $ar["product_teh"] = $tmp;
        // конец описания

        return $ar;

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
            $aTmp1[] = $this->link . $item->attr("href");
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
            $category = implode(",", preg_split("/[.,]/", $category)); // поправка, если разделитель - "."
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
