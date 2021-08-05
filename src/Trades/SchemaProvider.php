<?php

namespace SSITU\Hollerith\Trades;

use \SSITU\Blueprints;

class SchemaProvider implements Blueprints\HubLogInterface

{
    use Blueprints\HubLogTrait;
    private $schDir;

    private $sch = [];
    private $throwException;

    public function __construct($schDir, $throwException = false)
    {
        $this->schDir = $schDir;
        $this->throwException = $throwException;
        $this->setSchDir($schDir);
    }

    private function setSchDir($schDir)
    {
        if (!is_readable($schDir)) {
            $msg = 'unreadable-sch-dir';
            $this->hubLog('alert', $msg, $schDir);
            if ($throwException) {
                throw new \Exception("$msg $schDir");
            }
        } else {
            $this->schDir = $schDir;
        }
    }

    public function isOperational()
    {
        return isset($this->schDir);
    }

    public function getSch($filename)
    {
        if (!$this->isOperational()) {
            return false;
        }
        if (empty($filename)) {
            $this->sch[$filename] = false;
            $this->missingSchemaAlert($filename);
        }
        elseif (!array_key_exists($this->sch[$filename])) {
            $this->sch[$filename] = $this->loadSch($filename);
        }
        return $this->sch[$filename];
    }

    private function missingSchemaAlert($filename)
    {
        $msg = 'unable-to-load-schema';
        $this->hubLog('alert', $msg, $schIdent);
        if ($this->throwException) {
            throw new \Exception("$msg $schIdent");
        }
    }

    private function loadSch($filename)
    {
        $path = $this->schDir . $filename;
        $json = false;
        if(is_readable($path)){
            $json = file_get_contents($path);
        }     
        if (empty($json)) {
           $this->missingSchemaAlert($filename);
        }
        return $json;
    }

}
