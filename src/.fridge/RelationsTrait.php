<?php

namespace SSITU\Hollerith\Trades;

trait RelationsTrait 

{
   
    private $dbrelation;
    private $map = [];


    private function getIdHook($deckName)
    {
        return '__' . $deckName . '.__id';
    }

    private function getLabelHook($deckName)
    {
        return '__' . $deckName . '.__label';
    }

    private function pass($acton, $srcName)
    {
        if ($this->inAdminMode()) {
            return true;
        }
        $this->log('alert', 'unauthorized-attempt-to-'.$acton.'-relation', $srcName);
        return false;
        
    }

    public function deleteDeckRelation($srcName)
    {
       if($this->pass('delete', $srcName)){
        if($card = $this->findRelationsCard($deckName)){
            $card->delete();
        }
    }
    }

    public function rmvDeckRelation($srcName, $rltNames)
    {
        if($this->pass('remove', $srcName)){
        if($card = $this->findRelationsCard($deckName)){
            $card->rlt = array_diff($card->rlt, $rltNames); //@todo: test
            $card->save();
        }
    }
    }

    public function addDeckRelation($srcName, $rltNames)
    {
        if($this->pass('add', $srcName)){
        if($card = $this->findRelationsCard($deckName)){
            $card->rlt = $card->rlt + $rltNames; //@todo: test
        } else  {
            $card = $this->dbrelation->get();
            $card->src = $srcName;
            $card->rlt = $rltNames;
        }  
        $card->save();
    }
    }

    private function updateRltCards($deckName, $card, $unlink = false)
    {
        if ($fdecks = $this->deckRelations($deckName)) {
     
        $cardId = $card->getId();
        foreach ($fdecks as $fdeck) {
            if ($fcards = $this->getRltCards($deckName, $fdeck, $cardId)) {

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
        if(!array_key_exists($deckName, $this->map)){
            $fdecks = false;
             if($card = $this->findRelationsCard($deckName)){
                 $fdecks = $card->rlt;
             }
             $this->map[$deckName] = $fdecks;
        }
        return $this->map[$deckName];
    }

    private function findRelationsCard($deckName)
    {
        return $this->dbrelation->findOneBy(['src' => $deckName]);
    }


    private function loadRelationsDeck()
    {
        $this->dbrelation = $this->getDb()->collection('dbrelation');
        $this->dbrelation->enableCache();
    }

}
