<?php


namespace common\services\parsers;


use console\helpers\ParseUtil;
use console\models\ArSite;
use DiDom\Document;
use DiDom\Element;
use DiDom\Exceptions\InvalidSelectorException;
use Exception;
use Yii;

class BigParser extends AbstractParser
{

    const DEBUG = false;

    const SIZES = [
        'Длина',
        'Ширина',
        'Высота',
        'X',
        'Y'
    ];

    const DIMENTIONS = [
        'Длина',
        'Ширина',
        'Высота',
    ];

    const BERTH_SIZE = [
        'X',
        'Y'
    ];

    public function run()
    {
        if (!self::DEBUG) {
            ArSite::delModulData($this->site_id);
            ArSite::setStatus($this->site_id, 'parse');
        }

//        1. Соберем разделы мебели
        $this->runSection();
        print_r($this->aGroupProducts);

        // 2. Соберем ссылки на товары
        $this->runGroup();
        print_r($this->aProducts);

        // 3. Обработаем продукты
        $this->runProducts();
        die();
        /*
                // 2. Обработаем 2 лист - ссылки на товары
                $this->runProducts();

                // 3. Добавим товары со страниц с группами товаров
                $this->addProducts();

                // 4. Парсим товары, пишем в БД
                $this->runItems();
        */
        $messageLog = ["Загружено " . $this->cntProducts . " товаров"];
        Yii::info($messageLog, 'parse_info'); //запись в parse.log
        $this->endprint();
        if (!self::DEBUG) {
            ArSite::setStatus($this->site_id, 'new');
        }

    }

    protected function runSection(): void
    {
        $doc = ParseUtil::tryReadLink($this->link);

        $aProducts = $doc->find('ul.nav-child a');
        foreach ($aProducts as $el) {
            $link = $el->attr('href');
            $this->aGroupProducts[] = $this->link . $link;
            $this->print("Добавили ссылку на группу товаров: " . $this->link . $link);
        }
    }

    protected function runGroup(): void
    {
        foreach ($this->aGroupProducts as $el) {

            $doc = ParseUtil::tryReadLink($el);
            $aProducts = $doc->find('div.list-product a');

            foreach ($aProducts as $aProduct) {
                $link = $aProduct->attr('href');
                $this->aProducts[] = $this->link . $link;
                $this->print("Добавили ссылку на товар: " . $this->link . $link);
            }
        }
    }

    /**
     * @throws InvalidSelectorException
     */
    private function runProducts(): void
    {
        foreach ($this->aProducts as $aProduct) {

            $doc = ParseUtil::tryReadLink($aProduct);
            if (!$doc) {
                $this->print('Ссылку ' . $aProduct . ' пропускаем (нет продукта).');
                continue;
//            throw new Exception("Обрабатываем товары: " . $link . " Их нет!!!");
            }

            $this->print("Обрабатываем товары: " . $aProduct);

            // общие параметры продукта
            $topic = $this->getTopic($doc);
            if (empty($topic)) {
                continue;
            }
            $item['site_id'] = $this->site_id;
            $item['category'] = 0;
            $item['model'] = '7-10 дней';
            $item['manufacturer'] = 'г. Красноярск';
            $item['subtract'] = true;
            $item['link'] = $aProduct;
            $item['description'] = $this->getDescription($doc);

            if ($sizes = $this->getSizes($doc)) {
                $item['description'] .= $this->getDimensions($sizes);
                $item['attr'] = $this->getAttr($sizes);
            }

            // варианты
            $variants = $doc->find('#jshop_attr_id1 option');
            if (count($variants) == 0) {
                $variants = $doc->find('#jshop_attr_id2 option');
                if (count($variants) == 0) {
                    throw new Exception("Обрабатываем товары: " . $aProduct . ". Вариантов нет!!!");
                }
                foreach ($variants as $variant) {
                    $item['product_id'] = $this->minid + $this->cntProducts++;
                    $item['topic'] = $topic . ' ' . $variant->text();
                    $item['new_price'] = $this->getPrice();
                    $item["aImgLink"] = [$this->getImageLink($doc)];
                    $item["weight"] = $this->getWeight($doc);

                    ArSite::addProduct($item);
                    $this->print('Сохранили ' . $item['topic']);
                }
            } else {
                foreach ($variants as $variant) {
                    $item['product_id'] = $this->minid + $this->cntProducts++;
                    $item['topic'] = $topic . ' ' . $variant->text();
                    $item['new_price'] = $this->getPrice();
                    $item["aImgLink"] = $this->getColor($doc, $variant);
                    $item["weight"] = $this->getWeight($doc);

                    ArSite::addProduct($item);
                    $this->print('Сохранили ' . $item['topic']);
                }
            }
        }
    }

