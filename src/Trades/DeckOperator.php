<?php

namespace SSITU\Hollerith\Trades;

use Gebler\Doclite\Database;
use Gebler\Doclite\Exception\DatabaseException;
use \SSITU\Blueprints\Log;
use \SSITU\Blueprints\Mode;

class DeckOperator implements Log\FlexLogsInterface, Mode\HubModeInterface

{
    use Log\FlexLogsTrait;
    use Mode\HubModeTrait;

    private $db;
    private $dbMap;
    private $crudRights;

    private $SchemaProvider;

    private $decks = [];
    private $cardswithSch = [];
    private $cardOperators = [];

    public function __construct($SchemaProvider, $db, $dbMap, $crudRights)
    {

        $this->SchemaProvider = $SchemaProvider;
        $this->db = $db;
        $this->dbMap = $dbMap;
        $this->setRights($crudRights);
    }

    public function optimizeBoard()
    {
        $this->db->optimize(); //todo test;
    }

    public function getBoard()
    {
        return $this->db;
    }

    public function exportBoard($path, $decks = [])
    {
        $this->db->export($path, 'json', Database::MODE_EXPORT_COLLECTIONS, $decks);
    }

    public function atomicExportBoard($path, $decks = [])
    {
        $this->db->export($path, 'json', Database::MODE_EXPORT_DOCUMENTS, $decks);
    }

    public function getCardOperator($deckName)
    {
        if (!$this->cardOpIsRegistered($deckName)) {
            $this->cardOperators[$deckName] = new CardOperator($this, $deckName);
        }
        return $this->cardOperators[$deckName];
    }

    public function cardOpIsRegistered($deckName)
    {
        return array_key_exists($deckName, $this->cardOperators);
    }

    public function getDeck($deckName)
    {
        if (!$this->deckIsRegistered($deckName)) {
            $this->decks[$deckName] = $this->loadDeck($deckName);
        }
        return $this->decks[$deckName];
    }

    public function shredDeck($deckName)
    {
        if (!$this->inAdminMode()) {
            $this->log('alert', 'unauthorized-call-to-shred-deck', $deckName);
            return false;
        }
        $this->db->collection($deckName)->delete(); //todo test
        return true;
    }

    public function getDeckSlice($deckName, $offset = 0, $limit = 100, $orderByField = '__id', $desc = true)
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
        $cards = $deck->orderBy($orderByField, $order)
            ->limit($limit)
            ->offset($offset)
            ->fetch();

