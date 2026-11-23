<?php

namespace App\Modules\Geography;

use App\Modules\Foundation\Audit\Subject;

enum Master: string
{
    case Region = 'region';
    case Reason = 'reason';
    case Unit = 'unit';
    case Tax = 'tax';

    public function table(): string
    {
        return match ($this) {
            self::Region => 'regions',
            self::Reason => 'reason_codes',
            self::Unit => 'units',
            self::Tax => 'tax_classes',
        };
    }

    public function subject(): Subject
    {
        return match ($this) {
            self::Region => Subject::Region,
            self::Reason => Subject::Reason,
            self::Unit => Subject::Unit,
            self::Tax => Subject::TaxClass,
        };
    }

    public function permission(string $action): string
    {
        return "geography.{$this->value}.{$action}";
    }
}
