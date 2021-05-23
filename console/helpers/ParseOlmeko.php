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
use DateTime;
use common\traits\LogPrint;

class ParseOlmeko
{
    use LogPrint;

    protected $oProductsSheet;
    protected $aProducts = [];
    protected $product_id;
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
        $this->site_id = $site["id"];
        $this->name = $site["name"];
        $this->link = $site["link"];
        $this->minid = $site["minid"];
        $this->maxid = $site["maxid"];
        $linksFileName = __DIR__ . '/../../../XLSX/OlmekoLinks.XLSX';
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $this->spreadsheet = $reader->load($linksFileName);

        $messageLog = [
            'status' => 'Старт ParseOlmeko.',
            'post' => $this->name,
        ];

        Yii::info($messageLog, 'parse_info'); //запись в лог

        $this->reprint();
        $this->print("Создался ParseOlmeko");

    }

    public function run()
    {

        $cntProducts = 0;
        $this->product_id = $this->minid;

        // 1. Обработаем 1 лист - ссылки на группы товаров
        $this->runGroupProducts();

        // 2. Добавим товары со страниц с группами товаров
        $this->addProducts();

        // 4. Парсим товары, пишем в БД
        $product_id = $this->minid;
        foreach ($this->aProducts as $el) {
            $lnk = $el['link'];
            $cat = $el['category'];
            $productInfo = $this->getProductInfo($lnk);

            if ($productInfo['topic']) { // защита от пустых/кривых страниц с товарами (или если товара нет в наличии)
                $productInfo['site_id'] = $this->site_id;
                $productInfo['link'] = $lnk;
                $productInfo['category'] = $cat;

                $productInfo['model'] = 'Доставим через 3-7 дней';
                $productInfo['manufacturer'] = 'Олмеко, г.Балахна';

                $productInfo['subtract'] = true;
                $productInfo['product_id'] = $product_id++;

//            print_r($productInfo);
//            die();

                ArSite::addProduct($productInfo);
                $cntProducts++;
            }

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
            if ($category) { // защита от пустых строк
                $category = ParseUtil::dotToComma($category);
                $link = $worksheet->getCell("B" . $row)->getValue();
                $this->print("Добавляем группу продуктов: {$link}");
                $this->aGroupProducts[] = [
                    'category' => $category,
                    'link' => $link
                ];
            }
        }
        $this->aGroupProducts = ParseUtil::unique_multidim_array($this->aGroupProducts, 'link');
//        print_r($this->aGroupProducts);
//        die();
    }


    private function addProducts()
    {
        foreach ($this->aGroupProducts as $item) { // на странице ссылки на продукты
            $cat = $item['category'];
            $link = $item['link'] . "/?cnt=500"; // ходим показать все продукты, 500 хватит?
            $this->getProducts($link, $cat);
        }
//        $this->getProducts = ParseUtil::unique_multidim_array($this->getProducts, 'link');
    }


    private function getProducts($link, $cat)
    {
        $this->print("Качаем страничку $link.");
        $doc = ParseUtil::tryReadLink($link);

        $aProducts = $doc->find('a.bx_catalog_item_images.with-img');

        $countProducts = count($aProducts);
        $this->print("Найдено $countProducts продуктов на странице");

        // на странице есть товары с ценниками
        if ($countProducts > 0) {
            $i = 0;

            $oldlink = "qqqqqqqqq";
            foreach ($aProducts as $el) {
                $i++;

                $link = $this->link . $el->attr('href');
                if ($oldlink != $link) {
                    $oldlink = $link;
                    $this->print("Обрабатываем страницу: " . $link, "Категория $cat. Продукт $i/$countProducts");
                    $this->aProducts[] = [
                        'category' => $cat,
                        'link' => $link
                    ];
                }
            }
        }
    }

    protected function getProductInfo($link)
    {
        $this->print( "Обрабатываем страницу: $link");
        $doc = ParseUtil::tryReadLink($link);
        if (!$doc) {
            return false;
        }


        $sTmp = $doc->first('h1.bx-title');
        while (! $sTmp) {
            $this->print("НЕУДАЧА. Ждем 5 секунд.");
            sleep(5);
            $doc = ParseUtil::tryReadLink($link);
            $sTmp = $doc->first('h1.bx-title');
        }

        $ar = array();

        if ($sTmp) {
            $ar["topic"] = $sTmp->text(); // Заголовок товара
        } else {
            $sTmp = $doc->find('.bx-breadcrumb-item>span');
            $ar["topic"] = $sTmp[count($sTmp)-1];
        }

        $this->print("Продукт: {$ar["topic"]}");
        $this->print("Ссылка {$link}");
        $aTmp = explode("/",$link);

        $sTmp = trim($aTmp[count($aTmp)-2]);            // П00112060
        $this->print("Последняя палочка:".$sTmp);
        $sOffer = $doc->first("input[data-pagetype='{$sTmp}']")->attr("data-id_offer");
        $this->print("Оффер:".$sOffer);

        if (!$ar["topic"]) {
            print_r($doc);
            die();
        }

        $cod = $sOffer;
        $this->print("Код продукта: {$cod}");
        if ($cod){
            $ar["new_price"] = parseUtil::normalSum($doc->first("div.detailed_price_wr[data-id_offer='{$cod}']>div.detailed_price")); // Цена новая
            $sTmp = "div.detailed_l[data-id_offer='{$cod}'] .detailed_big-slider img";
            $this->print("Строка поиска: {$sTmp}");
        } else {
            $ar["new_price"] = parseUtil::normalSum($doc->first("div.detailed_price")); // Цена новая
            $sTmp = "div.detailed_l .detailed_big-slider img"; // код неизвестен
        }

        $this->print("Код = {$cod}");

        $aImgLink = array();  // список картинок для карусели
        $aImg = $doc->find($sTmp);

        $i = 0;
        $this->print("Нашли картинки ". count($aImg));
        foreach ($aImg as $el) {
            $href = $this->link . "/" . $el->attr('src');
            $i++;
            print_r("Картинка {$i}:".$href . "\n");
            if ($href <> '') {
                $aImgLink [] = $href;
            }
        }

        $ar["aImgLink"] = array_unique($aImgLink); // удалим дубли

        if (!$cod){
            die();
        }

        // атрибуты товара
        $ar["attr"] = [];
        $list_li = $doc->find('.detailed_characteristic_li');
        if ($list_li) {
            foreach ($list_li as $li) {
                $el = $li->text();
                $aTmp = explode(":", $el); // разобрали строки по знаку ":"
                foreach ($aTmp as $item) {
                    $name = $aTmp[0];
                    $value = parseUtil::normalSum($aTmp[1]); // значение
                    $ar["attr"][$name] = $value;
                }
            }
        }

        $aTmp = $doc->find('.tabs__content');
        $ar["product_teh"] = $aTmp[0]->text() . "<p>" . $aTmp[1]->html();

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

        // убираем все, начиная с (
        $re = '/[^(]+/m';
        preg_match_all($re, $s, $matches, PREG_SET_ORDER, 0);
        $s = $matches[0][0];

        return $s;
    }

    /**
     * @param $newDate
     * @return количество дней до даты $newDate
     */
    protected function dayCount($newDate)
    {
        $now = new DateTime(); // текущее время на сервере
        $date = DateTime::createFromFormat("d.m.Y", $newDate); // задаем дату в любом формате
        $interval = $now->diff($date); // получаем разницу в виде объекта DateInterval
        return $interval->days;
    }

