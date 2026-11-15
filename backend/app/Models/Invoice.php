<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Facture ou avoir émis — immuable (trigger PostgreSQL). */
class Invoice extends Model
{
    use HasUuids;

    public const TYPE_INVOICE = 'invoice';

    public const TYPE_CREDIT_NOTE = 'credit_note';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'vat_applicable' => 'boolean',
            'vat_rate_bp' => 'integer',
            'total_ht_xof' => 'integer',
            'total_vat_xof' => 'integer',
            'total_ttc_xof' => 'integer',
            'seller' => 'array',
            'buyer' => 'array',
            'lines' => 'array',
            'is_test' => 'boolean',
            'sequence' => 'integer',
            'year' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'original_invoice_id')->orderBy('issued_at');
    }

    public function isCreditNote(): bool
    {
        return $this->type === self::TYPE_CREDIT_NOTE;
    }

    public function label(): string
    {
        return $this->isCreditNote() ? 'Avoir' : 'Facture';
    }

    public function fileName(): string
    {
        return $this->number.'.pdf';
    }

    /** Résumé exposé au client et au back-office (sans les données vendeur/acheteur complètes). */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $this->label(),
            'number' => $this->number,
            'issued_at' => $this->issued_at->toIso8601ZuluString(),
            'vat_applicable' => $this->vat_applicable,
            'total_ht_xof' => $this->total_ht_xof,
            'total_vat_xof' => $this->total_vat_xof,
            'total_ttc_xof' => $this->total_ttc_xof,
            'is_test' => $this->is_test,
        ];
    }
}
