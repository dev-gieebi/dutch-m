<?php

namespace Tests\Unit;

use App\Support\Format;
use App\Support\Template;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function test_telephones_gabonais(): void
    {
        $this->assertSame('+24177123456', Format::normalizeGabonPhone('+241 77 12 34 56'));
        $this->assertSame('+24177123456', Format::normalizeGabonPhone('00241 77123456'));
        $this->assertSame('+24177123456', Format::normalizeGabonPhone('241 77123456'));
        $this->assertNull(Format::normalizeGabonPhone('12'));
        $this->assertSame('+241 77 12 34 56', Format::phone('+24177123456'));
    }

    public function test_montants_et_slugs(): void
    {
        $this->assertSame('12 500 FCFA', Format::fcfa(12500));
        $this->assertSame('fleurs-eternelles-du-gabon', Format::slugify('Fleurs éternelles du Gabon !'));
    }

    public function test_gabarits(): void
    {
        $tpl = '<p>{{name}}</p>{{#if comment}}<i>{{comment}}</i>{{else}}-{{/if}}{{{raw}}}';
        $this->assertSame('<p>A &amp; B</p><i>ok</i><b>x</b>', Template::render($tpl, ['name' => 'A & B', 'comment' => 'ok', 'raw' => '<b>x</b>']));
        $this->assertSame('<p></p>-', Template::render($tpl, []));
        $this->expectException(\InvalidArgumentException::class);
        Template::validate('{{#if x}}sans fin');
    }
}
