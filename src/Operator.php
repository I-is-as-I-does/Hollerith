<?php

//@doc: since sch validation is implemented, if schema is built this way "type":"object", "properties":{...}: remember to wrap card data in an array

namespace SSITU\Hollerith;

use Gebler\Doclite\Database;
use Gebler\Doclite\Exception\DatabaseException;
use \SSITU\Blueprints\Log;
use \SSITU\Blueprints\Mode;

class Operator implements Log\HubLogsInterface, Mode\HubModeInterface

{
    use Log\HubLogsTrait;
    use Mode\HubModeTrait;

    private $db;
    private $path;
    private $schemasDir;  
    private $crudRights;
    private $throwException;

    private $decks = [];

    private $trades = ['Relations' => null, 'Editions' => null, 'Schemas' => null];

    public function __construct($db, $path, $schemasDir, $crudRights, $throwException = false)
    {
        $this->db = $db;
        $this->path = $path;
        $this->schemasDir = $schemasDir;
        $this->crudRights = $crudRights;
        $this->throwException = $throwException;
    }

    public function __call($name, $argm)
    {
        foreach(['Schemas'=>'schem', 'Relations'=>'relat'] as $trade => $keyword){
            if(stripos($name, $keyword) !== false && method_exists($name, $this->trade($trade))){
                return $this->trades[$trade]->$name(...$argm);
            }
        }
        if(method_exists($name, $this->trade('Editions'))){
            return $this->trades['Editions']->$name(...$argm);
        }
        $msg = 'unknown-method';
        $this->log('error', $msg, $name);
        if ($this->throwException) {
            throw new \Exception($msg.' '.$name);
        }
    }

    public function getDbPath()
    {
        return $this->path;
    }

    public function throwException()
    {
        return $this->throwException;
    }

    public function pass($action, $details = null)
    {
        if ($this->inAdminMode()) {
            return true;
        }
        $this->log('alert', 'unauthorized-attempt-to-' . $action, $details);
        return false;
    }

    public function schDir()
    {
        return $this->schemasDir;
    }

    public function trade($name)
    {
        if (!array_key_exists($name, $this->trades)) {
            $this->log('error', 'unknown-trade', $name);
            return false;
        }
        if (empty($this->trades[$name])) {
            $class = __NAMESPACE__.'\Trades\\'.$name;
            $trade = new $class($this);
            $trade->setHubLog($this->hubLog);
            $this->trades[$name] = $trade;
        }
        return $this->trades[$name];
    }
    
    public function addCrudRight($type)
    {
        if (in_array($type, ['C', 'U', 'D'])) {
            $this->crudRights[$type] = true;
            return true;
        }
        return false;
    }

    public function hasRight($operation)
    {
        return !empty($this->crudRights[$operation]);
    }

    public function getDd()
    {
        if ($this->pass('get-db')) {
            return $this->db;
        }
        return 403;
    }

    public function optimizeCards()
    {
        $this->db->optimize(); //todo test;
    }

    public function export($path, $decks = [], $atomic = false)
    {
        if ($atomic) {
            $argm = Database::MODE_EXPORT_DOCUMENTS;
        } else {
            $argm = Database::MODE_EXPORT_COLLECTIONS;
        }
        $this->db->export($path, 'json', $argm, $decks);
    }


    public function unpreparedDeck($deckName)
    {
        return $this->db->collection($deckName);
    }

    public function getDeck($deckName)
    {
        if (!$this->deckIsRegistered($deckName)) {
            $this->setDeck($deckName, $this->loadDeck($deckName));
        }
        return $this->decks[$deckName];
    }

    public function setDeck($deckName, $deck)
    {
        $this->decks[$deckName] = $deck;
    }

    public function getDeckSequence($deckName, $offset = 0, $limit = 100, $orderByField = '__id', $desc = true)
    {
        //@doc: sorting by __id = sorting by date of creation
        if ($deck = $this->getDeck($deckName)) {
        $order = 'DESC';
        if (!$desc) {
            $order = 'ASC';
        }
        return $deck->orderBy($orderByField, $order)
            ->limit($limit)
            ->offset($offset)
            ->fetch();
        }
    }

    public function deckIsRegistered($deckName)
    {
        return array_key_exists($deckName, $this->decks);
    }

    public function loadDeck($deckName)
    {
        if ($this->deckExists($deckName)) {
            $deck = $this->db->collection($deckName);
            $deck->enableCache(); //@todo: benchmarks
            return $deck;
        }
        $this->log('alert', 'unable-to-load-deck', $deckName);
        return false;
    }

    public function unsetDeck($deckName)
    {
        unset($this->decks[$deckName]);
    }

    public function deckExists($deckName)
    {
        try {
            return $this->db->tableExists($deckName);
        } catch (DatabaseException $e) {
            $this->handleDataException($e, 'missing-deck', ['deck'=>$deckName]);         
            return false;
        }
    }

    public function handleDataException($e, $msg, $details = [])
    {
            $details['param'] = $e->getParams();
            $this->log('error', $msg, $details);
            if ($this->throwException) {
                throw new \Exception($msg.' '.$details['param']['error']);
            }
    }

    public function getCardByValue($deckName, $key, $value)
    {
        if ($deck = $this->getDeck($deckName)) {
        if ($card = $deck->findOneBy([$key => $value])) {
            return $card;
        }
    }

    }

    public function getCardById($deckName, $cardId)
    {

        return $this->getCardByValue($deckName, '__id', $cardId);
    }

    public function cardExists($deckName, $cardId)
    {
        if ($this->deckExists($deckName)) {
            return !empty($this->getCardById($deckName, $cardId));
        }

        return false;
    }

}
