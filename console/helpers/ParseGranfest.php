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

class ParseGranfest
{
    use LogPrint;

    protected $oProductsSheet;
    protected $aItems = []; // ссылки на конечный продукт
    protected $aProducts = []; // продукты без разделения на опции
    protected $aGroupProducts = []; // группы товаров
    private $aSection = [ // стартовые страницы (разделы) для поиска групп товаров
        ['link' => 'https://granfest.ru/katalog/mojki-dlya-kuhni', 'category' => 434],
        ['link' => 'https://granfest.ru/katalog/smesiteli', 'category' => 576],
    ];
    private $id;
    private $name;
    // объекты
    private $link;
    private $minid;
    private $maxid;    // массив ссылок на продукты
    private $cntProducts = 0; // количество продуктов

    /**
     * Parse constructor.
     */
    public function __construct($site)
    {
        $this->start(); // засечем время старта

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

    public function run()
    {
//        1. Пробежимся по разделам, заполним группы товаров $aGroupProducts
        foreach ($this->aSection as $section) {
            $this->runSection($section);
        }

//        2. Пробежимся по группам товаров $aGroupProducts, заполним товары
//            foreach ($this->aGroupProducts as $group) {
//                $this->runGroup($group);
//            }

//        3. Пробежимся по товарам, получим ссылки на подвиды товара (цвет, ...)
//            foreach ($this->aProducts as $product) {
//                $this->runProducts($product);
//            }

//        4. записываем в базу продукты
        $this->runItems();

        $messageLog = ["Загружено " . $this->cntProducts . " штук товаров"];
        Yii::info($messageLog, 'parse_info'); //запись в лог

        $this->endprint();
    }

    private function runSection(array $section)
    {
        $link = $section['link'];
        $category = $section['category'];
        $doc = ParseUtil::tryReadLink($link);
        $this->print("Обрабатываем секцию: $link");

        $aProducts = $doc->find('.title_tov_item a');

        foreach ($aProducts as $el) {
            $link = trim($this->link . $el->attr('href'));
            $link .= (substr($link, -1) != '/') ? '/' : '';
//            $link .= 'index.php';
            $this->aGroupProducts[] = compact('link', 'category');
            $this->print("Добавили ссылку на группу товаров: " . $link);
        }
        return;
    }

    private function runItems()
    {
        $product_id = $this->minid;
//        echo PHP_EOL . print_r($this->aGroupProducts, true) . PHP_EOL;
        foreach ($this->aGroupProducts as $el) {
            $link = $el['link'];
            $category = $el['category'];

            if (defined('DEBUG')) {
                echo PHP_EOL . str_repeat('-', 30) . PHP_EOL;
                print_r('link=' . $link) . PHP_EOL;
                echo PHP_EOL . str_repeat('-', 30) . PHP_EOL;
            }
            $productInfo = $this->getProductInfo($link);
            foreach ($productInfo as $item) {
                $item['site_id'] = $this->site_id;
                $item['link'] = $link;
                $item['category'] = $category;
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

        // артикул будет рассчитан при записи в таблицу

        // продукт
        $topic = $doc->first('h1.title_text_tov');
        $ar["topic"] = trim($topic->text());

        $product_teh = $doc->first('div.teh_info');
        if ($product_teh) {
            $ar["product_teh"] = $product_teh->html();
        } else {
            $ar["product_teh"] = "Нет описания";
        }

        $price = $doc->first('.price_span');
        if ($price) {
            $ar["new_price"] = ParseUtil::normalSum($price->text());
        } else {
            echo "ЦЕНА НЕ НЕЙДЕНА в DOM";
            die();
            $ar["new_price"] = 0;
        }
        if (defined('DEBUG')) {
            print_r($ar);
        }
        // цвета
        $col1 = $doc->first('.color')->find('.col1');

        $colors = [];
        foreach ($col1 as $col) {
            $colors[] = $col->attr('title');
        }

        if (defined('DEBUG')) {
            echo PHP_EOL . "colors" . PHP_EOL;
            print_r($colors);
            echo PHP_EOL . "--- colors ---" . PHP_EOL;
        }
        // картинки
        $imgs = $doc->find('img[style="width: 100%"]');

        // найти среди картинок схему
        $imgSchema = false; // пока не нашли схему
        $imgList = []; // массив ссылок на картинки
        $firstImg = false;
        foreach ($imgs as $img) {
            $url = $this->link . $img->attr('src');

            if (!$firstImg) {
                $firstImg = $url;
            }
            if ($imgSchema) {
                $imgList[] = $url;
            } else {
                if (strripos($url, 'schema') !== false) {
                    $imgSchema = $url;
                }
            }
        }
        if (defined('DEBUG')) {
            echo PHP_EOL . "imgSchema=";
            print_r($imgSchema);

            echo PHP_EOL . "imgList=";
            print_r($imgList);
        }
        // формируем массив информации о продукте в разных цветах
        $arList = array();
        $oldColor = 'qqqqqqq';
        $aImgLink = [];
        foreach ($colors as $color) {
            if ($oldColor != $color) {
                if (count($aImgLink)>0) {
                    array_push($aImgLink, $imgSchema);
                    $arList[] = [
                        'topic' => $ar["topic"] . " (" . $oldColor . ")",
                        'product_teh' => $ar["product_teh"],
                        'new_price' => $ar["new_price"],
                        'aImgLink' => $aImgLink,
                    ];
                    $aImgLink = [];
                }
            }

            $aImgLink[] = array_shift($imgList);
            $oldColor = $color;
        }
        if (count($aImgLink) == 0) {
            array_push($aImgLink, $firstImg);
        }
        array_push($aImgLink, $imgSchema);
        $arList[] = [
            'topic' => $ar["topic"] . " (" . $oldColor . ")",
            'product_teh' => $ar["product_teh"],
            'new_price' => $ar["new_price"],
            'aImgLink' => $aImgLink,
        ];

        if (defined('DEBUG')) {
            echo PHP_EOL . "arList=";
            print_r($arList);
        }
        return $arList;
    }

}