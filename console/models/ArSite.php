<?php

namespace console\models;

use phpDocumentor\Reflection\Types\Integer;
use Yii;

/**
 * This is the model class for table "ar_site".
 *
 * @property string $id ID
 * @property string $name Наименование
 * @property string $link Базовая ссылка
 * @property string $modulname Имя модуля обработки
 * @property string $minid Стартовый id продукта
 * @property string $maxid Финишный id продукта
 * @property string $mult Множитель
 * @property int $status 0-bad, 1-ok
 */
class ArSite extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'ar_site';
    }

    public static function getSiteById($site_id)
    {
        $sql = 'SELECT * FROM ar_site WHERE id=' . $site_id;
        $result = Yii::$app->db->createCommand($sql)->queryOne();
        return $result;
    }

    public static function getSiteByName($site_name)
    {
        $sql = 'SELECT * FROM ar_site WHERE modulname="' . $site_name . '"';
        $result = Yii::$app->db->createCommand($sql)->queryOne();

        return $result;
    }


    /**
     * Возвращает сайт, требующий парсинга (status='get')
     * @return array|false
     */
    public static function getSiteToParse()
    {
        $sql = 'select * from ar_site a where a.status=\'get\' and NOT EXISTS (select b.* from ar_site b where b.status=\'parse\') limit 1;';
        $result = Yii::$app->db->createCommand($sql)->queryOne();

        return $result;
    }

    /**
     * Удаляет все продукты указанного сайта (site_id)
     * @param int $site_id ID сайта
     * @return true|false
     * @throws \Throwable
     */
    public static function delModulData($site_id)
    {

        Yii::$app->db->createCommand()->delete('ar_product', 'site_id=:site_id')
            ->bindParam(':site_id', $site_id)
            ->execute();
        return true;
    }

    /**
     * Добавляет запись о продукте в таблицу ar_product
     * @param array $pInfo
     * @throws \yii\db\Exception
     */
    public static function addProduct(array $pInfo)
    {
//        $params = [
//            'id' => $pInfo['product_id'],
//            'site_id' => $pInfo['site_id'],
//            'name' => $pInfo['topic'],
//            'price' => $pInfo['new_price'],
//            'images_link' => serialize($pInfo['aImgLink']),
//            'product_info' => serialize($pInfo),
//            'status' => 'new',
//        ];
//        Yii::$app->db->createCommand('INSERT INTO ar_product(id, site_id, name, price, images_link, product_info, status) VALUES(:id, :site_id, :name, :price, :images_link, :product_info, :status)', $params)->execute();
//        Yii::$app->db->createCommand('SET SESSION wait_timeout = 28800;')->execute();

        Yii::$app->db->createCommand()->upsert('ar_product', [
            'id' => $pInfo['product_id'],
            'site_id' => $pInfo['site_id'],
            'name' => $pInfo['topic'],
            'price' => $pInfo['new_price'],
            'images_link' => serialize($pInfo['aImgLink']),
            'product_info' => serialize($pInfo),
            'status' => 'new'
        ])->execute();

    }

    /**
     * set site status for id
     * @param int $site_id
     * @param string $status
     * @throws \yii\db\Exception
     */
    public static function setStatus(int $site_id, string $status)
    {
        // если нету продуктов, то new заменяем на ok
        if ($status == 'new') {
            $sql = 'SELECT COUNT(p.id) as productCount, p.site_id FROM ar_product p WHERE p.site_id=:id;';
            $result = Yii::$app->db->createCommand($sql,['id' => $site_id])->queryOne();
            if ($result['productCount'] == 0) {
                $status = 'ok';
            }
        }
        // Обновление записи
        $params = [
            ':id' => $site_id,
            ':status' => $status,
        ];
        $cmd = Yii::$app->db->createCommand('UPDATE ar_site SET status=:status WHERE id=:id', $params);
//        var_dump($cmd->rawSql);
        $cmd->execute();
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['name', 'link', 'modulname', 'minid', 'maxid', 'mult', 'status'], 'required'],
            [['minid', 'maxid', 'status'], 'integer'],
            [['mult'], 'number'],
            [['name'], 'string', 'max' => 30],
            [['link', 'modulname'], 'string', 'max' => 255],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'name' => 'Наименование',
            'link' => 'Базовая ссылка',
            'modulname' => 'Имя модуля обработки',
            'minid' => 'Стартовый id продукта',
            'maxid' => 'Финишный id продукта',
            'mult' => 'Множитель',
            'status' => '0-bad, 1-ok',
        ];
    }
}
