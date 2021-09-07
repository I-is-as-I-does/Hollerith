<?php
namespace SSITU\Hollerith\Trades;

class CardOperator
{
 private $DeckOperator;
    private $deckName;

    public function __construct($DeckOperator, $deckName)
    {
        $this->deckName = $deckName;
        $this->DeckOperator = $DeckOperator;
    }
    public function __call($name, $argm)
    {
        if(is_callable([$this->DeckOperator, $name])){
            array_unshift($argm, $this->deckName);
            return $this->DeckOperator->$name(...$argm);
        }     
    }

}