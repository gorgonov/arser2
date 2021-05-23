<?php

namespace common\services\parsers;

use console\models\ArSite;
use DiDom\Exceptions\InvalidSelectorException;
use PhpOffice\PhpSpreadsheet\Exception;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Yii;
use DiDom\Document;
use common\traits\LogPrint;

abstract class AbstractParser
{
    use LogPrint;

    protected array $aProducts = [];
    protected array $aGroupProducts = [];
    protected string $name;
    protected string $link;
    protected int $minid;
    protected int $maxid;
    protected Spreadsheet $spreadsheet;
    protected int $site_id;

    abstract function run();

    public function __construct($site)
    {
        $this->site_id = $site["id"];
        $this->name = $site["name"];
        $this->link = $site["link"];
        $this->minid = $site["minid"];
        $this->maxid = $site["maxid"];
        $linksFileName = __DIR__ . '/../../../XLSX/DenxLinks.xlsx';
        $reader = new Xlsx();
        $this->spreadsheet = $reader->load($linksFileName);

        $messageLog = [
            'status' => 'Старт ParseDenx.',
            'post' => $this->name,
        ];

        Yii::info($messageLog, 'parse_info'); //запись в parse.log

        $this->reprint();
        $this->print("Создался ParseDenx");
    }

}