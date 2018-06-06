<?php

use Efabrica\TranslationsAutomatization\Bridge\KdybyTranslation\Storage\NeonFileStorage;
use Efabrica\TranslationsAutomatization\TranslationMaker\TranslationMaker;
use Efabrica\TranslationsAutomatization\Translator\BingTranslator;

$translationMaker = new TranslationMaker();

$sourceStorage = new NeonFileStorage($basePath . '/app/lang/dictionary.sk_SK.neon', '    ');
$targetStorage = new NeonFileStorage($basePath . '/app/lang/dictionary.hu_HU.neon', '    ');
$translator = new BingTranslator('sk', 'hu');
$translationMaker->add($sourceStorage, $targetStorage, $translator);
//
//$sourceStorage = new NeonFileStorage($basePath . '/app/lang/dictionary.sk_SK.neon', '    ');
//$targetStorage = new NeonFileStorage($basePath . '/app/lang/dictionary.cs_CZ.neon', '    ');
//$translator = new BingTranslator('sk', 'cs');
//$translationMaker->add($sourceStorage, $targetStorage, $translator);
//
//$sourceStorage = new NeonFileStorage($basePath . '/app/lang/dictionary.sk_SK.neon', '    ');
//$targetStorage = new NeonFileStorage($basePath . '/app/lang/dictionary.de_DE.neon', '    ');
//$translator = new BingTranslator('sk', 'de');
//$translationMaker->add($sourceStorage, $targetStorage, $translator);

return $translationMaker;
