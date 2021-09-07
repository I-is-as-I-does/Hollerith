<?php

//@doc: since sch validation is implemented, if schema is built this way "type":"object", "properties":{...}: remember to wrap card data in an array

namespace SSITU\Hollerith\Trades;

use Gebler\Doclite\Database;
use Gebler\Doclite\Exception\DatabaseException;
use \SSITU\Blueprints\Log;
use \SSITU\Blueprints\Mode;

class CardOperator implements Log\HubLogsInterface, Mode\HubModeInterface

{
    use Log\HubLogsTrait;
    use Mode\HubModeTrait;

    private $db;
    private $schProvider;
    private $crudRights;

    private $cardswithSch = [];

    private $decks = [];

    private $dbrelations;
    private $map = [];

    public function __construct($db,$schProvider,$crudRights)
    {
        $this->db = $db;
        $this->schProvider = $schProvider;
        $this->crudRights = $crudRights;
        $this->loadRelationsDeck();
    }


    public function extend()
    {
        if ($this->inAdminMode()) {

        }
        if(count($this->crudRights) > 1){
            return true;
         }

    }
 
    public function hasRight($operation)
    {
        return !empty($this->crudRights[$operation]);

    }

    public function optimizeCards()
    {
        $this->db->optimize(); //todo test;
    }
   
    public function export($path, $decks = [])
    {
        $this->db->export($path, 'json', Database::MODE_EXPORT_COLLECTIONS, $decks);
    }

    public function atomicExport($path, $decks = [])
    {
        $this->db->export($path, 'json', Database::MODE_EXPORT_DOCUMENTS, $decks);
    }

    private function updateRltCards($deckName, $card, $unlink = false)
    {
        if ($fdecks = $this->deckRelations($deckName)) {

            $cardId = $card->getId();
            foreach ($fdecks as $fdeck) {
                if ($fcards = $this->getRelatedCards($deckName, $fdeck, $cardId)) {

                    foreach ($fcards as $fcard) {
                        if ($unlink) {
                            $fcard->setValue($this->getIdHook($deckName), "");
                        } else {
                            $fcard->setValue($this->getLabelHook($deckName), $card->__label);
                        }

                        $fcard->save();
                    }
                }
            }
        }
    }


    private function loadDeckForPunch($deckName, $data, $operation)
    {
        if (!$this->hasRight($operation)) {
            return 403;
        }
        if (!$this->cardHasData($data)) {
            return 400;
        }

        if ($deck = $this->getDeck($deckName)  && $this->schProvider->setSch($deckName)) {
            return $deck;
        }
        return 404;
    }

    public function shredCard($deckName, $cardId)
    {
        if (!$this->hasRight('D')) {
            return 403;
        }
        if ($card = $this->getCardById($deckName, $cardId)) {
            $this->updateThenDelete($deckName, $card);
            return 204;
        }
        return 404;
    }

    private function updateThenDelete($deckName, $card)
    {
        if ($this->deckHasRelations($deckName)) {
            $this->updateRltCards($deckName, $card, true);
        }

        $card->delete();
    }


    public function createCard($deckName, $data, $givenId = null, $returnId = false)
    {

        $deck = $this->loadDeckForPunch($deckName, $data, 'C');
        if (is_int($deck)) {

            return $deck;
        }
        $sch = $this->schProvider->getSch($deckName);
        if (!empty($givenId)) {
            if ($this->cardExists($deckName, $givenId)) {
                return 409; #conflict
            }
            $card = $deck->get($givenId);
        } else {
            $card = $deck->get();
        }

        $card->addJsonSchema($sch);

        if ($this->cardTransaction($deck, $card, $data)) {
            $id = $card->getId();
            $this->cardswithSch[] = $id;

            if ($returnId) {
                return $id;
            }
            return 201;
        }
        $card->delete();
        return 400;
    }

