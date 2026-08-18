<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class Pedidos extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static string | UnitEnum | null $navigationGroup = 'Operação';
    protected static ?string $navigationLabel = 'Pedidos';
    protected static ?string $slug = 'pedidos';
    protected static ?int $navigationSort = 1;
    protected string $view = 'filament.pages.pedidos';

    public function getTitle(): string { return 'Operação ao vivo'; }
    public function getSubheading(): ?string { return 'Acompanhe e movimente os pedidos em tempo real.'; }
}
