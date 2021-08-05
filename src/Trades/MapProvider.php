<?php

namespace SSITU\Hollerith\Trades;

use \SSITU\Blueprints;

class MapProvider implements Blueprints\HubLogInterface
{
    use Blueprints\HubLogTrait;

    private $throwException;
    private $mapDir;

    private $boardMaps = [];

    public function __construct($mapDir, $throwException = false)
    {
        $this->throwException = $throwException;
        $this->setMapDir();
    }

    public function isOperational()
    {
        return isset($this->mapDir);
    }

    public function getBoardMap($boardPath)
    {
        if (!$this->isOperational() || empty($boardPath)) {
            return false;
        }
        $basename = basename($boardPath, '.db');
        if (!array_key_exists($basename, $this->boardMaps)) {
            $this->boardMaps[$basename] = $this->loadBoardMap($basename);
        }
        return $this->boardMaps[$basename];
    }

    private function setMapDir($mapDir)
    {
        if (!is_readable($mapDir)) {
            $msg = 'invalid-map-dir';
            $this->hubLog('alert', $msg, $mapDir);
            if ($this->throwException) {
                throw new \Exception("$msg $mapDir");
            }
            return false;
        }
        $this->mapDir = $mapDir;
        return true;
    }

    private function loadBoardMap($basename)
    {
        $boardMapPath = $this->mapDir . $basename . '.php';
        if (is_readable($boardMapPath) && $boardMap = $this->requireBoardMap($boardMapPath)) {
            return $this->validMap($boardMap);
        }
        $msg = 'unreadable-boardMap';
        $this->log('alert', $msg, $boardMapPath);
        if ($this->throwException) {
            throw new \Exception("$msg $boardMapPath");
        }
        return false;
    }

    private function requireBoardMap($boardMapPath)
    {
        require_once $boardMapPath;
    }

    private function validMap($boardMap)
    {
        foreach (['sch', 'rlt'] as $baseKey) {
            if (empy($boardMap[$baseKey])) {
                $msg = 'invalid-board-map';
                $this->log('alert', $msg, $boardMap);
                if ($this->throwException) {
                    throw new \Exception("$msg $boardMap");
                }
                return false;
            }
        }
        return $boardMap;
    }

}
