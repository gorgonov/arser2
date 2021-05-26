<?php

namespace common\services\parsers;

use console\helpers\ParseUtil;
use console\models\ArSite;
use DiDom\Exceptions\InvalidSelectorException;
use Throwable;
use Yii;
use yii\db\Exception as dbException;

class Adelco24Parser extends AbstractParser
{

    const DEBUG = false;

    protected array $special = [
        3990 => 353,
        4400 => 353,
        5290 => 430,
        5990 => 430,
        9990 => 845,
        9490 => 768,
        10950 => 2500,
        11490 => 2500,
        14990 => 2500,
        6790 => 430,
    ];

    /**
     * @throws Throwable
     * @throws dbException
     * @throws InvalidSelectorException
     */
    public function run()
    {
        if (!self::DEBUG) {
            ArSite::delModulData($this->site_id);
            ArSite::setStatus($this->site_id, 'parse');
        }

        // 1. Соберем разделы мебели
        $this->runSection();

        // 1.1 Возможно, есть подгруппы
        foreach ($this->aGroupProducts as $group) {
            $this->runSubSection($group);
        }

        // 2. Пробежимся по группам товаров $aGroupProducts, заполним товары
        foreach ($this->aGroupProducts as $group) {
            $this->runGroup($group);
        }

        // 3. Записываем в базу продукты
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
     * @throws InvalidSelectorException
     */
    protected function runSection(): void
    {
        $doc = ParseUtil::tryReadLink($this->link);

        $aProducts = $doc->find(".category a");
        foreach ($aProducts as $el) {
            $link = $this->link . $el->attr('href');
            $this->aGroupProducts[] = $link;
            $this->print("Добавили ссылку на категорию товаров: " . $link);
        }
    }

    /**
     * @param string $link
     * @throws InvalidSelectorException
     */
    protected function runSubSection(string $link): void
    {
        $this->print("Ищем подгруппы в: " . $link);
        $doc = ParseUtil::tryReadLink($link);

        $aProducts = $doc->find("button.all");
        foreach ($aProducts as $el) {
            $link = $this->link . $el->parent()->attr('href');
            $this->aGroupProducts[] = $link;
            $this->print("Добавили ссылку на подкатегорию товаров: " . $link);
        }
    }

    /**
     * @param string $link
     * @throws InvalidSelectorException
     */
    protected function runGroup(string $link): void
    {
        $this->print("Обрабатываем группу: " . $link);
        $doc = ParseUtil::tryReadLink($link);

        $aProducts = $doc->find('.items .item a'); // найдем ссылку на товар

        foreach ($aProducts as $el) {
            $link = $this->link . $el->attr('href');
            $this->aProducts[] = $link;
            $this->print("Добавили ссылку на товар: " . $link);
        }
    }

    /**
     * @throws dbException
     * @throws InvalidSelectorException
     */
    protected function runItems(): void
    {
        $product_id = $this->minid;
        foreach ($this->aProducts as $link) {
            $productInfo = $this->getProductInfo($link);
            $productInfo['link'] = $link;
            $productInfo['site_id'] = $this->site_id;
            $productInfo['category'] = 0;
            $productInfo['product_id'] = $product_id++;
            $productInfo['model'] = '2-3 недели';
            $productInfo['manufacturer'] = 'г.Казань';
            $productInfo['subtract'] = true;
            echo PHP_EOL . 'productInfo=';
            print_r($productInfo);
            if (count($productInfo['aImgLink']) > 0) {
                ArSite::addProduct($productInfo);
                $this->cntProducts++;
            }
        }
    }

    /**
     * @param $link
     * @return array
     * @throws InvalidSelectorException
     */
    protected function getProductInfo($link): array
    {
        $this->print("Обрабатываем страницу: $link");
        $doc = ParseUtil::tryReadLink($link);

        $card = $doc->first(".card .itemProps");

        $ar = array();
        $ar["topic"] = $card->first('h1')->text(); // Заголовок товара
        $ar["new_price"] = ParseUtil::normalSum($card->first('div.price')->text());
        $ar["old_price"] = $card->first('.old_price')->text();

        $ar["aImgLink"] = [];
        $aTmp = $doc->find('.itemImage a');
        foreach ($aTmp as $item) {
            $ar["aImgLink"][] = $this->link . $item->attr("href");
        }

        // описание
        $tmp = '';
        if ($s = $doc->first('div.description')) {
            $tmp .= $s->html() ?? '';
        }
        if ($s = $doc->first('div.long_description')) {
            $tmp .= $s->html() ?? '';
        }

        // размеры (см), перевожу в мм
        if ($tmp != '') {
            $ar["attr"] = $this->getSizes($tmp);
        }

        // ищем комплектацию
        $complects = $doc->find('.item.complect');
        if ($complects) {
            $tmp .= "<h2>Комплектация</h2>";
            foreach ($complects as $value) {
                $tmp .= $value->first('h4')->text() . "<br>";
                $ar["aImgLink"][] = $value->first('span')->attr('data-image');
            }
        }
        $ar["product_teh"] = $tmp;
        // конец описания

        return $ar;
    }

    private function getSizes(string $tmp): array
    {
        $attr = preg_split("/[X(]+/", $tmp);
        $sizes = [];
        $sizes["Длина"] = ParseUtil::normalSum($attr[1]) . '0';
        $sizes["Ширина"] = ParseUtil::normalSum($attr[2]) . '0';
        $sizes["Высота"] = ParseUtil::normalSum($attr[3]) . '0';

        return $sizes;
    }

}
