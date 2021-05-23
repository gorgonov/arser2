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

class ParseVmebel
{
    use LogPrint;

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
     * ParseVmrbel constructor.
     */
    public function __construct($site)
    {
        $this->start();

        $this->site_id = $site["id"];
        $this->name = $site["name"];
        $this->link = $site["link"];
        $this->minid = $site["minid"];
        $this->maxid = $site["maxid"];
        $linksFileName = __DIR__ . '\..\..\..\XLSX\vmebelLinks.XLSX';
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $this->spreadsheet = $reader->load($linksFileName);

        $this->print("Создался ParseVmebel");
        $messageLog = [
            'status' => 'Старт ParseVmebel.',
            'post' => $this->name,
        ];

        Yii::info($messageLog, 'parse_info'); //запись в лог
    }

    public function run()
    {
        $cntProducts = 0;
        // 1. Обработаем 1 лист - ссылки на группы товаров
        $this->runGroupProducts();

        // 2. Обработаем 2 лист - ссылки на товары
        $this->runProducts();
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
            $productInfo['product_id'] = $product_id++;
            $productInfo['model'] = 'Доставим через 3-7 дней';
            $productInfo['manufacturer'] = 'г.Красноярск';
            $productInfo['subtract'] = true;
//            $productInfo['subtract'] = Если есть в наличии то true если нет то false

            ArSite::addProduct($productInfo);
            $cntProducts++;
        }

        $messageLog = ["Загружено " . $cntProducts . " товаров"];

        Yii::info($messageLog, 'parse_info'); //запись в лог

        $this->print("Загружено " . $cntProducts . " товаров");
        $this->endprint();

    }

    private function runGroupProducts()
    {
        $worksheet = $this->spreadsheet->setActiveSheetIndex(0);
        $highestRow = $worksheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $category = $worksheet->getCell("A" . $row)->getValue();
            $category = ParseUtil::dotToComma($category);
            $link = $worksheet->getCell("B" . $row)->getValue();
            $this->print("Добавляем страницу: {$link}");
            $this->aGroupProducts[] = [
                'category' => $category,
                'link' => $link
            ];
        }
        $this->aGroupProducts = ParseUtil::unique_multidim_array($this->aGroupProducts, 'link');
    }


    private function runProducts()
    {
        $worksheet = $this->spreadsheet->setActiveSheetIndex(1);
        $highestRow = $worksheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $category = $worksheet->getCell("A" . $row)->getValue();
            $category = implode(",", preg_split("/[.,]/", $category));// поправка, если разделитель - "."
            $link = $worksheet->getCell("B" . $row)->getValue();
            if (trim($link) =='') {
                return false;
            }
            $this->aProducts[] = [
                'category' => $category,
                'link' => $link
            ];
        }
    }

    private function addProducts()
    {
        foreach ($this->aGroupProducts as $item) { // на странице ссылки на продукты
            $cat = $item['category'];
            $link = $item['link'] . "&limit=100";
            $this->getProducts($link, $cat);
        }
    }

    private function getProducts($link, $cat)
    {
        if (trim($link) =='') {
            return false;
        }

        $this->print("Качаем страничку $link.");
        $doc = ParseUtil::tryReadLink($link);

        $aProducts = $doc->find('.products-list__name');

        $countProducts = count($aProducts);
        $this->print("Найдено $countProducts продуктов на странице");

        // на странице есть товары с ценниками
        if ($countProducts > 0) {
            $i = 0;
            foreach ($aProducts as $el) {
                $i++;

                $link = $el->attr('href');
                $this->print("Обрабатываем страницу: " . $link, "Категория $cat. Продукт $i/$countProducts");
                $this->aProducts[] = [
                    'category' => $cat,
                    'link' => $link
                ];
            }
        }
    }

    protected function getProductInfo($link)
    {
        if (trim($link) =='') {
            return false;
        }

        $this->print("Обрабатываем страницу: $link");
        $doc = ParseUtil::tryReadLink($link);
        if (!$doc) {
            return false;
        }

        $ar = array();

        $ar["topic"] = $this->normalText($doc->first('.catalogue__product-name')->innerHtml()); // Заголовок товара

        $ar["new_price"] = parseUtil::normalSum($doc->first('.catalogue__price>span')->text()); // Цена
        if ($ar["new_price"] == "") {
            $ar["new_price"] = $doc->first("span[itemprop='price']")->attr("content");
        }

        $aImgLink = array();
        $aImg = $doc->find('.product-page__img-slider-item>a'); // список картинок для карусели
        foreach ($aImg as $el) {
            $href = $el->attr('href');
            $aImgLink [] = $href;
        }
        $aImgLink = array_unique($aImgLink);

        $ar["aImgLink"] = $aImgLink;

        $dt = $doc->find('.product-info__list>dt');
        $dd = $doc->find('.product-info__list>dd');

        $aTmp = array();
        for ($i = 0; $i < count($dt); $i++) {
            $aTmp[] = trim($dt[$i]->text()) . " " . trim($dd[$i]->text());
        }
        $s = implode("<br>", $aTmp);

        $description = trim($doc->first('.editor')->text());

        // 1. найдем размеры в формате "Высота, мм 2100<br>Глубина, мм 550<br>Ширина, мм 1600"
        $re = '/Высота[^\d]*(\d+).*Глубина[^\d]*(\d+).*Ширина[^\d]*(\d+)/m';
        $str = $s . $description;

        $aTmp = array();
        if (preg_match_all($re, $str, $matches, PREG_SET_ORDER, 0)) {
            $aTmp["Высота"] = $matches[0][1];
            $aTmp["Глубина"] = $matches[0][2];
            $aTmp["Ширина"] = $matches[0][3];
        }

        if (isset($aTmp)) {
            $ar["attr"] = $aTmp;
        }

        $ar['product_teh'] = $s . "<p>" . $description;

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