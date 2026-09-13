<?php

namespace Database\Seeders;

use App\Actions\Billing\ApproveInvoiceAction;
use App\Actions\Billing\CreateInvoiceFromPurchaseOrderAction;
use App\Actions\Billing\SubmitInvoiceAction;
use App\Actions\Fulfillment\AddGoodsReceiptAttachmentAction;
use App\Actions\Fulfillment\ApprovePurchaseOrderExceptionCloseAction;
use App\Actions\Fulfillment\ConfirmDeliveryScheduleAction;
use App\Actions\Fulfillment\CreateDeliveryScheduleAction;
use App\Actions\Fulfillment\InspectGoodsReceiptAction;
use App\Actions\Fulfillment\RecordGoodsReceiptAction;
use App\Actions\Fulfillment\RequestPurchaseOrderExceptionCloseAction;
use App\Actions\Payment\AttachPaymentProofAction;
use App\Actions\Payment\CreatePaymentAction;
use App\Actions\Payment\SubmitPaymentForVerificationAction;
use App\Actions\Payment\VerifyPaymentAction;
use App\Actions\Procurement\AcknowledgePurchaseOrderAction;
use App\Actions\Procurement\AllocatePurchaseRequestItemAction;
use App\Actions\Procurement\ApprovePurchaseOrderAction;
use App\Actions\Procurement\ApprovePurchaseRequestAction;
use App\Actions\Procurement\GeneratePurchaseOrdersAction;
use App\Actions\Procurement\IssuePurchaseOrderAction;
use App\Actions\Procurement\SubmitPurchaseOrderForApprovalAction;
use App\Actions\Procurement\SubmitPurchaseRequestAction;
use App\Enums\AccessScopeType;
use App\Enums\GoodsReceiptAttachmentType;
use App\Enums\OperationalProfile;
use App\Enums\PaymentMethod;
use App\Enums\SupplierDocumentStatus;
use App\Enums\SupplierStatus;
use App\Enums\SystemRole;
use App\Enums\VerificationStatus;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductProcurementRule;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierContact;
use App\Models\SupplierDocument;
use App\Models\SupplierProduct;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserAccessScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DemoDataSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'password';

    public function run(): void
    {
        DB::transaction(function (): void {
            $organizations = $this->seedOrganizationsAndKitchens();
            $users = $this->seedInternalUsers($organizations);
            $products = $this->seedProducts();
            $suppliers = $this->seedSuppliers($users, $products);
            $this->seedDemoFiles();

            if (PurchaseRequest::query()->where('number', 'PR-DEMO-WEEKLY-001')->exists()) {
                return;
            }

            $this->seedProcurementScenarios($organizations, $users, $products, $suppliers);
            $this->seedLeanOperationScenario($organizations, $users, $products, $suppliers);
        }, 3);
    }

    /**
     * @return array{
     *   standard: Organization,
     *   lean: Organization,
     *   bandung: SppgKitchen,
     *   cimahi: SppgKitchen,
     *   sumedang: SppgKitchen,
     *   lean_a: SppgKitchen,
     *   lean_b: SppgKitchen
     * }
     */
    private function seedOrganizationsAndKitchens(): array
    {
        $standard = Organization::query()->updateOrCreate(
            ['code' => 'YTB-DEMO'],
            [
                'name' => 'YTB SPPG Demo',
                'operational_profile' => OperationalProfile::Standard,
                'is_active' => true,
            ],
        );

        $lean = Organization::query()->updateOrCreate(
            ['code' => 'YTB-LEAN'],
            [
                'name' => 'YTB SPPG Lean Demo',
                'operational_profile' => OperationalProfile::Lean,
                'is_active' => true,
            ],
        );

        $bandung = $this->kitchen($standard, 'SPPG-BDG-001', 'SPPG Bandung Utama', 'Jl. Soekarno Hatta, Bandung');
        $cimahi = $this->kitchen($standard, 'SPPG-CMH-001', 'SPPG Cimahi', 'Jl. Amir Machmud, Cimahi');
        $sumedang = $this->kitchen($standard, 'SPPG-SMD-001', 'SPPG Sumedang', 'Jl. Prabu Gajah Agung, Sumedang');
        $leanA = $this->kitchen($lean, 'SPPG-LEAN-01', 'SPPG Lean A', 'Bandung, Jawa Barat');
        $leanB = $this->kitchen($lean, 'SPPG-LEAN-02', 'SPPG Lean B', 'Kabupaten Bandung, Jawa Barat');

        return compact('standard', 'lean', 'bandung', 'cimahi', 'sumedang', 'leanA', 'leanB');
    }

    private function kitchen(Organization $organization, string $code, string $name, string $address): SppgKitchen
    {
        return SppgKitchen::query()->updateOrCreate(
            ['code' => $code],
            [
                'organization_id' => $organization->getKey(),
                'name' => $name,
                'address' => $address,
                'province_code' => '32',
                'phone' => '0225550000',
                'is_active' => true,
            ],
        );
    }

    /** @return array<string, User> */
    private function seedInternalUsers(array $organizations): array
    {
        $admin = $this->user('admin', 'Administrator Demo', 'admin@example.test', '+628111000001');
        $admin->syncRoles([SystemRole::SuperAdmin->value]);
        $this->scope($admin, AccessScopeType::Global, 0, true);

        $pusat = $this->user('pusat', 'Petugas Pusat Demo', 'pusat@example.test', '+628111000002');
        $pusat->syncRoles([
            SystemRole::CentralManager->value,
            SystemRole::Procurement->value,
            SystemRole::ProcurementManager->value,
        ]);
        $this->scope($pusat, AccessScopeType::Global, 0, true);

        $finance = $this->user('finance', 'Finance Demo', 'finance@example.test', '+628111000003');
        $finance->syncRoles([SystemRole::Finance->value]);
        $this->scope($finance, AccessScopeType::Global, 0, true);

        $financeManager = $this->user('finance.manager', 'Finance Manager Demo', 'finance.manager@example.test', '+628111000004');
        $financeManager->syncRoles([SystemRole::FinanceManager->value]);
        $this->scope($financeManager, AccessScopeType::Global, 0, true);

        $bandung = $this->user('sppg.bandung', 'Petugas SPPG Bandung', 'sppg.bandung@example.test', '+628111000005');
        $bandung->syncRoles([
            SystemRole::Requester->value,
            SystemRole::SppgManager->value,
            SystemRole::Receiver->value,
            SystemRole::QualityControl->value,
        ]);
        $this->scope($bandung, AccessScopeType::SppgKitchen, $organizations['bandung']->getKey(), true);

        $cimahi = $this->user('sppg.cimahi', 'Petugas SPPG Cimahi', 'sppg.cimahi@example.test', '+628111000006');
        $cimahi->syncRoles([
            SystemRole::Requester->value,
            SystemRole::SppgManager->value,
            SystemRole::Receiver->value,
            SystemRole::QualityControl->value,
        ]);
        $this->scope($cimahi, AccessScopeType::SppgKitchen, $organizations['cimahi']->getKey(), true);

        $auditor = $this->user('auditor', 'Auditor Demo', 'auditor@example.test', '+628111000007');
        $auditor->syncRoles([SystemRole::Auditor->value]);
        $this->scope($auditor, AccessScopeType::Global, 0, true);

        $lean = $this->user('lean.operator', 'Operator Serba Bisa Demo', 'lean@example.test', '+628111000008');
        $lean->syncRoles([
            SystemRole::CentralManager->value,
            SystemRole::SppgManager->value,
            SystemRole::Requester->value,
            SystemRole::Procurement->value,
            SystemRole::ProcurementManager->value,
            SystemRole::Receiver->value,
            SystemRole::QualityControl->value,
            SystemRole::Finance->value,
            SystemRole::FinanceManager->value,
        ]);
        $this->scope($lean, AccessScopeType::Organization, $organizations['lean']->getKey(), true);

        return compact('admin', 'pusat', 'finance', 'financeManager', 'bandung', 'cimahi', 'auditor', 'lean');
    }

    private function user(string $username, string $name, string $email, string $phone): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'username' => $username,
                'name' => $name,
                'phone' => $phone,
                'password' => self::DEMO_PASSWORD,
            ],
        );

        $user->forceFill([
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ])->save();

        return $user;
    }

    private function scope(User $user, AccessScopeType $type, int $scopeId, bool $primary = false): void
    {
        UserAccessScope::query()->updateOrCreate(
            [
                'user_id' => $user->getKey(),
                'scope_type' => $type->value,
                'scope_id' => $scopeId,
            ],
            ['is_primary' => $primary],
        );
    }

    /** @return array<string, Product> */
    private function seedProducts(): array
    {
        $kg = Unit::query()->where('code', 'KG')->firstOrFail();
        $liter = Unit::query()->where('code', 'L')->firstOrFail();
        $pcs = Unit::query()->where('code', 'PCS')->firstOrFail();

        $protein = ProductCategory::query()->where('code', 'PROTEIN-HEWANI')->firstOrFail();
        $vegetable = ProductCategory::query()->where('code', 'SAYURAN')->firstOrFail();
        $staple = ProductCategory::query()->where('code', 'BAHAN-POKOK')->firstOrFail();
        $spice = ProductCategory::query()->where('code', 'BUMBU')->firstOrFail();

        $chicken = $this->product($protein, $kg, 'AYAM-BROILER', 'Ayam Broiler', 'Ayam broiler segar untuk kebutuhan dapur SPPG.');
        $chili = $this->product($spice, $kg, 'CABAI-MERAH', 'Cabai Merah', 'Cabai merah segar kualitas konsumsi.');
        $rice = $this->product($staple, $kg, 'BERAS-PREMIUM', 'Beras Premium', 'Beras premium medium untuk konsumsi harian.');
        $egg = $this->product($protein, $pcs, 'TELUR-AYAM', 'Telur Ayam', 'Telur ayam segar.');
        $oil = $this->product($staple, $liter, 'MINYAK-GORENG', 'Minyak Goreng', 'Minyak goreng kemasan food grade.');
        $flour = $this->product($staple, $kg, 'TEPUNG-TERIGU', 'Tepung Terigu', 'Tepung terigu serbaguna.');
        $carrot = $this->product($vegetable, $kg, 'WORTEL', 'Wortel', 'Wortel segar.');

        ProductProcurementRule::query()->updateOrCreate(
            ['product_id' => $chicken->getKey()],
            [
                'quantity_tolerance_percentage' => 1,
                'requires_expiry_date' => true,
                'requires_batch_number' => true,
                'requires_temperature' => true,
                'requires_photo' => true,
                'requires_weight_photo' => true,
            ],
        );

        foreach ([$chili, $rice, $egg, $oil, $flour, $carrot] as $product) {
            ProductProcurementRule::query()->updateOrCreate(
                ['product_id' => $product->getKey()],
                [
                    'quantity_tolerance_percentage' => 2,
                    'requires_expiry_date' => false,
                    'requires_batch_number' => false,
                    'requires_temperature' => false,
                    'requires_photo' => true,
                    'requires_weight_photo' => in_array($product->code, ['CABAI-MERAH', 'BERAS-PREMIUM', 'WORTEL'], true),
                ],
            );
        }

        return compact('chicken', 'chili', 'rice', 'egg', 'oil', 'flour', 'carrot');
    }

    private function product(ProductCategory $category, Unit $unit, string $code, string $name, string $description): Product
    {
        return Product::query()->updateOrCreate(
            ['code' => $code],
            [
                'category_id' => $category->getKey(),
                'default_unit_id' => $unit->getKey(),
                'name' => $name,
                'description' => $description,
                'is_active' => true,
            ],
        );
    }

    /** @return array<string, Supplier> */
    private function seedSuppliers(array $users, array $products): array
    {
        $chicken = $this->activeSupplier(
            'SUP-AYAM',
            'PT Ayam Makmur Indonesia',
            'Ayam Makmur',
            'supplier.ayam@example.test',
            '+628121000001',
            '0099001101',
            $users['pusat'],
        );
        $farmer = $this->activeSupplier(
            'SUP-TANI',
            'CV Tani Jaya Sejahtera',
            'Tani Jaya',
            'supplier.tani@example.test',
            '+628121000002',
            '0099001102',
            $users['pusat'],
        );
        $food = $this->activeSupplier(
            'SUP-PANGAN',
            'PT Pangan Nusantara Sejahtera',
            'Pangan Nusantara',
            'supplier.pangan@example.test',
            '+628121000003',
            '0099001103',
            $users['pusat'],
        );

        $pendingUser = $this->user('supplier.pending', 'PIC Supplier Pending', 'supplier.pending@example.test', '+628121000004');
        $pendingUser->syncRoles([SystemRole::SupplierAdmin->value]);
        $pending = Supplier::query()->updateOrCreate(
            ['code' => 'SUP-PENDING'],
            [
                'legal_name' => 'CV Supplier Menunggu Verifikasi',
                'display_name' => 'Supplier Pending',
                'supplier_type' => 'company',
                'email' => 'supplier.pending@example.test',
                'phone' => '+628121000004',
                'status' => SupplierStatus::Submitted,
                'submitted_at' => now()->subDay(),
            ],
        );
        $pending->users()->syncWithoutDetaching([
            $pendingUser->getKey() => ['is_owner' => true, 'is_active' => true],
        ]);
        $this->scope($pendingUser, AccessScopeType::Supplier, $pending->getKey(), true);

        $suspended = Supplier::query()->updateOrCreate(
            ['code' => 'SUP-SUSPENDED'],
            [
                'legal_name' => 'PT Supplier Ditangguhkan',
                'display_name' => 'Supplier Suspended',
                'supplier_type' => 'company',
                'email' => 'supplier.suspended@example.test',
                'phone' => '+628121000005',
                'status' => SupplierStatus::Suspended,
                'suspended_at' => now()->subDays(3),
                'suspension_reason' => 'Data demo: performa pengiriman perlu evaluasi.',
            ],
        );

        $this->supplierProduct($chicken, $products['chicken'], 'AYAM-01', 50, 1000, 1, 40_000);
        $this->supplierProduct($farmer, $products['chili'], 'CABAI-01', 10, 500, 1, 35_000);
        $this->supplierProduct($farmer, $products['carrot'], 'WORTEL-01', 10, 500, 1, 15_000);
        $this->supplierProduct($food, $products['rice'], 'BERAS-01', 50, 5000, 2, 15_000);
        $this->supplierProduct($food, $products['egg'], 'TELUR-01', 100, 20_000, 1, 2_200);
        $this->supplierProduct($food, $products['oil'], 'MINYAK-01', 12, 5000, 2, 18_000);
        $this->supplierProduct($food, $products['flour'], 'TEPUNG-01', 25, 5000, 2, 13_000);

        return compact('chicken', 'farmer', 'food', 'pending', 'suspended');
    }

    private function activeSupplier(
        string $code,
        string $legalName,
        string $displayName,
        string $email,
        string $phone,
        string $accountNumber,
        User $verifier,
    ): Supplier {
        $supplier = Supplier::query()->updateOrCreate(
            ['code' => $code],
            [
                'legal_name' => $legalName,
                'display_name' => $displayName,
                'supplier_type' => 'company',
                'npwp' => '00.000.000.0-000.000',
                'nib' => 'NIB-'.$code,
                'email' => $email,
                'phone' => $phone,
                'address' => 'Bandung, Jawa Barat',
                'province_code' => '32',
                'status' => SupplierStatus::Active,
                'submitted_at' => now()->subDays(30),
                'verified_at' => now()->subDays(25),
                'verified_by' => $verifier->getKey(),
                'activated_at' => now()->subDays(25),
            ],
        );

        $username = str_replace(['supplier.', '@example.test'], ['', ''], $email);
        $user = $this->user('vendor.'.$username, 'PIC '.$displayName, $email, $phone);
        $user->syncRoles([SystemRole::SupplierAdmin->value, SystemRole::SupplierOperator->value]);
        $supplier->users()->syncWithoutDetaching([
            $user->getKey() => ['is_owner' => true, 'is_active' => true],
        ]);
        $this->scope($user, AccessScopeType::Supplier, $supplier->getKey(), true);

        SupplierContact::query()->updateOrCreate(
            ['supplier_id' => $supplier->getKey(), 'email' => $email],
            [
                'name' => 'PIC '.$displayName,
                'position' => 'Owner / Account Manager',
                'phone' => $phone,
                'is_primary' => true,
                'is_finance' => true,
                'is_delivery' => true,
            ],
        );

        SupplierBankAccount::query()->updateOrCreate(
            ['supplier_id' => $supplier->getKey(), 'account_number' => $accountNumber],
            [
                'bank_code' => '014',
                'bank_name' => 'Bank BCA',
                'account_holder' => $legalName,
                'is_primary' => true,
                'verification_status' => VerificationStatus::Verified,
                'verified_at' => now()->subDays(20),
                'verified_by' => $verifier->getKey(),
            ],
        );

        foreach (['NIB', 'NPWP'] as $type) {
            SupplierDocument::query()->updateOrCreate(
                ['supplier_id' => $supplier->getKey(), 'document_type' => $type],
                [
                    'document_number' => $type.'-'.$code,
                    'issued_at' => today()->subYear(),
                    'expires_at' => null,
                    'file_path' => 'demo/suppliers/'.strtolower($code).'/'.strtolower($type).'.txt',
                    'status' => SupplierDocumentStatus::Verified,
                    'verified_at' => now()->subDays(20),
                    'verified_by' => $verifier->getKey(),
                ],
            );
        }

        return $supplier;
    }

    private function supplierProduct(
        Supplier $supplier,
        Product $product,
        string $supplierCode,
        float $minimum,
        float $maximum,
        int $leadTime,
        float $price,
    ): void {
        SupplierProduct::query()->updateOrCreate(
            ['supplier_id' => $supplier->getKey(), 'product_id' => $product->getKey()],
            [
                'supplier_product_code' => $supplierCode,
                'minimum_order_qty' => $minimum,
                'maximum_order_qty' => $maximum,
                'lead_time_days' => $leadTime,
                'indicative_price' => $price,
                'is_available' => true,
                'valid_from' => today()->subMonth(),
                'valid_until' => today()->addYear(),
            ],
        );
    }

    private function seedDemoFiles(): void
    {
        $disk = Storage::disk('local');

        foreach ([
            'demo/evidence/goods-photo.txt' => 'Placeholder foto barang demo.',
            'demo/evidence/weight-photo.txt' => 'Placeholder foto timbang demo.',
            'demo/evidence/delivery-note.txt' => 'Placeholder surat jalan demo.',
            'demo/payments/full-payment-proof.txt' => 'Placeholder bukti pembayaran penuh demo.',
            'demo/payments/partial-payment-proof.txt' => 'Placeholder bukti pembayaran parsial demo.',
            'demo/suppliers/sup-ayam/nib.txt' => 'Dokumen NIB demo SUP-AYAM.',
            'demo/suppliers/sup-ayam/npwp.txt' => 'Dokumen NPWP demo SUP-AYAM.',
            'demo/suppliers/sup-tani/nib.txt' => 'Dokumen NIB demo SUP-TANI.',
            'demo/suppliers/sup-tani/npwp.txt' => 'Dokumen NPWP demo SUP-TANI.',
            'demo/suppliers/sup-pangan/nib.txt' => 'Dokumen NIB demo SUP-PANGAN.',
            'demo/suppliers/sup-pangan/npwp.txt' => 'Dokumen NPWP demo SUP-PANGAN.',
        ] as $path => $content) {
            $disk->put($path, $content);
        }
    }

    private function seedProcurementScenarios(array $organizations, array $users, array $products, array $suppliers): void
    {
        $kg = Unit::query()->where('code', 'KG')->firstOrFail();

        $request = PurchaseRequest::query()->create([
            'number' => 'PR-DEMO-WEEKLY-001',
            'sppg_kitchen_id' => $organizations['bandung']->getKey(),
            'requested_by' => $users['bandung']->getKey(),
            'period_start' => today()->startOfWeek(),
            'period_end' => today()->endOfWeek(),
            'needed_from' => today()->addDay(),
            'needed_until' => today()->addDays(7),
            'description' => 'Kebutuhan bahan pangan mingguan demo.',
            'notes' => 'Skenario utama untuk pengujian alur procure-to-pay.',
        ]);

        $chickenItem = $this->requestItem($request, $products['chicken'], $kg, 500, 40_000, 'Ayam segar, suhu rantai dingin terjaga.');
        $chiliItem = $this->requestItem($request, $products['chili'], $kg, 100, 35_000, 'Cabai merah segar, tidak busuk.');
        $riceItem = $this->requestItem($request, $products['rice'], $kg, 300, 15_000, 'Beras premium, bebas kutu dan bau.');

        app(SubmitPurchaseRequestAction::class)->execute($request, $users['bandung']);
        app(ApprovePurchaseRequestAction::class)->execute($request->refresh(), $users['pusat'], 'Kebutuhan mingguan disetujui.');

        app(AllocatePurchaseRequestItemAction::class)->execute($chickenItem, $suppliers['chicken'], 500, 40_000, $users['pusat']);
        app(AllocatePurchaseRequestItemAction::class)->execute($chiliItem, $suppliers['farmer'], 100, 35_000, $users['pusat']);
        app(AllocatePurchaseRequestItemAction::class)->execute($riceItem, $suppliers['food'], 300, 15_000, $users['pusat']);

        $orders = app(GeneratePurchaseOrdersAction::class)->execute($request->refresh(), $users['pusat']);

        foreach ($orders as $order) {
            app(SubmitPurchaseOrderForApprovalAction::class)->execute($order, $users['pusat']);
            app(ApprovePurchaseOrderAction::class)->execute($order->refresh(), $users['pusat'], 'PO demo disetujui.', 'Operasional demo standard.');
            app(IssuePurchaseOrderAction::class)->execute($order->refresh());
            app(AcknowledgePurchaseOrderAction::class)->execute(
                $order->refresh(),
                $this->supplierUser($order->supplier),
                'PO diterima supplier dan siap dijadwalkan.',
            );
        }

        $chickenPo = $orders->firstWhere('supplier_id', $suppliers['chicken']->getKey());
        $chiliPo = $orders->firstWhere('supplier_id', $suppliers['farmer']->getKey());
        $ricePo = $orders->firstWhere('supplier_id', $suppliers['food']->getKey());

        $this->completeChickenOrder($chickenPo, $users, $suppliers['chicken']);
        $this->closeChiliWithException($chiliPo, $users, $suppliers['farmer']);
        $this->partiallyDeliverRice($ricePo, $users);

        $this->seedAdditionalPurchaseRequests($organizations, $users, $products);
    }

    private function requestItem(
        PurchaseRequest $request,
        Product $product,
        Unit $unit,
        float $quantity,
        float $estimatedPrice,
        string $quality,
    ): PurchaseRequestItem {
        return PurchaseRequestItem::query()->create([
            'purchase_request_id' => $request->getKey(),
            'product_id' => $product->getKey(),
            'unit_id' => $unit->getKey(),
            'description' => $product->name,
            'quality_specification' => $quality,
            'requested_qty' => $quantity,
            'estimated_unit_price' => $estimatedPrice,
            'estimated_total' => $quantity * $estimatedPrice,
            'preferred_delivery_date' => today()->addDay(),
        ]);
    }

    private function completeChickenOrder(PurchaseOrder $order, array $users, Supplier $supplier): void
    {
        $item = $order->items()->firstOrFail();

        foreach ([250, 250] as $index => $quantity) {
            $schedule = app(CreateDeliveryScheduleAction::class)->execute(
                $order->refresh(),
                [$item->getKey() => $quantity],
                now()->addHours(4 + ($index * 24)),
                $this->supplierUser($supplier),
                'Pengiriman ayam tahap '.($index + 1).'.',
            );
            app(ConfirmDeliveryScheduleAction::class)->execute($schedule, $this->supplierUser($supplier));

            $receipt = app(RecordGoodsReceiptAction::class)->execute(
                $schedule->refresh(),
                [$schedule->items->first()->getKey() => $quantity],
                $users['bandung'],
                'PIC Ayam Makmur',
                'Penerimaan ayam tahap '.($index + 1).'.',
            );

            $this->attachReceivingEvidence($receipt, $users['bandung']);
            $receiptItem = $receipt->items->first();

            app(InspectGoodsReceiptAction::class)->execute($receipt, [
                $receiptItem->getKey() => [
                    'accepted_qty' => $quantity,
                    'rejected_qty' => 0,
                    'condition' => 'baik',
                    'batch_number' => 'AYAM-DEMO-'.($index + 1),
                    'expiry_date' => today()->addDays(5),
                    'temperature' => 4.0,
                    'notes' => 'Lolos QC demo.',
                ],
            ], $users['bandung']);
        }

        $invoice = app(CreateInvoiceFromPurchaseOrderAction::class)->execute(
            $order->refresh(),
            $this->supplierUser($supplier),
            'SUP-INV-AYAM-001',
            today(),
            14,
        );
        app(SubmitInvoiceAction::class)->execute($invoice, $this->supplierUser($supplier));
        app(ApproveInvoiceAction::class)->execute($invoice->refresh(), $users['financeManager'], 'Invoice ayam sesuai PO dan penerimaan.');

        $account = $supplier->bankAccounts()->where('is_primary', true)->first();
        $payment = app(CreatePaymentAction::class)->execute(
            $invoice->refresh(),
            (float) $invoice->payable_amount,
            PaymentMethod::BankTransfer,
            $users['finance'],
            today(),
            'Bank BCA',
            'TRX-DEMO-FULL-001',
            $account,
            'Pembayaran penuh PO ayam demo.',
        );
        app(AttachPaymentProofAction::class)->execute(
            $payment,
            'demo/payments/full-payment-proof.txt',
            $users['finance'],
            mimeType: 'text/plain',
            caption: 'Bukti pembayaran penuh demo',
        );
        app(SubmitPaymentForVerificationAction::class)->execute($payment, $users['finance']);
        app(VerifyPaymentAction::class)->execute($payment->refresh(), $users['financeManager']);
    }

    private function closeChiliWithException(PurchaseOrder $order, array $users, Supplier $supplier): void
    {
        $item = $order->items()->firstOrFail();
        $schedule = app(CreateDeliveryScheduleAction::class)->execute(
            $order->refresh(),
            [$item->getKey() => 100],
            now()->addHours(6),
            $this->supplierUser($supplier),
            'Pengiriman cabai demo.',
        );
        app(ConfirmDeliveryScheduleAction::class)->execute($schedule, $this->supplierUser($supplier));

        $receipt = app(RecordGoodsReceiptAction::class)->execute(
            $schedule->refresh(),
            [$schedule->items->first()->getKey() => 95],
            $users['bandung'],
            'PIC Tani Jaya',
            'Terdapat kekurangan 5 kg dibanding jadwal.',
        );
        $this->attachReceivingEvidence($receipt, $users['bandung']);
        $receiptItem = $receipt->items->first();

        app(InspectGoodsReceiptAction::class)->execute($receipt, [
            $receiptItem->getKey() => [
                'accepted_qty' => 95,
                'rejected_qty' => 0,
                'condition' => 'baik',
                'notes' => '95 kg diterima baik; kekurangan 5 kg diproses sebagai exception.',
            ],
        ], $users['bandung']);

        app(RequestPurchaseOrderExceptionCloseAction::class)->execute(
            $order->refresh(),
            $users['bandung'],
            'Supplier tidak mengirim sisa 5 kg; kebutuhan dapur sudah ditutup.',
        );
        app(ApprovePurchaseOrderExceptionCloseAction::class)->execute(
            $order->refresh(),
            $users['pusat'],
            'Kekurangan 5 kg diterima sebagai exception demo.',
        );

        $invoice = app(CreateInvoiceFromPurchaseOrderAction::class)->execute(
            $order->refresh(),
            $this->supplierUser($supplier),
            'SUP-INV-CABAI-001',
            today(),
            14,
        );
        app(SubmitInvoiceAction::class)->execute($invoice, $this->supplierUser($supplier));
        app(ApproveInvoiceAction::class)->execute($invoice->refresh(), $users['financeManager'], 'Invoice cabai disetujui berdasarkan nilai PO.');

        $account = $supplier->bankAccounts()->where('is_primary', true)->first();
        $payment = app(CreatePaymentAction::class)->execute(
            $invoice->refresh(),
            1_000_000,
            PaymentMethod::BankTransfer,
            $users['finance'],
            today(),
            'Bank BCA',
            'TRX-DEMO-PARTIAL-001',
            $account,
            'Pembayaran parsial invoice cabai demo.',
        );
        app(AttachPaymentProofAction::class)->execute(
            $payment,
            'demo/payments/partial-payment-proof.txt',
            $users['finance'],
            mimeType: 'text/plain',
            caption: 'Bukti pembayaran parsial demo',
        );
        app(SubmitPaymentForVerificationAction::class)->execute($payment, $users['finance']);
        app(VerifyPaymentAction::class)->execute($payment->refresh(), $users['financeManager']);
    }

    private function partiallyDeliverRice(PurchaseOrder $order, array $users): void
    {
        $item = $order->items()->firstOrFail();
        $supplierUser = $this->supplierUser($order->supplier);
        $schedule = app(CreateDeliveryScheduleAction::class)->execute(
            $order->refresh(),
            [$item->getKey() => 150],
            now()->addDay(),
            $supplierUser,
            'Pengiriman beras tahap pertama dari total 300 kg.',
        );
        app(ConfirmDeliveryScheduleAction::class)->execute($schedule, $supplierUser);
        $receipt = app(RecordGoodsReceiptAction::class)->execute(
            $schedule->refresh(),
            [$schedule->items->first()->getKey() => 150],
            $users['bandung'],
            'PIC Pangan Nusantara',
            'Tahap pertama diterima lengkap.',
        );
        $this->attachReceivingEvidence($receipt, $users['bandung']);
        $receiptItem = $receipt->items->first();

        app(InspectGoodsReceiptAction::class)->execute($receipt, [
            $receiptItem->getKey() => [
                'accepted_qty' => 150,
                'rejected_qty' => 0,
                'condition' => 'baik',
                'notes' => 'Masih tersisa 150 kg untuk pengiriman berikutnya.',
            ],
        ], $users['bandung']);
    }

    private function attachReceivingEvidence($receipt, User $actor): void
    {
        app(AddGoodsReceiptAttachmentAction::class)->execute(
            $receipt,
            GoodsReceiptAttachmentType::GoodsPhoto,
            'demo/evidence/goods-photo.txt',
            $actor,
            'Foto barang demo',
        );
        app(AddGoodsReceiptAttachmentAction::class)->execute(
            $receipt,
            GoodsReceiptAttachmentType::WeightPhoto,
            'demo/evidence/weight-photo.txt',
            $actor,
            'Foto timbang demo',
        );
        app(AddGoodsReceiptAttachmentAction::class)->execute(
            $receipt,
            GoodsReceiptAttachmentType::DeliveryNote,
            'demo/evidence/delivery-note.txt',
            $actor,
            'Surat jalan demo',
        );
    }

    private function seedAdditionalPurchaseRequests(array $organizations, array $users, array $products): void
    {
        $kg = Unit::query()->where('code', 'KG')->firstOrFail();
        $liter = Unit::query()->where('code', 'L')->firstOrFail();

        $draft = PurchaseRequest::query()->create([
            'number' => 'PR-DEMO-DRAFT-001',
            'sppg_kitchen_id' => $organizations['cimahi']->getKey(),
            'requested_by' => $users['cimahi']->getKey(),
            'period_start' => today()->addWeek()->startOfWeek(),
            'period_end' => today()->addWeek()->endOfWeek(),
            'description' => 'PR draft untuk pengujian edit dan submit.',
        ]);
        $this->requestItem($draft, $products['flour'], $kg, 100, 13_000, 'Tepung kering dan kemasan utuh.');
        $this->requestItem($draft, $products['oil'], $liter, 60, 18_000, 'Minyak goreng kemasan utuh.');

        $submitted = PurchaseRequest::query()->create([
            'number' => 'PR-DEMO-SUBMITTED-001',
            'sppg_kitchen_id' => $organizations['cimahi']->getKey(),
            'requested_by' => $users['cimahi']->getKey(),
            'period_start' => today()->addWeek()->startOfWeek(),
            'period_end' => today()->addWeek()->endOfWeek(),
            'description' => 'PR menunggu approval untuk pengujian inbox manager.',
        ]);
        $this->requestItem($submitted, $products['rice'], $kg, 200, 15_000, 'Beras premium.');
        app(SubmitPurchaseRequestAction::class)->execute($submitted, $users['cimahi']);
    }

    private function seedLeanOperationScenario(array $organizations, array $users, array $products, array $suppliers): void
    {
        $kg = Unit::query()->where('code', 'KG')->firstOrFail();
        $request = PurchaseRequest::query()->create([
            'number' => 'PR-DEMO-LEAN-001',
            'sppg_kitchen_id' => $organizations['leanA']->getKey(),
            'requested_by' => $users['lean']->getKey(),
            'period_start' => today()->startOfWeek(),
            'period_end' => today()->endOfWeek(),
            'description' => 'Skenario SDM minimal: satu user membuat sekaligus menyetujui transaksi.',
        ]);
        $item = $this->requestItem($request, $products['carrot'], $kg, 50, 15_000, 'Wortel segar.');

        app(SubmitPurchaseRequestAction::class)->execute($request, $users['lean']);
        app(ApprovePurchaseRequestAction::class)->execute(
            $request->refresh(),
            $users['lean'],
            'Self approval diizinkan oleh operational profile lean.',
            'Demo satu orang menangani seluruh SPPG dalam organisasi lean.',
        );
        app(AllocatePurchaseRequestItemAction::class)->execute($item, $suppliers['farmer'], 50, 15_000, $users['lean']);
        app(GeneratePurchaseOrdersAction::class)->execute($request->refresh(), $users['lean']);
    }

    private function supplierUser(Supplier $supplier): User
    {
        return $supplier->users()
            ->wherePivot('is_active', true)
            ->orderByDesc('supplier_users.is_owner')
            ->firstOrFail();
    }
}
