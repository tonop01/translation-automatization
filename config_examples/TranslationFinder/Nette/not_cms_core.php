<?php

use Efabrica\TranslationsAutomatization\Bridge\KdybyTranslation\Saver\OneFileTranslationSaver;
use Efabrica\TranslationsAutomatization\Bridge\KdybyTranslation\Storage\NeonFileStorage;
use Efabrica\TranslationsAutomatization\Bridge\KdybyTranslation\TokenModifier\PhpParamsExtractorTokenModifier;
use Efabrica\TranslationsAutomatization\Bridge\KdybyTranslation\TokenModifier\PhpTranslatorTokenModifier;
use Efabrica\TranslationsAutomatization\Bridge\KdybyTranslation\TokenModifier\RemoveStartEndCharactersTokenModifier;
use Efabrica\TranslationsAutomatization\FileFinder\FileFinder;
use Efabrica\TranslationsAutomatization\TextFinder\RegexTextFinder;
use Efabrica\TranslationsAutomatization\Tokenizer\Tokenizer;
use Efabrica\TranslationsAutomatization\TokenModifier\BingTranslateTokenModifier;
use Efabrica\TranslationsAutomatization\TokenModifier\FilePathToKeyTokenModifier;
use Efabrica\TranslationsAutomatization\TokenModifier\LowercaseUnderscoredTokenModifier;
use Efabrica\TranslationsAutomatization\TokenModifier\PrefixTranslationKeyTokenModifier;
use Efabrica\TranslationsAutomatization\TranslationFinder\TranslationFinder;

$storage = new NeonFileStorage($basePath . '/lang/core.sk_SK.neon', 'core.', '    ');
$saver = new OneFileTranslationSaver($storage);
$translationFinder = new TranslationFinder($saver);
/*
$fileFinder = new FileFinder([$basePath . '/src'], ['latte']);

$textFinder = new RegexTextFinder();
$textFinder->addPattern('/\{\_(.*?)\}/', null);
$textFinder->addPattern('/\{block title\}\{\/block\}/', null);
$textFinder->addPattern('/\{sep\}/', null);
$textFinder->addPattern('/\{\/sep\}/', null);
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

$textFinder->addPattern('/[^-]\>([\p{L}\h\.\,\!\?\/\_\-\$\>\{\}\(\)\']+)[\:]{0,1}\</siu');
$textFinder->addPattern('/\}([\p{L}\h\.\,\!\?\/\_\-\$\>\{\}\(\)\']+)[\:]{0,1}\{/siu');

$tokenizer = new Tokenizer($fileFinder, $textFinder);
$tokenizer->addTokenModifier(new LatteParamsExtractorTokenModifier(
    [
        'count($plugins)' => 'pluginsCount',
        'count($results)' => 'resultsCount',
        'count($snippets)' => 'snippetsCount',
    ]
));
$tokenizer->addTokenModifier(new BingTranslateTokenModifier('sk', 'en'));
$tokenizer->addTokenModifier(new LowercaseUnderscoredTokenModifier());
$tokenizer->addTokenModifier(new FilePathToKeyTokenModifier($basePath, ['src', 'presenters', 'templates', 'components', 'modules']));
$tokenizer->addTokenModifier(new PrefixTranslationKeyTokenModifier('core.'));
$tokenizer->addTokenModifier((new FalsePositiveRemoverTokenModifier())->addFalsePositivePattern('/} selected{/', '/selected/'));
$tokenizer->addTokenModifier(new LatteTokenModifier());

$translationFinder->addTokenizer($tokenizer);


$fileFinder = new FileFinder([$basePath . '/src'], ['php']);

$textFinder = new RegexTextFinder();
$textFinder->addPattern('/->(addText|addTextArea|addCheckbox|addCheckboxList|addEmail|addInteger|addSelect|addMultiSelect|addUpload|addMultiUpload|addPassword|addRadioList|addSubmit|addButton)\((.*?)\, \'(.*?)\'/', 3);
$textFinder->addPattern('/->addChooze(.*?)\((.*?)\, \'(.*?)\'/', 3); // efabrica specific
$textFinder->addPattern('/->setRequired\(\'(.*?)\'/', 1);
$textFinder->addPattern('/->setAttribute\(\'placeholder\', \'(.*?)\'/', 1);
$textFinder->addPattern('/->setOption\(\'description\', \'(.*?)\'/', 1);
$textFinder->addPattern('/->setPrompt\(\'(.*?)\'/', 1);
$textFinder->addPattern('/->addGroup\(\'(.*?)\'/', 1);
$textFinder->addPattern('/ConfigItem\(\'(.*?)\', \'(.*?)\'/', 2); // notcms specific

$tokenizer = new Tokenizer($fileFinder, $textFinder);
$tokenizer->addTokenModifier(new BingTranslateTokenModifier('sk', 'en'));
$tokenizer->addTokenModifier(new LowercaseUnderscoredTokenModifier());
$tokenizer->addTokenModifier(new FilePathToKeyTokenModifier($basePath, ['src', 'presenters', 'templates', 'components', 'modules']));
$tokenizer->addTokenModifier(new PrefixTranslationKeyTokenModifier('core.'));
$tokenizer->addTokenModifier(new PhpFormTokenModifier());   // TODO rename to switch text for key or so
$translationFinder->addTokenizer($tokenizer);
*/
// TODO need some work to be done
$fileFinder = new FileFinder([$basePath . '/src'], ['php']);
//$fileFinder = new FileFinder([$basePath . '/app'], ['php']);
$textFinder = new RegexTextFinder();
$textFinder->addPattern('/flashMessage\((.*?)[\,\)]/', 1);
$tokenizer = new Tokenizer($fileFinder, $textFinder);
$tokenizer->addTokenModifier(new RemoveStartEndCharactersTokenModifier('\''));
$tokenizer->addTokenModifier(new PhpParamsExtractorTokenModifier());
$tokenizer->addTokenModifier(new BingTranslateTokenModifier('sk', 'en'));
$tokenizer->addTokenModifier(new LowercaseUnderscoredTokenModifier());
$tokenizer->addTokenModifier(new FilePathToKeyTokenModifier($basePath, ['src', 'presenters', 'templates', 'components', 'modules']));
$tokenizer->addTokenModifier(new PrefixTranslationKeyTokenModifier('core.'));
$tokenizer->addTokenModifier(new PhpTranslatorTokenModifier());
$translationFinder->addTokenizer($tokenizer);

return $translationFinder;
