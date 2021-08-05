<?php

namespace SSITU\Hollerith;

use Gebler\Doclite\Exception\DatabaseException;
use Gebler\Doclite\Exception\IOException;
use Gebler\Doclite\FileDatabase;
use Trades\MapProvider;
use Trades\SchemaProvider;
use \SSITU\Blueprints;

class Hollerith implements Blueprints\FlexLogsInterface, Blueprints\AdminModeInterface

{
    use Blueprints\FlexLogsTrait;
    use Blueprints\AdminModeTrait;

    private $store = [];

    private $SchemaProvider;
    private $MapProvider;

    private $schDir;
    private $mapDir;
    private $throwException;

    private $allSet = null;

    public function __construct($schDir, $mapDir, $throwException = false)
    {
        $this->schDir = $schDir;
        $this->mapDir = $mapDir;
        $this->throwException = $throwException;
        $this->callProviders();
    }

    public function isAllSet()
    {
        return $this->allSet;
    }

    public function isRegistered($boardpath)
    {
        return array_key_exists($boardpath, $this->store);
    }

    public function isOperational($boardpath)
    {
        return !empty($this->store[$boardpath]);
    }

    public function callDeckOperator($boardPath, $crudRights)
    {
        if (!$this->isRegistered($boardPath)) {
            $this->store[$boardPath] = $this->assignDeckOperator($boardPath, $crudRights);
        }
        return $this->store[$boardPath];
    }

    public function getAssignedDeckOperator($boardPath)
    {
        if ($this->isRegistered($boardPath)) {
            return $this->store[$boardPath];
        }
        return false;
    }

    private function assignDeckOperator($boardPath, $crudRights)
    {
        if (!$this->allSet) {
            return false;
        }
        if ($this->checkBoardPath($boardPath)
            && $this->checkRights($crudRights)
            && $boardMap = $this->MapProvider->getBoardMap($boardPath)
            && $board = $this->initBoard($boardPath)) {

            $deckOperator = new Trades\DeckOperator($this->SchemaProvider, $board, $boardMap, $crudRights);
            $this->relayLog($deckOperator);
            $this->relayChecker($deckOperator);
            return $deckOperator;
        }
        return false;
    }

    private function initBoard($boardPath)
    {
        try {
            $board = new FileDatabase($boardPath);
            return $board;
        } catch (IOException | DatabaseException $e) {
            $this->log('alert', 'database-loading-exception', ['board-path' => $boardPath, $e->getMessage()]);
            if ($this->throwException) {
                throw $e;
            }
            return false;
        }
    }

    private function callProviders()
    {
        foreach (['SchemaProvider' => $this->schDir, 'MapProvider' => $this->mapDir] as $baseProvider => $baseDir) {
            $this->$baseProvider = new $baseProvider($baseDir, $this->throwException);
            $this->relayLog($this->$baseProvider);

            if (!$this->$baseProvider->isOperational()) {
                $this->allSet = false;
                return;
            }
        }
        $this->allSet = true;
    }

    private function checkBoardPath($boardPath)
    {
        if (!is_readable($boardPath)) {
            $msg = 'unreadable-boardPath';
            $this->log('alert', $msg, $boardPath);
            if ($this->throwException) {
                throw new \Exception("$msg $boardPath");
            }
            return false;
        }
        return true;
    }

    private function checkRights($crudRights)
    {
        if (!array_key_exists('R', $crudRights) || $crudRights['R'] !== true) {
            $msg = 'no-read-right';
            $this->log('warning', $msg, $crudRights);
            if ($this->throwException) {
                throw new \Exception("$msg $crudRights");
            }
            return false;
        }
        return true;
    }

}
