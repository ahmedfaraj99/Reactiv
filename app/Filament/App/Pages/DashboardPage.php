<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use Filament\Pages\Dashboard;

/**
 * Employees see their personal assignment stats here (via
 * EmployeeAssignmentStatsWidget); everyone else sees OverviewStatsWidget.
 * The "حساباتي" page stays a pure working list of assigned accounts.
 */
class DashboardPage extends Dashboard
{
    protected static ?string $navigationLabel = 'الإحصائيات';
}
