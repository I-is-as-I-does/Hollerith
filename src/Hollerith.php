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

    public function callDeckOperator($boardPath, $crudRights, $createBoard = false)
    {
        if (!$this->isRegistered($boardPath)) {
            $this->store[$boardPath] = $this->assignDeckOperator($boardPath, $crudRights, $createBoard);
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

    private function assignDeckOperator($boardPath, $crudRights, $createBoard = false)
    {
        if (!$this->allSet) {
            return false;
        }

        $dirOnly = false;
        $target = 'R';

        if ($createBoard) {
            if (!$this->adminMode) {
                $this->log('alert', 'database-creation-restricted-to-admin-mode', $boardPath);
                return false;
            }
            $dirOnly = true;
            $target = 'C';
        }

        if ($this->checkBoardPath($boardPath, $dirOnly)
            && $this->checkRights($crudRights, $target)) {

                $boardMap = $this->MapProvider->getBoardMap($boardPath);

                if($boardMap && $board = $this->initBoard($boardPath)){
                  
                        $deckOperator = new Trades\DeckOperator($this->SchemaProvider, $board, $boardMap, $crudRights);
                        $deckOperator->setLogger($this->logger);
                        $this->relayChecker($deckOperator);
                        return $deckOperator;
                    }
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
            $providerClass = __NAMESPACE__ . '\Trades\\' . $baseProvider;
            $this->$baseProvider = new $providerClass($baseDir, $this->throwException);
            $this->$baseProvider->setLogger($this->logger);

            if (!$this->$baseProvider->isOperational()) {
                $this->allSet = false;
                return;
            }
        }
        $this->allSet = true;
    }

    private function checkBoardPath($boardPath, $dirOnly = false)
    {
        if ($dirOnly) {
            $boardPath = dirname($boardPath);
        }
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

    private function checkRights($crudRights, $target = 'R')
    {
        if (!array_key_exists($target, $crudRights) || $crudRights[$target] !== true) {
            $msg = 'no-' . $target . '-right';
            $this->log('warning', $msg, $crudRights);
            if ($this->throwException) {
                throw new \Exception("$msg $crudRights");
            }
            return false;
        }
      
        return true;
    }

}
