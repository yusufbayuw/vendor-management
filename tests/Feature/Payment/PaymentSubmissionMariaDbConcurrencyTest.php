<?php

namespace Tests\Feature\Payment;

use App\Actions\Payment\SubmitPaymentForVerificationAction;
use App\Enums\AccessScopeType;
use App\Enums\ApprovalStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OperationalProfile;
use App\Enums\PaymentAttachmentType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemPermission;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAttachment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\SppgKitchen;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserAccessScope;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PaymentSubmissionMariaDbConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_real_mariadb_connections_submit_once_and_create_one_pending_approval(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MariaDB/MySQL and pcntl: DB_CONNECTION=mysql php artisan test tests/Feature/Payment/PaymentSubmissionMariaDbConcurrencyTest.php');
        }

        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::findOrCreate(SystemPermission::PaymentCreate->value, 'web'));
        UserAccessScope::query()->create([
            'user_id' => $actor->getKey(),
            'scope_type' => AccessScopeType::Global,
            'scope_id' => 0,
        ]);
        $org = Organization::query()->create([
            'code' => 'ORG-PAY-RACE',
            'name' => 'Concurrency test',
            'operational_profile' => OperationalProfile::Standard,
        ]);
        $kitchen = SppgKitchen::query()->create([
            'organization_id' => $org->getKey(),
            'code' => 'SPPG-PAY-RACE',
            'name' => 'Kitchen',
        ]);
        $supplier = Supplier::query()->create([
            'code' => 'SUP-PAY-RACE',
            'legal_name' => 'Supplier',
            'phone' => '08123456789',
        ]);
        $request = PurchaseRequest::query()->create([
            'number' => 'PR-PAY-RACE',
            'sppg_kitchen_id' => $kitchen->getKey(),
            'requested_by' => $actor->getKey(),
            'status' => 'po_generated',
        ]);
        $po = PurchaseOrder::query()->create([
            'number' => 'PO-PAY-RACE',
            'purchase_request_id' => $request->getKey(),
            'sppg_kitchen_id' => $kitchen->getKey(),
            'supplier_id' => $supplier->getKey(),
            'order_date' => today(),
            'subtotal' => 500_000,
            'total_amount' => 500_000,
            'status' => PurchaseOrderStatus::Invoiced,
            'created_by' => $actor->getKey(),
        ]);
        $invoice = Invoice::query()->create([
            'number' => 'INV-PAY-RACE',
            'purchase_order_id' => $po->getKey(),
            'supplier_id' => $supplier->getKey(),
            'sppg_kitchen_id' => $kitchen->getKey(),
            'invoice_date' => today(),
            'po_amount' => 500_000,
            'total_amount' => 500_000,
            'payable_amount' => 500_000,
            'status' => InvoiceStatus::Approved,
            'created_by' => $actor->getKey(),
        ]);
        $payment = Payment::query()->create([
            'number' => 'PAY-RACE',
            'invoice_id' => $invoice->getKey(),
            'payment_date' => today(),
            'amount' => 500_000,
            'payment_method' => PaymentMethod::BankTransfer,
            'status' => PaymentStatus::Draft,
            'created_by' => $actor->getKey(),
        ]);
        PaymentAttachment::query()->create([
            'payment_id' => $payment->getKey(),
            'type' => PaymentAttachmentType::PaymentProof,
            'file_path' => 'payments/proof-race.pdf',
            'uploaded_by' => $actor->getKey(),
            'uploaded_at' => now(),
        ]);

        $dir = sys_get_temp_dir().'/vendor-pay-race-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $pids = [];

        // Fork only after fixture transactions have committed, and reopen the
        // inherited PDO in each child. Both workers race on the same real row.
        DB::disconnect();

        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Could not fork concurrency worker.');
                }

                if ($pid === 0) {
                    DB::purge();
                    DB::reconnect();

                    file_put_contents($dir.'/ready-'.$index, '1');
                    for ($attempt = 0; $attempt < 1000 && ! file_exists($dir.'/go'); $attempt++) {
                        usleep(10_000);
                    }

                    try {
                        app(SubmitPaymentForVerificationAction::class)->execute(
                            Payment::query()->findOrFail($payment->getKey()),
                            User::query()->findOrFail($actor->getKey()),
                        );
                        file_put_contents($dir.'/result-'.$index, 'submitted');
                    } catch (DomainException $exception) {
                        file_put_contents($dir.'/result-'.$index, 'denied');
                    } catch (\Throwable $exception) {
                        file_put_contents($dir.'/result-'.$index, 'unexpected: '.get_class($exception).': '.$exception->getMessage());
                    }

                    exit(0);
                }

                $pids[] = $pid;
            }

            for ($attempt = 0; $attempt < 300; $attempt++) {
                if (file_exists($dir.'/ready-0') && file_exists($dir.'/ready-1')) {
                    break;
                }
                usleep(10_000);
            }

            $this->assertFileExists($dir.'/ready-0');
            $this->assertFileExists($dir.'/ready-1');
            file_put_contents($dir.'/go', '1');

            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $results = [
                file_get_contents($dir.'/result-0'),
                file_get_contents($dir.'/result-1'),
            ];
            sort($results);
            $this->assertSame(['denied', 'submitted'], $results);

            DB::purge();
            DB::reconnect();
            $this->assertSame(PaymentStatus::Submitted, $payment->fresh()->status);
            $this->assertSame(1, $payment->approvalRequests()->count());
            $this->assertSame(ApprovalStatus::Pending, $payment->approvalRequests()->sole()->status);
            $this->assertSame(500_000.0, (float) $payment->approvalRequests()->sole()->amount);
            $this->assertSame(0, $payment->approvalRequests()->sole()->actions()->count());
        } finally {
            foreach ($pids as $pid) {
                // No unreaped children should retain DB connections.
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
