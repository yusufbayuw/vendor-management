<?php

namespace App\Enums;

enum DiscrepancyType: string
{
    case UnderDelivery = 'under_delivery';
    case OverDelivery = 'over_delivery';
    case RejectedGoods = 'rejected_goods';
    case LateDelivery = 'late_delivery';
    case MissingDelivery = 'missing_delivery';
    case WrongProduct = 'wrong_product';
    case QualityIssue = 'quality_issue';
}
