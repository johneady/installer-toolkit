<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema;
use InstallerToolkit\Update\Models\UpdateHistory as UpdateHistoryModel;
use InstallerToolkit\Update\UpdateService;
use Throwable;

/**
 * Lists every update the toolkit has applied (or attempted) and exposes a
 * manual rollback action for any update whose backup still exists on disk.
 *
 * This file is a published stub — own and adjust it for your panel, auth,
 * and Filament version. The engine lives in InstallerToolkit\Update\UpdateService.
 */
class UpdateHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $panel = 'server';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Update History';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.update-history';

    public static function shouldRegisterNavigation(): bool
    {
        return filament()->getCurrentPanel()->getId() === 'server';
    }

    public function getTitle(): string
    {
        return 'Update History';
    }

    protected function service(): UpdateService
    {
        return app(UpdateService::class);
    }

    public function table(Table $table): Table
    {
        $query = Schema::hasTable('update_history')
            ? UpdateHistoryModel::query()
            : UpdateHistoryModel::whereRaw('1 = 0');

        return $table
            ->query($query)
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('version_from')
                    ->label('From'),
                TextColumn::make('version_to')
                    ->label('To'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'applied' => 'success',
                        'in_progress' => 'warning',
                        'failed' => 'danger',
                        'rolled_back' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('backup_id')
                    ->label('Backup ID')
                    ->copyable()
                    ->copyMessage('Backup ID copied')
                    ->placeholder('—')
                    ->limit(24),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('error')
                    ->label('Error')
                    ->limit(50)
                    ->tooltip(fn (UpdateHistoryModel $record): ?string => $record->error ?: null)
                    ->placeholder('—'),
            ])
            ->actions([
                Action::make('rollback')
                    ->label('Rollback')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Roll back application')
                    ->modalDescription(fn (UpdateHistoryModel $record): string => "This will restore backup {$record->backup_id}, overwriting current files and database with the state from before the update to v{$record->version_to}.")
                    ->modalSubmitActionLabel('Yes, roll back')
                    ->visible(fn (UpdateHistoryModel $record): bool => $this->canRollback($record))
                    ->action(fn (UpdateHistoryModel $record) => $this->performRollback($record)),
            ])
            ->emptyStateHeading('No updates yet')
            ->emptyStateDescription('Update history will appear here after you apply an update.');
    }

    /**
     * @return list<array{id: string, version: ?string, created_at: ?string, has_database: bool, size: int}>
     */
    public function getBackupsProperty(): array
    {
        return $this->service()->listBackups();
    }

    protected function canRollback(UpdateHistoryModel $record): bool
    {
        return $record->status === UpdateHistoryModel::STATUS_APPLIED
            && $record->backup_id !== null
            && $this->service()->backupExists($record->backup_id);
    }

    protected function performRollback(UpdateHistoryModel $record): void
    {
        try {
            $this->service()->rollback($record->backup_id);

            Notification::make()
                ->success()
                ->title('Rollback complete')
                ->body("Restored from backup {$record->backup_id}.")
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Rollback failed')
                ->body($e->getMessage())
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('system_update')
                ->label('System Update')
                ->icon('heroicon-o-arrow-path')
                ->url(fn () => SystemUpdate::getUrl()),
        ];
    }
}