        foreach ($cards as $k => $card) {
            $cards[$k] = $this->resolveCard($deckName, $card);
        }
        return $cards;
    }

    public function shredAllDeckCards($deckName)
    {
        if (!$this->inAdminMode()) {
            $this->log('alert', 'unauthorized-call-to-shred-all-cards', $deckName);
            return false;
        }
        if ($deck = $this->getDeck($deckName)) {
            $deck->deleteAll();
            return 204;
        }
        return 404;
    }

    public function getCardById($deckName, $cardId)
    {
        $deck = $this->getDeck($deckName);
        if (empty($deck)) {
            return 404;
        }
        $card = $deck->where('__id', '=', $cardId);
        if ($card->count()) {
            return $this->resolveCard($card);
        }
        return 404;
    }

    public function shredCard($deckName, $cardId)
    {
        if (!$this->checkAuth('D')) {
            return 403;
        }
        if ($card = $this->getCardById($deckName, $cardId)) {
            $card->delete();
            return 204;
        }
        return 404;
    }

    public function createCard($deckName, $data)
    {
 
        $deck = $this->loadDeckForPunch($deckName, $data, 'C');
        if (is_int($deck)) {
            return $deck;
        }
        $sch = $this->getDeckSch($deckName);
        if (empty($sch)) {
            return 500;
        }

        $card = $deck->get();
        $card->addJsonSchema($sch);

        if ($this->cardTransaction($deck, $card, $data)) {
            $this->cardswithSch[] = $card->getId();
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
            $sch = $this->getDeckSch($deckName);
            if (empty($sch)) {
                return 500;
            }
            $card->addJsonSchema($sch);
            $this->cardswithSch[] = $cardId;
        }

        if ($this->cardTransaction($deck, $card, $data)) {
            return 201;
        }
        return 400;
    }

    protected function loadDeckForPunch($deckName, $data, $operation)
    {
        if (!$this->checkRights($operation)) {
            return 403;
        }
        if (!$this->cardHasData($data)) {
            return 400;
        }

        if ($deck = $this->getDeck($deckName)) {
            return $deck;
        }
        return 404;
    }

    protected function resolveCard($deckName, $card)
    {
        $deckRltMap = $this->getDeckRltMap($deckName);
        if (empty($deckRltMap)) {
            return $card;
        }
        $dataArr = $card->toArray();
        $nested = array_intersect($deckRltMap, array_keys($dataArr));
        if (empty($nested)) {
            return $card; //@doc aka no relations, or relations not implemented as fields
        }
        foreach ($nested as $rltField) {
            if ($rltField[0] != '_' && empty($dataArr[$rltField])) {
                continue;
            }
            $fdeckName = explode('_', ltrim($rltField, '_'))[1];
            $fdeck = $this->getDeck($fdeckName);
            if (!$fdeck) {
                continue;
            }
            if ($rltField[0] == '_') {
                $card = RelationResolver::resolveReadOnlyRlt($card, $rltField, $fdeck, []);
            } else {
                $card = RelationResolver::resolveRlt($card, $rltField, $fdeck, $dataArr[$rltField]);
            }
        }
        return $card;
    }

    protected function getDeckRltMap($deckName)
    {
        if ($this->deckisInMap($deckName)) {
            return $this->dbMap['rlt'][$deckName];
        }
        return [];
    }

    protected function deckisInMap($deckName)
    {
        return array_key_exists($deckName, $this->dbMap['sch']);
    }

    protected function deckIsRegistered($deckName)
    {
        return array_key_exists($deckName, $this->decks);
    }

    protected function getDeckSch($deckName)
    {
        return $this->SchemaProvider->getSch($this->getDeckSchFilename($deckName));
    }

    protected function getDeckSchFilename($deckName)
    {
        if (!$this->deckisInMap($deckName)) {
            $this->dbMap['sch'][$deckName] = false;
            $this->log('alert', 'missing-schema-path-in-board-map', $deckName);
            return false;
        }
        return $this->dbMap['sch'][$deckName];
    }

    protected function loadDeck($deckName)
    {
        if ($this->deckIsInMap($deckName)) {
            $deck = $this->db->collection($deckName);
            $deck->enableCache(); //@todo: benchmarks
            return $deck;
        }
        return false;
    }

# crud rights methods

    protected function setRights($crudRights)
    {
        foreach (['C', 'R', 'U', 'D'] as $prop) {
            if (!array_key_exists($prop, $crudRights) || $crudRights[$prop] !== true) {
                echo $prop;
                $crudRights[$prop] = false;
            }
        }
        $this->crudRights = $crudRights;
    }

    protected function checkRights($operation)
    {
        return !empty($this->crudRights[$operation]);

    }

# card-level methods
    protected function cardHasSch($cardId)
    {
        return in_array($cardId, $this->cardswithSch);
    }

    protected function cardTransaction($deck, $card, $data)
    {

        $deck->beginTransaction();
        try {
            foreach ($data as $key => $value) {
                $card->setValue($key, $value);
            }
            $card->save();
            $deck->commit();
            return true;
        } catch (\DatabaseException$e) {
            $this->log('notice', 'validation-fail', ['card' => $card->getId(), 'reasons' => $e->getParams()['error']]);
            $deck->rollback();
            return false;
        }
    }

    protected function cardHasData($data)
    {
        if (empty($data)) {
            $this->log('notice', 'empty-data');
            return false;
        }
        return true;
    }

}
