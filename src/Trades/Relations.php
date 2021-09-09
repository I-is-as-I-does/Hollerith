<?php

namespace SSITU\Hollerith\Trades;

use \SSITU\Blueprints\Log;

class Relations implements Log\HubLogsInterface

{
    use Log\HubLogsTrait;

    private $Operator;

    private $dbrelations;
    private $map = [];


    public function __construct($Operator)
    {
        $this->Operator = $Operator;
        $this->dbrelations = $Operator->unpreparedDeck('dbrelations');
        $this->dbrelations->enableCache();
    }



    public function deleteRelationsDeck($deckName)
    {
        if ($this->Operator->pass('delete', $deckName)) {
            $card = $this->findRelationsCard($deckName);
            if (!is_int($card)) {
                $card->delete();
                return 204;
            }
            return 404;
        }
        return 403;
    }

       
    public function relationsIdHook($deckName)
    {
        return '__' . $deckName . '.__id';
    }

    public function relationsLabelHook($deckName)
    {
        return '__' . $deckName . '.__label';
    }

    public function getRelatedCards($deckName, $fdeckName, $cardId)
    {
        if ($fdeck = $this->Operator->getDeck($fdeckName)) {
            return $fdeck->findAllBy([$this->relationsIdHook($deckName) => $cardId]);
        }
        return 404;
    }

    public function deckHasRelations($deckName)
    {
        return !is_int($this->deckRelations($deckName));
    }

    public function deckRelations($deckName)
    {
        if (!array_key_exists($deckName, $this->map)) {
            $fdecks = false;
            $card = $this->findRelationsCard($deckName);
            if (!is_int($card)) {
                $fdecks = $card->rlt;
            }
            $this->map[$deckName] = $fdecks;
        }
        if(!empty($this->map[$deckName])){
            return $this->map[$deckName];
        }
        return 404;
    }

    public function findRelationsCard($deckName)
    {
        if($card = $this->dbrelations->findOneBy(['src' => $deckName])){
            return $card;
        }
        return 404;
    }


    public function rmvDeckRelations($srcName, $rltNames)
    {
        if ($this->Operator->pass('remove', $srcName)) {
            $card = $this->findRelationsCard($deckName);
            if (!is_int($card)) {
                $card->rlt = array_diff($card->rlt, $rltNames); //@todo: test
                $card->save();
                return 204;
            }
            return 404;
        }
        return 403;
    }

    public function addDeckRelations($srcName, $rltNames)
    {
        if ($this->Operator->pass('add', $srcName)) {
            $card = $this->findRelationsCard($srcName);
            if (!is_int($card)) {
                $card->rlt = $card->rlt + $rltNames; //@todo: test
            } else {
                $card = $this->dbrelations->get();
                $card->src = $srcName;
                $card->rlt = $rltNames;
            }
            $card->save();
            return 201;
        }
        return 403;
    }

    public function updateRelatedCards($deckName, $card, $operation = 'U')
    {
        if (!$this->Operator->hasRight($operation)) {
            return 403;
        }

        $fdecks = $this->deckRelations($deckName);
        if (!is_int($fdecks)) {

            $cardId = $card->getId();
            foreach ($fdecks as $fdeck) {
                $fcards = $this->getRelatedCards($deckName, $fdeck, $cardId);
                if (!is_int($fcards)) {

                    foreach ($fcards as $fcard) {
                        if ($operation == 'D') {
                            $fcard->setValue($this->relationsIdHook($deckName), "");
                        } else {
                            $fcard->setValue($this->relationsLabelHook($deckName), $card->__label);
                        }

                        $fcard->save();
                    }
                }
            }
            return 200;
        }
        return 404;
    }
  
}