    public function updateCard($deckName, $cardId, $data)
    {
        $deck = $this->loadDeckForPunch($deckName, $data, 'U');
        if (is_int($deck)) {
            return $deck;
        }

        $card = $this->getCardById($deckName, $cardId);
        if (empty($card)) {
            return 404;
        }
        if (!$this->cardHasSch($cardId)) {
            $sch = $this->schProvider->getSch($deckName);
            $card->addJsonSchema($sch);
            $this->cardswithSch[] = $cardId;
        }
        if ($this->cardTransaction($deck, $card, $data)) {
            if (array_key_exists('__label', $data)) {
                $this->updateRltCards($deckName, $card, false);
            }
            return 201;
        }
        return 400;
    }

    private function cardTransaction($deck, $card, $data)
    {
        try {

            $deck->beginTransaction();
            foreach ($data as $key => $value) {
                $card->setValue($key, $value);
            }

            $card->save();
            $deck->commit();
            return true;
        } catch (DatabaseException $e) {
            $this->log('notice', 'validation-fail', ['card' => $card->getId(), 'reasons' => $e->getParams()['error']]);
            $deck->rollback();
            return false;
        }
    }

    private function cardHasData($data)
    {
        if (empty($data)) {
            $this->log('notice', 'empty-data');
            return false;
        }
        return true;
    }

    private function getIdHook($deckName)
    {
        return '__' . $deckName . '.__id';
    }

    private function getLabelHook($deckName)
    {
        return '__' . $deckName . '.__label';
    }

    public function getRelatedCards($deckName, $fdeckName, $cardId)
    {
        if ($fdeck = $this->getDeck($fdeckName)) {
            return $fdeck->findAllBy([$this->getIdHook($deckName) => $cardId]);
        }
    }

    public function deckHasRelations($deckName)
    {
        return !empty($this->deckRelations($deckName));
    }

    public function deckRelations($deckName)
    {
        if (!array_key_exists($deckName, $this->map)) {
            $fdecks = false;
            if ($card = $this->findRelationsCard($deckName)) {
                $fdecks = $card->rlt;
            }
            $this->map[$deckName] = $fdecks;
        }
        return $this->map[$deckName];
    }

    private function findRelationsCard($deckName)
    {
        return $this->dbrelations->findOneBy(['src' => $deckName]);
    }

    public function getDeck($deckName)
    {
        if (!$this->deckIsRegistered($deckName)) {
            $this->decks[$deckName] = $this->loadDeck($deckName);
        }
        return $this->decks[$deckName];
    }

    public function getDeckSequence($deckName, $offset = 0, $limit = 100, $orderByField = '__id', $desc = true)
    {
        //@doc: sorting by __id = sorting by date of creation
        $deck = $this->getDeck($deckName);
        if (empty($deck)) {
            return 500;
        }
        $order = 'DESC';
        if (!$desc) {
            $order = 'ASC';
        }
        return $deck->orderBy($orderByField, $order)
            ->limit($limit)
            ->offset($offset)
            ->fetch();
    }

    public function deckExists($deckName)
    {
        try {
            return $this->db->tableExists('unitsetting');
        } catch (DatabaseException $e) {
            $this->log('error', 'missing-deck', $e);
            return false;
        }

    }

    private function deckIsRegistered($deckName)
    {
        return array_key_exists($deckName, $this->decks);
    }

    public function getCardByValue($deckName, $key, $value)
    {
        $deck = $this->getDeck($deckName);
        if (empty($deck)) {
            return 404;
        }
        if ($card = $deck->findOneBy([$key => $value])) {
            return $card;
        }
        return 404;
    }

    public function getCardById($deckName, $cardId)
    {

        return $this->getCardByValue($deckName, '__id', $cardId);
    }

    public function cardExists($deckName, $cardId)
    {
        if ($this->deckExists($deckName)) {
            return !is_int($this->getCardById($deckName, $cardId));
        }

        return false;
    }

    private function loadRelationsDeck()
    {
        $this->dbrelations = $this->getDb()->collection('dbrelations');
        $this->dbrelations->enableCache();
    }

    private function loadDeck($deckName)
    {
        if ($this->deckExists($deckName)) {
            $deck = $this->db->collection($deckName);
            $deck->enableCache(); //@todo: benchmarks
            return $deck;
        }
        $this->log('alert', 'unable-to-load-deck', $deckName);
        return false;
    }
}
