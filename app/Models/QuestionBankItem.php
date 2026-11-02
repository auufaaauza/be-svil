<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionBankItem extends Model
{
    use HasFactory;

    protected $fillable = ['classroom_id', 'created_by', 'subject', 'category', 'cognitive_level', 'question', 'version'];

    protected function casts(): array
    {
        return ['question' => 'array', 'version' => 'integer'];
    }
}