    /**
     * @param Document $doc
     * @param Element $variant
     * @return array
     * @throws InvalidSelectorException
     */
    private function getColor(Document $doc, Element $variant): array
    {
        $titleSearch = $variant->text();

        $aImageLinks = [];
        $a = $doc->find('a[title="' . $titleSearch . '"]');
        if ($a) {
            foreach ($a as $item) {
                $aImageLinks[] = $item->attr('href');
            }
        }

        return $aImageLinks;
    }

    /**
     * @param Document $doc
     * @return string
     * @throws InvalidSelectorException
     */
    private function getDescription(Document $doc): string
    {
        $result = '';
        $aParagraph = $doc->find('.jshop_prod_description p');

        foreach ($aParagraph as $item) {
            $text = $item->text();
            $text = trim($text, " \n\r\t\v\0 ");

            if (empty($text)) {
                break;
            }
            $result .= $item->html();
        }

        return $result;
    }

    /**
     * @return string
     */
    private function getPrice(): string
    {
        // код продукта
        $productCode = 19;
        // вариант
        $variant = 143;

        $url = 'https://xn--90aagfbtte2m.xn--p1ai/каталог/product/ajax_attrib_select_and_price/'
            . $productCode
            . '?attr[1]=' . $variant;


        $res = json_decode(ParseUtil::get_web_page($url)['content'], true);

        return $res['pricefloat'];
    }

    private function getWeight(Document $doc): string
    {
        return '90 кг';
    }

    /**
     * @param Document $doc
     * @return array
     * @throws InvalidSelectorException
     */
    private function getSizes(Document $doc): array
    {
        $sizeList = [];
        if ($elem = $doc->first('.extra_fields_value')) {
            $str = $elem->text();
            $re = '/\d+/m';
            preg_match_all($re, $str, $matches, PREG_SET_ORDER);

            for ($i=0;$i++;i<count($matches)) {
                $sizeList[self::SIZES] = $matches[$i][0];
            }
        }

        return $sizeList;
    }

    /**
     * @param Document $doc
     * @return string
     * @throws InvalidSelectorException
     */
    protected function getTopic(Document $doc): string
    {
        if ($topicElement = $doc->first('#comjshop h2')) {
            return $topicElement->text();
        }
        return '';
    }

    /**
     * @param array $sizes
     * @param array $keyList
     * @param string $labelString
     * @return string
     */
    private function getStringSizes(array $sizes, array $keyList, string $labelString): string
    {
        $strResult = '';
        foreach ($keyList as $key) {
            $strResult .= '*' . $sizes[$key];
        }

        if (!empty($strResult)) {
            $strResult = '<p>'
                . $labelString
                . substr($strResult, 1)
                . '</p>';
        }

        return $strResult;
    }

    /**
     * @param array $sizes
     * @return string
     */
    private function getDimensions(array $sizes): string
    {
        return $this->getStringSizes($sizes, self::DIMENTIONS, 'Габариты: ')
            . $this->getStringSizes($sizes, self::BERTH_SIZE, 'Размер спального места ');
    }

    /**
     * @param array $sizes
     * @return array
     */
    private function getAttr(array $sizes): array
    {
        $attrList = [];
        foreach (self::DIMENTIONS as $dim) {
            $attrList[$dim] = $sizes[$dim];
        }

        $strBerth = '';
        foreach (self::BERTH_SIZE as $dim) {
            $strBerth .= '*' .  $sizes[$dim];
        }

        if (!empty($strBerth)) {
            $attrList['Размер спального места'] = substr($strBerth,1);
        }

        return $attrList;
    }

    /**
     * @param Document $doc
     * @return string
     * @throws InvalidSelectorException
     */
    private function getImageLink(Document $doc): string
    {
        return $doc->first('.l-full-product-picture a')->attr('href');
    }
}
