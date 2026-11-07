<?php

declare(strict_types=1);

namespace Modules\Ledger\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    public function normalSide(): NormalSide
    {
        return match ($this) {
            self::Asset, self::Expense => NormalSide::Debit,
            self::Liability, self::Equity, self::Revenue => NormalSide::Credit,
        };
    }
}
