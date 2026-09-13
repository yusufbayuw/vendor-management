<?php

namespace App\Enums;

enum SupplierStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case RevisionRequired = 'revision_required';
    case Approved = 'approved';
    case Active = 'active';
    case Suspended = 'suspended';
    case Rejected = 'rejected';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Diajukan',
            self::UnderReview => 'Dalam Verifikasi',
            self::RevisionRequired => 'Perlu Perbaikan',
            self::Approved => 'Disetujui',
            self::Active => 'Aktif',
            self::Suspended => 'Ditangguhkan',
            self::Rejected => 'Ditolak',
            self::Inactive => 'Tidak Aktif',
        };
    }
}
