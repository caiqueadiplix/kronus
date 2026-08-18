<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class MesasComandas extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-squares-2x2';
    protected static string | UnitEnum | null $navigationGroup = 'Salão';
    protected static ?string $navigationLabel = 'Mesas e comandas';
    protected static ?string $slug = 'mesas-comandas';
    protected static ?int $navigationSort = 3;
    protected string $view = 'filament.pages.mesas-comandas';

    public function getTitle(): string { return 'Mesas e comandas'; }
    public function getSubheading(): ?string { return 'Controle de contas, consumo e fechamento do salão.'; }
}
