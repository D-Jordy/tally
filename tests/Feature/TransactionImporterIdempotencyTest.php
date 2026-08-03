<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Services\Import\TransactionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionImporterIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /** Write a DEGIRO transactions CSV to a temp file and return its path. */
    private function writeCsv(array $dataRows): string
    {
        $header = 'Datum,Tijd,Product,ISIN,Beurs,Uitvoeringsplaats,Aantal,Koers,,Lokale waarde,,Waarde,Wisselkoers,AutoFX,Transactiekosten,Totaal,Order Id,';
        $path = tempnam(sys_get_temp_dir(), 'degiro').'.csv';
        file_put_contents($path, $header."\n".implode("\n", $dataRows)."\n");

        return $path;
    }

    public function test_reimporting_the_same_export_does_not_duplicate(): void
    {
        $account = Account::factory()->create();
        $rows = [
            '02-01-2024,10:00,Some Stock,US1234567890,NDQ,NDQ,10,"25,00",EUR,"-250,00",EUR,"-250,00",,,"-1,00","-251,00",,aaaa-1111',
            '03-01-2024,11:00,Other Stock,US9876543210,NDQ,NDQ,-5,"40,00",EUR,"200,00",EUR,"200,00",,,"-1,00","199,00",,bbbb-2222',
        ];

        $first = (new TransactionImporter)->import($account, $this->writeCsv($rows));
        $this->assertSame(2, $first->inserted);

        $second = (new TransactionImporter)->import($account, $this->writeCsv($rows));
        $this->assertSame(0, $second->inserted);
        $this->assertSame(2, $second->skipped);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_rows_without_a_broker_uuid_still_dedupe(): void
    {
        $account = Account::factory()->create();
        $rows = [
            '02-01-2024,10:00,Some Stock,US1234567890,NDQ,NDQ,10,"25,00",EUR,"-250,00",EUR,"-250,00",,,"-1,00","-251,00",,',
        ];

        (new TransactionImporter)->import($account, $this->writeCsv($rows));
        $result = (new TransactionImporter)->import($account, $this->writeCsv($rows));

        $this->assertSame(0, $result->inserted);
        $this->assertSame(1, $result->skipped);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertNull(Transaction::whereNull('dedupe_hash')->first());
    }

    /**
     * The real LGEN sale of 22-10-2025: one order id, two fills at the same second,
     * quantity and price, told apart only by the execution venue and the fee. Both
     * rows carry the UUID in column 16 — DEGIRO omits the blank Order ID column on
     * most rows, so these are 17 columns wide, not 18.
     */
    public function test_partial_fills_of_one_order_are_kept_apart(): void
    {
        $account = Account::factory()->create();
        $rows = [
            '22-10-2025,10:23,LEGAL & GENERAL GROUP PLC,GB0005603997,LSE,MESI,-193,"240,5000",GBX,"46416,50",GBX,"533,22","87,0496","-1,33",,"531,89",abfc0b92-5318-4c99-89c4-624d560a5d9a',
            '22-10-2025,10:23,LEGAL & GENERAL GROUP PLC,GB0005603997,LSE,XLON,-193,"240,5000",GBX,"46416,50",GBX,"533,22","87,0496","-1,33","-4,90","526,99",abfc0b92-5318-4c99-89c4-624d560a5d9a',
        ];

        $first = (new TransactionImporter)->import($account, $this->writeCsv($rows));

        $this->assertSame(2, $first->inserted);
        $this->assertSame(386.0, (float) Transaction::where('type', 'sell')->sum('quantity'));
        $this->assertSame(
            'abfc0b92-5318-4c99-89c4-624d560a5d9a',
            Transaction::first()->external_id,
        );

        // Re-importing the overlapping export still matches both rows.
        $second = (new TransactionImporter)->import($account, $this->writeCsv($rows));

        $this->assertSame(0, $second->inserted);
        $this->assertSame(2, $second->skipped);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_the_same_export_imported_into_two_accounts_is_kept_separate(): void
    {
        $rows = [
            '02-01-2024,10:00,Some Stock,US1234567890,NDQ,NDQ,10,"25,00",EUR,"-250,00",EUR,"-250,00",,,"-1,00","-251,00",,aaaa-1111',
        ];

        foreach (Account::factory()->count(2)->create() as $account) {
            $result = (new TransactionImporter)->import($account, $this->writeCsv($rows));
            $this->assertSame(1, $result->inserted);
        }

        $this->assertDatabaseCount('transactions', 2);
    }
}
