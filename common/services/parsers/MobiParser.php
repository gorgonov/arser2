<?php

namespace common\services\parsers;

use console\helpers\ParseUtil;
use console\models\ArSite;
use DiDom\Document;
use DiDom\Element;
use DiDom\Exceptions\InvalidSelectorException;
use Exception;
use Throwable;
use Yii;
use yii\db\Exception as dbException;

class MobiParser extends AbstractParser
{

    const DEBUG = false;

    /**
     * @throws InvalidSelectorException
     * @throws dbException
     * @throws Throwable
     */
    public function run()
    {
        if (!self::DEBUG) {
            ArSite::delModulData($this->site_id);
            ArSite::setStatus($this->site_id, 'parse');
        }

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
    protected function runSection():void
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
    }

    /**
     * @param string $link
     * @param Document $doc
     * @throws InvalidSelectorException
     */
    protected function runGroup(string $link, Document $doc): void
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
        $aProducts = $doc->find($groupClass);

        $oldLink = 'qqqqqqqqqqqq';
        foreach ($aProducts as $el) {
            $link = $this->link . $el->attr('href') . 'index.php';
            if ($oldLink != $link) {
                $oldLink = $link;
                $this->aProducts[] = $link;
                $this->print("Добавили ссылку на товары: " . $link);
            }
        }
    }

    /**
     * @throws InvalidSelectorException
     * @throws dbException
     * @throws Exception
     */
    private function runProducts($link): void
    {
        $doc = ParseUtil::tryReadLink($link);
        if (!$doc) {
            return;
//            throw new Exception("Обрабатываем товары: " . $link . " Их нет!!!");
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

        // собственно описание (справа от картинки)
        $description = $this->getDescription($doc);

        $dopImg = []; // допкартинки, добавим в конец каждому продукту
        // картинки из карусели
        $tmp = $doc->find('div[data-desktop]');
        foreach ($tmp as $el) {
            $dopImg[] = 'http:' . $el->attr('data-desktop');
        }

        foreach ($aProducts as $el) {
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
            if ($sizes = $this->getSizes($el)) {

                $item['product_teh'] = 'Размеры: ' . $sizes[0];
                $item['attr'] = [
                    'Ширина' => $sizes[1],
                    'Глубина' => $sizes[2],
                    'Высота' => $sizes[3],
                ];
            }

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
    }

    /**
     * @param Element $el
     * @return array|null
     * @throws InvalidSelectorException
     */
    private function getSizes(Element $el): ?array
    {

        $element = $el->first('.module_element p')
            ?? $el->first('.module_description p');

        if (!$element) return null;

        $str = $element->innerHtml();

        $re = '/(\d+)[\sx]+(\d+)[^\d]+(\d+)/m';
        if (preg_match_all($re, $str, $matches, PREG_SET_ORDER) == 0) return null;

        return $matches[0];
    }

    /**
     * @param Document $doc
     * @return string
     * @throws InvalidSelectorException
     */
    private function getDescription(Document $doc): string
    {
        $tmp = $doc->first('.siteorigin-widget-tinymce.textwidget')->html();
        $tmp = str_replace('<br>', '</p><p>', $tmp); // заменим все <br> на абзацы
        $doc1 = new Document($tmp);
        $tmp = $doc1->find('p');

        $description = '';
        foreach ($tmp as $el) {
            if (!$el->find('a')) {
                $description .= $el->html();
            }
        }
        $description = str_replace("::", ":", $description);

        return $description;
    }
}