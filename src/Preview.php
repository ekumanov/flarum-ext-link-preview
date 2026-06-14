<?php

namespace Ekumanov\LinkPreview;

use Flarum\Database\AbstractModel;

class Preview extends AbstractModel
{
    protected $table = 'ekumanov_link_previews';

    public $timestamps = false;

    protected $casts = [
        'opengraph' => 'array',
        'icons' => 'array',
        'fallback' => 'array',
        'api_resource' => 'array',
        'exif' => 'array',
    ];
}
