<?php

namespace common\services\parsers;

use console\helpers\ParseUtil;
use console\models\ArSite;
use DiDom\Exceptions\InvalidSelectorException;
use Exception;
use Throwable;
use Yii;
use yii\db\Exception as dbException;

class GorizontParser extends AbstractParser
{
    const DEBUG = false;

    /**
     * @throws InvalidSelectorException
     * @throws dbException
     * @throws Throwable
     */
    function run()
    {
        if (!self::DEBUG) {
            ArSite::delModulData($this->site_id);
            ArSite::setStatus($this->site_id, 'parse');
        }

        // 1. Соберем разделы мебели
        $this->runSection();

        // 2. Пробежимся по группам товаров $aGroupProducts, заполним товары
        foreach ($this->aGroupProducts as $group) {
            $this->runGroup($group);
        }

        // 3. Записываем в базу продукты
        $this->runItems();

        $messageLog = "Загружено " . $this->cntProducts . " товаров";
        Yii::info($messageLog, 'parse_info'); //запись в parse.log
        $this->print($messageLog);
        $this->endprint();

        if (!self::DEBUG) {
            ArSite::setStatus($this->site_id, 'new');
        }

    }

    /**
     * @throws InvalidSelectorException
     * @throws Exception
     */
    private function runSection(): void
    {
        $this->print("Обрабатываем секцию: " . $this->link);
        $doc = ParseUtil::tryReadLink($this->link);
        if (!$doc) {
            throw new Exception('Не удалось прочитать страницу ' . $this->link);
        }

        $re = '/:actions.+(\[[^\]]+\])/m';
        preg_match_all($re, $doc->html(), $matches, PREG_SET_ORDER);
        $json = $matches[0][1];
        $action = json_decode($json, true);

        foreach ($action as $el) {
            $link = $el['url'];
            $this->aGroupProducts[] = $link;
            $this->print("Добавили ссылку на группу товаров: " . $link);
        }
    }

    /**
     * @param string $link
     * @throws InvalidSelectorException
     * @throws Exception
     */
    private function runGroup(string $link)
    {
        $this->print("Обрабатываем группу: " . $link);
        $doc = ParseUtil::tryReadLink($this->link . $link . '?page_size=1000');
        if (!$doc) {
            throw new Exception('Не удалось прочитать страницу ' . $link);
        }

        $str = $doc->html();
        $re = '/products=.+$/m';
        preg_match_all($re, $str, $matches, PREG_SET_ORDER);
        $tmp = $matches[0][0];
        $separator = substr($tmp, 9, 1);
        $tmp = explode($separator, $tmp);
        $json = $tmp[1];
        $json = str_replace("&quot;",'"', $json);
        $s = json_decode($json, true);
        if (!is_array($s)) {
            echo "tmp=";
            print_r($tmp);
            echo str_repeat('-', 10) . PHP_EOL;
            print_r($json);
            echo str_repeat('-', 10) . PHP_EOL;
            die();
        }
        $newGroup = json_decode($json, true);
        $this->aProducts = array_merge($this->aProducts, $newGroup);
        $this->print("Добавили ссылки на товары");
    }

    /**
     * @throws dbException
     */
    private function runItems(): void
    {
        $product_id = $this->minid;
        foreach ($this->aProducts as $aProduct) {
            $productInfo['link'] = $aProduct['url'];
            $productInfo['site_id'] = $this->site_id;
            $productInfo['category'] = 0;
            $productInfo['model'] = '5-6 недель';
            $productInfo['manufacturer'] = 'Горизонт, г.Пенза';
            $productInfo['subtract'] = true;
            $productInfo['product_teh'] = $aProduct['short_description'];
            $images = $this->getImages($aProduct['images']);

            if (self::DEBUG) {
                print_r($aProduct);
                echo PHP_EOL . str_repeat('-',10) . PHP_EOL;
                print_r($images);
                echo PHP_EOL . str_repeat('-',10) . PHP_EOL;
            }

            if (count($aProduct['variants']) == 1) {
                $productInfo['product_id'] = $product_id++;
                $productInfo['new_price'] = $aProduct['variants'][0]['price'];
                $productInfo['topic'] = $aProduct['title'];
                $productInfo['aImgLink'] = array_values($images);

                if (self::DEBUG) {
                    print_r($productInfo);
                    echo PHP_EOL . str_repeat('=', 10) . PHP_EOL;
                }

                ArSite::addProduct($productInfo);
                $this->cntProducts++;
            } else {
                foreach ($aProduct['variants'] as $item) {
                    $productInfo['product_id'] = $product_id++;
                    $productInfo['new_price'] = $item['price'];
                    $productInfo['topic'] = $aProduct['title'] . ' ' . $item['title'];
                    $productInfo['aImgLink'] = $this->getImageList($images, $item['image_ids']);

                    if (self::DEBUG) {
                        print_r($productInfo);
                        echo PHP_EOL . str_repeat('=', 10) . PHP_EOL;
                    }

                    ArSite::addProduct($productInfo);
                    $this->cntProducts++;
                }
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
            throw new Exception('Не удалось прочитать страницу ' . $link);
        }

        $product_id = $doc->first('meta[name="product-id"]')->attr('content');
        $linkJson = "http://pnz.gorizontmebel.ru/products_by_id/" . $product_id . ".json?lang=&format=json";
        $res = json_decode(ParseUtil::get_web_page($linkJson)['content'], true);
        $status = $res['status'];

        if ($status !== 'ok') {
            throw new Exception('Не найден продукт ' . $product_id);
        }

        $product = $res['products'][0];
        $title = $product['title'];
        $short_description = $product['short_description'];

        $listProduct = [];
        foreach ($product['variants'] as $variant) {
            $key = $variant['image_id'];
            $listProduct[$key]['title'] = $title . " " . $variant['title'];
            $listProduct[$key]['price'] = $variant['price'];
        }

        $commonImages = [];
        foreach ($product['images'] as $image) {
            $key = $image['id'];
            if (isset($listProduct[$key])) {
                $listProduct[$key]['url'] = $image['original_url'];
            } else {
                $commonImages[] = $image['original_url'];
            }
        }

        return compact('listProduct', 'commonImages', 'short_description');
    }

    /**
     * Возвращает список всех картинок к продукту (включая разные цвета, схему, ...)
     * @param $images
     * @return array
     */
    private function getImages($images): array
    {
        $listImage = [];
        foreach ($images as $image) {
            $listImage[$image['id']] = $image['original_url'];
        }
        return $listImage;
    }

    /**
     * @param array $images Список всех картинок продукта
     * @param array $image_ids Список индексов варианта продукта (в конкретном цвете, размере, ...)
     * @return array
     */
    private function getImageList(array $images, array $image_ids): array
    {
        $listImage = [];
        foreach ($image_ids as $image_id) {
            $listImage[] = $images[$image_id] ?? '';
        }
        return $listImage;
    }
}