<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/bin', __DIR__ . '/public', __DIR__ . '/src', __DIR__ . '/tests'])
    ->append([__FILE__]);

return new PhpCsFixer\Config()
    ->setRules(['@PER-CS' => true])
    ->setUsingCache(false)
    ->setFinder($finder);
