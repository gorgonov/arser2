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
use phpDocumentor\Reflection\Types\Boolean;
use phpDocumentor\Reflection\Types\Integer;
use Yii;
use DiDom\Document;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use common\traits\LogPrint;

//define('DEBUG', true);

class ParseMobi
{
    use LogPrint;

    protected $oProductsSheet;
    protected $aItems = []; // ссылки на конечный продукт
    protected $aProducts = []; // продукты без разделения на опции
    protected $aGroupProducts = []; // группы товаров
    private $aSection = [ // стартовые страницы (разделы) для поиска групп товаров
//        'http://sitparad.com/catalog/stulya/index.php',
//        'http://sitparad.com/catalog/stoly/index.php',
//        'http://sitparad.com/catalog/obedennye_zony/index.php'
    ];
    private $id;
    private $name;
    // объекты
    private $link;
    private $minid;
    private $maxid;    // массив ссылок на продукты
    private $cntProducts = 0; // количество продуктов

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
        $linksFileName = 'none';
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
//        $this->spreadsheet = $reader->load($linksFileName);

        $this->reprint();
        $this->print("Создался " . self::class);

        $messageLog = [
            'status' => 'Старт ' . self::class,
            'post' => $this->name,
        ];

        Yii::info($messageLog, 'parse_info'); //запись в лог

    }

    /**
     * Вычисляет новую цену
     * @param Integer $price
     * @return int
     */
    private function newPrice(Integer $price): Integer
    {
        return (int)round($price * 1.5, 10, PHP_ROUND_HALF_UP);
    }

    public function run()
    {
//        1. Соберем разделы мебели
        $this->runSection();

        //        2. Пробежимся по группам товаров $aGroupProducts, заполним товары
        $doc = ParseUtil::tryReadLink('https://mobi-mebel.ru/katalog');
        foreach ($this->aGroupProducts as $group) {
            $this->runGroup($group, $doc);
        }

//        3. Пробежимся по товарам, получим ссылки на подвиды товара (цвет, ...)
        foreach ($this->aProducts as $product) {
            $this->runProducts($product);
        }

//        4. записываем в базу продукты
//        $this->runItems();

        $messageLog = ["Загружено " . $this->cntProducts . " штук товаров"];
        Yii::info($messageLog, 'parse_info'); //запись в лог

        $this->endprint();
    }

    private function runSection()
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
        return;
    }

    private function runGroup(string $link, $doc)
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
//        echo PHP_EOL . "groupClass=";
//        print_r($groupClass);

//        print_r($doc->html());
//        echo PHP_EOL . "groupClass=";
//        print_r($groupClass);

        $aProducts = $doc->find($groupClass);
