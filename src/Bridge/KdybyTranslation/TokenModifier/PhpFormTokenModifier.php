<?php

namespace Efabrica\TranslationsAutomatization\Bridge\KdybyTranslation\TokenModifier;

use Efabrica\TranslationsAutomatization\Tokenizer\Token;
use Efabrica\TranslationsAutomatization\TokenModifier\TokenModifier;

class PhpFormTokenModifier extends TokenModifier
{
    protected function modify(Token $token): Token
    {
        $token->changeTranslationCode($token->getTranslationKey());
        return $token;
    }
}
