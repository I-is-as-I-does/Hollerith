<?php
 namespace SSITU\Hollerith\Trades;

 use Gebler\Doclite\Database; //@todo: need?

 class RelationResolver {

    public static function resolveReadOnlyRlt($card, $rltField, $fdeck, $pile)
    {
        //@if _ = read only, fetch cards where current id is listed in rlt_field; get id and label of card
        $nestedCards = $fdeck->where(ltrim($rltField, '_') . '.__id', '=', $card->getId())->fetch();
        if ($nestedCards->current()) {
            foreach ($nestedCards as $fCard) {
                foreach (['__id', '_label'] as $field) {
                    $pile[$field] = $fCard->$field;
                }
            }
            if ($pile != $card->$rltField) {
                $card->$rltField = $pile; # @todo: test; maybe require to use setValue()
                $card->save();
            }
        }
        return $card;

    }

    public static function resolveRlt($card, $rltField, $fdeck, $pile)
    {

        foreach ($pile as $k => $fCardData) {
            $fCard = $fdeck->findOneBy(['__id' => $fCardData['__id']]);
            if (!$fCard) {
                unset($pile[$k]); //@doc it's a choice; could also flag it as unlinked; could be mapped in the php conf file
            } elseif ($fCardData['_label'] != $fCard->_label) {
                $pile[$k]['_label'] = $fCard->_label;
            }
        }
        if ($pile != $card->$rltField) {
            $card->$rltField = $pile; # @todo: test; maybe require to use setValue()
            $card->save();
        }

        return $card;
    }

 }