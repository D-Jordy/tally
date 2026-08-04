<?php

namespace App\Filament\Resources\Instruments\Pages;

use App\Filament\Resources\Instruments\InstrumentResource;
use App\Models\Instrument;
use App\Services\MarketData\DividendSyncService;
use App\Services\MarketData\PriceSyncService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;

class EditInstrument extends EditRecord
{
    protected static string $resource = InstrumentResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * Instruments are shared by every user, and the model drops the prices and
     * dividends fetched under the old symbol. Refill them straight away, or every
     * holder of this instrument stares at an empty chart until the 02:00 sync.
     *
     * Only this one instrument, and failures are reported rather than thrown: a
     * Yahoo hiccup must not lose the correction the user just saved.
     */
    protected function afterSave(): void
    {
        /** @var Instrument $instrument */
        $instrument = $this->getRecord();

        if (! $instrument->wasChanged('yahoo_symbol') || $instrument->yahoo_symbol === null) {
            return;
        }

        try {
            app(PriceSyncService::class)->syncInstrument($instrument);
            app(DividendSyncService::class)->syncInstrument($instrument);
        } catch (\Throwable $e) {
            Log::error("EditInstrument resync: {$instrument->yahoo_symbol} failed", ['error' => $e->getMessage()]);

            Notification::make()
                ->title(__('instruments.resync.failed'))
                ->warning()
                ->send();
        }
    }
}
