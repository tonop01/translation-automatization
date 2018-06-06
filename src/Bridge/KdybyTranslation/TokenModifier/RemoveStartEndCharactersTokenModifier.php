<?php

namespace Efabrica\TranslationsAutomatization\Bridge\KdybyTranslation\TokenModifier;

use Efabrica\TranslationsAutomatization\Tokenizer\Token;
use Efabrica\TranslationsAutomatization\TokenModifier\TokenModifier;

class RemoveStartEndCharactersTokenModifier extends TokenModifier
{
    private $startEndCharacter;

    public function __construct(string $startEndCharacter)
    {
        $this->startEndCharacter = $startEndCharacter;
    }

    protected function modify(Token $token): Token
    {
        $targetText = $token->getTargetText();
        $newTargetText = trim($targetText, $this->startEndCharacter);
        $token->changeTargetText($newTargetText);
        return $token;
    }
}
