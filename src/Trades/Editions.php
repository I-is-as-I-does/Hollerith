<?php

namespace SSITU\Hollerith\Trades;

use Gebler\Doclite\DatabaseException;
use \SSITU\Blueprints\Log;

class Editions implements Log\HubLogsInterface

{
    use Log\HubLogsTrait;

    private $Operator;
    private $cardswithSch = [];


    public function __construct($Operator)
    {
        $this->Operator = $Operator;
    }

    public function loadDeckForPunch($deckName, $data, $operation)
    {
        if (!$this->Operator->hasRight($operation)) {
            return 403;
        }
        if (empty($data)) {
            $this->log('notice', 'empty-data');
            return 400;
        }

        if ($deck = $this->Operator->getDeck($deckName)) {
            if (!$this->Operator->trade('Schemas')->setSchema($deckName)) {
                unset($deck);
                return 500;
            }
            return $deck;
        }
        return 404;
    }

    public function shredCard($deckName, $cardId)
    {
        if (!$this->Operator->hasRight('D')) {
            return 403;
        }
        if ($card = $this->Operator->getCardById($deckName, $cardId)) {
            $this->updateThenDelete($deckName, $card);
            return 204;
        }
        return 404;
    }

    public function updateThenDelete($deckName, $card)
    {
        if (!$this->Operator->hasRight('D')) {
            return 403;
        }
  
            $this->Operator->trade('Relations')->updateRelatedCards($deckName, $card, 'D');

        $card->delete();
        return 204;
    }

    public function createCard($deckName, $data, $givenId = null, $returnId = false)
    {

        $deck = $this->loadDeckForPunch($deckName, $data, 'C');
        if (is_int($deck)) {

            return $deck;
        }
        $sch = $this->Operator->trade('Schemas')->getSchema($deckName);
        if (!empty($givenId)) {
            if ($this->Operator->cardExists($deckName, $givenId)) {
                return 409; #conflict
            }
            $card = $deck->get($givenId);
        } else {
            $card = $deck->get();
        }

        $card->addJsonSchema($sch);
        $transc = $this->cardTransaction($deck, $card, $data, 'C');
        if ($transc === 201) {
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
            $sch = $this->Operator->trade('Schemas')->getSchema($deckName);
            $card->addJsonSchema($sch);
            $this->cardswithSch[] = $cardId;
        }
        $transc = $this->cardTransaction($deck, $card, $data, 'U');
        if ($transc === 201) {
            if (array_key_exists('__label', $data)) {
                $this->Operator->trade('Relations')->updateRelatedCards($deckName, $card, 'U');
            }
            return 201;
        }
        return 400;
    }

    public function cardTransaction($deck, $card, $data, $operation)
    {

        if (!$this->Operator->hasRight($operation)) {
            return 403;
        }
        try {
            $deck->beginTransaction();
            foreach ($data as $key => $value) {
                $card->setValue($key, $value);
            }
            $card->save();
            $deck->commit();
            return 201;
        } catch (DatabaseException $e) {
            $this->Operator->handleDataException($e, 'validation-fail');     
            $deck->rollback();
            return 400;
        }
    }

    public function shredAllDeckCards($deckName, $andDeleteDeck = false)
    {
        if ($this->Operator->pass('shred-all-cards', $deckName)) {
            if ($deck = $this->Operator->getDeck($deckName)) {
                foreach ($deck->findAll() as $card) {
                    $this->updateThenDelete($deckName, $card);
                }
                if($andDeleteDeck){
                    $deck->delete();
                    $this->Operator->unsetDeck($deckName);
                }
                return 204;
            }
            return 404;
        }
        return 403;
    }

    public function createDeck($deckName)
    {

        if (!$this->Operator->pass('create-deck', $deckName)) {
            return 403;
        }
        if ($this->Operator->deckExists($deckName)) {
            $this->log('error', 'attempt-to-create-an-already-existing-deck', $deckName);
            return 409; #conflict
        }
        if (!$this->Operator->trade('Schemas')->setSchema($deckName)) {
            $this->log('error', 'attempt-to-create-a-deck-without-corresponding-schema', $deckName);
            return 422; #Unprocessable Entity
        }
        try {
            $deck = $this->Operator->unpreparedDeck($deckName);
        } catch (DatabaseException $e) {
            $this->Operator->handleDataException($e,'failure-to-create-deck',['deck'=>$deckName]);     
            return 500;
        }
        $deck->enableCache(); //@todo: benchmarks
        $this->Operator->setDeck($deckName, $deck);
        return 201;

    }


}