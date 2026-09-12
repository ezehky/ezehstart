@props(['transaction'])
<span @class([
    'font-medium',
    'text-red-600 dark:text-red-400' => $transaction->transaction_type->isDebit(),
    'text-emerald-600 dark:text-emerald-400' => ! $transaction->transaction_type->isDebit(),
])>
    {{ $transaction->signedAmount() }}
</span>
