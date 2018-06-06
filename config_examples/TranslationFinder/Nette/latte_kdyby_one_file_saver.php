<?php

use Efabrica\TranslationsAutomatization\Bridge\KdybyTranslation\Saver\OneFileTranslationSaver;
use Efabrica\TranslationsAutomatization\Bridge\KdybyTranslation\Storage\NeonFileStorage;
use Efabrica\TranslationsAutomatization\Bridge\KdybyTranslation\TokenModifier\LatteTokenModifier;
use Efabrica\TranslationsAutomatization\Bridge\KdybyTranslation\TokenModifier\LatteParamsExtractorTokenModifier;
use Efabrica\TranslationsAutomatization\Bridge\KdybyTranslation\TokenModifier\PhpFormTokenModifier;
use Efabrica\TranslationsAutomatization\FileFinder\FileFinder;
use Efabrica\TranslationsAutomatization\TextFinder\RegexTextFinder;
use Efabrica\TranslationsAutomatization\Tokenizer\Tokenizer;
use Efabrica\TranslationsAutomatization\TokenModifier\BingTranslateTokenModifier;
use Efabrica\TranslationsAutomatization\TokenModifier\FalsePositiveRemoverTokenModifier;
use Efabrica\TranslationsAutomatization\TokenModifier\FilePathToKeyTokenModifier;
use Efabrica\TranslationsAutomatization\TokenModifier\LowercaseUnderscoredTokenModifier;
use Efabrica\TranslationsAutomatization\TokenModifier\PrefixTranslationKeyTokenModifier;
use Efabrica\TranslationsAutomatization\TranslationFinder\TranslationFinder;

$storage = new NeonFileStorage($basePath . '/app/lang/dictionary.sk_SK.neon', 'dictionary.', '    ');
$saver = new OneFileTranslationSaver($storage);
$translationFinder = new TranslationFinder($saver);

$fileFinder = new FileFinder([$basePath . '/app'], ['latte']);

$textFinder = new RegexTextFinder();
$textFinder->addPattern('/\{\_(.*?)\}/', null);
$textFinder->addPattern('/title=\"([\p{L}\h\.\,\!\?\/\_\-]+)\"/siu');
$textFinder->addPattern('/alt=\"([\p{L}\h\.\,\!\?\/\_\-]+)\"/siu');
$textFinder->addPattern('/placeholder=\"([\p{L}\h\.\,\!\?\/\_\-]+)\"/siu');
$textFinder->addPattern('/data-modal-title-small=\"([\p{L}\h\.\,\!\?\/\_\-]+)\"/siu');
$textFinder->addPattern('/data-modal-body=\"([\p{L}\h\.\,\!\?\/\_\-\$\<\>\{\}\(\)\']+)\"/siu');
$textFinder->addPattern('/[\>\}](\s)*\{if \$(.*?)\}(\s)*[\<\{]/iu', null);
$textFinder->addPattern('/[\>\}](\s)*\{\$(.*?)\}(\s)*[\<\{]/iu', null);
$textFinder->addPattern('/\{\/if\}/u', null);
$textFinder->addPattern('/\{else\}/u', null);
$textFinder->addPattern('/\{\/ifCurrent\}/u', null);

$textFinder->addPattern('/[^-]\>([\p{L}\h\.\,\!\?\/\_\-\$\>\{\}\(\)\']+)\</siu');
$textFinder->addPattern('/\}([\p{L}\h\.\,\!\?\/\_\-\$\>\{\}\(\)\']+)\{/siu');

$tokenizer = new Tokenizer($fileFinder, $textFinder);
$tokenizer->addTokenModifier(new LatteParamsExtractorTokenModifier(
    [
        'count($channels)' => 'channelsCount',
        'date(\'j.n.Y\', strtotime($actualDate))' => 'actualDate',
    ]
));

$tokenizer->addTokenModifier(new BingTranslateTokenModifier('sk', 'en'));
$tokenizer->addTokenModifier(new LowercaseUnderscoredTokenModifier());
$tokenizer->addTokenModifier(new FilePathToKeyTokenModifier($basePath, ['presenters', 'templates', 'components', 'modules']));
$tokenizer->addTokenModifier(new PrefixTranslationKeyTokenModifier('dictionary.'));
$tokenizer->addTokenModifier(new LatteTokenModifier());
$tokenizer->addTokenModifier((new FalsePositiveRemoverTokenModifier())->addFalsePositivePattern('/} selected{/', '/selected/'));

$translationFinder->addTokenizer($tokenizer);


$fileFinder = new FileFinder([$basePath . '/app'], ['php']);

$textFinder = new RegexTextFinder();
$textFinder->addPattern('/->(addText|addTextArea|addCheckbox|addCheckboxList|addEmail|addInteger|addSelect|addMultiSelect|addUpload|addMultiUpload|addPassword|addRadioList|addSubmit|addButton)\((.*?)\, \'(.*?)\'/', 3);
$textFinder->addPattern('/->addChooze(.*?)\((.*?)\, \'(.*?)\'/', 3); // efabrica specific


$textFinder->addPattern('/->setAttribute\(\'placeholder\', \'(.*?)\'/', 1 );

$tokenizer = new Tokenizer($fileFinder, $textFinder);
$tokenizer->addTokenModifier(new BingTranslateTokenModifier('sk', 'en'));
$tokenizer->addTokenModifier(new LowercaseUnderscoredTokenModifier());
$tokenizer->addTokenModifier(new FilePathToKeyTokenModifier($basePath, ['presenters', 'templates', 'components', 'modules']));
$tokenizer->addTokenModifier(new PrefixTranslationKeyTokenModifier('dictionary.'));
$tokenizer->addTokenModifier(new PhpFormTokenModifier());   // TODO rename to switch text for key or so
$translationFinder->addTokenizer($tokenizer);

return $translationFinder;