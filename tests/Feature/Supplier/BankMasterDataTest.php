<?php

namespace Tests\Feature\Supplier;

use App\Models\Bank;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use Database\Seeders\BankSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankMasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_seeder_contains_current_common_bank_codes(): void
    {
        $this->seed(BankSeeder::class);

        $this->assertDatabaseHas('banks', ['code' => '002', 'name' => 'Bank BRI']);
        $this->assertDatabaseHas('banks', ['code' => '451', 'name' => 'Bank Syariah Indonesia (BSI)']);
        $this->assertDatabaseHas('banks', ['code' => '535', 'name' => 'SeaBank Indonesia']);
        $this->assertDatabaseHas('banks', ['code' => '542', 'name' => 'Bank Jago']);
        $this->assertDatabaseHas('banks', ['code' => '562', 'name' => 'Superbank']);
    }

    public function test_supplier_bank_account_uses_bank_master_as_source_of_truth(): void
    {
        $bank = Bank::query()->create([
            'code' => '014',
            'name' => 'Bank BCA',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $supplier = Supplier::query()->create([
            'code' => 'SUP-BANK-MASTER',
            'legal_name' => 'Warung Pangan Sejahtera',
            'display_name' => 'Warung Pangan Sejahtera',
        ]);

        $account = SupplierBankAccount::query()->create([
            'supplier_id' => $supplier->getKey(),
            'bank_id' => $bank->getKey(),
            'account_number' => '1234567890',
            'account_holder' => 'WARUNG PANGAN SEJAHTERA',
            'is_primary' => true,
        ]);

        $this->assertSame('014', $account->bank_code);
        $this->assertSame('Bank BCA', $account->bank_name);

        $bank->update([
            'code' => '999',
            'name' => 'Bank Uji Baru',
        ]);

        $account->refresh();

        $this->assertSame('999', $account->bank_code);
        $this->assertSame('Bank Uji Baru', $account->bank_name);
    }
}
