<?php

namespace SSITU\Hollerith\Trades;


class SchemaProvider implements Log\HubLogsInterface

{
    use Log\HubLogsTrait;

    private $schDir;
    private $throwException;
    private $sch = [];

    public function __construct($schDir, $throwException = false)
    {
        $this->schDir = $schDir;
        $this->throwException = $throwException;
    }

    private function setSch($deckName)
    {
        $this->sch[$deckName] =$this->loadSch($deckName);
            return !empty($this->sch[$deckName]);
    }

    public function getSch($deckName)
    {    
        if (!array_key_exists($deckName, $this->sch)) {
            $this->setSch($deckName);
        }
        return $this->sch[$deckName];
    }

    private function missingSchAlert($deckName)
    {
        $msg = 'unable-to-load-schema';
        $this->log('alert', $msg, $deckName);
        if ($this->throwException) {
            throw new \Exception("$msg $deckName");
        }
    }

    private function loadSch($deckName)
    {
        $content = false;
        if($this->schDir){
        $path = $this->schDir . $deckName . '-sch.json';
       
        if (is_readable($path)) {
                $content = file_get_contents($path);
            }
        if (empty($content)) {
            $this->missingSchAlert($path);
        }
    }
        return $content;
    }

}
