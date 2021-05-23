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
use common\traits\LogPrint;

class ParseForvardmebel
{
    use LogPrint;

    protected $oProductsSheet;
    protected $aProducts = [];
    protected $aGroupProducts = [];
    private $baseUrl;
    private $id;
    private $name;
    // объекты
    private $link;
    private $minid;
    private $maxid;    // массив ссылок на продукты
    private $spreadsheet;    // массив ссылок на страницы с продуктами
    private $cntProducts;
    /**
     * @var mixed
     */
    private $site_id;

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
//        $linksFileName = __DIR__ . '\..\..\..\XLSX\vmebelLinks.XLSX';
//        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
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
        // 1. Соберем ссылки на товары
        $this->addProducts();

        // 2. Парсим товары, пишем в БД
        $this->runItems();

        $messageLog = ["Загружено " . $this->cntProducts . " штук товаров"];
        Yii::info($messageLog, 'parse_info'); //запись в лог

        $this->endprint();

    }

    /**
     * @param string $link
     * @return string
     */
    private function getBaseUrl(string $link): string
    {
//        extract(parse_url($link)); // плохой код
        $aUrl = parse_url($link);

        return $aUrl['scheme'] . '://' . $aUrl['host'];
    }

    private function addProducts()
    {
        $cat = 0; // todo нужна ли категория?
        $link = $this->link;
        $this->baseUrl = $this->getBaseUrl($link);

        $this->print("Качаем страничку $link.");
        $doc = ParseUtil::tryReadLink($link);

        $aProducts = $doc->find('div.bx_catalog_item .product-title a');

        $countProducts = count($aProducts);
        $this->print("Найдено $countProducts продуктов на странице");

        // на странице есть товары с ценниками
        if ($countProducts > 0) {
            $i = 0;
            foreach ($aProducts as $el) {
                $i++;

                $link = $this->baseUrl . $el->attr('href');
                $this->print("Обрабатываем страницу: " . $link . "Категория $cat. Продукт $i/$countProducts");
                $this->aProducts[] = [
                    'category' => 0,
                    'link' => $link,
                    ];
            }
        }
    }

    private function runItems()
    {
        $product_id = $this->minid;
        foreach ($this->aProducts as $product) {
            $link = $product['link'];
            $productInfo = $this->getProductInfo($link);
            
            $productInfo['site_id'] = $this->site_id;
            $productInfo['category'] = 0;
            $productInfo['model'] = '3-4 недели';
            $productInfo['manufacturer'] = 'ГЗМИ, г.Глазов';
            $productInfo['subtract'] = true;
            $topic = $productInfo['topic'];
            foreach ($productInfo['prices'] as $price) {

                if (isset($price['price'])) {
                    $productInfo['product_id'] = $product_id++;
                    $productInfo['new_price'] = $price['price'];
                    if (isset($price['size'])) {
                        $productInfo['attr'] = [
                            'Размер спального места' => $price['size'],
                        ];
                        $productInfo['topic'] = $topic . ' (' . $price['size'] . ')';
                    } else {
                        unset($productInfo['attr']);
                        $productInfo['topic'] = $topic;
                    }

                    echo PHP_EOL . 'productInfo=';
                    print_r($productInfo);

                    ArSite::addProduct($productInfo);
                    $this->cntProducts++;
                }
            }
        }
    }

    protected function getProductInfo($link)
    {
//        if (trim($link) == '') {
//            return false;
//        }
        $this->print("Обрабатываем страницу: $link");

        /** @var Document $doc */
        $doc = ParseUtil::tryReadLink($link);
        if (!$doc) {
            return false;
        }

        // найдем кнопки с размерами
        $aItems = $doc->find('li[data-onevalue]');
        if ($aItems) {
            foreach ($aItems as $item) {
                $aButton[$item->attr('data-onevalue')] = [
                    'size' => trim($item->text()),
                    'treevalue' =>  $item->attr('data-treevalue'),
                ];
            }
            list($propName,$treevalue) = explode('_',$item->attr('data-treevalue'));
            $propName = 'PROP_' . $propName;

            echo 'propName=';print_r($propName);
            echo str_repeat('-',60);

            echo 'aButton=';print_r($aButton);
            echo str_repeat('-',60);

            // найдем цены
            $re = '/new JCCatalogElement\(([^)]+)\);/m';
            $str = $doc->html();

            preg_match_all($re, $str, $matches, PREG_SET_ORDER, 0);
            $json = $matches[0][1];
            $json = str_replace("'", '"', $json);
            $json = \GuzzleHttp\json_decode($json,true);

            $offers = $json['OFFERS'];

            foreach ($offers as $offer) {
                $keyButton = $offer['TREE'][$propName];
                echo 'keyButton=';
                print_r($keyButton);
                echo str_repeat('-',60);
                $aButton[$keyButton]['price'] = $offer['ITEM_PRICES'][0]['PRICE'];
            }
            echo 'aButton=';
            print_r($aButton);
            echo str_repeat('-',60);
        } else {
            $aButton[] =[
                    'price' => parseUtil::normalSum($doc->first('.product-item-detail-price-current')->text()),
            ];
            echo 'aButton=';
            print_r($aButton);
            echo str_repeat('-',60);
//            echo 'СТОП';
//            die();
        }

        $ar = array();

        $ar['prices'] = $aButton; // не забыть пропускать элементы, у кот. нет элемента 'price'
        $ar["topic"] = $doc->first('.catalog-element-cols h1')->text(); // Заголовок товара
        $ar['product_teh'] = $doc->first('.product-item-detail-info-container')->innerHtml();


        $aImgLink = array();
        $aImg = $doc->find('.product-item-detail-slider-image img'); // список картинок для карусели
        foreach ($aImg as $el) {
            $href = $el->attr('src');
            $aImgLink [] = $this->baseUrl . $href;
        }
        $aImgLink = array_unique($aImgLink);

        $ar["aImgLink"] = $aImgLink;

        echo 'ar=';
        print_r($ar);
        echo str_repeat('-',60);

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