//        $log = str_contains ($groupClass,'hall_ctgr');
//        if ($log){
//            echo PHP_EOL . "aProducts=";
//            print_r($aProducts);
//            die();
//        }

        $oldLink = 'qqqqqqqqqqqq';
        foreach ($aProducts as $el) {
            $link = $this->link . $el->attr('href') . 'index.php';
            if ($oldLink != $link) {
                $oldLink = $link;
                $this->aProducts[] = $link;
                $this->print("Добавили ссылку на товары: " . $link);
            }
        }

        return;
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

    private function runProducts($link)
    {
        $doc = ParseUtil::tryReadLink($link);
        if (!$doc) {
            $this->print("Обрабатываем товары: " . $link . " Их нет!!!");
            return false;
        }
        $this->print("Обрабатываем товары: " . $link);
        $aProducts = $doc->find('.module_element');

        // общие параметры продукта
        $product_id = $this->minid + $this->cntProducts;
        $item['site_id'] = $this->site_id;
        $item['category'] = 0;
        $item['model'] = '3-4 недели';
        $item['manufacturer'] = 'Mobi, Нижегородская обл.';
        $item['subtract'] = true;
        $item['link'] = $link;
        $item['new_price'] = 0;

        // описание
        $description = '';
        // ищем Цветовое решение
        $div = false;
        $tmp = $doc->find('.panel-grid.panel-has-style');
        foreach ($tmp as $el) {
            $str = trim($el->text());
            if (str_contains($str, "ветов") or str_contains($str, "Декор")) {
                $div = $el;
                break;
            }
        }
        $str = trim($div->text());

        if ($div) { // Если нашли, то собираем далее все тексты
            while ($div) {
                if ($div->tag = 'div') {
                    $str = $div->first('h1');
                    if ($str) {
                        $description .= "<p>" . trim($str->text()) . ':';
                    }
                    $str = $div->first('h3');
                    if ($str) {
                        $description .= trim($str->text()) . "</p>";
                    }
                }
                $div = $div->nextSibling();
            }
        }

        // video
        $tmp = $doc->first('#existing-iframe-example');
        if ($tmp) {
            $description .= '<p><iframe frameborder="0" src="' . $tmp->attr(
                    'src'
                ) . '" width="640" height="360" class="note-video-clip"></iframe></p>';
        }

        // собственно описание (справа от картинки
        $tmp = $doc->first('.siteorigin-widget-tinymce.textwidget')->html();
        $tmp = str_replace('<br>', '</p><p>', $tmp); // заменим все <br> на абзацы
        $doc1 = new Document($tmp);
        $tmp = $doc1->find('p');

        foreach ($tmp as $el) {
            if (!$el->find('a')) {
                $description .= $el->html();
            }
        }
        $description = str_replace("::", ":", $description);

        $dopImg = []; // допкартинки, добавим в конец каждому продукту
        // картинки из карусели
        $tmp = $doc->find('div[data-desktop]');
        foreach ($tmp as $el) {
            $dopImg[] = 'http:' . $el->attr('data-desktop');
        }

        foreach ($aProducts as $el) {
            $link = $this->link . $el->attr('href');

            // индивидуальные параметры продукта
            $topic = $el->first('.module_element b')->text();

//            'это допкартинки'
            if ($topic == "") {
                $tmp = $el->find('img');
                foreach ($tmp as $img) {
                    $dopImg[] = 'http:' . $img->attr('src');
                }

                $dopImg = array_unique($dopImg);
                continue;
            }

            $item['topic'] = trim($topic);
            $item['product_id'] = $product_id++;

            // размеры
            $str = $el->first('.module_element p')->innerHtml();
            $re = '/(\d+)[\sx]+(\d+)[^\d]+(\d+)/m';
            preg_match_all($re, $str, $matches, PREG_SET_ORDER, 0);
            $attr = $matches[0];
//            $this->print(print_r($attr, true));

            $item['product_teh'] = 'Размеры: ' . $attr[0];
            $item['attr'] = [
                'Ширина' => $attr[1],
                'Глубина' => $attr[2],
                'Высота' => $attr[3],
            ];
            $item['product_teh'] .= $description;

            // картинки
            $imgs = [];
            $tmp = $el->find('img');
            foreach ($tmp as $img) {
                $imgs[] = 'http:' . $img->attr('src');
            }

            $imgs = array_unique($imgs);
            sort($imgs);
            if (str_contains($imgs[0], 'shem')) {
                rsort($imgs);
            }
            $item['aImgLink'] = array_merge($imgs, $dopImg);

            echo PHP_EOL . 'item=';
            print_r($item);

            ArSite::addProduct($item);
            $this->cntProducts++;
            $this->print('Сохранили ' . $item['topic']);
        }
        return;
    }

    private function runItems()
    {
        $product_id = $this->minid;
        foreach ($this->aProducts as $link) {
            $productInfo = $this->getProductInfo($link);
            foreach ($productInfo as $item) {
                $item['site_id'] = $this->site_id;
                $item['category'] = 0;
                $item['product_id'] = $product_id++;
                $item['model'] = '2-3 недели';
                $item['manufacturer'] = 'г.Новосибирск';
                $item['subtract'] = true;
//echo PHP_EOL;
//                print_r($item);
                ArSite::addProduct($item);
                $this->cntProducts++;
            }
        }
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

    private function getJSON($re, $str)
    {
        if (preg_match($re, $str, $matches, PREG_OFFSET_CAPTURE)) {
            $res = ($matches[1][0]);

            if (defined('DEBUG')) {
                print_r(PHP_EOL . '----------------- нашлось --------------' . PHP_EOL);
                print_r($matches);
                print_r('**** res ***********');
                print_r($res);
                print_r('**** end res ***********');
                echo PHP_EOL;
            }
        } else {
            if (defined('DEBUG')) {
                print_r(PHP_EOL . '----------------- res НЕ нашлось --------------' . PHP_EOL);
                echo PHP_EOL;
            }
            return false;
        }
        $res = str_replace("'", '"', $res);
        $json = json_decode($res);
        if ($json) {
            if (defined('DEBUG')) {
                echo PHP_EOL;
                print_r('===== json =========');
                echo PHP_EOL;
                print_r($json);
                echo PHP_EOL;
                print_r('===== end json =========');
                echo PHP_EOL;
                echo PHP_EOL;
                echo PHP_EOL;
                echo PHP_EOL;
            } else {
                // не выводим текст
            }
        } else {
            return false;
        }
        return $json;
    }


    protected function getProductInfo($link)
    {
        $this->print("Обрабатываем страницу: $link");
        $doc = ParseUtil::tryReadLink($link);
        if (!$doc) {
            return false;
        }
        if (defined('DEBUG')) {
            print_r('doc=' . $doc->html());
        }

        $ar = array();

        $aImgLink = array();

        // артикул будет рассчитан при записи в таблицу

        // продукт
        $re = '/JCCatalogElement\((.+)\);/m';
        $str = $doc->html();
        $json1 = $this->getJSON($re, $str);
        // описания продукта
        $re = '/offer_text[\s=]+({.+});/m';
        $json2 = $this->getJSON($re, $str);

        if ($json1) { // продукт имеет разные модификации (цвета)
//            echo 'offer' . PHP_EOL;
            $offers = $json1->OFFERS;
            // цикл по offers (товар разного цвета)
            foreach ($offers as $offer) {
                $id = $offer->ID;
                $ar[$id] ['topic'] = html_entity_decode($offer->NAME);
                $ar[$id] ['link'] = $this->link . html_entity_decode($offer->URL);

                $ar[$id]["new_price"] = $offer->PRICE->VALUE; // Цена новая
                if (!$ar[$id]["new_price"]) {
                    echo "цена не найдена";
                    die();
                }
                $slider = $offer->SLIDER;
                if (defined('DEBUG')) {
                    echo 'SLIDER=';
                    print_r($slider);
                    echo PHP_EOL;
                    print_r('======= imgs =======');
                    echo PHP_EOL;
                }
                $aImgLink = [];
                foreach ($slider as $item) {
                    if (defined('DEBUG')) {
                        echo 'img=' . $item->BIG->src . PHP_EOL;
                    }
                    $aImgLink [] = $this->link . $item->BIG->src;
                }
                // если нет карусели, то только основную картинку
                if (count($aImgLink) == 0) {
                    $preview = $this->link . $offers[0]->PREVIEW_PICTURE->SRC;
                    if (defined('DEBUG')) {
                        echo 'preview=';
                        print_r($preview);
                    }
                    $aImgLink [] = $preview;
                }
                $ar[$id]["aImgLink"] = $aImgLink;
            }
            if ($json2) {
                foreach ($json2 as $key => $value) {
                    $ar[$key]["product_teh"] = $value;
                }
            }
        } else {
//            echo 'No offer' . PHP_EOL;
            $product_teh = $doc->first('div.detail_text');
            if ($product_teh) {
                $ar[0]["product_teh"] = $product_teh->html();
            } else {
                $product_teh = $doc->first('table.props_list');
                if ($product_teh) {
                    $ar[0]["product_teh"] = $product_teh->html();
                } else {
                    $ar[0]["product_teh"] = "Нет описания";
                }
            }

            $topic = $doc->first('h1#pagetitle');
            $ar[0]["topic"] = $topic->text();

            $price = $doc->first('meta[itemprop="price"]');
            if ($price) {
                $ar[0]["new_price"] = $price->attr('content');
            } else {
                $price = $doc->first('.offers_price .price_value');
                if ($price) {
                    $ar[0]["new_price"] = $price->text();
                } else {
                    $price = $doc->first('.price .values_wrapper');
                    if ($price) {
                        $ar[0]["new_price"] = $price->text();
                    } else {
                        echo "ЦЕНА НЕ НЕЙДЕНА в DOM";
                        die();
                        $ar[0]["new_price"] = 0;
                    }
                }
            }

            // картинки
            $imgs = $doc->find('.slides a');

            if (defined('DEBUG')) {
                echo PHP_EOL . "imgs" . PHP_EOL;
                print_r($imgs);
                echo PHP_EOL . "--- imgs ---" . PHP_EOL;
            }
            $oldimg = "qqqqqqqqqqq";
            foreach ($imgs as $img) {
                $newimg = $img->attr('href');
                if ($newimg != $oldimg) {
                    $aImgLink [] = $this->link . $newimg;
                }
                $oldimg = $newimg;
            }
            $ar[0]["aImgLink"] = $aImgLink;

            $ar[0]["title"] = $ar["topic"];

            $ar[0]["old_price"] = "";
            $ar[0]["link"] = $link;
        }
        if (defined('DEBUG')) {
            echo PHP_EOL . "-----------------------------------------------------------------------------" . PHP_EOL;
            echo PHP_EOL . "-----------------------------------------------------------------------------" . PHP_EOL;
            echo PHP_EOL . "-----------------------------------------------------------------------------" . PHP_EOL;
            print_r($ar);
            echo PHP_EOL . "-----------------------------------------------------------------------------" . PHP_EOL;
            echo PHP_EOL . "-----------------------------------------------------------------------------" . PHP_EOL;
            echo PHP_EOL . "-----------------------------------------------------------------------------" . PHP_EOL;
//            die();
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