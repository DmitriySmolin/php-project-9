<?php

namespace Parser;

use DiDom\Document;
use DiDom\Exceptions\InvalidSelectorException;
use Illuminate\Support\Str;

class Parser
{
    /**
     * @throws InvalidSelectorException
     */
    public static function parseHtml(string $body): array
    {
        $document = new Document($body);

        $h1 = Str::limit(optional($document->first('h1'))->text() ?? '', 255);
        $title = optional($document->first('title'))->text();
        $description = optional($document->first('meta[name=description]'))->getAttribute('content');

        return [
            'h1' => $h1,
            'title' => $title,
            'description' => $description,
        ];
    }
}
