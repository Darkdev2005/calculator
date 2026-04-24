<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalculationHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'calculation_section_id',
        'operation',
        'operand_left',
        'operand_right',
        'result',
        'note',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'operand_left' => 'float',
            'operand_right' => 'float',
            'result' => 'float',
            'calculated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CalculationSection::class, 'calculation_section_id');
    }
}
