<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class Pdv extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-calculator';
    protected static string | UnitEnum | null $navigationGroup = 'Operação';
    protected static ?string $navigationLabel = 'Balcão (PDV)';
    protected static ?string $slug = 'pdv';
    protected static ?int $navigationSort = 2;
    protected string $view = 'filament.pages.pdv';

    public function getTitle(): string { return 'Balcão (PDV)'; }
    public function getSubheading(): ?string { return 'Venda rápida com envio direto para a cozinha.'; }
}
