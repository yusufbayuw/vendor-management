<?php

namespace App\Enums;

enum SystemPermission: string
{
    case OrganizationView = 'organization.view';
    case OrganizationManage = 'organization.manage';
    case KitchenView = 'kitchen.view';
    case KitchenManage = 'kitchen.manage';
    case UserView = 'user.view';
    case UserManage = 'user.manage';
    case MasterDataView = 'master_data.view';
    case MasterDataManage = 'master_data.manage';
    case GovernanceManage = 'governance.manage';
    case LegacyImportManage = 'legacy_import.manage';
    case SupplierView = 'supplier.view';
    case SupplierManage = 'supplier.manage';
    case SupplierVerify = 'supplier.verify';
    case SupplierSuspend = 'supplier.suspend';
    case SupplierProfileManage = 'supplier.profile.manage';
    case PurchaseRequestSubmit = 'purchase_request.submit';
    case PurchaseRequestApprove = 'purchase_request.approve';
    case PurchaseRequestAllocate = 'purchase_request.allocate';
    case PurchaseOrderCreate = 'purchase_order.create';
    case PurchaseOrderApprove = 'purchase_order.approve';
    case PurchaseOrderIssue = 'purchase_order.issue';
    case PurchaseOrderAcknowledge = 'purchase_order.acknowledge';
    case PurchaseOrderAmendmentApprove = 'purchase_order.amendment.approve';
    case PurchaseOrderExceptionClose = 'purchase_order.exception.close';
    case DeliverySchedule = 'delivery.schedule';
    case DeliveryManage = 'delivery.manage';
    case GoodsReceiptCreate = 'goods_receipt.create';
    case GoodsReceiptInspect = 'goods_receipt.inspect';
    case GoodsReceiptReject = 'goods_receipt.reject';
    case InvoiceSubmit = 'invoice.submit';
    case InvoiceReview = 'invoice.review';
    case InvoiceApprove = 'invoice.approve';
    case PaymentCreate = 'payment.create';
    case PaymentVerify = 'payment.verify';
    case AuditView = 'audit.view';
    case ReportsView = 'reports.view';
}
