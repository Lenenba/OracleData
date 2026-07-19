<?php

namespace App\Enums;

enum QueryTemplateRole: string
{
    case Editor = 'template_editor';
    case Publisher = 'template_publisher';
}
