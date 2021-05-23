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
use GuzzleHttp\Client;
use DiDom\Query;

class ParseEsandwich
{
    use LogPrint;

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
        $linksFileName = __DIR__ . '\..\..\..\XLSX\esandwichLinks.XLSX';
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $this->spreadsheet = $reader->load($linksFileName);

        $messageLog = [
            'status' => 'Старт ParseEsandwich.',
            'post' => $this->name,
        ];

        Yii::info($messageLog, 'parse_info'); //запись в лог

        $this->reprint();
        $this->print("Создался ParseEsLinks");
    }

    public function run()
    {
        // проставим признак parse
        for ($i=15;$i<=22;$i++) {
            ArSite::setStatus($i,'parse');
        }
        $this->product_id = $this->minid;

        // 1. Обработаем 1 лист - ссылки на группы товаров
        $this->runGroupProducts();

        // 2. Обработаем 2 лист - ссылки на товары
        $this->runProducts();

        // 3. Добавим товары
        $this->addProducts();

        $messageLog = ["Загружено " . count($this->aProducts) . " товаров"];

        Yii::info($messageLog, 'parse_info'); //запись в лог

        $this->endprint();

        // проставим признак new
        for ($i=15;$i<=22;$i++) {
            ArSite::setStatus($i,'new');
        }

    }

    private function runGroupProducts()
    {
        $worksheet = $this->spreadsheet->setActiveSheetIndex(0);
        $highestRow = $worksheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $category = $worksheet->getCell("A" . $row)->getValue();
            if ($category) { // борьба с пустыми строками с таблице
                $category = ParseUtil::dotToComma($category);
                $link = $worksheet->getCell("B" . $row)->getValue();
                $this->print("Добавляем страницу: {$link}");
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

    private function runProducts()
    {
        $worksheet = $this->spreadsheet->setActiveSheetIndex(1);
        $highestRow = $worksheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $category = $worksheet->getCell("A" . $row)->getValue();
            $category = implode(",", preg_split("/[.,]/", $category));// поправка, если разделитель - "."
            $link = $worksheet->getCell("B" . $row)->getValue();
            $this->saveProduct($link, $category);
        }
    }

    private function addProducts()
    {
        foreach ($this->aGroupProducts as $item) { // на странице ссылки на продукты
            $cat = $item['category'];
            $link = $item['link'] . "/?SHOWALL_1=1";
            $this->getProducts($link, $cat);
        }
//        $this->getProducts = ParseUtil::unique_multidim_array($this->getProducts, 'link');
    }

    private function getProducts($link, $cat)
    {
        $this->print("Качаем страничку $link.");
        /***
         * @var $doc Document
         */
        $doc = ParseUtil::tryReadLink($link);
//        print_r($doc->html());
//
//        echo str_repeat('*',10);
        $html = $doc->html();
        $h = stristr($html, '<div class="one"');

//        print_r($h);
        echo str_repeat('=', 10);

        $doc = new Document($h);

        //        $aProducts = $doc->find('div.one_small');
        $aProducts = $doc->find('div.one_small>a');

//        foreach ($aProducts as $product) {
//            echo '-------->\n';
//            echo($product . text);
//        }
//        echo 'Все продукты выведены';
//        die();

        $countProducts = count($aProducts);
        $this->print("Найдено $countProducts продуктов на странице");

        // на странице есть товары с ценниками
        if ($countProducts > 0) {
            $i = 0;
            $oldlink = "qqqqqqqqq";
            foreach ($aProducts as $el) {
                $i++;
                $link = $el->attr('href');

                if ($oldlink != $link) {
                    $this->print("Обрабатываем страницу: " . $link . " Категория $cat. Продукт $i/$countProducts");
                    $lnk = $this->link . $link;
                    $this->saveProduct($lnk, $cat);
                    $oldlink = $link;
                } else {
                    $this->print("Повтор ссылки. Пропускаем страницу: $link");
                }
            }
        }
    }

    /**
     * Читает информацию о продукте по ссылке и сохранят в БД
     * @param $link
     * @throws \yii\db\Exception
     */
    protected function saveProduct($link, $cat)
    {
        $lnk = $link;
        $productInfo = $this->getProductInfo($lnk);

        if ($productInfo == false) {
            return;
        } // что-то пошло не так

        // будем сразу писать в базу

        $batch = $this->getBatch($productInfo); // определим "кучку" - поставщика esandwich

        if ($batch == -1) { // не удалось определить или в игноре: пропускаем
            return;
        }

        $arModel = array(
            1 => 'Доставим через 7-10 дней',
            2 => 'Доставим через 10-14 дней',
            3 => 'Доставим через 7-10 дней',
            4 => 'Доставим через 10-14 дней',
            5 => 'Доставим через 7-10 дней',
        );

        $arManufacturer = array(
            1 => 'Биг г.Красноярск',
            2 => 'Интерьер-Центр г.Пенза',
            3 => 'DSV г.Пенза',
            4 => 'Олмеко г.Балахна',
            5 => 'Трия г.Волгодонск',
            6 => 'Стендмебель г.Пенза',
            7 => 'г.Глазов',
        );

        // нормализация текста
        $str = $this->normalText($productInfo['topic']);
        $productInfo['topic'] = substr($str, 0, strrpos($str, '(')) ? substr(
            $str,
            0,
            strrpos($str, '(')
        ) : $str;

        $productInfo['model'] = $arModel[$batch] ?? $productInfo['model'];
        $productInfo['manufacturer'] = $arManufacturer[$batch] ?? $productInfo['manufacturer'];

// TODO: возможно придется изменить способ расчета id сайта. не хотелось бы привязываться к четким значениям

        $productInfo['site_id'] = $this->site_id + $batch;
        $productInfo['link'] = $lnk;
        $productInfo['category'] = $cat;
//                    $productInfo['product_id'] = $this->product_id++;
        $productInfo['product_id'] = $productInfo['articule'];

        $productInfo['subtract'] = true;
//            $productInfo['subtract'] = Если есть в наличии то true если нет то false
//                    echo "productInfo=";
//                    print_r($productInfo);
        ArSite::addProduct($productInfo);
    }

    protected function getProductInfo($link)
    {
        $this->print("Обрабатываем страницу: $link");
        $doc = ParseUtil::tryReadLink($link);
        if (!$doc) {
            return false;
        }

        // обрабатываем только товары, имеющиеся в наличии
        if ($doc->first('div.unavailable')) {
            $this->print("Товара нет в наличии");
            return false;
        }

        $ar = array();

        $ar["topic"] = $doc->first('h1[itemprop="name"]')->text(); // Заголовок товара
        $ar["articule"] = parseUtil::normalSum($doc->first('div.articule')->html()); // Артикул товара

        if ($doc->has('div.manufacturer_desc_center>span')) {
            $tmp = preg_split("/:/", $doc->first('div.manufacturer_desc_center>span')->text());
            $ar["manufacturer"] = trim(end($tmp));
        } else {
            $ar["manufacturer"] = "";
        }

        $ar["new_price"] = parseUtil::normalSum($doc->first('div.new_price')->text()); // Цена новая

        if ($doc->has('div.old_price')) {
            $ar["old_price"] = parseUtil::normalSum($doc->first('div.old_price'));
        } else {
            $ar["old_price"] = "";
        }

        // доставка
        $tmp = trim($doc->first('div.delivery_clock')->text()); // доставим
        $delivery_date = preg_replace("/[^0-9\.]/", '', $tmp);
        $ar["model"] = 'Доставим через ' . $this->dayCount($delivery_date) . " дней";

        $aImgLink = array();
        $aImg = $doc->find('div.preview_con a'); // список картинок для карусели
        foreach ($aImg as $el) {
            $href = $this->getImageSrc($el->attr('href'));
            if ($href <> '') {
                $aImgLink [] = $href;
            }
        }
        $ar["aImgLink"] = $aImgLink;

        // формируем таблицу с характеристиками продукта
        $product_teh = $doc->first('#product_teh');
        if ($product_teh) {
            $aTmp = $this->getAttrAll($product_teh); // читаем в массив все атрибуты товара

            // список нужных атрибутов товара
            $attrList = [
                "Материал корпуса",
                "Материал фасада",
                "Материал фасадов",
                "Ширина",
                "Высота",
                "Глубина",
                "Длина",
                "Размер спального места",
                "Цвет",
                "Наполнение",
            ];
            $ar["attr"] = $this->getAttr($aTmp, $attrList);
            $ar['product_teh'] = preg_replace('/<a[^>]*?>.*?<\/a>/si', '---', $product_teh->html());
        } else {
            $ar["attr"] = [];
            $ar['product_teh'] = '';
        }

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
//  echo $interval->y, "\n"; // кол-во лет
//  echo $interval->d, "\n"; // кол-во дней
//  echo $interval->h, "\n"; // кол-во часов
//  echo $interval->i, "\n"; // кол-во минут
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
            $this->print("Картинка:" . $ret);
        } elseif (preg_match($re2, $str, $matches)) {
            $ret = 'https://esandwich.ru' . $matches[1] . $matches[2] . $matches[3];
            $this->print("Картинка (bmp):" . $ret);
        } elseif (preg_match($re1, $str, $matches)) {
            $ret = 'https://esandwich.ru' . $matches[1] . $matches[2] . $matches[3];
            $this->print("Картинка (jpg):" . $ret);
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
     * Возвращает номер кучки (номер поставщика esandwich)
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