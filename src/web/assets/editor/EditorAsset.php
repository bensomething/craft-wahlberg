<?php

namespace bensomething\wahlberg\web\assets\editor;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class EditorAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $js = [
        'editor.js',
    ];

    public $css = [
        'editor.css',
    ];
}
