<?php

namespace common\services\parsers;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Yii;
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
    protected string $moduleName;
    protected int $cntProducts = 0;
    protected string $linksFileName;

    abstract function run();

    public function __construct($site)
    {
        $this->site_id = $site["id"];
        $this->name = $site["name"];
        $this->link = $site["link"];
        $this->minid = $site["minid"];
        $this->maxid = $site["maxid"];
        $this->moduleName = $site["modulname"];
        $this->linksFileName = __DIR__ . '/../../../XLSX/' . ucfirst($site['modulname']) . "Links.xlsx";
        if (file_exists($this->linksFileName)) {
            $reader = new Xlsx();
            $this->spreadsheet = $reader->load($this->linksFileName);
        }

        $messageLog = [
            'status' => 'Старт ' . static::class,
            'post' => $this->name,
        ];

        Yii::info($messageLog, 'parse_info'); //запись в parse.log

        $this->reprint();
        $this->print("Создался " . static::class);
    }

}