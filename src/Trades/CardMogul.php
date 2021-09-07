<?php

//@doc: since sch validation is implemented, if schema is built this way "type":"object", "properties":{...}: remember to wrap card data in an array

namespace SSITU\Hollerith\Trades;

use Gebler\Doclite\Exception\DatabaseException;
use \SSITU\Blueprints\Log;
use \SSITU\Blueprints\Mode;

class CardOperator implements Log\HubLogsInterface, Mode\HubModeInterface

{
    use Log\HubLogsTrait;
    use Mode\HubModeTrait;

    private $db;
    private $schProvider;
    private $deckOp;

    public function __construct($db,$schProvider,$deckOp)
    {
        $this->db = $db;
        $this->schProvider = $schProvider;
        $this->deckOp = $deckOp;
    }

    public function getDb()
    {
        return $this->db;
    }
    
    public function shredDeck($deckName)
    {
        if (!$this->inAdminMode()) {
            $this->log('alert', 'unauthorized-call-to-shred-deck', $deckName);
            return false;
        }
        $this->shredAllDeckCards($deckName);
        $this->db->collection($deckName)->delete(); //todo test
        return true;
    }

    public function shredAllDeckCards($deckName)
    {
        if (!$this->inAdminMode()) {
            $this->log('alert', 'unauthorized-call-to-shred-all-cards', $deckName);
            return 403;
        }
        if ($deck = $this->getDeck($deckName)) {
            foreach ($deck->findAll() as $card) {
                $this->updateThenDelete($deckName, $card);
            }
            return 204;
        }
        return 404;
    }

    
    public function createDeck($deckName)
    {

        if (!$this->inAdminMode()) {
            $this->log('alert', 'attempt-to-create-deck-in-non-admin-mode', $deckName);
            return 403;
        }
        if ($this->deckExists($deckName)) {
            $this->log('error', 'attempt-to-create-an-already-existing-deck', $deckName);
            return 409; #conflict
        }
        if (!$this->schProvider->setSch($deckName)) {
            $this->log('error', 'attempt-to-create-a-deck-without-corresponding-schema', $deckName);
            return 422; #Unprocessable Entity
        }
        try {
            $deck = $this->db->collection($deckName);
        } catch (DatabaseException $e) {
            $this->log('error', 'failure-to-create-deck', $deckName, $e);
            return 500;
        }
        $deck->enableCache(); //@todo: benchmarks
        $this->decks[$deckName] = $deck;
        return 201;

    }

    private function pass($acton, $srcName)
    {
        if ($this->inAdminMode()) {
            return true;
        }
        $this->log('alert', 'unauthorized-attempt-to-' . $acton . '-relation', $srcName);
        return false;

    }

    public function deleteDeckRelation($srcName)
    {
        if ($this->pass('delete', $srcName)) {
            if ($card = $this->findRelationsCard($deckName)) {
                $card->delete();
            }
        }
    }

    public function rmvDeckRelation($srcName, $rltNames)
    {
        if ($this->pass('remove', $srcName)) {
            if ($card = $this->findRelationsCard($deckName)) {
                $card->rlt = array_diff($card->rlt, $rltNames); //@todo: test
                $card->save();
            }
        }
    }

    public function addDeckRelation($srcName, $rltNames)
    {
        if ($this->pass('add', $srcName)) {
            if ($card = $this->findRelationsCard($deckName)) {
                $card->rlt = $card->rlt + $rltNames; //@todo: test
            } else {
                $card = $this->dbrelation->get();
                $card->src = $srcName;
                $card->rlt = $rltNames;
            }
            $card->save();
        }
    }
}