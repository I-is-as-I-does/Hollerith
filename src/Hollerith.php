<?php

namespace SSITU\Hollerith;

use Gebler\Doclite\Exception\DatabaseException;
use Gebler\Doclite\Exception\IOException;
use Gebler\Doclite\FileDatabase;
use \SSITU\Blueprints\Log;
use \SSITU\Blueprints\Mode;

class Hollerith implements Log\FlexLogsInterface, Mode\AdminModeInterface

{
    use Log\FlexLogsTrait;
    use Mode\AdminModeTrait;

    private $throwException;
    private $defaultSchemasDir;

    public function __construct($defaultSchemasDir = null, $throwException = false)
    {
        $this->defaultSchemasDir = $defaultSchemasDir;
        $this->throwException = $throwException;

    }

    public function getOperator($path, $crudRights, $schemasDir = null, $create = false)
    {
        if (is_null($schemasDir)) {
                $schemasDir = $this->defaultSchemasDir;
        }
        if ($this->passChecks($path, $crudRights, $schemasDir, $create)
            && $db = $this->initDb($path)) {
            $Operator = new Operator($db, $path, $schemasDir, $crudRights, $this->throwException);

            $this->relayLog($Operator);
            $this->relayChecker($Operator);

            return $Operator;
        }
        return false;
    }

    
    private function initDb($path)
    {
        try {
            $db = new FileDatabase($path);
            return $db;
        } catch (IOException | DatabaseException $e) {
            $this->log('alert', 'database-loading-exception', ['db-path' => $path, $e->getMessage()]);
            if ($this->throwException) {
                throw $e;
            }
            return false;
        }
    }

  

    private function passChecks($path, $crudRights, $schemasDir, $create)
    {
        if ($create && !$this->adminMode) {
            $this->log('alert', 'database-creation-restricted-to-admin-mode', $path);
            return false;
        }
        $callParam = $this->callParam($create);
        return $this->checkPath($path, $callParam['dirOnly'])
        && $this->checkRights($crudRights, $callParam['target'])
        && $this->checkSchemasDir($schemasDir);
    }

    private function callParam($create)
    {
        if ($create) {
            return [
                'dirOnly' => true,
                'target' => 'C',
            ];
        }
        return [
            'dirOnly' => false,
            'target' => 'R',
        ];
    }

    private function checkSchemasDir($schemasDir)
    {
        if (!is_null($schemasDir) && is_readable($schemasDir)) {
            return true;
        }
        $msg = 'unreadable-schemas-dir';
        $this->log('alert', $msg, $schemasDir);
        if ($this->throwException) {
            throw new \Exception("$msg $schemasDir");
        }
        return false;
    }


    private function checkPath($path, $dirOnly = false)
    {
        if ($dirOnly) {
            $path = dirname($path);
        }
        if (is_readable($path)) {
            return true;
        }

            $msg = 'unreadable-db-path';
            $this->log('alert', $msg, $path);
            if ($this->throwException) {
                throw new \Exception("$msg $path");
            }
            return false;
    }

    private function checkRights($crudRights, $target = 'R')
    {
        if(!empty($crudRights[$target])){
            return true;
        }
            $msg = 'no-' . $target . '-right';
            $this->log('warning', $msg, $crudRights);
            if ($this->throwException) {
                throw new \Exception("$msg $crudRights");
            }
            return false;

    }

}
