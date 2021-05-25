<?php

namespace common\services\parsers;

use console\helpers\ParseUtil;
use console\models\ArSite;
use DiDom\Document;
use DiDom\Exceptions\InvalidSelectorException;
use Exception;
use Throwable;
use Yii;
use yii\db\Exception as dbException;

class ForvardmebelParser extends AbstractParser
{
    const DEBUG = false;
    private string $baseUrl;

    /**
     * @throws dbException
     * @throws Throwable
     * @throws InvalidSelectorException
     */
    public function run()
    {
        if (!self::DEBUG) {
            ArSite::delModulData($this->site_id);
            ArSite::setStatus($this->site_id, 'parse');
        }

        // 1. Соберем ссылки на товары
        $this->addProducts();

        // 2. Парсим товары, пишем в БД
        $this->runItems();

        $messageLog = ["Загружено " . $this->cntProducts . " штук товаров"];
        Yii::info($messageLog, 'parse_info'); //запись в лог

        $this->endprint();

        if (!self::DEBUG) {
            ArSite::setStatus($this->site_id, 'new');
        }

    }

    /**
     * @throws InvalidSelectorException
     */
    private function addProducts(): void
    {
        $cat = 0; // todo нужна ли категория?
        $link = $this->link;
        $this->baseUrl = $this->getBaseUrl($link);

        $this->print("Качаем страничку $link.");
        $doc = ParseUtil::tryReadLink($link);

        $aProducts = $doc->find('div.bx_catalog_item .product-title a');
        $countProducts = count($aProducts);
        $this->print("Найдено $countProducts продуктов на странице");

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

    /**
     * @throws InvalidSelectorException
     * @throws dbException
     */
    private function runItems()
    {
        $product_id = $this->minid;
        foreach ($this->aProducts as $product) {
            $link = $product['link'];
            $productInfo = $this->getProductInfo($link);

            $productInfo['link'] = $link;
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

        /** @var Document $doc */
        $doc = ParseUtil::tryReadLink($link);
        if (!$doc) {
            throw new Exception('Не удалось прочитать страницу ' . $link);
        }

        // найдем кнопки с размерами
        $aItems = $doc->find('li[data-onevalue]');
        if ($aItems) {
            foreach ($aItems as $item) {
                $aButton[$item->attr('data-onevalue')] = [
                    'size' => trim($item->text()),
                    'treevalue' => $item->attr('data-treevalue'),
                ];
            }
            list($propName) = explode('_', $item->attr('data-treevalue'));

            // найдем цены
            $aButton = $this->getPrices($doc->html(), 'PROP_' . $propName);
        } else {
            $aButton[] = [
                'price' => parseUtil::normalSum($doc->first('.product-item-detail-price-current')->text()),
            ];
        }

        $ar = [];
        $ar['prices'] = $aButton; // не забыть пропускать элементы, у кот. нет элемента 'price'
        $ar["topic"] = $doc->first('.catalog-element-cols h1')->text(); // Заголовок товара
        $ar['product_teh'] = $doc->first('.product-item-detail-info-container')->innerHtml();

        $aImgLink = [];
        $aImg = $doc->find('.product-item-detail-slider-image img'); // список картинок для карусели
        foreach ($aImg as $el) {
            $href = $el->attr('src');
            $aImgLink[] = $this->baseUrl . $href;
        }
        $aImgLink = array_unique($aImgLink);
        $ar["aImgLink"] = $aImgLink;

        return $ar;
    }

    /**
     * @param string $link
     * @return string
     */
    private function getBaseUrl(string $link): string
    {
        $aUrl = parse_url($link);

        return $aUrl['scheme'] . '://' . $aUrl['host'];
    }

    /**
     * @param string $str
     * @param string $propName
     * @return array
     */
    private function getPrices(string $str, string $propName): array
    {
        $aButton = [];

        $re = '/new JCCatalogElement\(([^)]+)\);/m';
        preg_match_all($re, $str, $matches, PREG_SET_ORDER);
        $json = $matches[0][1];
        $json = str_replace("'", '"', $json);
        $json = \GuzzleHttp\json_decode($json, true);

        $offers = $json['OFFERS'];
        foreach ($offers as $offer) {
            $keyButton = $offer['TREE'][$propName];
            $aButton[$keyButton]['price'] = $offer['ITEM_PRICES'][0]['PRICE'];
        }

        return $aButton;
    }
}