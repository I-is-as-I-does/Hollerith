<?php

namespace SSITU\Hollerith\Trades;

use \SSITU\Blueprints\Log;

class SchemaProvider implements Log\FlexLogsInterface

{
    use Log\FlexLogsTrait;
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
            $this->log('alert', $msg, $schDir);
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
        } elseif (!array_key_exists($filename, $this->sch)) {
            $this->sch[$filename] = $this->loadSch($filename);
        }
        return $this->sch[$filename];
    }

    private function missingSchemaAlert($filename)
    {
        $msg = 'unable-to-load-schema';
        $this->log('alert', $msg, $filename);
        if ($this->throwException) {
            throw new \Exception("$msg $filename");
        }
    }

    private function loadSch($filename)
    {

        $path = $this->schDir . $filename.'.json';
        $json = false;
        if (is_readable($path)) {
            $json = file_get_contents($path);
        }
        if (empty($json)) {
            $this->missingSchemaAlert($filename);
        }
        return $json;
    }

}
