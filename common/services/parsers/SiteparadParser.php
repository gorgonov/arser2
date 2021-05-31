<?php

namespace common\services\parsers;

use console\helpers\ParseUtil;
use console\models\ArSite;
use DiDom\Document;
use DiDom\Exceptions\InvalidSelectorException;
use Exception;
use Yii;
use yii\db\Exception as dbException;

class SiteparadParser extends AbstractParser
{

    const DEBUG = false;

    protected array $aItems = []; // ссылки на конечный продукт
    protected array $aProducts = []; // продукты без разделения на опции
    protected array $aSection = [ // стартовые страницы (разделы) для поиска групп товаров
        'http://sitparad.com/catalog/stulya/index.php',
        'http://sitparad.com/catalog/stoly/index.php',
        'http://sitparad.com/catalog/obedennye_zony/index.php'
    ];

    /**
     * @throws InvalidSelectorException
     * @throws dbException
     */
    public function run()
    {
        if (self::DEBUG) {
//        $this->aItems[] = 'http://sitparad.com/catalog/stoly/kruglye_stoly/stol_razdvizhnoy_kruglyy_belyy/?oid=777';
//        $this->aItems[] = 'http://sitparad.com/catalog/stulya/stulya_na_metallokarkase/ctul_so_spinkoy_stil_na_metallokarkase/?oid=624';
//        $this->aItems[] = 'http://sitparad.com/catalog/stulya/stulya_na_metallokarkase/ctul_so_spinkoy_chili_skvaer_na_metallokarkase/';
//        $this->aItems[] = 'http://sitparad.com/catalog/obedennye_zony/sovremennye/obedennaya_zona_stol_olimp_stulya_dublin_4_sht/?oid=653';
            $this->aItems[] = 'http://sitparad.com/catalog/stulya/stulya_na_metallokarkase/ctul_so_spinkoy_chili_na_metallokarkase/?oid=578';
            $this->aItems[] = 'http://sitparad.com/catalog/stulya/stulya_na_metallokarkase/ctul_so_spinkoy_chili_na_metallokarkase/?oid=579';
        } else {
//        1. Пробежимся по разделам, заполним группы товаров $aGroupProducts
            // теперь - это группы, пишем в товары сразу
            foreach ($this->aSection as $section) {
                $this->runSection($section);
            }

//        2. Пробежимся по группам товаров $aGroupProducts, заполним товары
            // стало ненужным
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

        $messageLog = 'Загружено ' . $this->cntProducts . ' товаров';
        Yii::info($messageLog, 'parse_info'); //запись в parse.log
        $this->print($messageLog);
        $this->endprint();

        if (!self::DEBUG) {
            ArSite::setStatus($this->site_id, 'new');
        }


    }

    /**
     * @param string $link
     * @throws InvalidSelectorException
     */
    private function runSection(string $link): void
    {
        $doc = ParseUtil::tryReadLink($link);
        $this->print("Обрабатываем секцию: $link");

        $aProducts = $doc->find('.product-content a.product-loop-title');
        if (count($aProducts) == 0 ){
            throw new Exception("ВНИМАНИЕ! Не найдены группы товаров! Видимо, изменилась верстка!");
        }

        foreach ($aProducts as $el) {
            $link = $el->attr('href');
            $this->aProducts[] = $link . 'index.php';
            $this->print("Добавили ссылку на товар: " . $link);
        }
    }

    /**
     * @param string $link
     * @throws InvalidSelectorException
     */
    private function runGroup(string $link): void
    {
        /** @var Document $doc */
        $doc = ParseUtil::tryReadLink($link);
        $this->print("Обрабатываем группу: " . $link);
        $aProducts = $doc->find('.image_wrapper_block a');
        if (count($aProducts) == 0 ){
            throw new Exception("ВНИМАНИЕ! Не найден товар! Видимо, изменилась верстка!");
        }

        foreach ($aProducts as $el) {
            $link = $this->link . $el->attr('href') . 'index.php';
            $this->aProducts[] = $link;
            $this->print("Добавили ссылку на товары: " . $link);
        }
    }

    /**
     * @throws InvalidSelectorException
     * @throws dbException
     */
    private function runItems(): void
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

                ArSite::addProduct($item);
                $this->cntProducts++;
            }
        }
    }

    /**
     * @param $link
     * @return array
     * @throws InvalidSelectorException
     * @throws Exception
     */
    protected function getProductInfo($link): array
    {
        $this->print("Обрабатываем страницу: $link");
        $doc = ParseUtil::tryReadLink($link);
        if (!$doc) {
            throw new Exception("Обрабатываем товары: " . $link . " Их нет!!!");
        }
        if (self::DEBUG) {
            print_r('doc=' . $doc->html());
        }

        $ar = [];

        // продукт
        $formData = $doc->first('form.variations_form');
        if (!$formData) {
            throw new Exception("Обрабатываем товары: " . $link . " Не найдена информация о товаре!");
        }

        $products = json_decode($formData->attr('data-product_variations'));

        if ($products) {
            $pa_color = $this->getColors($doc);

            foreach ($products as $product) {
                $aElement ['topic'] = $product->image->title . ' ' . $pa_color[$product->attributes->attribute_pa_color];
                $aElement ['link'] = $link;
                $aElement ['new_price'] = $product->display_price;
                $aElement ['product_teh'] = $product->display_price;

print_r($aElement);
die();
            }
        }

        if ($json1) { // продукт имеет разные модификации (цвета)
            $offers = $json1->OFFERS;
            // цикл по offers (товар разного цвета)
            foreach ($offers as $offer) {
                $id = $offer->ID;
                $ar[$id] ['topic'] = html_entity_decode($offer->NAME);
                $ar[$id] ['link'] = $this->link . html_entity_decode($offer->URL);
                $ar[$id]["new_price"] = $offer->PRICE->VALUE; // Цена новая
                if (!$ar[$id]["new_price"]) {
                    throw new Exception("Обрабатываем товары: " . $link . " Не найдена цена!!!");
                }
                $slider = $offer->SLIDER;
                if (self::DEBUG) {
                    echo 'SLIDER=';
                    print_r($slider);
                    echo PHP_EOL;
                    print_r('======= imgs =======');
                    echo PHP_EOL;
                }
                $aImgLink = [];
                foreach ($slider as $item) {
                    if (self::DEBUG) {
                        echo 'img=' . $item->BIG->src . PHP_EOL;
                    }
                    $aImgLink [] = $this->link . $item->BIG->src;
                }
                // если нет карусели, то только основную картинку
                if (count($aImgLink) == 0) {
                    $preview = $this->link . $offers[0]->PREVIEW_PICTURE->SRC;
                    if (self::DEBUG) {
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
            $ar[0]["new_price"] = $this->getPrice($doc);

            // картинки
            $ar[0]["aImgLink"] = $this->getImages($doc);
            $ar[0]["title"] = $ar["topic"];
            $ar[0]["old_price"] = "";
            $ar[0]["link"] = $link;
        }
        if (self::DEBUG) {
            echo PHP_EOL . "-----------------------------------------------------------------------------" . PHP_EOL;
            print_r($ar);
            echo PHP_EOL . "-----------------------------------------------------------------------------" . PHP_EOL;
        }

        return $ar;
    }

    /**
     * @param $re
     * @param $str
     * @return string
     */
    private function getJSON($re, $str): string
    {
        if (preg_match($re, $str, $matches, PREG_OFFSET_CAPTURE)) {
            $res = ($matches[1][0]);

            if (self::DEBUG) {
                print_r(PHP_EOL . '----------------- нашлось --------------' . PHP_EOL);
                print_r($matches);
                print_r('**** res ***********');
                print_r($res);
                print_r('**** end res ***********');
                echo PHP_EOL;
            }
        } else {
            if (self::DEBUG) {
                print_r(PHP_EOL . '----------------- res НЕ нашлось --------------' . PHP_EOL);
                echo PHP_EOL;
            }
            return false;
        }
        $res = str_replace("'", '"', $res);
        $json = json_decode($res);
        if ($json) {
            if (self::DEBUG) {
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

    /**
     * @param Document $doc
     * @return array
     * @throws InvalidSelectorException
     */
    private function getImages(Document $doc): array
    {
        $images = $doc->find('.slides a');

        if (self::DEBUG) {
            echo PHP_EOL . "images" . PHP_EOL;
            print_r($images);
            echo PHP_EOL . "--- images ---" . PHP_EOL;
        }
        $oldImageLink = "qqqqqqqqqqq";
        $aImgLink = [];
        foreach ($images as $img) {
            $newImageLink = $img->attr('href');
            if ($newImageLink != $oldImageLink) {
                $aImgLink [] = $this->link . $newImageLink;
            }
            $oldImageLink = $newImageLink;
        }

        return $aImgLink;
    }

    /**
     * @param Document $doc
     * @return string
     * @throws InvalidSelectorException
     * @throws Exception
     */
    private function getPrice(Document $doc): string
    {
        if ($price = $doc->first('meta[itemprop="price"]')) {
            return $price->attr('content');
        }
        if ($price = $doc->first('.offers_price .price_value')) {
            return $price->text();
        }
        if ($price = $doc->first('.price .values_wrapper')) {
            return $price->text();
        }
        throw new Exception(" Не найдена цена в DOM !!!");

//        return '0'; // как альтернатива
    }

    private function getColors(Document $doc): array
    {
        $colorList=[];
        $colors = $doc->find('select#pa_color option');
        foreach ($colors as $color) {
            $key = $color->value;
            if (!empty($key)) {
                $colorList[$key] = $color->text();
            }
        }
        return $colorList;
    }
}
