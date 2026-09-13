<?php

namespace App\Enums;

enum GoodsReceiptAttachmentType: string
{
    case DeliveryPhoto = 'delivery_photo';
    case WeightPhoto = 'weight_photo';
    case GoodsPhoto = 'goods_photo';
    case HandoverPhoto = 'handover_photo';
    case DeliveryNote = 'delivery_note';
    case QualityEvidence = 'quality_evidence';
    case Other = 'other';
}
