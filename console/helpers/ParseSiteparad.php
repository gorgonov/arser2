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
use Yii;
use DiDom\Document;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use common\traits\LogPrint;

//define('DEBUG', true);

class ParseSiteparad
{
    use LogPrint;

    protected $oProductsSheet;
    protected $aItems = []; // ссылки на конечный продукт
    protected $aProducts = []; // продукты без разделения на опции
    protected $aGroupProducts = []; // группы товаров
    private $aSection = [ // стартовые страницы (разделы) для поиска групп товаров
        'http://sitparad.com/catalog/stulya/index.php',
        'http://sitparad.com/catalog/stoly/index.php',
        'http://sitparad.com/catalog/obedennye_zony/index.php'
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
        $this->print("Создался ParseSiteparad");

        $messageLog = [
            'status' => 'Старт ParseSiteparad.',
            'post' => $this->name,
        ];

        Yii::info($messageLog, 'parse_info'); //запись в лог

    }

    public function run()
    {
        if (defined('DEBUG')) {
//        $this->aItems[] = 'http://sitparad.com/catalog/stoly/kruglye_stoly/stol_razdvizhnoy_kruglyy_belyy/?oid=777';
//        $this->aItems[] = 'http://sitparad.com/catalog/stulya/stulya_na_metallokarkase/ctul_so_spinkoy_stil_na_metallokarkase/?oid=624';
//        $this->aItems[] = 'http://sitparad.com/catalog/stulya/stulya_na_metallokarkase/ctul_so_spinkoy_chili_skvaer_na_metallokarkase/';
//        $this->aItems[] = 'http://sitparad.com/catalog/obedennye_zony/sovremennye/obedennaya_zona_stol_olimp_stulya_dublin_4_sht/?oid=653';
            $this->aItems[] = 'http://sitparad.com/catalog/stulya/stulya_na_metallokarkase/ctul_so_spinkoy_chili_na_metallokarkase/?oid=578';
            $this->aItems[] = 'http://sitparad.com/catalog/stulya/stulya_na_metallokarkase/ctul_so_spinkoy_chili_na_metallokarkase/?oid=579';
        } else {
//        1. Пробежимся по разделам, заполним группы товаров $aGroupProducts
            foreach ($this->aSection as $section) {
                $this->runSection($section);
            }

//        2. Пробежимся по группам товаров $aGroupProducts, заполним товары
            foreach ($this->aGroupProducts as $group) {
                $this->runGroup($group);
            }

//        3. Пробежимся по товарам, получим ссылки на подвиды товара (цвет, ...)
//            foreach ($this->aProducts as $product) {
//                $this->runProducts($product);
//            }
        }

//        4. записываем в базу продукты
        $this->runItems();

        $messageLog = ["Загружено " . $this->cntProducts . " штук товаров"];
        Yii::info($messageLog, 'parse_info'); //запись в лог

        $this->endprint();
    }

    private function runSection(string $link)
    {
        $doc = ParseUtil::tryReadLink($link);
        $this->print("Обрабатываем секцию: $link");

        $aProducts = $doc->find('.item .item-title a');

        foreach ($aProducts as $el) {
            $link = $this->link . $el->attr('href');
            $this->aGroupProducts[] = $link . 'index.php';
            $this->print("Добавили ссылку на группу товаров: " . $link);
        }
        return;
    }

    private function runGroup(string $link)
    {
        $doc = ParseUtil::tryReadLink($link);
        $this->print("Обрабатываем группу: " . $link);
        $aProducts = $doc->find('.image_wrapper_block a');

        foreach ($aProducts as $el) {
            $link = $this->link . $el->attr('href') . 'index.php';
            $this->aProducts[] = $link;
            $this->print("Добавили ссылку на товары: " . $link);
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
        $this->print("Обрабатываем товары: " . $link);
        $aProducts = $doc->find('link[itemprop="url"]');

        foreach ($aProducts as $el) {
            $link = $this->link . $el->attr('href');
            $this->aItems[] = $link;
            $this->print("Добавили ссылку на товар: " . $link);
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