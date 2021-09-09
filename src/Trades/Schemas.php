<?php

namespace SSITU\Hollerith\Trades;

use \SSITU\Blueprints\Log;
class Schemas implements Log\HubLogsInterface

{
    use Log\HubLogsTrait;

    private $schemasDir;
    private $throwException;
    private $sch = [];

    public function __construct($Operator)
    { 
        $this->schemasDir = rtrim($Operator->schDir(), '/\\') . '/';
        $this->throwException = $Operator->throwException();   
    }

    public function setSchema($deckName)
    {
        $this->sch[$deckName] =$this->loadSchema($deckName);
            return !empty($this->sch[$deckName]);
    }

    public function getSchema($deckName)
    {    
        if (!array_key_exists($deckName, $this->sch)) {
            $this->setSchema($deckName);
        }
        return $this->sch[$deckName];
    }

    public function missingSchemaAlert($deckName)
    {
        $msg = 'unable-to-load-schema';
        $this->log('alert', $msg, $deckName);
        if ($this->throwException) {
            throw new \Exception("$msg $deckName");
        }
    }

    public function loadSchema($deckName)
    {
        $content = false;
        $path = $this->schemasDir . $deckName . '-sch.json';
       
        if (is_readable($path)) {
                $content = file_get_contents($path);
            }
        if (empty($content)) {
            $this->missingSchemaAlert($path);
        }
        return $content;
    }

}
