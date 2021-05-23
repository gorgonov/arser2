<?php

namespace common\traits;

use Yii;

trait LogPrint
{
    /**
     * @var float время начала выполнения скрипта
     */
    private static float $time_start = .0;

    /**
     * Начало выполнения
     */
    static function start()
    {
        self::$time_start = microtime(true);
    }

    /**
     * Разница между текущей меткой времени и меткой self::$time_start
     * @return string
     */
    static function finish(): string
    {
        $tm = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];
        $hour = floor($tm/3600);
        $sec = $tm - ($hour*3600);
        $min = floor($sec/60);
        $sec = $sec - ($min*60);

        return sprintf("%02d:%02d:%02d", $hour, $min, $sec);

    }

    /**
     * Возвращает имя лога (соответствует имени модуля парсера)
     * @return string
     */
    public function getLogName(): string
    {
        $array = explode('\\', static::class);
        $a = $array[count($array) - 1];
        return Yii::getAlias('@runtime') . '/logs/' . $a . '.log';
    }

    /**
     * Выводит в лог строку с таймстампом
     * @param string $message
     */
    public function print(string $message)
    {
        $dt = date("d.m.Y H:i:s");
        $fd = fopen($this->getLogName(), 'at');
        fprintf($fd, "%s %s\r\n", $dt, $message);
        fclose($fd);
    }

    /**
     * Выводит время работы скрипта (строку) в лог
     */
    public function endprint()
    {
        $dt = date("d.m.Y H:i:s");
        $fd = fopen($this->getLogName(), 'at');
        fprintf($fd, "%s Время работы скрипта: %s\r\n", $dt, $this->finish());
        fclose($fd);
    }

    /**
     * Выводит в лог время старта скрипта
     */
    public function reprint()
    {
        $dt = date("d.m.Y H:i:s");
        $fd = fopen($this->getLogName(), 'wb');
        fprintf($fd, "%s START %s\r\n", $dt, static::class);
        fclose($fd);
    }
}