// еще раз уточним. Я иду по твоим ссылкам и пропускаю товары, которые не проходят фильтр: в тексте BTS, Лаворо, Рики, Ричи, Ральф, Tr, Em, Вэлкам, ТрЯ или производители из городов Волгодонск, Калининград, Ростов-на-Дону? Все так?

    /**
     * возвращает адрес ссылки на картинку
     * @param $str - сырой адрес ссылки на картинку
     * @return string - уже обработанный адрес ссылки на картинку, можно уже качать картинку.
     */
    protected function getImageSrc($str)
    {
        $str = str_replace("bmp.jpg", "bmp", $str); // предобработка при двух расширениях

        $ret = '';

        $re = '/p\d+_[\d\w\.]+/';
        $re2 = '/(\/upload\/).+(iblock\/[\d\w]*\/).+_([\d\w]+\.bmp)/';
        $re1 = '/(\/upload\/).+(iblock\/[\d\w]*\/).+_([\d\w]+\.jpg)/';

        if (preg_match($re, $str, $matches)) {
            $ret = 'https://esandwich.ru/upload/iblock/' . $matches[0];
            $this->print("!!! Совпало 1");
        } elseif (preg_match($re2, $str, $matches)) {
            $ret = 'https://esandwich.ru' . $matches[1] . $matches[2] . $matches[3];
            $this->print("!!! Совпало bmp");
        } elseif (preg_match($re1, $str, $matches)) {
            $ret = 'https://esandwich.ru' . $matches[1] . $matches[2] . $matches[3];
            $this->print("!!! Совпало jpg");
        } else {
            $ret = '';
        }

        return htmlspecialchars($ret);
    }

    /**
     *
     * @param $table - didom-объект на таблицу с характеристиками товара
     * по умолчанию используется
     *
     * @return возвращает таблицу с характеристиками в формате:
     * <table>
     * <tr>
     * <td class="feature_name">имя характеристики</td>;
     * <td>значение характеристики</td>;
     * </tr>
     * ...
     * </table>
     */
    protected function getAttrAll($table)
    {
        $aTmp = $table->find("tr>td.feature_name");
        $result = "<table class='desc-table'>";
        foreach ($aTmp as $td) {
            $td_name = $td->text();
            $td_value = $td->nextSibling("td")->text();  // $td_name->parent()->find('td')[1]->text();

            $result .= "<tr>";
            $result .= "<td class=\"feature_name\">" . trim($td_name) . "</td>";
            $result .= "<td>" . trim($td_value) . "</td>";
            $result .= "</tr>";
        }
        $result .= "</table>";
        return $result;
    }

    /**
     * возвращает массив - атрибуты продукта
     *
     * @param $table - элемент table, с описанием продукта
     * <table>
     * <tr>
     * <td class="feature_name">имя характеристики</td>;
     * <td>значение характеристики</td>;
     * </tr>
     * ...
     * </table>
     * @param $attrList - список атрибутов, значения которых надо найти
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
                $td = $table->first("tr>td.feature_name:contains('" . $str . "')");
                if ($td) {
                    $td2 = $td->nextSibling("td"); // $td->parent()->find('td')[1]->text();
                    $aTmp[$str] = $td2->text(); // $td->parent()->find('td')[1]->text();
                } else {
                }
            }
            return $aTmp;
        } else {
            $td = $table->first("tr>td.feature_name:contains('" . $attrList . "')");
            if ($td) {
                return $td->nextSibling()->text();
            } else {
                return "";
            }
        }
    }

    protected function arrayInStr(array $ar, string $s): bool
    {
        $m = false; //ставлю флаг
        foreach ($ar as $slovo) {
            if (strpos($s, $slovo) !== false) {
                $m = true; // если слова найдены то переключаю на ИСТИНА
            }
        }

        return $m;
    }

    /**
     * @param array $pInfo - информация о продукте
     * @return int          - номер кучки. (-1 -игнорировать, любое положительное - номер кучки (будет прибавлено к baseID)
     */
    protected function getBatch(array $pInfo): int
    {
        // игнорируем
        if ($this->arrayInStr(array('Лаворо', 'bts'), mb_strtolower($pInfo["topic"]))) {
            return -1;
        }

        if ($this->arrayInStr(array('Рики', 'Ричи', 'Ральф', 'Вэлкам', 'Асти', 'Мокко', 'Оливьер'), $pInfo["topic"])) {
            return 1;
        }

        if ($this->arrayInStr(array('ИЦ'), $pInfo["topic"])) {
            return 2;
        }

        if ($this->arrayInStr(array('DSV'), $pInfo["topic"])) {
            return 3;
        }

        if ($this->arrayInStr(array('-Ол', 'ОлФ'), $pInfo["topic"])) {
            return 4;
        }

        if ($this->arrayInStr(array('г.Волгодонск', 'Tr'), $pInfo["topic"])) {
            return 5;
        }

        if ($this->arrayInStr(array('Gnt'), $pInfo["topic"])) {
            return 6;
        }

        if ($this->arrayInStr(array('г.Глазов'), $pInfo["topic"])) {
            return 7;
        }

        // теперь по городу производства
        if ($this->arrayInStr(array('г.Волгодонск'), $pInfo["manufacturer"])) {
            return 5;
        }

        if ($this->arrayInStr(array('г.Глазов'), $pInfo["manufacturer"])) {
            return 7;
        }

        return 0;
    }

    /**
     * @param $pInfo - массив с информацией о продукте
     * @return bool - истина, если продукт подходит под условия фильтра
     */
    protected function filter($pInfo)
    {

        $ignoreList = array(
            'BTS',
            'Лаворо',
            'Рики',
            'Ричи',
            'Ральф',
            'Tr',
            'Em',
            'Вэлкам',
            'ТрЯ',
        );

        $ignoreCity = array(
            'Волгодонск',
            'Калининград',
            'Ростов-на-Дону',
        );

        if (in_array($pInfo["topic"], $ignoreList)) {
            return false;
        } // игнорим

        // производитель в черном списке - игнорим
        if (in_array($pInfo["manufacturer"], $ignoreCity)) {
            return false;
        }

        return true; // прошел все испытания - берем
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