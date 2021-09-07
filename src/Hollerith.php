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
    private $defaultSchDir;

    public function __construct($defaultSchDir = null, $throwException = false)
    {
        $this->defaultSchDir = $defaultSchDir;
        $this->throwException = $throwException;

    }

    public function getCardOperator($path, $crudRights, $schDir = null, $create = false)
    {
        if ($this->passChecks($path, $crudRights, $schDir, $create)
            && $db = $this->initDb($path)) {
            $schProvider = $this->getSchemaProvider($schDir);
            $CardOperator = new Trades\CardOperator($db, $schProvider, $crudRights);

            $this->relayLog($CardOperator);
            $this->relayChecker($CardOperator);

            return $CardOperator;
        }
        return false;
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

    private function passChecks($path, &$crudRights, &$schDir, $create)
    {
        if ($create && !$this->adminMode) {
            $this->log('alert', 'database-creation-restricted-to-admin-mode', $path);
            return false;
        }
        $callParam = $this->callParam($create);
        return $this->checkPath($path, $callParam['dirOnly'])
        && $this->checkRights($crudRights, $callParam['target'])
        && $this->checkSchDir($schDir);
    }

    private function checkSchDir(&$schDir)
    {
        if (empty($schDir)) {
            if (!empty($this->defaultSchDir)) {
                $schDir = $this->defaultSchDir;
            }
        }
        if ($schDir && is_readable($schDir)) {
            $schDir = rtrim($schDir, '/\\') . '/';
            return true;
        }
        $msg = 'unreadable-sch-dir';
        $this->log('alert', $msg, $schDir);
        if ($this->throwException) {
            throw new \Exception("$msg $schDir");
        }
        return false;
    }

    private function getSchemaProvider($schDir)
    {
        $schProvider = new Trades\SchemaProvider($schDir, $this->throwException);
        $this->relayLog($schProvider);
        return $schProvider;
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

    private function checkPath($path, $dirOnly = false)
    {
        if ($dirOnly) {
            $path = dirname($path);
        }
        if (!is_readable($path)) {

            $msg = 'unreadable-db-path';
            $this->log('alert', $msg, $path);
            if ($this->throwException) {
                throw new \Exception("$msg $path");
            }
            return false;
        }
        return true;
    }

    private function checkRights(&$crudRights, $target = 'R')
    {
        if (!array_key_exists($target, $crudRights) || $crudRights[$target] !== true) {
            $msg = 'no-' . $target . '-right';
            $this->log('warning', $msg, $crudRights);
            if ($this->throwException) {
                throw new \Exception("$msg $crudRights");
            }
            return false;
        }
        
            $crudRights = array_filter($crudRights, function ($itm) {return !empty($itm);});
        return true;
    }

}
