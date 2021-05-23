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

class ParseBig
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
     * ParseBig constructor.
     */
    public function __construct($site)
    {
        $this->site_id = $site["id"];
        $this->name = $site["name"];
        $this->link = $site["link"];
        $this->minid = $site["minid"];
        $this->maxid = $site["maxid"];
        $linksFileName = __DIR__ . '\..\..\..\XLSX\BigLinks.xlsx';
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $this->spreadsheet = $reader->load($linksFileName);

        echo "Создался ParseBig\n";
        $messageLog = [
            'status' => 'Старт ParseBig.',
            'post' => $this->name,
        ];

        Yii::info($messageLog, 'parse_info'); //запись в лог
    }

    public function run()
    {
        $cntProducts = 0;
        // 1. Обработаем 1 лист - ссылки на группы товаров
        $this->runGroupProducts();
//        echo "aGroupProducts=";
//        print_r($this->aGroupProducts);

        // 2. Обработаем 2 лист - ссылки на товары
        $this->runProducts();
//        echo "\naProducts=";
//        print_r($this->aProducts);
//        die();
        // 3. Добавим товары со страниц с группами товаров
        $this->addProducts();
        // 4. Парсим товары, пишем в БД
        $product_id = $this->minid;
        foreach ($this->aProducts as $el) {
            $lnk = $el['link'];
            $cat = $el['category'];
            $productInfo = $this->getProductInfo($lnk);

            $productInfo['site_id'] = $this->site_id;
            $productInfo['link'] = $lnk;
            $productInfo['category'] = $cat;

            $productInfo['model'] = 'Доставим через 7-10 дней';
            $productInfo['manufacturer'] = 'г. Красноярск';
            $productInfo['subtract'] = true;

            $aImgLink = $productInfo['aImgLink'];
            $DopImgLink = $productInfo['DopImgLink'];
            $colorList = $productInfo['colorList'];
            $dimensions = $productInfo['dimensions'];
            $description = $productInfo['description'];
            unset(
                $productInfo['DopImgLink'],
                $productInfo['colorList'],
                $productInfo['dimensions'],
                $productInfo['description']
            );

//            print_r($aImgLink);
            foreach ($aImgLink as $i => $item) {
                echo "{$product_id}\n";
                $productInfo['product_id'] = $product_id++;
                if ($DopImgLink) {
                    $productInfo['aImgLink'] = array($aImgLink[$i], $DopImgLink);
                } else {
                    $productInfo['aImgLink'] = array($aImgLink[$i]);
                }

                $productInfo['product_teh'] =
                    $dimensions .
                    "<p>Цвет: " . $colorList[$i] .
                    "<p>" . $description;
                ArSite::addProduct($productInfo);
                $cntProducts++;
            }
//            echo "---\n";
//            die();

//            $productInfo['subtract'] = Если есть в наличии то true если нет то false

//            print_r($productInfo);
//            die();

        }

        $messageLog = ["Загружено " . $cntProducts . " товаров"];

        Yii::info($messageLog, 'parse_info'); //запись в лог

    }

    private function runGroupProducts()
    {
        $worksheet = $this->spreadsheet->setActiveSheetIndex(0);
        $highestRow = $worksheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $category = $worksheet->getCell("A" . $row)->getValue();
            $category = ParseUtil::dotToComma($category);
            $link = $worksheet->getCell("B" . $row)->getValue();
            echo "Добавляем страницу: {$link}\n";
            $this->aGroupProducts[] = [
                'category' => $category,
                'link' => $link
            ];
        }
        $this->aGroupProducts = ParseUtil::unique_multidim_array($this->aGroupProducts, 'link');
//        print_r($this->aGroupProducts);
//        die();
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
        }
//        print_r($this->aProducts);
//        die();
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

        $aProducts = $doc->find('.list-item>div>a');

        $countProducts = count($aProducts);
        echo "Найдено $countProducts продуктов на странице\n";

        // на странице есть товары с ценниками
        if ($countProducts > 0) {
            $i = 0;
            foreach ($aProducts as $el) {
                $i++;

                $link = $this->link . $el->attr('href');
                echo "Обрабатываем страницу: " . $link, "Категория $cat. Продукт $i/$countProducts\n";
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

        $ar["topic"] = trim($doc->first('.wa > h3')->text()); // Заголовок товара

        // артикул будет рассчитан при записи в таблицу

        if ($doc->has('.goods-list>s')) {
            $ar["new_price"] = parseUtil::normalSum($doc->first('.goods-list>s')->text()); // Цена старая
        } elseif ($doc->has('.newprice')) {
            $ar["new_price"] = parseUtil::normalSum($doc->first('.newprice')->text()); // Цена новая
        } elseif ($doc->has('div.goods-list>h3')) {
            $ar["new_price"] = parseUtil::normalSum($doc->first('div.goods-list>h3')->text()); // Цена единственная
        }

        $aImgLink = array();
        $aImg = $doc->find('.gphoto'); // список картинок для карусели

//        if (!$aImg) {
//            $aImg = $doc->find('.product-image>a');
//        }

        foreach ($aImg as $el) {
            $href = $el->attr('src');
            $pattern = "/(.+\/)(\d+)s(.+)/m";
            $replace = "$1$2$3";

            if (preg_match($pattern, $href)) {
//                echo "{$href} берем\n";
                $text = preg_filter($pattern, $replace, $href);
                $aImgLink [] = $this->link . $text;
            } else {
//                echo "{$href} пропускаем\n";
            }
        }

//        var_dump($aImgLink);
//        die();
//
        $ar["aImgLink"] = $aImgLink;

        if ($doc->has("td.wa>img")) {
            $ar["DopImgLink"] = $doc->first("td.wa>img")->attr("src");
        }

//        $ar["title"] = $this->normalText($doc->first('title')->text()); // title страницы

        // цвета
        $aColors = array();
        $aTmp = $doc->find('#id-364-oval-1>option');
        foreach ($aTmp as $item) {
            $aColors[] = trim($item->text());
        }

        $ar["colorList"] = $aColors;

        $re = '/(\d+)[^\d]+(\d+)[^\d]+(\d+)/m';
        $str = trim($doc->first('.shop-options')->text());

        if (preg_match_all($re, $str, $matches, PREG_SET_ORDER, 0)) {
            $ar["dimensions"] = "Габариты: {$matches[0][1]}x{$matches[0][2]}x{$matches[0][3]}";
            $ar["attr"] = array(
                "Ширина" => $matches[0][1],
                "Глубина" => $matches[0][2],
                "Высота" => $matches[0][3]
            );

//            var_dump($matches);
        } else {
            var_dump($matches);
            echo "\n{$str}\nГабариты определить не удалось";
            die();
        }

// Print the entire match result
        $ar["description"] = trim($doc->first(".shop-info")->text());

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
     */
    protected function getAttr($table, $attrList)
    {
        if (is_string($table)) {
            $table = new Document($table);
        }

        if (is_array($attrList)) {
            $aTmp = array();
            foreach ($attrList as $str) {
                $th = $table->first("tr>th:contains('" . $str . "')");
                if ($th) {
                    $td = $th->nextSibling("td"); // $td->parent()->find('td')[1]->text();
                    $aTmp[] = $td->text(); // $td->parent()->find('td')[1]->text();
                } else {
                }
            }
            return $aTmp;
        } else {
            $th = $table->first("tr>th:contains('" . $attrList . "')");
            if ($th) {
                return $th->nextSibling()->text();
            } else {
                return "";
            }
        }
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