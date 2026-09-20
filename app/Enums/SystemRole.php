<?php

namespace App\Enums;

enum SystemRole: string
{
    case SuperAdmin = 'super_admin';
    case PanelUser = 'panel_user';
    case CentralManager = 'central_manager';
    case MasterDataSteward = 'master_data_steward';
    case SppgManager = 'sppg_manager';
    case Requester = 'requester';
    case Procurement = 'procurement';
    case ProcurementManager = 'procurement_manager';
    case Receiver = 'receiver';
    case QualityControl = 'quality_control';
    case Finance = 'finance';
    case FinanceManager = 'finance_manager';
    case SupplierAdmin = 'supplier_admin';
    case SupplierOperator = 'supplier_operator';
    case Auditor = 'auditor';
}
