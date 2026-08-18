<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class Cozinha extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-fire';
    protected static string | UnitEnum | null $navigationGroup = 'Operação';
    protected static ?string $navigationLabel = 'Cozinha (KDS)';
    protected static ?string $slug = 'cozinha';
    protected static ?int $navigationSort = 4;
    protected string $view = 'filament.pages.cozinha';

    public function getTitle(): string { return 'Cozinha (KDS)'; }
    public function getSubheading(): ?string { return 'Pedidos em produção, organizados por tempo de espera.'; }
}
