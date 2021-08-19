<?php

namespace common\services\parsers;

use console\helpers\ParseUtil;
use console\models\ArSite;
use DiDom\Exceptions\InvalidSelectorException;
use Exception;
use Throwable;
use Yii;
use PhpOffice\PhpSpreadsheet\Exception as OfficeException;
use yii\db\Exception as dbException;

class VmebelParser extends AbstractParser
{

    const DEBUG = false;

    /**
     * @throws InvalidSelectorException
     * @throws OfficeException
     * @throws Throwable
     * @throws dbException
     */
    public function run()
    {
        if (!isset($this->spreadsheet)) {
            throw new Exception('Не удалось открыть файл ' . $this->linksFileName);
        }

        if (!self::DEBUG) {
            ArSite::delModulData($this->site_id);
            ArSite::setStatus($this->site_id, 'parse');
        }

        // 1. Обработаем 1 лист - ссылки на группы товаров
        $this->runGroupProducts();

        // 2. Обработаем 2 лист - ссылки на товары
        $this->runProducts();
        // 3. Добавим товары со страниц с группами товаров
        $this->addProducts();

        // 4. Парсим товары, пишем в БД
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
     * @throws OfficeException
     */
    protected function runGroupProducts(): void
    {
        $worksheet = $this->spreadsheet->setActiveSheetIndex(0);
        $highestRow = $worksheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $category = $worksheet->getCell('A' . $row)->getValue();
            $category = ParseUtil::dotToComma($category);
            $link = $worksheet->getCell('B' . $row)->getValue();
            $this->print('Добавляем страницу: ' . $link);
            $this->aGroupProducts[] = [
                'category' => $category,
                'link' => $link
            ];
        }
        $this->aGroupProducts = ParseUtil::unique_multidim_array($this->aGroupProducts, 'link');
    }

    /**
     * @throws OfficeException
     */
    protected function runProducts(): void
    {
        $worksheet = $this->spreadsheet->setActiveSheetIndex(1);
        $highestRow = $worksheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            $category = $worksheet->getCell('A' . $row)->getValue();
            $category = implode(',', preg_split('/[.,]/', $category));// поправка, если разделитель - '.'
            $link = $worksheet->getCell('B' . $row)->getValue();
            if (trim($link) == '') {
                return;
            }
            $this->aProducts[] = [
                'category' => $category,
                'link' => $link
            ];
        }
    }

    /**
     * @throws InvalidSelectorException
     */
    protected function addProducts(): void
    {
        foreach ($this->aGroupProducts as $item) { // на странице ссылки на продукты
            $cat = $item['category'];
            $link = $item['link'] . '&limit=100';
            $this->getProducts($link, $cat);
        }
    }

    /**
     * @param string $link
     * @param string $cat
     * @throws InvalidSelectorException
     */
    private function getProducts(string $link, string $cat): void
    {
        if (trim($link) == '') {
            return;
        }

        $this->print('Качаем страничку ' . $link);
        $doc = ParseUtil::tryReadLink($link);

        $aProducts = $doc->find('.products-list__name');
        $countProducts = count($aProducts);
        $this->print("Найдено $countProducts продуктов на странице");

        // на странице есть товары с ценниками
        if ($countProducts > 0) {
            $i = 0;
            foreach ($aProducts as $el) {
                $i++;

                $link = $el->attr('href');
                $this->print('Обрабатываем страницу: ' . $link . ". Категория $cat. Продукт $i/$countProducts");
                $this->aProducts[] = [
                    'category' => $cat,
                    'link' => $link
                ];
            }
        }
    }

    /**
     * @param string $link
     * @return array
     * @throws InvalidSelectorException
     * @throws Exception
     */
    protected function getProductInfo(string $link): array
    {
        if (trim($link) == '') {
            return [];
        }

        $this->print('Обрабатываем страницу: ' . $link);
        $doc = ParseUtil::tryReadLink($link);
        if (!$doc) {
            throw new Exception('Не удалось прочитать страницу ' . $link);
        }

        $ar = [];

        $ar['topic'] = trim($doc->first('.catalogue__product-name')->innerHtml()); // Заголовок товара

        $ar['new_price'] = parseUtil::normalSum($doc->first('.catalogue__price>span')->text()); // Цена
        if ($ar['new_price'] == '') {
            $ar['new_price'] = $doc->first("span[itemprop='price']")->attr('content');
        }

        $aImgLink = array();
        $aImg = $doc->find('.product-page__img-slider-item>a'); // список картинок для карусели
        foreach ($aImg as $el) {
            $href = $el->attr('href');
            $aImgLink [] = $href;
        }
        $aImgLink = array_unique($aImgLink);

        $ar['aImgLink'] = $aImgLink;

        $dt = $doc->find('.product-info__list>dt');
        $dd = $doc->find('.product-info__list>dd');

        $aTmp = array();
        for ($i = 0; $i < count($dt); $i++) {
            $aTmp[] = trim($dt[$i]->text()) . ' ' . trim($dd[$i]->text());
        }
        $s = implode('<br>', $aTmp);

        $description = trim($doc->first('.editor')->text());

        // найдем размеры в формате "Высота, мм 2100<br>Глубина, мм 550<br>Ширина, мм 1600"
        $aTmp = $this->getSizes($s . $description);
        if (isset($aTmp)) {
            $ar['attr'] = $aTmp;
        }

        $ar['product_teh'] = $s . '<p>' . $description;

        return $ar;
    }

    /**
     * @param string $str
     * @return array|null
     */
    protected function getSizes(string $str): ?array
    {
        $re = '/Высота[^\d]*(\d+).*Глубина[^\d]*(\d+).*Ширина[^\d]*(\d+)/m';

        $aTmp = [];
        if (preg_match_all($re, $str, $matches, PREG_SET_ORDER)) {
            $aTmp['Высота'] = $matches[0][1];
            $aTmp['Глубина'] = $matches[0][2];
            $aTmp['Ширина'] = $matches[0][3];
            return $aTmp;
        }

        return null;
    }

    /**
     * @throws InvalidSelectorException
     * @throws \yii\db\Exception
     */
    protected function runItems()
    {
        $product_id = $this->minid;
        foreach ($this->aProducts as $el) {
            $lnk = $el['link'];
            $cat = $el['category'];
            $productInfo = $this->getProductInfo($lnk);
            $productInfo['site_id'] = $this->site_id;
            $productInfo['link'] = $lnk;
            $productInfo['category'] = $cat;
            $productInfo['product_id'] = $product_id++;
            $productInfo['model'] = 'Доставим через 3-7 дней';
            $productInfo['manufacturer'] = 'г.Красноярск';
            $productInfo['subtract'] = true;
//            $productInfo['subtract'] = Если есть в наличии то true если нет то false

            ArSite::addProduct($productInfo);
            $this->cntProducts++;
        }
    }

}