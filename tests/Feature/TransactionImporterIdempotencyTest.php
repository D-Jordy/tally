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
