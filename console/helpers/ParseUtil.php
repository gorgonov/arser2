<?php

namespace console\helpers;

use DiDom\Document;

// будем получать страницы сайтов
use console\models\ArSite;

// будем сохранять в БД
use Yii;

class ParseUtil
{
    public static function dotToComma(string $text)
    {
        return str_replace(".", ",", $text);
    }

    /**
     * @param $sum - строка, содержащая цифры и текст
     * @return int - возвращает целое число, состоящее из цифр $sum
     */
    public static function normalSum($sum)
    {
        $result = (int)preg_replace("/[^0-9]/", '', $sum);
        return $result;
    }

    /**
     * получает содержимое страницы по ссылке $link (3 попытки)
     * @param $link
     */
    public static function tryReadLink($link)
    {
//        $link = ParseUtil::myUrlEncode($link); // перекодируем кириллицу ...
        $noTry = ["Перавая", "Вторая", "Третья"];
        for ($i = 0; $i < 3; $i++) {
            $result = ParseUtil::get_web_page($link);

            if (($result['errno'] != 0) || ($result['http_code'] != 200)) {
                $mess = $noTry[$i] . " попытка. link=";
                print_r($link);
                $status = 'Ошибка №=' . $result['errno'] . ' http_code=' . $result['http_code'] . " " . $result['errmsg'];
                echo "$mess\n, $status \n";
                sleep(3);
            } else {
//                echo str_repeat("=",10);
                $page = $result['content'];
//                print_r($page);
                $document = new Document($page, false, 'UTF-8', Document::TYPE_HTML);
//                $document = ParseUtil::myUrlEncode($document); // перекодируем кириллицу ...
//                echo str_repeat("-",10);
//                print_r($document->html());
//                die();
                return $document;
            }
        }
        return false; // не удалось скачать страницу
    }

    /**
     * @param $string
     * @return заменяет в $string кириллицу на %nn
     */
    protected static function myUrlEncode($string)
    {
        $entities = array(
            '%21',
            '%2A',
            '%27',
            '%28',
            '%29',
            '%3B',
            '%3A',
            '%40',
            '%26',
            '%3D',
            '%2B',
            '%24',
            '%2C',
            '%2F',
            '%3F',
            '%25',
            '%23',
            '%5B',
            '%5D'
        );
        $replacements = array(
            '!',
            '*',
            "'",
            "(",
            ")",
            ";",
            ":",
            "@",
            "&",
            "=",
            "+",
            "$",
            ",",
            "/",
            "?",
            "%",
            "#",
            "[",
            "]"
        );
        return str_replace($entities, $replacements, urlencode($string));
    }

    function get_contents($url)
    {
        try {
            $json = @file_get_contents($url, true); //getting the file content

            if ($json == false) {
                $json = @file_get_contents(myUrlEncode($url), true); //getting the file content
            }
        } catch (Exception $e) {
            echo $e->getMessage();
        }
        return $json;
    }

    // ----------------------------- функции --------------------------------
// Входные параметры:
// url — адрес страницы или сайта.
// Значения выходных параметров (массив с тремя элементами):
// header[‘errno’] — если что-то пошло не так, то тут будет код ошибки.
// header[‘errmsg’] — здесь при этом будет текст ошибки.
// header[‘content’] — собственно сама страница\файл\картинка и т.д.

    public static function get_web_page($url)
    {
        $uagent = "Opera/9.80 (Windows NT 6.1; WOW64) Presto/2.12.388 Version/12.14";
        $uagent = "Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/61.0.3163.100 YaBrowser/17.10.1.1204 Yowser/2.5 Safari/537.36";
        $uagent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.60 YaBrowser/20.12.0.963 Yowser/2.5 Safari/537.36";

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);   // возвращает веб-страницу
        curl_setopt($ch, CURLOPT_HEADER, 0);           // не возвращает заголовки
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);   // переходит по редиректам
        curl_setopt($ch, CURLOPT_ENCODING, "");        // обрабатывает все кодировки
        curl_setopt($ch, CURLOPT_USERAGENT, $uagent);  // useragent
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120); // таймаут соединения
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);        // таймаут ответа
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);       // останавливаться после 10-ого редиректа
// адрес прокси НЕ ПОЛУЧИЛОСЬ :(
// curl_setopt($ch, CURLOPT_PROXY, '138.68.240.218:3128');
// для https-протокола
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $content = curl_exec($ch);
        $err = curl_errno($ch);
        $errmsg = curl_error($ch);
        $header = curl_getinfo($ch);
        curl_close($ch);

        $header['errno'] = $err;
        $header['errmsg'] = $errmsg;
        $header['content'] = $content;
        return $header;
    }

    public static function unique_multidim_array($array, $key)
    {
        $temp_array = array();
        $i = 0;
        $key_array = array();

        foreach ($array as $val) {
            if (!in_array($val[$key], $key_array)) {
                $key_array[$i] = $val[$key];
                $temp_array[$i] = $val;
            }
            $i++;
        }
        return $temp_array;
    }

    /**
     * @param string|string[] $ptr - шаблон (строка) или массив строк в utf8 (что искать)
     * @param string $replace - чем заменить
     * @param string $str - строка, в которой выполнять замены
     * @param bool $cr - если true, то многострочная замена (глобальная)
     * @return null|string|string[]
     */
    public static function utf8_replace($ptr, $replace, $str, $cr = false)
    {
        if (is_array($ptr)) {
            foreach ($ptr as $p) {
                $str = ParseUtil::utf8_replace($p, $replace, $str, $cr);
            }
            return $str;
        }
        return preg_replace('/' . preg_quote($ptr, '/') . '/su' . ($cr ? 'i' : ''), $replace, $str);
    }

}